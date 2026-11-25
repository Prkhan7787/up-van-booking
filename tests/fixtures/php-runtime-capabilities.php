<?php
declare(strict_types=1);
// Standalone loopback test router; never bootstraps the app or accesses MySQL.
header('Content-Type: application/json');
echo json_encode(['php'=>PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION,
    'curl'=>extension_loaded('curl'),'openssl'=>extension_loaded('openssl'),
    'mbstring'=>extension_loaded('mbstring'),'pdo_mysql'=>extension_loaded('pdo_mysql')],JSON_THROW_ON_ERROR);
