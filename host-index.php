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

define('DB_PATH', __DIR__ . '/database.sqlite');
define('TWIG_CACHE_PATH', __DIR__ . '/twig_cache');

// Ensure Twig cache directory exists
if (!is_dir(TWIG_CACHE_PATH)) {
    @mkdir(TWIG_CACHE_PATH, 0755, true);
}

// Auto-clear cache on new deployment
$versionFile = __DIR__ . '/version.txt';
$deployedVersionFile = __DIR__ . '/deployed_version.txt';

if (file_exists($versionFile)) {
    $currentVersion = trim(file_get_contents($versionFile));
    $deployedVersion = file_exists($deployedVersionFile) ? trim(file_get_contents($deployedVersionFile)) : '';
    
    if ($currentVersion !== $deployedVersion && is_dir(TWIG_CACHE_PATH)) {
        // Clear Twig Cache
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(TWIG_CACHE_PATH, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $fileinfo) {
            $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
            @$todo($fileinfo->getRealPath());
        }
        
        file_put_contents($deployedVersionFile, $currentVersion);
    }
}

// Require the compiled phar
require __DIR__ . '/pos.phar';
