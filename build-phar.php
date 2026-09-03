<?php
$pharFile = 'app.phar';

if (file_exists($pharFile)) {
    unlink($pharFile);
}

$phar = new Phar($pharFile);
$phar->startBuffering();

// Create a stub
$defaultStub = $phar->createDefaultStub('public/index.php');
$phar->setStub($defaultStub);

// Build from directory, ignoring the phar itself and git
$phar->buildFromDirectory(__DIR__, '/^(?!(?:\.git|app\.phar|database\.sqlite|live_database\.sqlite|cl_database\.sqlite|build-phar\.php|host-index\.php|\.github)).*$/');

$phar->stopBuffering();
echo "Successfully built $pharFile\n";
