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

// Create Twig — use file cache in production (defined in host-index.php), disable in dev
$twigCache = defined('TWIG_CACHE_PATH') ? TWIG_CACHE_PATH : false;
$twig = Twig::create(__DIR__ . '/../templates', ['cache' => $twigCache]);
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
    if ($role === 'Owner') {
        // Owner can switch branches; use active_branch_id from session
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
    
    if (($_SESSION['user']['role'] ?? '') !== 'Owner') {
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

        // Repairs Revenue & Cost
        $repBranchFilter = $branchId ? ' AND r.branch_id = ?' : '';
        $repBranchParams = $branchId ? [$branchId] : [];
        
        $stmt = $pdo->prepare("SELECT SUM(COALESCE(NULLIF(actual_shop_cost,''), estimated_cost)) FROM repair_logs r WHERE status = 'Completed' AND created_at BETWEEN ? AND ?" . $repBranchFilter);
        $stmt->execute(array_merge([$startDate . ' 00:00:00', $endDate . ' 23:59:59'], $repBranchParams));
        $repairs_revenue = $stmt->fetchColumn() ?: 0;

        $stmt = $pdo->prepare("SELECT SUM(real_cost) FROM repair_logs r WHERE status = 'Completed' AND created_at BETWEEN ? AND ?" . $repBranchFilter);
        $stmt->execute(array_merge([$startDate . ' 00:00:00', $endDate . ' 23:59:59'], $repBranchParams));
        $repairs_cogs = $stmt->fetchColumn() ?: 0;

        $total_revenue = $products_revenue + $repairs_revenue;

        // Expenses
        $stmt = $pdo->prepare("SELECT SUM(amount) FROM expenses WHERE expense_date BETWEEN ? AND ?" . $expBranchFilter);
        $stmt->execute(array_merge([$startDate . ' 00:00:00', $endDate . ' 23:59:59'], $expBranchParams));
        $total_expenses = $stmt->fetchColumn() ?: 0;

        // Net Profit
        $products_profit = $products_revenue - $products_cogs;
        $repairs_profit = $repairs_revenue - $repairs_cogs;
        $net_profit = $products_profit + $repairs_profit - $total_expenses;

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
            $stmt = $pdo->prepare("SELECT p.name, bs.stock_count, bs.min_stock, c.name as category_name FROM branch_stock bs JOIN products p ON bs.product_id = p.id LEFT JOIN categories c ON p.category_id = c.id WHERE bs.stock_count <= bs.min_stock AND bs.branch_id = ? ORDER BY bs.stock_count ASC LIMIT 5");
            $stmt->execute([$branchId]);
        } else {
            $stmt = $pdo->query("SELECT p.name, bs.stock_count, bs.min_stock, c.name as category_name, b.name as branch_name FROM branch_stock bs JOIN products p ON bs.product_id = p.id LEFT JOIN categories c ON p.category_id = c.id LEFT JOIN branches b ON bs.branch_id = b.id WHERE bs.stock_count <= bs.min_stock ORDER BY bs.stock_count ASC LIMIT 5");
        }
        $low_stock_items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Outstanding Dues
        $stmt = $pdo->prepare("SELECT SUM(due) FROM sales s WHERE due > 0 AND status != 'Voided'" . $branchFilter);
        $stmt->execute($branchParams);
        $outstanding_dues = $stmt->fetchColumn() ?: 0;

        $stats = [
            'products_revenue' => $products_revenue,
            'repairs_revenue'  => $repairs_revenue,
            'total_revenue'    => $total_revenue,
            'total_expenses'   => $total_expenses,
            'net_profit'       => $net_profit,
            'total_inventory'  => $total_inventory,
            'low_stock_count'  => $low_stock_count,
            'outstanding_dues' => $outstanding_dues,
            'total_cogs'       => $products_cogs + $repairs_cogs
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
    
        $stmt = $pdo->prepare("SELECT p.name, bs.stock_count, bs.min_stock, c.name as category_name FROM branch_stock bs JOIN products p ON bs.product_id = p.id LEFT JOIN categories c ON p.category_id = c.id WHERE bs.stock_count <= bs.min_stock AND bs.branch_id = ? ORDER BY bs.stock_count ASC LIMIT 10");
        $stmt->execute([$branchId]);
        $low_stock_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $view = Twig::fromRequest($request);
    return $view->render($response, 'dashboard.twig', [
        'stats' => $stats,
        'low_stock_items' => $low_stock_items,
        'branch_stats' => $branch_stats,
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
            SELECT p.*, c.name as category_name, bs.stock_count as branch_stock, bs.min_stock as branch_min_stock
            FROM products p 
            LEFT JOIN categories c ON p.category_id = c.id 
            LEFT JOIN branch_stock bs ON p.id = bs.product_id AND bs.branch_id = ?
            ORDER BY p.id DESC
        ');
        $stmt->execute([$branchId]);
    } else {
        // Owner viewing all — show aggregate stock
        $stmt = $pdo->query('
            SELECT p.*, c.name as category_name, 
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

    $view = Twig::fromRequest($request);
    return $view->render($response, 'inventory.twig', [
        'products' => $products,
        'categories' => $categories,
        'branches' => $branches,
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
    $warranty = $data['warranty'] ?? '';
    $branchId = getActiveBranchId();

    try {
        $pdo->beginTransaction();
        
        // Insert product into global catalog (stock_count stays 0 in products table)
        $stmt = $pdo->prepare('INSERT INTO products (name, category_id, cost_price, selling_price, stock_count, min_stock, barcode, warranty) VALUES (?, ?, ?, ?, 0, ?, ?, ?)');
        $stmt->execute([$name, $category_id, $cost_price, $selling_price, $min_stock, $barcode, $warranty]);
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

    if (!empty($name)) {
        $stmt = $pdo->prepare('INSERT INTO categories (name) VALUES (?)');
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
})->add($roleMiddleware(['Admin', 'Stock Manager']));

$app->post('/category/delete/{id}', function (Request $request, Response $response, $args) use ($pdo) {
    $id = $args['id'];
    $stmt = $pdo->prepare('DELETE FROM categories WHERE id = ?');
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
        SELECT si.*, p.name as product_name
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
        SELECT p.*, c.name as category_name, bs.stock_count as branch_stock
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

    $view = Twig::fromRequest($request);
    return $view->render($response, 'pos.twig', [
        'products' => $products,
        'categories' => $categories,
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
    $branchId = getActiveBranchId();

    if (!$branchId) {
        $response->getBody()->write(json_encode(['success' => false, 'message' => 'Please select a branch first.']));
        return $response->withHeader('Content-Type', 'application/json');
    }

    if (empty($cart)) {
        $response->getBody()->write(json_encode(['success' => false, 'message' => 'Cart is empty.']));
        return $response->withHeader('Content-Type', 'application/json');
    }

    // Server-side cart validation using branch_stock
    $stmtCheck = $pdo->prepare('SELECT p.id, p.selling_price, p.cost_price, bs.stock_count FROM products p JOIN branch_stock bs ON p.id = bs.product_id AND bs.branch_id = ? WHERE p.id = ?');
    $subtotal = 0;
    $totalCost = 0;
    foreach ($cart as $item) {
        $id       = (int)($item['id'] ?? 0);
        $qty      = (int)($item['quantity'] ?? 0);
        $price    = (float)($item['price'] ?? 0);
        if ($id <= 0 || $qty <= 0 || $price < 0) {
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

        $total = $subtotal - $discount;
        if ($total < 0) $total = 0;
        $total = round($total, 2);
        $paid  = $total;
        $due   = 0;

        $invoiceId = 'INV-' . strtoupper(bin2hex(random_bytes(4)));
        $cashierId = $_SESSION['user']['id'] ?? null;
        
        $stmtShift = $pdo->prepare("SELECT id FROM shifts WHERE user_id = ? AND status = 'Open' ORDER BY id DESC LIMIT 1");
        $stmtShift->execute([$cashierId]);
        $shiftId = $stmtShift->fetchColumn() ?: null;

        $stmt = $pdo->prepare('INSERT INTO sales (invoice_id, cashier_id, total, paid, due, payment_method, tendered_amount, change_amount, split_cash, split_card, split_fawran, shift_id, branch_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$invoiceId, $cashierId, $total, $paid, $due, $paymentMethod, $tenderedAmount, $changeAmount, $splitCash, $splitCard, $splitFawran, $shiftId, $branchId]);
        $saleId = $pdo->lastInsertId();

        $stmtItem        = $pdo->prepare('INSERT INTO sale_items (sale_id, product_id, quantity, price) VALUES (?, ?, ?, ?)');
        $stmtUpdateStock = $pdo->prepare('UPDATE branch_stock SET stock_count = stock_count - ? WHERE product_id = ? AND branch_id = ?');

        foreach ($cart as $item) {
            $stmtItem->execute([$saleId, (int)$item['id'], (int)$item['quantity'], (float)$item['price']]);
            $stmtUpdateStock->execute([(int)$item['quantity'], (int)$item['id'], $branchId]);
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
// REPAIRS MODULE (branch-aware)
// =====================================================
$app->get('/repairs', function (Request $request, Response $response, $args) use ($pdo) {
    $branchId = getActiveBranchId();
    
    if ($branchId) {
        $stmt = $pdo->prepare('
            SELECT r.*, rc.name as category_name, b.name as branch_name
            FROM repair_logs r
            LEFT JOIN repair_categories rc ON r.category_id = rc.id
            LEFT JOIN branches b ON r.branch_id = b.id
            WHERE r.branch_id = ?
            ORDER BY r.id DESC
        ');
        $stmt->execute([$branchId]);
    } else {
        $stmt = $pdo->query('
            SELECT r.*, rc.name as category_name, b.name as branch_name
            FROM repair_logs r
            LEFT JOIN repair_categories rc ON r.category_id = rc.id
            LEFT JOIN branches b ON r.branch_id = b.id
            ORDER BY r.id DESC
        ');
    }
    $repairs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->query('SELECT * FROM repair_categories ORDER BY name ASC');
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $view = Twig::fromRequest($request);
    return $view->render($response, 'repairs.twig', [
        'repairs' => $repairs,
        'categories' => $categories,
        'active_menu' => 'repairs'
    ]);
})->add($roleMiddleware(['Admin', 'Cashier']));

$app->post('/repairs/add', function (Request $request, Response $response, $args) use ($pdo) {
    $data = (array)$request->getParsedBody();
    $branchId = getActiveBranchId();
    
    $customer_name = $data['customer_name'] ?? '';
    $phone = $data['phone'] ?? '';
    $category_id = $data['category_id'] ?? null;
    $device_issue = $data['device_issue'] ?? '';
    $estimated_cost = $data['estimated_cost'] ?? 0;
    $real_cost = $data['real_cost'] ?? 0;
    $warranty = $data['warranty'] ?? '';
    $status = 'Pending';
    
    $stmt = $pdo->prepare('INSERT INTO repair_logs (customer_name, phone, category_id, device_issue, estimated_cost, real_cost, warranty, status, branch_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$customer_name, $phone, $category_id, $device_issue, $estimated_cost, $real_cost, $warranty, $status, $branchId]);

    $response->getBody()->write(json_encode(['success' => true]));
    return $response->withHeader('Content-Type', 'application/json');
})->add($roleMiddleware(['Admin', 'Cashier']));

$app->post('/repair-category/add', function (Request $request, Response $response, $args) use ($pdo) {
    $data = (array)$request->getParsedBody();
    $name = $data['name'] ?? '';

    if (!empty($name)) {
        $stmt = $pdo->prepare('INSERT INTO repair_categories (name) VALUES (?)');
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
})->add($roleMiddleware(['Admin', 'Cashier']));

$app->post('/repair-category/delete/{id}', function (Request $request, Response $response, $args) use ($pdo) {
    $id = $args['id'];
    $stmt = $pdo->prepare('DELETE FROM repair_categories WHERE id = ?');
    $stmt->execute([$id]);

    $response->getBody()->write(json_encode(['success' => true]));
    return $response->withHeader('Content-Type', 'application/json');
})->add($roleMiddleware(['Admin', 'Cashier']));

$app->post('/repairs/set-warranty/{id}', function (Request $request, Response $response, $args) use ($pdo) {
    $id = $args['id'];
    $data = (array)$request->getParsedBody();
    $warranty = $data['warranty'] ?? '';
    
    $stmt = $pdo->prepare('UPDATE repair_logs SET warranty = ? WHERE id = ?');
    $stmt->execute([$warranty, $id]);
    
    $response->getBody()->write(json_encode(['success' => true]));
    return $response->withHeader('Content-Type', 'application/json');
})->add($roleMiddleware(['Admin', 'Cashier']));

// =====================================================
// EXPENSES MODULE (branch-aware)
// =====================================================
$app->get('/expenses', function (Request $request, Response $response, $args) use ($pdo) {
    $branchId = getActiveBranchId();
    
    if ($branchId) {
        $stmt = $pdo->prepare('
            SELECT e.*, ec.name as category_name, b.name as branch_name
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
            SELECT e.*, ec.name as category_name, b.name as branch_name
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
    $stmt = $pdo->prepare('UPDATE products SET name = ?, category_id = ?, cost_price = ?, selling_price = ?, min_stock = ?, barcode = ?, warranty = ? WHERE id = ?');
    $stmt->execute([
        $data['name'] ?? '', 
        $data['category_id'] ?? null, 
        $data['cost_price'] ?? 0, 
        $data['selling_price'] ?? 0, 
        $data['min_stock'] ?? 5,
        $data['barcode'] ?? '',
        $data['warranty'] ?? '',
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

$app->post('/repairs/edit/{id}', function (Request $request, Response $response, $args) use ($pdo) {
    $id = $args['id'];
    $data = (array)$request->getParsedBody();
    $stmt = $pdo->prepare('UPDATE repair_logs SET customer_name = ?, phone = ?, category_id = ?, device_issue = ?, estimated_cost = ?, real_cost = ?, warranty = ?, status = ? WHERE id = ?');
    $stmt->execute([
        $data['customer_name'] ?? '',
        $data['phone'] ?? '',
        $data['category_id'] ?? null,
        $data['device_issue'] ?? '',
        $data['estimated_cost'] ?? 0,
        $data['real_cost'] ?? 0,
        $data['warranty'] ?? '',
        $data['status'] ?? 'Pending',
        $id
    ]);
    $response->getBody()->write(json_encode(['success' => true]));
    return $response->withHeader('Content-Type', 'application/json');
})->add($roleMiddleware(['Admin', 'Cashier']));

$app->post('/repairs/delete/{id}', function (Request $request, Response $response, $args) use ($pdo) {
    $stmt = $pdo->prepare('DELETE FROM repair_logs WHERE id = ?');
    $stmt->execute([$args['id']]);
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

$app->post('/repairs/update-status/{id}', function (Request $request, Response $response, $args) use ($pdo) {
    $id = $args['id'];
    $data = (array)$request->getParsedBody();
    $status = $data['status'] ?? '';
    
    if (in_array($status, ['Pending', 'In Progress', 'Completed'])) {
        $stmt = $pdo->prepare('UPDATE repair_logs SET status = ? WHERE id = ?');
        $stmt->execute([$status, $id]);
    }

    $response->getBody()->write(json_encode(['success' => true]));
    return $response->withHeader('Content-Type', 'application/json');
})->add($roleMiddleware(['Admin', 'Cashier']));

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
        $stmt = $pdo->prepare("SELECT p.name, bs.stock_count, c.name as category_name FROM branch_stock bs JOIN products p ON bs.product_id = p.id LEFT JOIN categories c ON p.category_id = c.id WHERE bs.stock_count <= bs.min_stock AND bs.branch_id = ? ORDER BY bs.stock_count ASC LIMIT 10");
        $stmt->execute([$branchId]);
    } else {
        $stmt = $pdo->query("SELECT p.name, bs.stock_count, c.name as category_name FROM branch_stock bs JOIN products p ON bs.product_id = p.id LEFT JOIN categories c ON p.category_id = c.id WHERE bs.stock_count <= bs.min_stock ORDER BY bs.stock_count ASC LIMIT 10");
    }
    $lowStock = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Repairs by Category
    $rBranchFilter = $branchId ? ' AND r.branch_id = ?' : '';
    $rBranchParams = $branchId ? [$branchId] : [];
    
    $stmt = $pdo->prepare("
        SELECT c.name as category_name, 
               COUNT(r.id) as count, 
               SUM(COALESCE(NULLIF(r.actual_shop_cost,''), r.estimated_cost)) as revenue
        FROM repair_logs r
        LEFT JOIN repair_categories c ON r.category_id = c.id
        WHERE r.status = 'Completed' AND r.created_at BETWEEN ? AND ?" . $rBranchFilter . "
        GROUP BY r.category_id
        ORDER BY revenue DESC
    ");
    $stmt->execute(array_merge([$startDate, $endDateFull], $rBranchParams));
    $repairsByCategory = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
    
    $stmt = $pdo->prepare("SELECT SUM(COALESCE(NULLIF(actual_shop_cost,''), estimated_cost)) as rev, SUM(real_cost) as cost FROM repair_logs r WHERE status = 'Completed' AND created_at BETWEEN ? AND ?" . $rBranchFilter);
    $stmt->execute(array_merge([$startDate, $endDateFull], $rBranchParams));
    $repairsData = $stmt->fetch(PDO::FETCH_ASSOC);
    $repairsRevenue = (float)$repairsData['rev'];
    $repairsCogs = (float)$repairsData['cost'];

    $stmt = $pdo->prepare("SELECT SUM(amount) FROM expenses WHERE expense_date BETWEEN ? AND ?" . $branchFilter);
    $stmt->execute(array_merge([$startDate, $endDateFull], $branchParams));
    $expenses = (float)$stmt->fetchColumn();

    $grossProfit = ($productsRevenue - $productsCogs) + ($repairsRevenue - $repairsCogs);
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
        'repairs' => [
            'by_category' => $repairsByCategory
        ],
        'shifts' => $shiftsReport,
        'pl' => [
            'products_revenue' => $productsRevenue,
            'products_cogs' => $productsCogs,
            'repairs_revenue' => $repairsRevenue,
            'repairs_cogs' => $repairsCogs,
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

$app->run();