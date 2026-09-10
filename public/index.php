<?php
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

require __DIR__ . '/../vendor/autoload.php';

// Set default timezone to Qatar
date_default_timezone_set('Asia/Qatar');

// Start session
session_start();

// Create App
$app = AppFactory::create();

// Language Support
$_SESSION['lang'] = $_SESSION['lang'] ?? 'en';
$langArray = [];
if ($_SESSION['lang'] === 'ar') {
    if (file_exists(__DIR__ . '/lang.php')) {
        $langArray = require __DIR__ . '/lang.php';
    }
}

if (!function_exists('__')) {
    function __($text) {
        global $langArray;
        return $langArray[$text] ?? $text;
    }
}

// Create Twig — use file cache in production (defined in host-index.php), disable in dev
$twigCache = defined('TWIG_CACHE_PATH') ? TWIG_CACHE_PATH : false;
$twig = Twig::create(__DIR__ . '/../templates', ['cache' => $twigCache]);

// Add Twig custom function for translation
$twig->getEnvironment()->addFunction(new \Twig\TwigFunction('__', function ($text) {
    return __($text);
}));
$twig->getEnvironment()->addGlobal('lang', $_SESSION['lang']);

$twig->getEnvironment()->addGlobal('current_user', $_SESSION['user'] ?? null);

// Add Twig-View Middleware
$app->add(TwigMiddleware::create($app, $twig));

// Add Body Parsing Middleware for JSON requests
$app->addBodyParsingMiddleware();

$app->addErrorMiddleware(true, true, true);

// Set up SQLite Database Connection
$dbFile = defined('DB_PATH') ? DB_PATH : __DIR__ . '/../database.sqlite';
$pdo = new PDO('sqlite:' . $dbFile);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

// SQLite Performance Pragmas (WAL mode for concurrent reads, fast sync, large cache)
$pdo->exec('PRAGMA journal_mode=WAL');
$pdo->exec('PRAGMA synchronous=NORMAL');
$pdo->exec('PRAGMA cache_size=10000');
$pdo->exec('PRAGMA temp_store=MEMORY');
$pdo->exec('PRAGMA foreign_keys=ON');

// =====================================================
// HELPER: Get the active branch_id for the current user
// =====================================================
function getActiveBranchId() {
    $role = $_SESSION['user']['role'] ?? '';
    if ($role === 'Owner' || $role === 'Admin') {
        // Owner and Admin can switch branches; use active_branch_id from session
        return $_SESSION['active_branch_id'] ?? null; // null = all branches
    }
    return $_SESSION['user']['branch_id'] ?? null;
}

function isOwner() {
    return ($_SESSION['user']['role'] ?? '') === 'Owner';
}

function isAdmin() {
    return in_array($_SESSION['user']['role'] ?? '', ['Admin', 'Owner']);
}

function getOverdueCreditAlerts($pdo, $customerId = null) {
    $alerts = [];
    if ($customerId) {
        $stmtC = $pdo->prepare("SELECT id, name, balance FROM customers WHERE id = ? AND balance > 0");
        $stmtC->execute([$customerId]);
    } else {
        $stmtC = $pdo->query("SELECT id, name, balance FROM customers WHERE balance > 0");
    }
    
    while ($c = $stmtC->fetch(PDO::FETCH_ASSOC)) {
        $remBal = (float)$c['balance'];
        
        $stmtS = $pdo->prepare("SELECT invoice_id, due, sale_date FROM sales WHERE customer_id = ? AND payment_method = 'Credit' AND status != 'Voided' AND due > 0 ORDER BY sale_date DESC");
        $stmtS->execute([$c['id']]);
        $sales = $stmtS->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($sales as $sale) {
            if ($remBal <= 0) break;
            $amtOwed = min((float)$sale['due'], $remBal);
            $remBal -= $amtOwed;
            
            $sDate = new DateTime($sale['sale_date']);
            $now = new DateTime();
            $days = $now->diff($sDate)->days;
            
            if ($days >= 27) {
                $alerts[] = [
                    'customer_id' => $c['id'],
                    'customer_name' => $c['name'],
                    'invoice_id' => $sale['invoice_id'],
                    'amount_owed' => $amtOwed,
                    'days_elapsed' => $days,
                    'sale_date' => $sale['sale_date']
                ];
            }
        }
    }
    
    usort($alerts, function($a, $b) {
        return $b['days_elapsed'] <=> $a['days_elapsed'];
    });
    
    return $alerts;
}

// Make branch info available globally in templates
$twig->getEnvironment()->addGlobal('active_branch_id', $_SESSION['active_branch_id'] ?? null);

// Load branches for template use
$branchesForNav = [];
try {
    $stmt = $pdo->query('SELECT * FROM branches WHERE is_active = 1 ORDER BY name ASC');
    $branchesForNav = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Table might not exist yet during migration
}
$twig->getEnvironment()->addGlobal('all_branches', $branchesForNav);

// Get current branch name
$activeBranchName = 'All Branches';
if (isset($_SESSION['active_branch_id'])) {
    foreach ($branchesForNav as $b) {
        if ($b['id'] == $_SESSION['active_branch_id']) {
            $activeBranchName = $b['name'];
            break;
        }
    }
}
$twig->getEnvironment()->addGlobal('active_branch_name', $activeBranchName);

// Authentication Middleware
$authMiddleware = function (Request $request, RequestHandler $handler) use ($app) {
    if ($request->getUri()->getPath() === '/login') {
        return $handler->handle($request);
    }
    if (!isset($_SESSION['user'])) {
        $response = $app->getResponseFactory()->createResponse();
        return $response->withHeader('Location', '/login')->withStatus(302);
    }
    return $handler->handle($request);
};

$roleMiddleware = function (array $allowedRoles) use ($app) {
    return function (Request $request, RequestHandler $handler) use ($allowedRoles, $app) {
        $userRole = $_SESSION['user']['role'] ?? '';
        // Owner has access to everything
        if ($userRole === 'Owner' || in_array($userRole, $allowedRoles)) {
            return $handler->handle($request);
        }
        $response = $app->getResponseFactory()->createResponse();
        return $response->withHeader('Location', '/')->withStatus(302);
    };
};

$app->add($authMiddleware);

// =====================================================
// AUTH ROUTES
// =====================================================
$app->get('/login', function (Request $request, Response $response, $args) {
    $view = Twig::fromRequest($request);
    return $view->render($response, 'login.twig', [
        'error' => $_SESSION['error'] ?? null
    ]);
});

$app->post('/login', function (Request $request, Response $response, $args) use ($pdo) {
    $data = (array)$request->getParsedBody();
    $username = $data['username'] ?? '';
    $password = $data['password'] ?? '';
    
    $stmt = $pdo->prepare('SELECT * FROM users WHERE username = ?');
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['user'] = [
            'id' => $user['id'],
            'username' => $user['username'],
            'role' => $user['role'],
            'branch_id' => $user['branch_id'] ?? null
        ];
        
        // Set active branch based on role
        if ($user['role'] === 'Owner') {
            // Owner starts with "All Branches" view
            $_SESSION['active_branch_id'] = null;
        } else {
            // Other roles are locked to their branch
            $_SESSION['active_branch_id'] = $user['branch_id'];
        }
        
        unset($_SESSION['error']);
        return $response->withHeader('Location', '/')->withStatus(302);
    }
    
    $_SESSION['error'] = 'Invalid username or password.';
    return $response->withHeader('Location', '/login')->withStatus(302);
});

$app->get('/lang/{locale}', function (Request $request, Response $response, $args) {
    $locale = $args['locale'] === 'ar' ? 'ar' : 'en';
    $_SESSION['lang'] = $locale;
    
    // Redirect back
    $referer = $request->getHeaderLine('Referer');
    if (empty($referer)) {
        $referer = '/';
    }
    return $response->withHeader('Location', $referer)->withStatus(302);
});

$app->get('/logout', function (Request $request, Response $response, $args) {
    session_destroy();
    return $response->withHeader('Location', '/login')->withStatus(302);
});

// =====================================================
// BRANCH SWITCHER (Owner only)
// =====================================================
$app->post('/api/switch-branch', function (Request $request, Response $response, $args) {
    $data = (array)$request->getParsedBody();
    $branchId = $data['branch_id'] ?? null;
    
    $role = $_SESSION['user']['role'] ?? '';
    if ($role !== 'Owner' && $role !== 'Admin') {
        $response->getBody()->write(json_encode(['success' => false, 'message' => 'Unauthorized']));
        return $response->withHeader('Content-Type', 'application/json');
    }
    
    if ($branchId === '' || $branchId === 'all' || $branchId === null) {
        $_SESSION['active_branch_id'] = null;
    } else {
        $_SESSION['active_branch_id'] = (int)$branchId;
    }
    
    $response->getBody()->write(json_encode(['success' => true]));
    return $response->withHeader('Content-Type', 'application/json');
});

// =====================================================
// DASHBOARD
// =====================================================
$app->get('/', function (Request $request, Response $response, $args) use ($pdo) {
    $role = $_SESSION['user']['role'] ?? '';
    $userId = $_SESSION['user']['id'] ?? 0;
    $branchId = getActiveBranchId();

    $params = $request->getQueryParams();
    $startDate = $params['start_date'] ?? date('Y-m-01');
    $endDate = $params['end_date'] ?? date('Y-m-t');

    $stats = [];
    $low_stock_items = [];
    $branch_stats = []; // For Owner multi-branch view

    if ($role === 'Owner' || $role === 'Admin') {
        // Build branch filter SQL
        $branchFilter = '';
        $branchParams = [];
        if ($branchId) {
            $branchFilter = ' AND s.branch_id = ?';
            $branchParams = [$branchId];
        }
        
        $expBranchFilter = '';
        $expBranchParams = [];
        if ($branchId) {
            $expBranchFilter = ' AND branch_id = ?';
            $expBranchParams = [$branchId];
        }

        // Revenue
        $stmt = $pdo->prepare("SELECT SUM(total) FROM sales s WHERE sale_date BETWEEN ? AND ? AND status != 'Voided'" . $branchFilter);
        $stmt->execute(array_merge([$startDate . ' 00:00:00', $endDate . ' 23:59:59'], $branchParams));
        $products_revenue = $stmt->fetchColumn() ?: 0;

        // Calculate Products COGS
        $stmt = $pdo->prepare("
            SELECT SUM(si.quantity * p.cost_price) 
            FROM sale_items si 
            JOIN sales s ON si.sale_id = s.id 
            JOIN products p ON si.product_id = p.id 
            WHERE s.sale_date BETWEEN ? AND ? AND s.status != 'Voided'" . $branchFilter);
        $stmt->execute(array_merge([$startDate . ' 00:00:00', $endDate . ' 23:59:59'], $branchParams));
        $products_cogs = $stmt->fetchColumn() ?: 0;

        // Expenses
        $stmt = $pdo->prepare("SELECT SUM(amount) FROM expenses WHERE expense_date BETWEEN ? AND ?" . $expBranchFilter);
        $stmt->execute(array_merge([$startDate . ' 00:00:00', $endDate . ' 23:59:59'], $expBranchParams));
        $total_expenses = $stmt->fetchColumn() ?: 0;

        // Net Profit
        $products_profit = $products_revenue - $products_cogs;
        $net_profit = $products_profit - $total_expenses;

        // Inventory (branch-aware using branch_stock)
        if ($branchId) {
            $stmt = $pdo->prepare("SELECT SUM(bs.stock_count) as total_stock, COUNT(*) as cnt FROM branch_stock bs WHERE bs.branch_id = ?");
            $stmt->execute([$branchId]);
        } else {
            $stmt = $pdo->query("SELECT SUM(bs.stock_count) as total_stock, COUNT(DISTINCT bs.product_id) as cnt FROM branch_stock bs");
        }
        $invRow = $stmt->fetch();
        $total_inventory = $invRow['total_stock'] ?: 0;

        if ($branchId) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM branch_stock WHERE stock_count <= min_stock AND branch_id = ?");
            $stmt->execute([$branchId]);
        } else {
            $stmt = $pdo->query("SELECT COUNT(*) FROM branch_stock WHERE stock_count <= min_stock");
        }
        $low_stock_count = $stmt->fetchColumn() ?: 0;

        if ($branchId) {
            $stmt = $pdo->prepare("SELECT p.name, bs.stock_count, bs.min_stock, c.name as category_name, c.arabic_name as category_arabic_name FROM branch_stock bs JOIN products p ON bs.product_id = p.id LEFT JOIN categories c ON p.category_id = c.id WHERE bs.stock_count <= bs.min_stock AND bs.branch_id = ? ORDER BY bs.stock_count ASC LIMIT 5");
            $stmt->execute([$branchId]);
        } else {
            $stmt = $pdo->query("SELECT p.name, bs.stock_count, bs.min_stock, c.name as category_name, c.arabic_name as category_arabic_name, b.name as branch_name FROM branch_stock bs JOIN products p ON bs.product_id = p.id LEFT JOIN categories c ON p.category_id = c.id LEFT JOIN branches b ON bs.branch_id = b.id WHERE bs.stock_count <= bs.min_stock ORDER BY bs.stock_count ASC LIMIT 5");
        }
        $low_stock_items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Outstanding Dues
        $stmt = $pdo->prepare("SELECT SUM(due) FROM sales s WHERE due > 0 AND status != 'Voided'" . $branchFilter);
        $stmt->execute($branchParams);
        $outstanding_dues = $stmt->fetchColumn() ?: 0;

        $stats = [
            'products_revenue' => $products_revenue,
            'total_revenue'    => $products_revenue,
            'total_expenses'   => $total_expenses,
            'net_profit'       => $net_profit,
            'total_inventory'  => $total_inventory,
            'low_stock_count'  => $low_stock_count,
            'outstanding_dues' => $outstanding_dues,
            'total_cogs'       => $products_cogs
        ];
        
        // Owner: Per-branch breakdown
        if ($role === 'Owner' && !$branchId) {
            $stmtBranches = $pdo->query("SELECT * FROM branches WHERE is_active = 1 ORDER BY id");
            $branches = $stmtBranches->fetchAll(PDO::FETCH_ASSOC);
            foreach ($branches as $br) {
                $bid = $br['id'];
                $stBr = $pdo->prepare("SELECT SUM(total) FROM sales WHERE branch_id = ? AND sale_date BETWEEN ? AND ? AND status != 'Voided'");
                $stBr->execute([$bid, $startDate . ' 00:00:00', $endDate . ' 23:59:59']);
                $brRevenue = $stBr->fetchColumn() ?: 0;
                
                $stBr = $pdo->prepare("SELECT SUM(bs.stock_count) FROM branch_stock bs WHERE bs.branch_id = ?");
                $stBr->execute([$bid]);
                $brStock = $stBr->fetchColumn() ?: 0;
                
                $stBr = $pdo->prepare("SELECT COUNT(*) FROM sales WHERE branch_id = ? AND sale_date BETWEEN ? AND ? AND status != 'Voided'");
                $stBr->execute([$bid, $startDate . ' 00:00:00', $endDate . ' 23:59:59']);
                $brTransactions = $stBr->fetchColumn() ?: 0;
                
                $branch_stats[] = [
                    'id' => $bid,
                    'name' => $br['name'],
                    'code' => $br['code'],
                    'revenue' => $brRevenue,
                    'stock' => $brStock,
                    'transactions' => $brTransactions
                ];
            }
        }
        
    } elseif ($role === 'Cashier') {
        $branchId = $_SESSION['user']['branch_id'];
        
        $stmt = $pdo->prepare("SELECT SUM(total) FROM sales WHERE cashier_id = ? AND branch_id = ? AND sale_date BETWEEN ? AND ? AND status != 'Voided'");
        $stmt->execute([$userId, $branchId, $startDate . ' 00:00:00', $endDate . ' 23:59:59']);
        $stats['today_sales'] = $stmt->fetchColumn() ?: 0;
        
        $stmt = $pdo->prepare("SELECT SUM(total) FROM sales WHERE cashier_id = ? AND branch_id = ? AND sale_date BETWEEN ? AND ? AND status != 'Voided'");
        $stmt->execute([$userId, $branchId, $startDate . ' 00:00:00', $endDate . ' 23:59:59']);
        $stats['month_sales'] = $stmt->fetchColumn() ?: 0;
        
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM sales WHERE cashier_id = ? AND branch_id = ? AND sale_date BETWEEN ? AND ? AND status != 'Voided'");
        $stmt->execute([$userId, $branchId, $startDate . ' 00:00:00', $endDate . ' 23:59:59']);
        $stats['today_transactions'] = $stmt->fetchColumn() ?: 0;
        
    } elseif ($role === 'Stock Manager') {
        $branchId = $_SESSION['user']['branch_id'];
        
        $stmt = $pdo->prepare("SELECT SUM(stock_count) FROM branch_stock WHERE branch_id = ?");
        $stmt->execute([$branchId]);
        $stats['total_inventory'] = $stmt->fetchColumn() ?: 0;
        
        $stmt = $pdo->prepare("SELECT SUM(bs.stock_count * p.cost_price) FROM branch_stock bs JOIN products p ON bs.product_id = p.id WHERE bs.branch_id = ?");
        $stmt->execute([$branchId]);
        $stats['inventory_value'] = $stmt->fetchColumn() ?: 0;
    
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM branch_stock WHERE stock_count <= min_stock AND branch_id = ?");
        $stmt->execute([$branchId]);
        $stats['low_stock_count'] = $stmt->fetchColumn() ?: 0;
    
        $stmt = $pdo->prepare("SELECT p.name, bs.stock_count, bs.min_stock, c.name as category_name, c.arabic_name as category_arabic_name FROM branch_stock bs JOIN products p ON bs.product_id = p.id LEFT JOIN categories c ON p.category_id = c.id WHERE bs.stock_count <= bs.min_stock AND bs.branch_id = ? ORDER BY bs.stock_count ASC LIMIT 10");
        $stmt->execute([$branchId]);
        $low_stock_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $credit_alerts = [];
    if (in_array($role, ['Admin', 'Owner'])) {
        $credit_alerts = getOverdueCreditAlerts($pdo);
    }

    $view = Twig::fromRequest($request);
    return $view->render($response, 'dashboard.twig', [
        'stats' => $stats,
        'low_stock_items' => $low_stock_items,
        'branch_stats' => $branch_stats,
        'credit_alerts' => $credit_alerts,
        'active_menu' => 'dashboard',
        'start_date' => $startDate,
        'end_date' => $endDate
    ]);
});

// =====================================================
// INVENTORY MODULE (branch-aware stock)
// =====================================================
$app->get('/inventory', function (Request $request, Response $response, $args) use ($pdo) {
    $branchId = getActiveBranchId();
    
    if ($branchId) {
        $stmt = $pdo->prepare('
            SELECT p.*, c.name as category_name, c.arabic_name as category_arabic_name, bs.stock_count as branch_stock, bs.min_stock as branch_min_stock
            FROM products p 
            LEFT JOIN categories c ON p.category_id = c.id 
            LEFT JOIN branch_stock bs ON p.id = bs.product_id AND bs.branch_id = ?
            ORDER BY p.id DESC
        ');
        $stmt->execute([$branchId]);
    } else {
        // Owner viewing all — show aggregate stock
        $stmt = $pdo->query('
            SELECT p.*, c.name as category_name, c.arabic_name as category_arabic_name, 
                   COALESCE(SUM(bs.stock_count), 0) as branch_stock,
                   MIN(bs.min_stock) as branch_min_stock
            FROM products p 
            LEFT JOIN categories c ON p.category_id = c.id 
            LEFT JOIN branch_stock bs ON p.id = bs.product_id
            GROUP BY p.id
            ORDER BY p.id DESC
        ');
    }
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmtCat = $pdo->query('SELECT * FROM categories ORDER BY name ASC');
    $categories = $stmtCat->fetchAll(PDO::FETCH_ASSOC);
    
    // Get all branches for stock transfer modal
    $stmtBr = $pdo->query('SELECT * FROM branches WHERE is_active = 1 ORDER BY name ASC');
    $branches = $stmtBr->fetchAll(PDO::FETCH_ASSOC);

    $stmtUnits = $pdo->query('SELECT * FROM units ORDER BY name ASC');
    $units = $stmtUnits->fetchAll(PDO::FETCH_ASSOC);

    $view = Twig::fromRequest($request);
    return $view->render($response, 'inventory.twig', [
        'products' => $products,
        'categories' => $categories,
        'branches' => $branches,
        'units' => $units,
        'active_menu' => 'inventory'
    ]);
});

$app->post('/inventory/add', function (Request $request, Response $response, $args) use ($pdo) {
    $data = (array)$request->getParsedBody();
    $name = $data['name'] ?? '';
    $category_id = $data['category_id'] ?? null;
    $cost_price = $data['cost_price'] ?? 0;
    $selling_price = $data['selling_price'] ?? 0;
    $stock_count = $data['stock_count'] ?? 0;
    $min_stock = $data['min_stock'] ?? 5;
    $barcode = $data['barcode'] ?? '';
    $unit = $data['unit'] ?? 'PCS';
    $arabic_name = $data['arabic_name'] ?? '';
    $expiry_date = !empty($data['expiry_date']) ? $data['expiry_date'] : null;
    $branchId = getActiveBranchId();

    try {
        $pdo->beginTransaction();
        
        // Insert product into global catalog (stock_count stays 0 in products table)
        $stmt = $pdo->prepare('INSERT INTO products (name, arabic_name, category_id, cost_price, selling_price, stock_count, min_stock, barcode, unit, expiry_date) VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?, ?)');
        $stmt->execute([$name, $arabic_name, $category_id, $cost_price, $selling_price, $min_stock, $barcode, $unit, $expiry_date]);
        $productId = $pdo->lastInsertId();
        
        // Add stock to the active branch (or all branches if owner with no branch selected)
        if ($branchId) {
            $stmtBs = $pdo->prepare('INSERT INTO branch_stock (branch_id, product_id, stock_count, min_stock) VALUES (?, ?, ?, ?)');
            $stmtBs->execute([$branchId, $productId, $stock_count, $min_stock]);
        } else {
            // Add to all branches
            $stmtBranches = $pdo->query('SELECT id FROM branches WHERE is_active = 1');
            $allBranches = $stmtBranches->fetchAll(PDO::FETCH_COLUMN);
            $stmtBs = $pdo->prepare('INSERT INTO branch_stock (branch_id, product_id, stock_count, min_stock) VALUES (?, ?, ?, ?)');
            foreach ($allBranches as $bid) {
                $stmtBs->execute([$bid, $productId, $stock_count, $min_stock]);
            }
        }
        
        $pdo->commit();
        $response->getBody()->write(json_encode(['success' => true]));
    } catch (Exception $e) {
        $pdo->rollBack();
        $response->getBody()->write(json_encode(['success' => false, 'message' => $e->getMessage()]));
    }
    return $response->withHeader('Content-Type', 'application/json');
})->add($roleMiddleware(['Admin', 'Stock Manager']));

$app->post('/category/add', function (Request $request, Response $response, $args) use ($pdo) {
    $data = (array)$request->getParsedBody();
    $name = $data['name'] ?? '';
    $arabic_name = $data['arabic_name'] ?? '';

    if (!empty($name)) {
        $stmt = $pdo->prepare('INSERT INTO categories (name, arabic_name) VALUES (?, ?)');
        try {
            $stmt->execute([$name, $arabic_name]);
            $response->getBody()->write(json_encode(['success' => true]));
        } catch (PDOException $e) {
            $response->getBody()->write(json_encode(['success' => false, 'message' => 'Category already exists or error.']));
        }
    } else {
        $response->getBody()->write(json_encode(['success' => false, 'message' => 'Name required.']));
    }
    return $response->withHeader('Content-Type', 'application/json');
})->add($roleMiddleware(['Admin', 'Stock Manager']));

$app->post('/category/edit/{id}', function (Request $request, Response $response, $args) use ($pdo) {
    $id = $args['id'];
    $data = (array)$request->getParsedBody();
    $name = $data['name'] ?? '';
    $arabic_name = $data['arabic_name'] ?? '';

    if (!empty($name)) {
        $stmt = $pdo->prepare('UPDATE categories SET name = ?, arabic_name = ? WHERE id = ?');
        try {
            $stmt->execute([$name, $arabic_name, $id]);
            $response->getBody()->write(json_encode(['success' => true]));
        } catch (PDOException $e) {
            $response->getBody()->write(json_encode(['success' => false, 'message' => 'Error updating category.']));
        }
    } else {
        $response->getBody()->write(json_encode(['success' => false, 'message' => 'Name required.']));
    }
    return $response->withHeader('Content-Type', 'application/json');
})->add($roleMiddleware(['Admin', 'Stock Manager']));

$app->post('/category/delete/{id}', function (Request $request, Response $response, $args) use ($pdo) {
    $id = $args['id'];
    $stmt = $pdo->prepare('DELETE FROM categories WHERE id = ?');
    $stmt->execute([$id]);

    $response->getBody()->write(json_encode(['success' => true]));
    return $response->withHeader('Content-Type', 'application/json');
})->add($roleMiddleware(['Admin', 'Stock Manager']));

$app->post('/unit/add', function (Request $request, Response $response, $args) use ($pdo) {
    $data = (array)$request->getParsedBody();
    $name = $data['name'] ?? '';
    $allow_fractional = isset($data['allow_fractional']) && $data['allow_fractional'] === 'on' ? 1 : 0;

    if (!empty($name)) {
        $stmt = $pdo->prepare('INSERT INTO units (name, allow_fractional) VALUES (?, ?)');
        try {
            $stmt->execute([$name, $allow_fractional]);
            $response->getBody()->write(json_encode(['success' => true]));
        } catch (PDOException $e) {
            $response->getBody()->write(json_encode(['success' => false, 'message' => 'Unit already exists.']));
        }
    } else {
        $response->getBody()->write(json_encode(['success' => false, 'message' => 'Name required.']));
    }
    return $response->withHeader('Content-Type', 'application/json');
})->add($roleMiddleware(['Admin', 'Stock Manager']));

$app->post('/unit/delete/{id}', function (Request $request, Response $response, $args) use ($pdo) {
    $id = $args['id'];
    $stmt = $pdo->prepare('DELETE FROM units WHERE id = ?');
    $stmt->execute([$id]);

    $response->getBody()->write(json_encode(['success' => true]));
    return $response->withHeader('Content-Type', 'application/json');
})->add($roleMiddleware(['Admin', 'Stock Manager']));

// =====================================================
// SALES MODULE (branch-aware)
// =====================================================
$app->get('/sales', function (Request $request, Response $response, $args) use ($pdo) {
    $role = $_SESSION['user']['role'] ?? '';
    $userId = $_SESSION['user']['id'] ?? 0;
    $branchId = getActiveBranchId();

    if ($role === 'Cashier') {
        $stmt = $pdo->prepare('
            SELECT s.*, u.username as cashier_name, b.name as branch_name
            FROM sales s
            LEFT JOIN users u ON s.cashier_id = u.id
            LEFT JOIN branches b ON s.branch_id = b.id
            WHERE s.cashier_id = ? AND s.branch_id = ?
            ORDER BY s.id DESC
        ');
        $stmt->execute([$userId, $_SESSION['user']['branch_id']]);
    } elseif ($branchId) {
        $stmt = $pdo->prepare('
            SELECT s.*, u.username as cashier_name, b.name as branch_name
            FROM sales s
            LEFT JOIN users u ON s.cashier_id = u.id
            LEFT JOIN branches b ON s.branch_id = b.id
            WHERE s.branch_id = ?
            ORDER BY s.id DESC
        ');
        $stmt->execute([$branchId]);
    } else {
        $stmt = $pdo->query('
            SELECT s.*, u.username as cashier_name, b.name as branch_name
            FROM sales s
            LEFT JOIN users u ON s.cashier_id = u.id
            LEFT JOIN branches b ON s.branch_id = b.id
            ORDER BY s.id DESC
        ');
    }
    
    $sales = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $view = Twig::fromRequest($request);
    return $view->render($response, 'sales.twig', [
        'sales' => $sales,
        'active_menu' => 'sales'
    ]);
})->add($roleMiddleware(['Admin']));

$app->get('/sales/view/{id}', function (Request $request, Response $response, $args) use ($pdo) {
    $id = $args['id'];
    
    $stmt = $pdo->prepare('
        SELECT s.*, u.username as cashier_name, b.name as branch_name
        FROM sales s
        LEFT JOIN users u ON s.cashier_id = u.id
        LEFT JOIN branches b ON s.branch_id = b.id
        WHERE s.id = ?
    ');
    $stmt->execute([$id]);
    $sale = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$sale) {
        $response->getBody()->write(json_encode(['success' => false, 'message' => 'Sale not found.']));
        return $response->withHeader('Content-Type', 'application/json');
    }

    $stmtItems = $pdo->prepare('
        SELECT si.*, p.name as product_name, p.arabic_name as product_arabic_name, p.unit as product_unit
        FROM sale_items si
        LEFT JOIN products p ON si.product_id = p.id
        WHERE si.sale_id = ?
    ');
    $stmtItems->execute([$id]);
    $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

    $sale['items'] = $items;

    $response->getBody()->write(json_encode(['success' => true, 'sale' => $sale]));
    return $response->withHeader('Content-Type', 'application/json');
})->add($roleMiddleware(['Admin']));

$app->post('/sales/void/{id}', function (Request $request, Response $response, $args) use ($pdo) {
    $id = $args['id'];
    
    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare('SELECT id, status, branch_id FROM sales WHERE id = ?');
        $stmt->execute([$id]);
        $sale = $stmt->fetch();

        if (!$sale) {
            throw new Exception("Sale not found.");
        }
        if ($sale['status'] === 'Voided') {
            throw new Exception("Sale is already voided.");
        }

        $stmtUpdate = $pdo->prepare("UPDATE sales SET status = 'Voided' WHERE id = ?");
        $stmtUpdate->execute([$id]);

        // Restore stock to the branch where the sale was made
        $stmtItems = $pdo->prepare('SELECT product_id, quantity FROM sale_items WHERE sale_id = ?');
        $stmtItems->execute([$id]);
        $items = $stmtItems->fetchAll();

        $stmtStock = $pdo->prepare('UPDATE branch_stock SET stock_count = stock_count + ? WHERE product_id = ? AND branch_id = ?');
        foreach ($items as $item) {
            $stmtStock->execute([$item['quantity'], $item['product_id'], $sale['branch_id']]);
        }

        $pdo->commit();
        $response->getBody()->write(json_encode(['success' => true]));
    } catch (Exception $e) {
        $pdo->rollBack();
        $response->getBody()->write(json_encode(['success' => false, 'message' => $e->getMessage()]));
    }

    return $response->withHeader('Content-Type', 'application/json');
})->add($roleMiddleware(['Admin']));

// =====================================================
// POS MODULE (branch-scoped)
// =====================================================
$app->get('/pos', function (Request $request, Response $response, $args) use ($pdo) {
    $userId = $_SESSION['user']['id'] ?? 0;
    $branchId = getActiveBranchId();
    
    if (!$branchId) {
        // Owner must select a branch to use POS
        $view = Twig::fromRequest($request);
        return $view->render($response, 'pos.twig', [
            'products' => [],
            'categories' => [],
            'active_menu' => 'pos',
            'active_shift' => null,
            'needs_branch_selection' => true
        ]);
    }
    
    // Check for open shift
    $stmtShift = $pdo->prepare("SELECT * FROM shifts WHERE user_id = ? AND status = 'Open' ORDER BY id DESC LIMIT 1");
    $stmtShift->execute([$userId]);
    $activeShift = $stmtShift->fetch(PDO::FETCH_ASSOC);
    
    // Fetch products with branch-specific stock
    $stmt = $pdo->prepare('
        SELECT p.*, c.name as category_name, c.arabic_name as category_arabic_name, bs.stock_count as branch_stock
        FROM products p 
        LEFT JOIN categories c ON p.category_id = c.id 
        JOIN branch_stock bs ON p.id = bs.product_id AND bs.branch_id = ?
        WHERE bs.stock_count > 0
        ORDER BY p.name ASC
    ');
    $stmt->execute([$branchId]);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch categories
    $stmtCats = $pdo->query('SELECT * FROM categories ORDER BY name ASC');
    $categories = $stmtCats->fetchAll(PDO::FETCH_ASSOC);
    
    // Fetch fractional units
    $stmtUnits = $pdo->query('SELECT name FROM units WHERE allow_fractional = 1');
    $fractional_units = $stmtUnits->fetchAll(PDO::FETCH_COLUMN);

    // Fetch customers
    $stmtCust = $pdo->query('SELECT * FROM customers ORDER BY name ASC');
    $customers = $stmtCust->fetchAll(PDO::FETCH_ASSOC);

    // Fetch exchange items if requested
    $exchangeSaleId = $request->getQueryParams()['exchange_sale_id'] ?? null;
    $exchangeItems = null;
    if ($exchangeSaleId) {
        $stmtEx = $pdo->prepare('SELECT p.id, p.name, p.unit, si.quantity, si.price FROM sale_items si JOIN products p ON si.product_id = p.id WHERE si.sale_id = ?');
        $stmtEx->execute([$exchangeSaleId]);
        $exchangeItems = $stmtEx->fetchAll(PDO::FETCH_ASSOC);
    }

    $view = Twig::fromRequest($request);
    return $view->render($response, 'pos.twig', [
        'products' => $products,
        'categories' => $categories,
        'fractional_units' => $fractional_units,
        'customers' => $customers,
        'exchange_items' => $exchangeItems,
        'active_menu' => 'pos',
        'active_shift' => $activeShift ?: null,
        'needs_branch_selection' => false
    ]);
})->add($roleMiddleware(['Admin', 'Cashier']));

$app->post('/pos/shift/start', function (Request $request, Response $response, $args) use ($pdo) {
    $data = (array)$request->getParsedBody();
    $startingCash = (float)($data['starting_cash'] ?? 0);
    $userId = $_SESSION['user']['id'] ?? 0;
    $branchId = getActiveBranchId();
    
    $stmtCheck = $pdo->prepare("SELECT id FROM shifts WHERE user_id = ? AND status = 'Open' LIMIT 1");
    $stmtCheck->execute([$userId]);
    if ($stmtCheck->fetch()) {
        $response->getBody()->write(json_encode(['success' => false, 'message' => 'A shift is already open.']));
        return $response->withHeader('Content-Type', 'application/json');
    }
    
    $stmt = $pdo->prepare("INSERT INTO shifts (user_id, starting_cash, status, branch_id) VALUES (?, ?, 'Open', ?)");
    if ($stmt->execute([$userId, $startingCash, $branchId])) {
        $response->getBody()->write(json_encode(['success' => true]));
    } else {
        $response->getBody()->write(json_encode(['success' => false, 'message' => 'Failed to start shift.']));
    }
    return $response->withHeader('Content-Type', 'application/json');
})->add($roleMiddleware(['Admin', 'Cashier']));

$app->post('/pos/shift/end', function (Request $request, Response $response, $args) use ($pdo) {
    $data = (array)$request->getParsedBody();
    $endingCash = (float)($data['ending_cash'] ?? 0);
    $userId = $_SESSION['user']['id'] ?? 0;
    
    $stmt = $pdo->prepare("UPDATE shifts SET status = 'Closed', end_time = CURRENT_TIMESTAMP, ending_cash = ? WHERE user_id = ? AND status = 'Open'");
    if ($stmt->execute([$endingCash, $userId])) {
        $response->getBody()->write(json_encode(['success' => true]));
    } else {
        $response->getBody()->write(json_encode(['success' => false, 'message' => 'Failed to end shift.']));
    }
    return $response->withHeader('Content-Type', 'application/json');
})->add($roleMiddleware(['Admin', 'Cashier']));

$app->post('/pos/checkout', function (Request $request, Response $response, $args) use ($pdo) {
    $data = (array)$request->getParsedBody();
    $cart = $data['cart'] ?? [];
    $paymentMethod = $data['payment_method'] ?? 'Cash';
    $tenderedAmount = $data['tendered_amount'] ?? 0;
    $changeAmount = $data['change_amount'] ?? 0;
    $splitCash = $data['split_cash'] ?? 0;
    $splitCard = $data['split_card'] ?? 0;
    $splitFawran = $data['split_fawran'] ?? 0;
    
    $customerId = !empty($data['customer_id']) ? (int)$data['customer_id'] : null;
    $pointsApplied = (int)($data['points_applied'] ?? 0);
    $pointsDiscount = $pointsApplied / 100.0; // 100 points = 1 QAR
    
    $branchId = getActiveBranchId();

    if (!$branchId) {
        $response->getBody()->write(json_encode(['success' => false, 'message' => 'Please select a branch first.']));
        return $response->withHeader('Content-Type', 'application/json');
    }

    if (empty($cart)) {
        $response->getBody()->write(json_encode(['success' => false, 'message' => 'Cart is empty.']));
        return $response->withHeader('Content-Type', 'application/json');
    }
    
    if ($paymentMethod === 'Credit' && !$customerId) {
        $response->getBody()->write(json_encode(['success' => false, 'message' => 'A customer must be selected for Credit sales.']));
        return $response->withHeader('Content-Type', 'application/json');
    }

    // Server-side cart validation using branch_stock
    $stmtCheck = $pdo->prepare('SELECT p.id, p.selling_price, p.cost_price, bs.stock_count FROM products p JOIN branch_stock bs ON p.id = bs.product_id AND bs.branch_id = ? WHERE p.id = ?');
    $subtotal = 0;
    $totalCost = 0;
    foreach ($cart as $item) {
        $id       = (int)($item['id'] ?? 0);
        $qty      = (float)($item['quantity'] ?? 0);
        $price    = (float)($item['price'] ?? 0);
        if ($id <= 0 || $qty == 0 || $price < 0) {
            $response->getBody()->write(json_encode(['success' => false, 'message' => 'Invalid item in cart.']));
            return $response->withHeader('Content-Type', 'application/json');
        }
        $stmtCheck->execute([$branchId, $id]);
        $product = $stmtCheck->fetch();
        if (!$product) {
            $response->getBody()->write(json_encode(['success' => false, 'message' => "Product ID $id not found in this branch."]));
            return $response->withHeader('Content-Type', 'application/json');
        }
        if ($product['stock_count'] < $qty) {
            $response->getBody()->write(json_encode(['success' => false, 'message' => "Insufficient stock for product ID $id in this branch."]));
            return $response->withHeader('Content-Type', 'application/json');
        }
        $subtotal += $price * $qty;
        $totalCost += (float)$product['cost_price'] * $qty;
    }

    $discount = (float)($data['discount'] ?? 0);
    $discount += $pointsDiscount;
    $role = $_SESSION['user']['role'] ?? '';
    
    if ($role !== 'Admin' && $role !== 'Owner') {
        $maxDiscount = $subtotal - $totalCost;
        if ($maxDiscount < 0) $maxDiscount = 0;
        if ($discount > $maxDiscount + 0.01) {
            $response->getBody()->write(json_encode(['success' => false, 'message' => 'Discount exceeds maximum allowed for this order.']));
            return $response->withHeader('Content-Type', 'application/json');
        }
    }

    try {
        $pdo->beginTransaction();
        
        // Verify points if applying
        if ($pointsApplied > 0 && $customerId) {
            $stmtC = $pdo->prepare('SELECT loyalty_points FROM customers WHERE id = ?');
            $stmtC->execute([$customerId]);
            $cPoints = (int)$stmtC->fetchColumn();
            if ($pointsApplied > $cPoints) {
                throw new Exception("Not enough loyalty points.");
            }
        }

        $total = $subtotal - $discount;
        if ($total < 0) {
            throw new Exception("Exchange total cannot be negative. No cash returns allowed.");
        }
        $total = round($total, 2);
        
        $paid = $total;
        $due = 0;
        
        if ($paymentMethod === 'Credit') {
            $paid = 0;
            $due = $total;
        }

        $invoiceId = 'INV-' . strtoupper(bin2hex(random_bytes(4)));
        $cashierId = $_SESSION['user']['id'] ?? null;
        
        $stmtShift = $pdo->prepare("SELECT id FROM shifts WHERE user_id = ? AND status = 'Open' ORDER BY id DESC LIMIT 1");
        $stmtShift->execute([$cashierId]);
        $shiftId = $stmtShift->fetchColumn() ?: null;

        $stmt = $pdo->prepare('INSERT INTO sales (invoice_id, cashier_id, total, paid, due, payment_method, tendered_amount, change_amount, split_cash, split_card, split_fawran, shift_id, branch_id, customer_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$invoiceId, $cashierId, $total, $paid, $due, $paymentMethod, $tenderedAmount, $changeAmount, $splitCash, $splitCard, $splitFawran, $shiftId, $branchId, $customerId]);
        $saleId = $pdo->lastInsertId();

        $stmtItem        = $pdo->prepare('INSERT INTO sale_items (sale_id, product_id, quantity, price) VALUES (?, ?, ?, ?)');
        $stmtUpdateStock = $pdo->prepare('UPDATE branch_stock SET stock_count = stock_count - ? WHERE product_id = ? AND branch_id = ?');

        foreach ($cart as $item) {
            $stmtItem->execute([$saleId, (int)$item['id'], (float)$item['quantity'], (float)$item['price']]);
            $stmtUpdateStock->execute([(float)$item['quantity'], (int)$item['id'], $branchId]);
        }
        
        // Update Customer Balance and Loyalty
        if ($customerId) {
            $pointsEarned = floor($total / 10); // 1 point per 10 QAR spent
            
            $stmtCust = $pdo->prepare('UPDATE customers SET balance = balance + ?, loyalty_points = loyalty_points - ? + ? WHERE id = ?');
            $stmtCust->execute([$due, $pointsApplied, $pointsEarned, $customerId]);
        }

        $pdo->commit();
        $response->getBody()->write(json_encode(['success' => true, 'invoice_id' => $invoiceId, 'total' => $total]));
    } catch (Exception $e) {
        $pdo->rollBack();
        $response->getBody()->write(json_encode(['success' => false, 'message' => 'Checkout failed: ' . $e->getMessage()]));
    }

    return $response->withHeader('Content-Type', 'application/json');
})->add($roleMiddleware(['Admin', 'Cashier']));

// =====================================================
// EXPENSES MODULE (branch-aware)
// =====================================================
$app->get('/expenses', function (Request $request, Response $response, $args) use ($pdo) {
    $branchId = getActiveBranchId();
    
    if ($branchId) {
        $stmt = $pdo->prepare('
            SELECT e.*, ec.name as category_name, c.arabic_name as category_arabic_name, b.name as branch_name
            FROM expenses e 
            LEFT JOIN expense_categories ec ON e.category_id = ec.id 
            LEFT JOIN branches b ON e.branch_id = b.id
            WHERE e.branch_id = ?
            ORDER BY e.expense_date DESC
        ');
        $stmt->execute([$branchId]);
        
        $stmtTotal = $pdo->prepare('SELECT SUM(amount) FROM expenses WHERE branch_id = ?');
        $stmtTotal->execute([$branchId]);
    } else {
        $stmt = $pdo->query('
            SELECT e.*, ec.name as category_name, c.arabic_name as category_arabic_name, b.name as branch_name
            FROM expenses e 
            LEFT JOIN expense_categories ec ON e.category_id = ec.id 
            LEFT JOIN branches b ON e.branch_id = b.id
            ORDER BY e.expense_date DESC
        ');
        
        $stmtTotal = $pdo->query('SELECT SUM(amount) FROM expenses');
    }
    $expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $total_expenses = $stmtTotal->fetchColumn() ?: 0;
    
    $stmt = $pdo->query('SELECT * FROM expense_categories ORDER BY name ASC');
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $view = Twig::fromRequest($request);
    return $view->render($response, 'expenses.twig', [
        'expenses' => $expenses,
        'categories' => $categories,
        'total_expenses' => $total_expenses,
        'active_menu' => 'expenses'
    ]);
})->add($roleMiddleware(['Admin', 'Stock Manager']));

$app->post('/expenses/add', function (Request $request, Response $response, $args) use ($pdo) {
    $data = (array)$request->getParsedBody();
    $branchId = getActiveBranchId();
    
    $description = $data['description'] ?? '';
    $amount = $data['amount'] ?? 0;
    $category_id = $data['category_id'] ?? null;
    $date = $data['expense_date'] ?? date('Y-m-d H:i:s');
    
    $stmt = $pdo->prepare('INSERT INTO expenses (description, amount, category_id, expense_date, branch_id) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$description, $amount, $category_id, $date, $branchId]);

    $response->getBody()->write(json_encode(['success' => true]));
    return $response->withHeader('Content-Type', 'application/json');
})->add($roleMiddleware(['Admin']));

$app->post('/expense-category/add', function (Request $request, Response $response, $args) use ($pdo) {
    $data = (array)$request->getParsedBody();
    $name = $data['name'] ?? '';

    if (!empty($name)) {
        $stmt = $pdo->prepare('INSERT INTO expense_categories (name) VALUES (?)');
        try {
            $stmt->execute([$name]);
            $response->getBody()->write(json_encode(['success' => true]));
        } catch (PDOException $e) {
            $response->getBody()->write(json_encode(['success' => false, 'message' => 'Category already exists or error.']));
        }
    } else {
        $response->getBody()->write(json_encode(['success' => false, 'message' => 'Name required.']));
    }
    return $response->withHeader('Content-Type', 'application/json');
})->add($roleMiddleware(['Admin']));

$app->post('/expense-category/delete/{id}', function (Request $request, Response $response, $args) use ($pdo) {
    $id = $args['id'];
    $stmt = $pdo->prepare('DELETE FROM expense_categories WHERE id = ?');
    $stmt->execute([$id]);

    $response->getBody()->write(json_encode(['success' => true]));
    return $response->withHeader('Content-Type', 'application/json');
})->add($roleMiddleware(['Admin']));

// =====================================================
// SETTINGS MODULE (with Branch Management)
// =====================================================
$app->get('/settings', function (Request $request, Response $response, $args) use ($pdo) {
    $stmt = $pdo->query('SELECT * FROM users ORDER BY id ASC');
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->query('SELECT * FROM settings');
    $settingsRaw = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $settings = [];
    foreach ($settingsRaw as $row) {
        $settings[$row['key']] = $row['value'];
    }
    
    // Get branches for management
    $stmt = $pdo->query('SELECT * FROM branches ORDER BY id ASC');
    $branches = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $view = Twig::fromRequest($request);
    return $view->render($response, 'settings.twig', [
        'users' => $users,
        'settings' => $settings,
        'branches' => $branches,
        'active_menu' => 'settings'
    ]);
})->add($roleMiddleware(['Admin']));

$app->post('/settings/identity', function (Request $request, Response $response, $args) use ($pdo) {
    $data = (array)$request->getParsedBody();
    
    $stmt = $pdo->prepare('UPDATE settings SET value = ? WHERE key = ?');
    $stmt->execute([$data['shop_name'] ?? '', 'shop_name']);
    $stmt->execute([$data['shop_phone'] ?? '', 'shop_phone']);
    $stmt->execute([$data['shop_address'] ?? '', 'shop_address']);
    
    // Check if shop_logo exists in DB first, insert if not
    $checkStmt = $pdo->prepare('SELECT COUNT(*) FROM settings WHERE key = ?');
    $checkStmt->execute(['shop_logo']);
    if ($checkStmt->fetchColumn() == 0) {
        $insertStmt = $pdo->prepare('INSERT INTO settings (key, value) VALUES (?, ?)');
        $insertStmt->execute(['shop_logo', $data['shop_logo'] ?? '']);
    } else {
        $stmt->execute([$data['shop_logo'] ?? '', 'shop_logo']);
    }

    $response->getBody()->write(json_encode(['success' => true]));
    return $response->withHeader('Content-Type', 'application/json');
})->add($roleMiddleware(['Admin']));

$app->post('/users/add', function (Request $request, Response $response, $args) use ($pdo) {
    $data = (array)$request->getParsedBody();
    
    $username = $data['username'] ?? '';
    $password = $data['password'] ?? '';
    $role = $data['role'] ?? 'Cashier';
    $branch_id = $data['branch_id'] ?? null;
    
    // Owner role has no branch
    if ($role === 'Owner') {
        $branch_id = null;
    }
    
    if (!empty($username) && !empty($password)) {
        $hashed = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare('INSERT INTO users (username, password, role, branch_id) VALUES (?, ?, ?, ?)');
        $stmt->execute([$username, $hashed, $role, $branch_id]);
        $response->getBody()->write(json_encode(['success' => true]));
    } else {
        $response->getBody()->write(json_encode(['success' => false]));
    }
    return $response->withHeader('Content-Type', 'application/json');
})->add($roleMiddleware(['Admin']));

$app->post('/users/delete/{id}', function (Request $request, Response $response, $args) use ($pdo) {
    $id = $args['id'];
    // Prevent deleting the default admin (id 1) or self
    if ($id == 1) {
        $response->getBody()->write(json_encode(['success' => false, 'message' => 'Cannot delete primary admin.']));
        return $response->withHeader('Content-Type', 'application/json');
    }
    
    $stmt = $pdo->prepare('DELETE FROM users WHERE id = ?');
    $stmt->execute([$id]);

    $response->getBody()->write(json_encode(['success' => true]));
    return $response->withHeader('Content-Type', 'application/json');
})->add($roleMiddleware(['Admin']));


// =====================================================
// BRANCH CRUD (Owner only)
// =====================================================
$app->post('/branches/add', function (Request $request, Response $response, $args) use ($pdo) {
    $data = (array)$request->getParsedBody();
    $name = $data['name'] ?? '';
    $code = $data['code'] ?? '';
    $address = $data['address'] ?? '';
    $phone = $data['phone'] ?? '';
    
    if (empty($name) || empty($code)) {
        $response->getBody()->write(json_encode(['success' => false, 'message' => 'Name and code are required.']));
        return $response->withHeader('Content-Type', 'application/json');
    }
    
    try {
        $pdo->beginTransaction();
        
        $stmt = $pdo->prepare('INSERT INTO branches (name, code, address, phone) VALUES (?, ?, ?, ?)');
        $stmt->execute([$name, $code, $address, $phone]);
        $newBranchId = $pdo->lastInsertId();
        
        // Add all existing products to the new branch with 0 stock
        $stmt = $pdo->query('SELECT id FROM products');
        $products = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        $stmtBs = $pdo->prepare('INSERT OR IGNORE INTO branch_stock (branch_id, product_id, stock_count, min_stock) VALUES (?, ?, 0, 5)');
        foreach ($products as $pid) {
            $stmtBs->execute([$newBranchId, $pid]);
        }
        
        $pdo->commit();
        $response->getBody()->write(json_encode(['success' => true]));
    } catch (Exception $e) {
        $pdo->rollBack();
        $response->getBody()->write(json_encode(['success' => false, 'message' => $e->getMessage()]));
    }
    return $response->withHeader('Content-Type', 'application/json');
})->add($roleMiddleware(['Admin']));

$app->post('/branches/edit/{id}', function (Request $request, Response $response, $args) use ($pdo) {
    $id = $args['id'];
    $data = (array)$request->getParsedBody();
    
    $stmt = $pdo->prepare('UPDATE branches SET name = ?, code = ?, address = ?, phone = ?, is_active = ? WHERE id = ?');
    $stmt->execute([
        $data['name'] ?? '',
        $data['code'] ?? '',
        $data['address'] ?? '',
        $data['phone'] ?? '',
        $data['is_active'] ?? 1,
        $id
    ]);
    
    $response->getBody()->write(json_encode(['success' => true]));
    return $response->withHeader('Content-Type', 'application/json');
})->add($roleMiddleware(['Admin']));

$app->post('/branches/delete/{id}', function (Request $request, Response $response, $args) use ($pdo) {
    $id = $args['id'];
    
    // Check if branch has data
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM sales WHERE branch_id = ?');
    $stmt->execute([$id]);
    if ($stmt->fetchColumn() > 0) {
        // Soft delete — just deactivate
        $stmt = $pdo->prepare('UPDATE branches SET is_active = 0 WHERE id = ?');
        $stmt->execute([$id]);
        $response->getBody()->write(json_encode(['success' => true, 'message' => 'Branch deactivated (has existing data).']));
    } else {
        $pdo->prepare('DELETE FROM branch_stock WHERE branch_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM branches WHERE id = ?')->execute([$id]);
        $response->getBody()->write(json_encode(['success' => true]));
    }
    return $response->withHeader('Content-Type', 'application/json');
})->add($roleMiddleware(['Admin']));

// =====================================================
// STOCK TRANSFER (Owner/Admin)
// =====================================================
$app->post('/stock/transfer', function (Request $request, Response $response, $args) use ($pdo) {
    $data = (array)$request->getParsedBody();
    $productId = (int)($data['product_id'] ?? 0);
    $fromBranch = (int)($data['from_branch_id'] ?? 0);
    $toBranch = (int)($data['to_branch_id'] ?? 0);
    $quantity = (int)($data['quantity'] ?? 0);
    
    if ($productId <= 0 || $fromBranch <= 0 || $toBranch <= 0 || $quantity <= 0) {
        $response->getBody()->write(json_encode(['success' => false, 'message' => 'Invalid transfer parameters.']));
        return $response->withHeader('Content-Type', 'application/json');
    }
    
    if ($fromBranch === $toBranch) {
        $response->getBody()->write(json_encode(['success' => false, 'message' => 'Cannot transfer to the same branch.']));
        return $response->withHeader('Content-Type', 'application/json');
    }
    
    try {
        $pdo->beginTransaction();
        
        // Check source stock
        $stmt = $pdo->prepare('SELECT stock_count FROM branch_stock WHERE branch_id = ? AND product_id = ?');
        $stmt->execute([$fromBranch, $productId]);
        $sourceStock = $stmt->fetchColumn();
        
        if ($sourceStock === false || $sourceStock < $quantity) {
            throw new Exception('Insufficient stock in source branch.');
        }
        
        // Deduct from source
        $stmt = $pdo->prepare('UPDATE branch_stock SET stock_count = stock_count - ? WHERE branch_id = ? AND product_id = ?');
        $stmt->execute([$quantity, $fromBranch, $productId]);
        
        // Add to destination (create entry if doesn't exist)
        $stmt = $pdo->prepare('INSERT INTO branch_stock (branch_id, product_id, stock_count, min_stock) VALUES (?, ?, ?, 5) ON CONFLICT(branch_id, product_id) DO UPDATE SET stock_count = stock_count + ?');
        $stmt->execute([$toBranch, $productId, $quantity, $quantity]);
        
        $pdo->commit();
        $response->getBody()->write(json_encode(['success' => true]));
    } catch (Exception $e) {
        $pdo->rollBack();
        $response->getBody()->write(json_encode(['success' => false, 'message' => $e->getMessage()]));
    }
    return $response->withHeader('Content-Type', 'application/json');
})->add($roleMiddleware(['Admin']));

// =====================================================
// EDIT & DELETE ROUTES
// =====================================================
$app->post('/products/edit/{id}', function (Request $request, Response $response, $args) use ($pdo) {
    $id = $args['id'];
    $data = (array)$request->getParsedBody();
    $branchId = getActiveBranchId();
    
    // Update product catalog
    $stmt = $pdo->prepare('UPDATE products SET name = ?, arabic_name = ?, category_id = ?, cost_price = ?, selling_price = ?, min_stock = ?, barcode = ?, unit = ?, expiry_date = ? WHERE id = ?');
    $stmt->execute([
        $data['name'] ?? '', 
        $data['arabic_name'] ?? '',
        $data['category_id'] ?? null, 
        $data['cost_price'] ?? 0, 
        $data['selling_price'] ?? 0, 
        $data['min_stock'] ?? 5,
        $data['barcode'] ?? '',
        $data['unit'] ?? 'PCS',
        !empty($data['expiry_date']) ? $data['expiry_date'] : null,
        $id
    ]);
    
    // Update branch stock if stock_count is provided
    if (isset($data['stock_count']) && $branchId) {
        $stmt = $pdo->prepare('UPDATE branch_stock SET stock_count = ?, min_stock = ? WHERE product_id = ? AND branch_id = ?');
        $stmt->execute([$data['stock_count'], $data['min_stock'] ?? 5, $id, $branchId]);
    }
    
    $response->getBody()->write(json_encode(['success' => true]));
    return $response->withHeader('Content-Type', 'application/json');
})->add($roleMiddleware(['Admin']));

$app->post('/products/generate-barcode/{id}', function (Request $request, Response $response, $args) use ($pdo) {
    $id = $args['id'];
    $barcode = 'PRD-' . str_pad($id, 4, '0', STR_PAD_LEFT);
    $stmt = $pdo->prepare('UPDATE products SET barcode = ? WHERE id = ?');
    $stmt->execute([$barcode, $id]);
    
    $response->getBody()->write(json_encode(['success' => true, 'barcode' => $barcode]));
    return $response->withHeader('Content-Type', 'application/json');
})->add($roleMiddleware(['Admin', 'Stock Manager']));

$app->post('/products/delete/{id}', function (Request $request, Response $response, $args) use ($pdo) {
    $id = $args['id'];
    // Delete branch stock entries first
    $pdo->prepare('DELETE FROM branch_stock WHERE product_id = ?')->execute([$id]);
    $stmt = $pdo->prepare('DELETE FROM products WHERE id = ?');
    $stmt->execute([$id]);
    $response->getBody()->write(json_encode(['success' => true]));
    return $response->withHeader('Content-Type', 'application/json');
})->add($roleMiddleware(['Admin']));



$app->post('/expenses/edit/{id}', function (Request $request, Response $response, $args) use ($pdo) {
    $id = $args['id'];
    $data = (array)$request->getParsedBody();
    $stmt = $pdo->prepare('UPDATE expenses SET description = ?, amount = ?, category_id = ? WHERE id = ?');
    $stmt->execute([
        $data['description'] ?? '',
        $data['amount'] ?? 0,
        $data['category_id'] ?? null,
        $id
    ]);
    $response->getBody()->write(json_encode(['success' => true]));
    return $response->withHeader('Content-Type', 'application/json');
})->add($roleMiddleware(['Admin']));

$app->post('/expenses/delete/{id}', function (Request $request, Response $response, $args) use ($pdo) {
    $stmt = $pdo->prepare('DELETE FROM expenses WHERE id = ?');
    $stmt->execute([$args['id']]);
    $response->getBody()->write(json_encode(['success' => true]));
    return $response->withHeader('Content-Type', 'application/json');
})->add($roleMiddleware(['Admin']));

$app->post('/users/edit/{id}', function (Request $request, Response $response, $args) use ($pdo) {
    $id = $args['id'];
    $data = (array)$request->getParsedBody();
    $username = $data['username'] ?? '';
    $role = $data['role'] ?? 'Cashier';
    $branch_id = $data['branch_id'] ?? null;
    
    // Owner has no branch
    if ($role === 'Owner') {
        $branch_id = null;
    }
    
    if (!empty($data['password'])) {
        $passwordHash = password_hash($data['password'], PASSWORD_BCRYPT, ['cost' => 12]);
        $stmt = $pdo->prepare('UPDATE users SET username = ?, password = ?, role = ?, branch_id = ? WHERE id = ?');
        $stmt->execute([$username, $passwordHash, $role, $branch_id, $id]);
    } else {
        $stmt = $pdo->prepare('UPDATE users SET username = ?, role = ?, branch_id = ? WHERE id = ?');
        $stmt->execute([$username, $role, $branch_id, $id]);
    }
    
    $response->getBody()->write(json_encode(['success' => true]));
    return $response->withHeader('Content-Type', 'application/json');
})->add($roleMiddleware(['Admin']));

$app->post('/products/add-stock/{id}', function (Request $request, Response $response, $args) use ($pdo) {
    $id = $args['id'];
    $data = (array)$request->getParsedBody();
    $added_stock = (int)($data['added_stock'] ?? 0);
    $branchId = getActiveBranchId();
    
    if ($added_stock > 0 && $branchId) {
        // Add to specific branch stock
        $stmt = $pdo->prepare('UPDATE branch_stock SET stock_count = stock_count + ? WHERE product_id = ? AND branch_id = ?');
        $stmt->execute([$added_stock, $id, $branchId]);
        
        if ($stmt->rowCount() === 0) {
            // Create entry if doesn't exist
            $stmt = $pdo->prepare('INSERT INTO branch_stock (branch_id, product_id, stock_count, min_stock) VALUES (?, ?, ?, 5)');
            $stmt->execute([$branchId, $id, $added_stock]);
        }
    } elseif ($added_stock > 0) {
        // Owner with no branch selected — add to all branches
        $stmtBranches = $pdo->query('SELECT id FROM branches WHERE is_active = 1');
        $allBranches = $stmtBranches->fetchAll(PDO::FETCH_COLUMN);
        foreach ($allBranches as $bid) {
            $stmt = $pdo->prepare('UPDATE branch_stock SET stock_count = stock_count + ? WHERE product_id = ? AND branch_id = ?');
            $stmt->execute([$added_stock, $id, $bid]);
        }
    }

    $response->getBody()->write(json_encode(['success' => true]));
    return $response->withHeader('Content-Type', 'application/json');
})->add($roleMiddleware(['Admin', 'Stock Manager']));

$app->post('/products/update-price/{id}', function (Request $request, Response $response, $args) use ($pdo) {
    $id = $args['id'];
    $data = (array)$request->getParsedBody();
    $cost_price = (float)($data['cost_price'] ?? 0);
    $selling_price = (float)($data['selling_price'] ?? 0);
    
    if ($cost_price > 0 && $selling_price > 0) {
        $stmt = $pdo->prepare('UPDATE products SET cost_price = ?, selling_price = ? WHERE id = ?');
        $stmt->execute([$cost_price, $selling_price, $id]);
    }

    $response->getBody()->write(json_encode(['success' => true]));
    return $response->withHeader('Content-Type', 'application/json');
})->add($roleMiddleware(['Admin', 'Stock Manager']));


// =====================================================
// REPORTS (branch-aware)
// =====================================================
$app->get('/reports', function (Request $request, Response $response, $args) use ($twig) {
    $active_menu = 'reports';
    $current_user = $_SESSION['user'] ?? null;
    
    // Get branches for filter
    return $twig->render($response, 'reports.twig', [
        'active_menu' => $active_menu,
        'current_user' => $current_user,
    ]);
})->add($roleMiddleware(['Admin']));

$app->post('/api/reports', function (Request $request, Response $response, $args) use ($pdo) {
    $data = (array)$request->getParsedBody();
    $startDate = $data['start_date'] ?? date('Y-m-01');
    $endDate = $data['end_date'] ?? date('Y-m-t');
    $branchId = getActiveBranchId();
    
    // Allow override from request for Owner
    if (isset($data['branch_id']) && isOwner()) {
        $branchId = $data['branch_id'] === 'all' ? null : (int)$data['branch_id'];
    }

    $endDateFull = $endDate . ' 23:59:59';
    
    // Build branch filter
    $sBranchFilter = $branchId ? ' AND s.branch_id = ?' : '';
    $sBranchParams = $branchId ? [$branchId] : [];
    $branchFilter = $branchId ? ' AND branch_id = ?' : '';
    $branchParams = $branchId ? [$branchId] : [];

    // 1. Sales Report
    $stmt = $pdo->prepare("SELECT SUM(total) as revenue, COUNT(id) as transactions FROM sales s WHERE sale_date BETWEEN ? AND ? AND status != 'Voided'" . $sBranchFilter);
    $stmt->execute(array_merge([$startDate, $endDateFull], $sBranchParams));
    $salesData = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT payment_method, SUM(total) as total FROM sales s WHERE sale_date BETWEEN ? AND ? AND status != 'Voided'" . $sBranchFilter . " GROUP BY payment_method");
    $stmt->execute(array_merge([$startDate, $endDateFull], $sBranchParams));
    $paymentMethods = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Cashier stats
    $stmt = $pdo->prepare("SELECT u.username, SUM(s.total) as total, COUNT(s.id) as count FROM sales s JOIN users u ON s.cashier_id = u.id WHERE s.sale_date BETWEEN ? AND ? AND s.status != 'Voided'" . $sBranchFilter . " GROUP BY s.cashier_id");
    $stmt->execute(array_merge([$startDate, $endDateFull], $sBranchParams));
    $cashierStats = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 2. Inventory
    if ($branchId) {
        $stmt = $pdo->prepare("SELECT SUM(bs.stock_count) as total_items, SUM(bs.stock_count * p.cost_price) as total_cost, SUM(bs.stock_count * p.selling_price) as total_retail FROM branch_stock bs JOIN products p ON bs.product_id = p.id WHERE bs.branch_id = ?");
        $stmt->execute([$branchId]);
    } else {
        $stmt = $pdo->query("SELECT SUM(bs.stock_count) as total_items, SUM(bs.stock_count * p.cost_price) as total_cost, SUM(bs.stock_count * p.selling_price) as total_retail FROM branch_stock bs JOIN products p ON bs.product_id = p.id");
    }
    $inventoryStats = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($branchId) {
        $stmt = $pdo->prepare("SELECT p.name, bs.stock_count, c.name as category_name, c.arabic_name as category_arabic_name FROM branch_stock bs JOIN products p ON bs.product_id = p.id LEFT JOIN categories c ON p.category_id = c.id WHERE bs.stock_count <= bs.min_stock AND bs.branch_id = ? ORDER BY bs.stock_count ASC LIMIT 10");
        $stmt->execute([$branchId]);
    } else {
        $stmt = $pdo->query("SELECT p.name, bs.stock_count, c.name as category_name, c.arabic_name as category_arabic_name FROM branch_stock bs JOIN products p ON bs.product_id = p.id LEFT JOIN categories c ON p.category_id = c.id WHERE bs.stock_count <= bs.min_stock ORDER BY bs.stock_count ASC LIMIT 10");
    }
    $lowStock = $stmt->fetchAll(PDO::FETCH_ASSOC);



    // Shifts Report
    $shBranchFilter = $branchId ? ' AND sh.branch_id = ?' : '';
    $shBranchParams = $branchId ? [$branchId] : [];
    
    $stmt = $pdo->prepare("
        SELECT sh.id, u.username as cashier_name, sh.start_time, sh.end_time, sh.starting_cash, sh.ending_cash, sh.status,
        (SELECT COALESCE(SUM(s.total), 0) FROM sales s WHERE s.shift_id = sh.id AND s.payment_method = 'Cash' AND s.status != 'Voided') as cash_sales
        FROM shifts sh
        LEFT JOIN users u ON sh.user_id = u.id
        WHERE sh.start_time BETWEEN ? AND ?" . $shBranchFilter . "
        ORDER BY sh.start_time DESC
    ");
    $stmt->execute(array_merge([$startDate, $endDateFull], $shBranchParams));
    $shiftsReport = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 3. P&L
    $stmt = $pdo->prepare("
        SELECT SUM(si.quantity * p.cost_price) as cogs
        FROM sale_items si 
        JOIN sales s ON si.sale_id = s.id 
        JOIN products p ON si.product_id = p.id 
        WHERE s.sale_date BETWEEN ? AND ? AND s.status != 'Voided'" . $sBranchFilter);
    $stmt->execute(array_merge([$startDate, $endDateFull], $sBranchParams));
    $productsCogs = (float)$stmt->fetchColumn();
    $productsRevenue = (float)$salesData['revenue'];
    
    $stmt = $pdo->prepare("SELECT SUM(amount) FROM expenses WHERE expense_date BETWEEN ? AND ?" . $branchFilter);
    $stmt->execute(array_merge([$startDate, $endDateFull], $branchParams));
    $expenses = (float)$stmt->fetchColumn();

    $grossProfit = $productsRevenue - $productsCogs;
    $netProfit = $grossProfit - $expenses;

    $result = [
        'sales' => [
            'revenue' => $productsRevenue,
            'transactions' => $salesData['transactions'] ?: 0,
            'payment_methods' => $paymentMethods,
            'cashiers' => $cashierStats
        ],
        'inventory' => [
            'stats' => $inventoryStats,
            'low_stock' => $lowStock
        ],
        'shifts' => $shiftsReport,
        'pl' => [
            'products_revenue' => $productsRevenue,
            'products_cogs' => $productsCogs,
            'gross_profit' => $grossProfit,
            'expenses' => $expenses,
            'net_profit' => $netProfit
        ]
    ];

    $response->getBody()->write(json_encode($result));
    return $response->withHeader('Content-Type', 'application/json');
})->add($roleMiddleware(['Admin']));

// =====================================================
// BRANCHES API (for AJAX)
// =====================================================
$app->get('/api/branches', function (Request $request, Response $response, $args) use ($pdo) {
    $stmt = $pdo->query('SELECT * FROM branches ORDER BY name ASC');
    $branches = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $response->getBody()->write(json_encode($branches));
    return $response->withHeader('Content-Type', 'application/json');
});


// =====================================================
// GRN MODULE
// =====================================================
$app->get('/grn', function (Request $request, Response $response, $args) use ($pdo) {
    $branchId = getActiveBranchId();
    $view = Twig::fromRequest($request);
    
    if ($branchId) {
        $stmt = $pdo->prepare('SELECT * FROM grns WHERE branch_id = ? ORDER BY date DESC');
        $stmt->execute([$branchId]);
    } else {
        $stmt = $pdo->query('
            SELECT g.*, b.name as branch_name 
            FROM grns g 
            LEFT JOIN branches b ON g.branch_id = b.id 
            ORDER BY g.date DESC
        ');
    }
    $grns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    return $view->render($response, 'grn.twig', [
        'active_menu' => 'grn',
        'grns' => $grns,
        'needs_branch_selection' => !$branchId && $_SESSION['user']['role'] === 'Owner'
    ]);
})->add($roleMiddleware(['Admin', 'Owner', 'Stock Manager']));

$app->get('/grn/add', function (Request $request, Response $response, $args) use ($pdo) {
    $branchId = getActiveBranchId();
    if (!$branchId) {
        return $response->withHeader('Location', '/grn')->withStatus(302);
    }
    
    $stmt = $pdo->query('
        SELECT p.*, c.name as category_name, c.arabic_name as category_arabic_name
        FROM products p 
        LEFT JOIN categories c ON p.category_id = c.id
        ORDER BY p.name ASC
    ');
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stmtSup = $pdo->query('SELECT id, name FROM suppliers ORDER BY name ASC');
    $suppliers = $stmtSup->fetchAll(PDO::FETCH_ASSOC);
    
    $view = Twig::fromRequest($request);
    return $view->render($response, 'grn_add.twig', [
        'active_menu' => 'grn',
        'products' => $products,
        'suppliers' => $suppliers
    ]);
})->add($roleMiddleware(['Admin', 'Owner', 'Stock Manager']));

$app->post('/grn/add', function (Request $request, Response $response, $args) use ($pdo) {
    $data = (array)$request->getParsedBody();
    $branchId = getActiveBranchId();
    $supplierId = !empty($data['supplier_id']) ? (int)$data['supplier_id'] : null;
    $supplierName = $data['supplier_name'] ?? '';
    $notes = $data['notes'] ?? '';
    $items = $data['items'] ?? [];
    $paymentMethod = $data['payment_method'] ?? 'Cash';
    
    if (empty($items)) {
        $response->getBody()->write(json_encode(['success' => false, 'message' => 'No items added to GRN']));
        return $response->withHeader('Content-Type', 'application/json');
    }
    
    if ($paymentMethod === 'Credit' && !$supplierId) {
        $response->getBody()->write(json_encode(['success' => false, 'message' => 'Supplier must be selected for Credit purchases']));
        return $response->withHeader('Content-Type', 'application/json');
    }
    
    try {
        $pdo->beginTransaction();
        
        $ref = 'GRN-' . time();
        $total = 0;
        
        $stmtGrn = $pdo->prepare('INSERT INTO grns (reference_no, supplier_name, supplier_id, branch_id, notes, payment_method, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmtGrn->execute([$ref, $supplierName, $supplierId, $branchId, $notes, $paymentMethod, $_SESSION['user']['id']]);
        $grnId = $pdo->lastInsertId();
        
        $stmtItem = $pdo->prepare('INSERT INTO grn_items (grn_id, product_id, quantity, cost_price) VALUES (?, ?, ?, ?)');
        $stmtStock = $pdo->prepare('UPDATE branch_stock SET stock_count = stock_count + ? WHERE product_id = ? AND branch_id = ?');
        $stmtCost = $pdo->prepare('UPDATE products SET cost_price = ? WHERE id = ?');
        
        foreach ($items as $item) {
            $qty = (float)$item['quantity'];
            $cost = (float)$item['cost'];
            $total += ($qty * $cost);
            
            $stmtItem->execute([$grnId, $item['id'], $qty, $cost]);
            $stmtStock->execute([$qty, $item['id'], $branchId]);
            $stmtCost->execute([$cost, $item['id']]);
        }
        
        $paidAmount = ($paymentMethod === 'Credit') ? 0 : $total;
        $pdo->prepare('UPDATE grns SET total_amount = ?, paid_amount = ? WHERE id = ?')->execute([$total, $paidAmount, $grnId]);
        
        if ($paymentMethod === 'Credit' && $supplierId) {
            $pdo->prepare('UPDATE suppliers SET balance = balance + ? WHERE id = ?')->execute([$total, $supplierId]);
        }
        
        $pdo->commit();
        $response->getBody()->write(json_encode(['success' => true, 'grn_id' => $grnId]));
    } catch (Exception $e) {
        $pdo->rollBack();
        $response->getBody()->write(json_encode(['success' => false, 'message' => $e->getMessage()]));
    }
    return $response->withHeader('Content-Type', 'application/json');
})->add($roleMiddleware(['Admin', 'Owner', 'Stock Manager']));

$app->get('/grn/view/{id}', function (Request $request, Response $response, $args) use ($pdo) {
    $id = $args['id'];
    
    $stmt = $pdo->prepare('SELECT * FROM grns WHERE id = ?');
    $stmt->execute([$id]);
    $grn = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($grn) {
        $stmtItems = $pdo->prepare('
            SELECT gi.*, p.name as product_name, p.unit 
            FROM grn_items gi 
            JOIN products p ON gi.product_id = p.id 
            WHERE gi.grn_id = ?
        ');
        $stmtItems->execute([$id]);
        $grn['items'] = $stmtItems->fetchAll(PDO::FETCH_ASSOC);
        
        $response->getBody()->write(json_encode(['success' => true, 'grn' => $grn]));
    } else {
        $response->getBody()->write(json_encode(['success' => false, 'message' => 'GRN not found']));
    }
    return $response->withHeader('Content-Type', 'application/json');
})->add($roleMiddleware(['Admin', 'Owner', 'Stock Manager']));

// =====================================================
// TRANSFERS MODULE
// =====================================================
$app->get('/transfers', function (Request $request, Response $response, $args) use ($pdo) {
    $view = Twig::fromRequest($request);
    
    $stmt = $pdo->query('
        SELECT t.*, b1.name as from_branch, b2.name as to_branch, u.username as creator
        FROM stock_transfers t
        JOIN branches b1 ON t.from_branch_id = b1.id
        JOIN branches b2 ON t.to_branch_id = b2.id
        LEFT JOIN users u ON t.created_by = u.id
        ORDER BY t.date DESC
    ');
    $transfers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stmtBranches = $pdo->query('SELECT * FROM branches ORDER BY name ASC');
    $branches = $stmtBranches->fetchAll(PDO::FETCH_ASSOC);
    
    $stmtProducts = $pdo->query('SELECT id, name, unit FROM products ORDER BY name ASC');
    $products = $stmtProducts->fetchAll(PDO::FETCH_ASSOC);
    
    return $view->render($response, 'transfers.twig', [
        'active_menu' => 'transfers',
        'transfers' => $transfers,
        'branches' => $branches,
        'products' => $products
    ]);
})->add($roleMiddleware(['Admin', 'Owner']));

$app->post('/transfers/add', function (Request $request, Response $response, $args) use ($pdo) {
    $data = (array)$request->getParsedBody();
    $fromBranch = (int)($data['from_branch_id'] ?? 0);
    $toBranch = (int)($data['to_branch_id'] ?? 0);
    $notes = $data['notes'] ?? '';
    $items = $data['items'] ?? [];
    
    if ($fromBranch === $toBranch) {
        $response->getBody()->write(json_encode(['success' => false, 'message' => 'Cannot transfer to the same branch']));
        return $response->withHeader('Content-Type', 'application/json');
    }
    if (empty($items)) {
        $response->getBody()->write(json_encode(['success' => false, 'message' => 'No items added']));
        return $response->withHeader('Content-Type', 'application/json');
    }
    
    try {
        $pdo->beginTransaction();
        
        // Verify stock
        $stmtCheck = $pdo->prepare('SELECT stock_count FROM branch_stock WHERE product_id = ? AND branch_id = ?');
        foreach ($items as $item) {
            $stmtCheck->execute([$item['id'], $fromBranch]);
            $currentStock = $stmtCheck->fetchColumn();
            if ($currentStock === false || $currentStock < $item['quantity']) {
                throw new Exception('Insufficient stock for product ID ' . $item['id']);
            }
        }
        
        $ref = 'TRF-' . time();
        $stmtTrf = $pdo->prepare('INSERT INTO stock_transfers (reference_no, from_branch_id, to_branch_id, notes, created_by) VALUES (?, ?, ?, ?, ?)');
        $stmtTrf->execute([$ref, $fromBranch, $toBranch, $notes, $_SESSION['user']['id']]);
        $trfId = $pdo->lastInsertId();
        
        $stmtItem = $pdo->prepare('INSERT INTO stock_transfer_items (transfer_id, product_id, quantity) VALUES (?, ?, ?)');
        $stmtDeduct = $pdo->prepare('UPDATE branch_stock SET stock_count = stock_count - ? WHERE product_id = ? AND branch_id = ?');
        $stmtAdd = $pdo->prepare('UPDATE branch_stock SET stock_count = stock_count + ? WHERE product_id = ? AND branch_id = ?');
        
        foreach ($items as $item) {
            $qty = (float)$item['quantity'];
            $stmtItem->execute([$trfId, $item['id'], $qty]);
            $stmtDeduct->execute([$qty, $item['id'], $fromBranch]);
            
            // Check if to_branch has branch_stock record, if not insert it
            $stmtCheckTo = $pdo->prepare('SELECT id FROM branch_stock WHERE product_id = ? AND branch_id = ?');
            $stmtCheckTo->execute([$item['id'], $toBranch]);
            if (!$stmtCheckTo->fetch()) {
                $pdo->prepare('INSERT INTO branch_stock (product_id, branch_id, stock_count) VALUES (?, ?, 0)')->execute([$item['id'], $toBranch]);
            }
            $stmtAdd->execute([$qty, $item['id'], $toBranch]);
        }
        
        $pdo->commit();
        $response->getBody()->write(json_encode(['success' => true]));
    } catch (Exception $e) {
        $pdo->rollBack();
        $response->getBody()->write(json_encode(['success' => false, 'message' => $e->getMessage()]));
    }
    return $response->withHeader('Content-Type', 'application/json');
})->add($roleMiddleware(['Admin', 'Owner']));

$app->get('/transfers/view/{id}', function (Request $request, Response $response, $args) use ($pdo) {
    $id = $args['id'];
    
    $stmt = $pdo->prepare('
        SELECT t.*, b1.name as from_branch, b2.name as to_branch 
        FROM stock_transfers t
        JOIN branches b1 ON t.from_branch_id = b1.id
        JOIN branches b2 ON t.to_branch_id = b2.id
        WHERE t.id = ?
    ');
    $stmt->execute([$id]);
    $trf = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($trf) {
        $stmtItems = $pdo->prepare('
            SELECT ti.*, p.name as product_name, p.unit 
            FROM stock_transfer_items ti 
            JOIN products p ON ti.product_id = p.id 
            WHERE ti.transfer_id = ?
        ');
        $stmtItems->execute([$id]);
        $trf['items'] = $stmtItems->fetchAll(PDO::FETCH_ASSOC);
        
        $response->getBody()->write(json_encode(['success' => true, 'transfer' => $trf]));
    } else {
        $response->getBody()->write(json_encode(['success' => false, 'message' => 'Transfer not found']));
    }
    return $response->withHeader('Content-Type', 'application/json');
})->add($roleMiddleware(['Admin', 'Owner']));


// =====================================================
// PEOPLE MODULE (Suppliers & Customers)
// =====================================================

// Suppliers
$app->get('/suppliers', function (Request $request, Response $response, $args) use ($pdo) {
    $stmt = $pdo->query('SELECT * FROM suppliers ORDER BY name ASC');
    $suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $view = Twig::fromRequest($request);
    return $view->render($response, 'suppliers.twig', [
        'active_menu' => 'suppliers',
        'suppliers' => $suppliers
    ]);
})->add($roleMiddleware(['Admin', 'Owner', 'Stock Manager']));

$app->post('/suppliers/add', function (Request $request, Response $response, $args) use ($pdo) {
    $data = (array)$request->getParsedBody();
    $stmt = $pdo->prepare('INSERT INTO suppliers (name, phone, email, address) VALUES (?, ?, ?, ?)');
    $stmt->execute([
        $data['name'] ?? '',
        $data['phone'] ?? '',
        $data['email'] ?? '',
        $data['address'] ?? ''
    ]);
    
    $response->getBody()->write(json_encode(['success' => true]));
    return $response->withHeader('Content-Type', 'application/json');
})->add($roleMiddleware(['Admin', 'Owner', 'Stock Manager']));

$app->post('/suppliers/edit/{id}', function (Request $request, Response $response, $args) use ($pdo) {
    $id = $args['id'];
    $data = (array)$request->getParsedBody();
    $stmt = $pdo->prepare('UPDATE suppliers SET name = ?, phone = ?, email = ?, address = ? WHERE id = ?');
    $stmt->execute([
        $data['name'] ?? '',
        $data['phone'] ?? '',
        $data['email'] ?? '',
        $data['address'] ?? '',
        $id
    ]);
    
    $response->getBody()->write(json_encode(['success' => true]));
    return $response->withHeader('Content-Type', 'application/json');
})->add($roleMiddleware(['Admin', 'Owner', 'Stock Manager']));

$app->post('/suppliers/delete/{id}', function (Request $request, Response $response, $args) use ($pdo) {
    $stmt = $pdo->prepare('DELETE FROM suppliers WHERE id = ?');
    $stmt->execute([$args['id']]);
    $response->getBody()->write(json_encode(['success' => true]));
    return $response->withHeader('Content-Type', 'application/json');
})->add($roleMiddleware(['Admin', 'Owner']));

// Customers
$app->get('/customers', function (Request $request, Response $response, $args) use ($pdo) {
    $stmt = $pdo->query('SELECT * FROM customers ORDER BY name ASC');
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $view = Twig::fromRequest($request);
    return $view->render($response, 'customers.twig', [
        'active_menu' => 'customers',
        'customers' => $customers
    ]);
})->add($roleMiddleware(['Admin', 'Owner', 'Cashier', 'Stock Manager']));

$app->post('/customers/add', function (Request $request, Response $response, $args) use ($pdo) {
    $data = (array)$request->getParsedBody();
    $stmt = $pdo->prepare('INSERT INTO customers (name, phone, email, address) VALUES (?, ?, ?, ?)');
    $stmt->execute([
        $data['name'] ?? '',
        $data['phone'] ?? '',
        $data['email'] ?? '',
        $data['address'] ?? ''
    ]);
    
    $id = $pdo->lastInsertId();
    $response->getBody()->write(json_encode(['success' => true, 'id' => $id]));
    return $response->withHeader('Content-Type', 'application/json');
});

$app->post('/customers/edit/{id}', function (Request $request, Response $response, $args) use ($pdo) {
    $id = $args['id'];
    $data = (array)$request->getParsedBody();
    $stmt = $pdo->prepare('UPDATE customers SET name = ?, phone = ?, email = ?, address = ? WHERE id = ?');
    $stmt->execute([
        $data['name'] ?? '',
        $data['phone'] ?? '',
        $data['email'] ?? '',
        $data['address'] ?? '',
        $id
    ]);
    
    $response->getBody()->write(json_encode(['success' => true]));
    return $response->withHeader('Content-Type', 'application/json');
});

$app->post('/customers/delete/{id}', function (Request $request, Response $response, $args) use ($pdo) {
    $stmt = $pdo->prepare('DELETE FROM customers WHERE id = ?');
    $stmt->execute([$args['id']]);
    $response->getBody()->write(json_encode(['success' => true]));
    return $response->withHeader('Content-Type', 'application/json');
})->add($roleMiddleware(['Admin', 'Owner']));


// =====================================================
// CRM PROFILES & PAYMENTS
// =====================================================

$app->get('/customers/view/{id}', function (Request $request, Response $response, $args) use ($pdo) {
    $id = (int)$args['id'];
    
    // Get Customer
    $stmt = $pdo->prepare('SELECT * FROM customers WHERE id = ?');
    $stmt->execute([$id]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$customer) {
        $response->getBody()->write("Customer not found.");
        return $response->withStatus(404);
    }
    
    // Get Sales History
    $stmtS = $pdo->prepare('SELECT * FROM sales WHERE customer_id = ? ORDER BY sale_date DESC');
    $stmtS->execute([$id]);
    $sales = $stmtS->fetchAll(PDO::FETCH_ASSOC);
    
    // Get Payments History
    $stmtP = $pdo->prepare('SELECT p.*, u.username as cashier_name FROM customer_payments p LEFT JOIN users u ON p.created_by = u.id WHERE p.customer_id = ? ORDER BY p.date DESC');
    $stmtP->execute([$id]);
    $payments = $stmtP->fetchAll(PDO::FETCH_ASSOC);

    $credit_alerts = getOverdueCreditAlerts($pdo, $id);
    
    $view = Twig::fromRequest($request);
    return $view->render($response, 'customer_profile.twig', [
        'active_menu' => 'customers',
        'customer' => $customer,
        'sales' => $sales,
        'payments' => $payments,
        'credit_alerts' => $credit_alerts
    ]);
})->add($roleMiddleware(['Admin', 'Owner', 'Stock Manager', 'Cashier']));

$app->post('/customers/pay/{id}', function (Request $request, Response $response, $args) use ($pdo) {
    $id = (int)$args['id'];
    $data = (array)$request->getParsedBody();
    $amount = (float)($data['amount'] ?? 0);
    $method = $data['payment_method'] ?? 'Cash';
    $notes = $data['notes'] ?? '';
    
    if ($amount <= 0) {
        $response->getBody()->write(json_encode(['success' => false, 'message' => 'Invalid amount']));
        return $response->withHeader('Content-Type', 'application/json');
    }
    
    $pdo->beginTransaction();
    try {
        // Record Payment
        $stmtP = $pdo->prepare('INSERT INTO customer_payments (customer_id, amount, payment_method, notes, created_by) VALUES (?, ?, ?, ?, ?)');
        $stmtP->execute([$id, $amount, $method, $notes, $_SESSION['user']['id']]);
        
        // Deduct Balance
        $stmtB = $pdo->prepare('UPDATE customers SET balance = balance - ? WHERE id = ?');
        $stmtB->execute([$amount, $id]);
        
        $pdo->commit();
        $response->getBody()->write(json_encode(['success' => true]));
    } catch (Exception $e) {
        $pdo->rollBack();
        $response->getBody()->write(json_encode(['success' => false, 'message' => 'Database error']));
    }
    
    return $response->withHeader('Content-Type', 'application/json');
})->add($roleMiddleware(['Admin', 'Owner', 'Cashier']));

$app->get('/suppliers/view/{id}', function (Request $request, Response $response, $args) use ($pdo) {
    $id = (int)$args['id'];
    
    // Get Supplier
    $stmt = $pdo->prepare('SELECT * FROM suppliers WHERE id = ?');
    $stmt->execute([$id]);
    $supplier = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$supplier) {
        $response->getBody()->write("Supplier not found.");
        return $response->withStatus(404);
    }
    
    // Get GRN History
    $stmtG = $pdo->prepare('SELECT * FROM grns WHERE supplier_id = ? ORDER BY date DESC');
    $stmtG->execute([$id]);
    $grns = $stmtG->fetchAll(PDO::FETCH_ASSOC);
    
    // Get Payments History
    $stmtP = $pdo->prepare('SELECT p.*, u.username as creator_name FROM supplier_payments p LEFT JOIN users u ON p.created_by = u.id WHERE p.supplier_id = ? ORDER BY p.date DESC');
    $stmtP->execute([$id]);
    $payments = $stmtP->fetchAll(PDO::FETCH_ASSOC);
    
    $view = Twig::fromRequest($request);
    return $view->render($response, 'supplier_profile.twig', [
        'active_menu' => 'suppliers',
        'supplier' => $supplier,
        'grns' => $grns,
        'payments' => $payments
    ]);
})->add($roleMiddleware(['Admin', 'Owner', 'Stock Manager']));

$app->post('/suppliers/pay/{id}', function (Request $request, Response $response, $args) use ($pdo) {
    $id = (int)$args['id'];
    $data = (array)$request->getParsedBody();
    $amount = (float)($data['amount'] ?? 0);
    $method = $data['payment_method'] ?? 'Cash';
    $notes = $data['notes'] ?? '';
    
    if ($amount <= 0) {
        $response->getBody()->write(json_encode(['success' => false, 'message' => 'Invalid amount']));
        return $response->withHeader('Content-Type', 'application/json');
    }
    
    $pdo->beginTransaction();
    try {
        // Record Payment
        $stmtP = $pdo->prepare('INSERT INTO supplier_payments (supplier_id, amount, payment_method, notes, created_by) VALUES (?, ?, ?, ?, ?)');
        $stmtP->execute([$id, $amount, $method, $notes, $_SESSION['user']['id']]);
        
        // Deduct Balance
        $stmtB = $pdo->prepare('UPDATE suppliers SET balance = balance - ? WHERE id = ?');
        $stmtB->execute([$amount, $id]);
        
        $pdo->commit();
        $response->getBody()->write(json_encode(['success' => true]));
    } catch (Exception $e) {
        $pdo->rollBack();
        $response->getBody()->write(json_encode(['success' => false, 'message' => 'Database error']));
    }
    
    return $response->withHeader('Content-Type', 'application/json');
})->add($roleMiddleware(['Admin', 'Owner']));

$app->run();


