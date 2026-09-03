<?php
$pharFile = 'pos.phar';

if (file_exists($pharFile)) {
    unlink($pharFile);
}

$phar = new Phar($pharFile);
$phar->startBuffering();

// Create a stub
$defaultEntry = 'public/index.php';
$stub = "<?php
Phar::mapPhar('pos.phar');
require 'phar://pos.phar/' . '$defaultEntry';
__HALT_COMPILER();";

$phar->setStub($stub);

// Add directories
function addDir($phar, $dir, $baseDir) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if ($file->isFile()) {
            // Get relative path
            $localPath = str_replace($baseDir . DIRECTORY_SEPARATOR, '', $file->getPathname());
            $localPath = str_replace('\\', '/', $localPath);
            $phar->addFile($file->getPathname(), $localPath);
        }
    }
}

$baseDir = __DIR__;
addDir($phar, __DIR__ . '/public', $baseDir);
addDir($phar, __DIR__ . '/templates', $baseDir);
addDir($phar, __DIR__ . '/vendor', $baseDir);

$phar->stopBuffering();
echo "pos.phar built successfully!\n";
