<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap/cli.php';

use App\Database\ConnectionFactory;

$result = ConnectionFactory::diagnoseMigrationTestConnection();
echo json_encode($result, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR), PHP_EOL;
exit(($result['status'] ?? 'FAIL') === 'PASS' ? 0 : 1);
