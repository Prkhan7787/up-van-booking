<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/ui-preview/admin/users';
ob_start();
require dirname(__DIR__) . '/public/index.php';
$body = (string) ob_get_clean();
$status = http_response_code();

if ($status !== 404 || !str_contains($body, 'ไม่พบหน้าที่ต้องการ')) {
    fwrite(STDERR, 'FAIL: removed Admin UI Preview must resolve to the router 404.' . PHP_EOL);
    exit(1);
}

echo 'PASS: GET /ui-preview/admin/users returns router 404 without database access.' . PHP_EOL;
