<?php

require __DIR__ . '/../vendor/autoload.php';

$dbFile = __DIR__ . '/../database.sqlite';

try {
    $pdo = new PDO('sqlite:' . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    echo "Connected to SQLite database successfully.\n";

    // 1. Users
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT NOT NULL UNIQUE,
        password TEXT NOT NULL,
        role TEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // 2. Categories
    $pdo->exec("CREATE TABLE IF NOT EXISTS categories (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL UNIQUE
    )");

    // 3. Products
    $pdo->exec("CREATE TABLE IF NOT EXISTS products (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        category_id INTEGER,
        cost_price REAL NOT NULL,
        selling_price REAL NOT NULL,
        stock_count INTEGER NOT NULL DEFAULT 0,
        image_url TEXT,
        FOREIGN KEY(category_id) REFERENCES categories(id)
    )");

    // 4. Sales
    $pdo->exec("CREATE TABLE IF NOT EXISTS sales (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        invoice_id TEXT NOT NULL UNIQUE,
        cashier_id INTEGER,
        total REAL NOT NULL,
        paid REAL NOT NULL,
        due REAL NOT NULL,
        sale_date DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(cashier_id) REFERENCES users(id)
    )");

    // 5. Expenses
    $pdo->exec("CREATE TABLE IF NOT EXISTS expenses (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        description TEXT NOT NULL,
        amount REAL NOT NULL,
        expense_date DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // 6. RepairLogs
    $pdo->exec("CREATE TABLE IF NOT EXISTS repair_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        customer_name TEXT NOT NULL,
        phone TEXT NOT NULL,
        device_issue TEXT NOT NULL,
        estimated_cost REAL,
        actual_shop_cost REAL,
        status TEXT NOT NULL,
        warranty TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    echo "Tables created successfully.\n";

    // Seed Data
    // Seed Admin User
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = 'admin'");
    $stmt->execute();
    if ($stmt->fetchColumn() == 0) {
        $password = password_hash('admin123', PASSWORD_DEFAULT);
        $pdo->exec("INSERT INTO users (username, password, role) VALUES ('admin', '$password', 'Admin')");
        echo "Default admin user seeded. Username: admin | Password: admin123\n";
    }

    // Seed Categories
    $defaultCategories = ['Back covers', 'Tempered glass', 'Chargers', 'Batteries', 'Accessories'];
    $stmt = $pdo->prepare("INSERT OR IGNORE INTO categories (name) VALUES (:name)");
    foreach ($defaultCategories as $category) {
        $stmt->execute([':name' => $category]);
    }
    echo "Default categories seeded.\n";

    echo "Database initialization complete!\n";

} catch (PDOException $e) {
    echo "Database error: " . $e->getMessage() . "\n";
    exit(1);
}
