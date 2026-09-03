<?php
// host-index.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Check for production database name
$dbFile = dirname(__DIR__) . '/live_database.sqlite';
if (!file_exists($dbFile)) {
    $dbFile = dirname(__DIR__) . '/database.sqlite';
}

// Define the real path to the production database outside the PHAR
define('DB_PATH', $dbFile);

// Determine the absolute path to the phar file
$pharPath = dirname(__DIR__) . '/app.phar';

if (file_exists($pharPath)) {
    // Include the phar archive which will execute its stub (public/index.php)
    require $pharPath;
} else {
    http_response_code(500);
    echo "Application archive (app.phar) not found.";
}
