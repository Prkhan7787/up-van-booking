<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap/app.php';
function check(bool $condition, string $label): void {
    if (!$condition) throw new RuntimeException('FAIL: ' . $label);
    echo 'PASS: ' . $label . PHP_EOL;
}
check(e('<script>"&') === '&lt;script&gt;&quot;&amp;', 'HTML escaping');
check(!App\Core\Csrf::valid(null), 'CSRF rejects missing token');
$token = App\Core\Csrf::token();
check(strlen($token) === 64 && App\Core\Csrf::valid($token), 'CSRF accepts session token');
check(!App\Core\Csrf::valid('wrong') && !App\Core\Csrf::valid([]), 'CSRF rejects invalid input');
check(count(App\Services\ModuleCatalog::all()) === 4, 'Future module catalog');
ob_start();
(new App\Controllers\HomeController())->index();
$html = ob_get_clean();
check(str_contains($html, 'lang="th"') && str_contains($html, 'ยังไม่เปิดรับคำขอจริง'), 'Home view rendering');
check(is_file(dirname(__DIR__) . '/public/assets/app.css'), 'Built CSS exists');
session_destroy();
