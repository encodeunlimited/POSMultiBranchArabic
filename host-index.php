<?php
/**
 * Hostinger Production Entry Point
 * This file replaces public/index.php on the production server.
 * It defines the absolute path to the SQLite database (outside the phar)
 * and then executes the compiled pos.phar application.
 */

// Enable error reporting for initial setup
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Define absolute path to database in Hostinger (relative to public_html)
// Hostinger typically places public_html inside the domain directory.
// We'll store the database one level above public_html to keep it secure.
define('DB_PATH', dirname(__DIR__) . '/database.sqlite');
define('TWIG_CACHE_PATH', dirname(__DIR__) . '/twig_cache');

// Ensure Twig cache directory exists
if (!is_dir(TWIG_CACHE_PATH)) {
    @mkdir(TWIG_CACHE_PATH, 0755, true);
}

// Require the compiled phar
require __DIR__ . '/pos.phar';
