<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap/app.php';

function uiCheck(bool $condition, string $label): void
{
    if (!$condition) throw new RuntimeException('FAIL: ' . $label);
    echo 'PASS: ' . $label . PHP_EOL;
}

$service = new App\Services\UiFoundationService();
$dashboards = $service->dashboardPreviews();
uiCheck(count($dashboards) === 4, 'four role dashboard previews');
uiCheck(array_column($dashboards, 'role') === ['requester', 'vehicle_officer', 'approver', 'admin'], 'only approved roles are presented');
$admin = $service->adminUserManagementOptions();
uiCheck(array_column($admin['roles'], 'value') === ['requester','vehicle_officer','approver','admin'], 'Admin users UI uses the four approved roles');
uiCheck(array_column($admin['roles'], 'label', 'value') === ['requester'=>'requester — ผู้ขอใช้รถ','vehicle_officer'=>'vehicle_officer — เจ้าหน้าที่รถ','approver'=>'approver — ผู้อำนวยการ','admin'=>'admin — ผู้ดูแลระบบ'], 'Admin role labels use the requested display names');
uiCheck(isset($service->validatePasswordPair('abcdef', 'abcdef')['confirmation']) === false, 'six-character matching password passes policy');
uiCheck(isset($service->validatePasswordPair('abcde', 'abcde')['password']), 'password shorter than six is rejected');
uiCheck(isset($service->validatePasswordPair('abcdef', 'abcdeg')['confirmation']), 'password confirmation mismatch is rejected');
uiCheck($service->validatePasswordPair('กขคงจฉ', 'กขคงจฉ') === [], 'Unicode password length counts characters');

ob_start();
(new App\Controllers\HomeController())->index();
$home = (string) ob_get_clean();
uiCheck(str_contains($home, 'href="/login"') && str_contains($home, 'href="/ui-preview/dashboards"'), 'home links to login and role preview');
uiCheck(!str_contains($home, 'Task 01') && !str_contains($home, 'about-system'), 'stale Task 01 message removed');
uiCheck(str_contains($home, '/assets/images/university-van-illustration.webp') && str_contains($home, 'width="1555" height="687"') && str_contains($home, 'alt="ภาพประกอบ'), 'home uses sized and described van illustration');
uiCheck(str_contains($home, 'fetchpriority="high"') && substr_count($home, '<svg') >= 4, 'hero art is eager-loaded and service cards have decorative icons');

ob_start();
(new App\Controllers\HomeController())->index(new App\Models\AuthenticatedPrincipal(42, 1, ['requester','admin']));
$authenticatedHome = (string) ob_get_clean();
uiCheck(str_contains($authenticatedHome, 'href="/dashboard"') && str_contains($authenticatedHome, 'href="/admin/users"'), 'authenticated Home exposes Dashboard and Admin destination for a multi-role account');
uiCheck(!str_contains($authenticatedHome, 'href="/login"') && str_contains($authenticatedHome, 'action="/logout"') && str_contains($authenticatedHome, 'name="_token"'), 'authenticated Home hides Login and uses CSRF-protected POST Logout');

ob_start();
(new App\Controllers\HomeController())->index(new App\Models\AuthenticatedPrincipal(43, 1, ['requester']));
$requesterHome = (string) ob_get_clean();
uiCheck(str_contains($requesterHome, 'href="/dashboard"') && !str_contains($requesterHome, 'href="/admin/users"'), 'non-Admin Home exposes Dashboard but hides Admin User Management');

ob_start();
(new App\Controllers\LoginController())->show();
$login = (string) ob_get_clean();
uiCheck(str_contains($login, 'name="_token"') && str_contains($login, 'disabled'), 'login preview has CSRF token and disabled controls');
uiCheck(str_contains($login, 'loading="lazy"') && str_contains($login, 'width="1555" height="687"'), 'login illustration is dimensioned and lazy-loaded');

ob_start();
(new App\Controllers\WorkspaceController($service))->previewDashboards();
$dashboardHtml = (string) ob_get_clean();
uiCheck(str_contains($dashboardHtml, 'ยังไม่มีการยืนยันตัวตน') && str_contains($dashboardHtml, 'approver') && substr_count($dashboardHtml, '<svg') === 4, 'dashboard preview is clearly unauthed and includes four role icons');
uiCheck(is_file(dirname(__DIR__) . '/public/assets/images/university-van-illustration.webp'), 'van illustration asset exists');

$routes = require dirname(__DIR__) . '/routes/web.php';
uiCheck(isset($routes['POST /login'], $routes['POST /admin/users'], $routes['GET /dashboard']), 'Login, dashboard and Admin routes remain registered');
uiCheck($routes['GET /admin/users'][2] === 'users.manage' && $routes['POST /admin/users'][2] === 'users.manage', 'Admin user management routes require server-side Admin permission');
uiCheck(!isset($routes['GET /ui-preview/admin/users']) && !method_exists(App\Controllers\AdminUserManagementController::class, 'preview'), 'removed Admin Preview route cannot dispatch to its former controller');
$loginSource = file_get_contents(dirname(__DIR__) . '/app/Controllers/LoginController.php');
uiCheck(str_contains($loginSource, "header('Location: /', true, 303)") && !str_contains($loginSource, "header('Location: /dashboard'"), 'Login success and Logout return to Home, not an unavailable Dashboard landing');
$dispatcherSource = file_get_contents(dirname(__DIR__) . '/public/index.php');
uiCheck(str_contains($dispatcherSource, '$method === \'GET\'') && str_contains($dispatcherSource, 'in_array($path, [\'/\', \'/login\'], true)'), 'optional session validation is limited to public Home and Login GET pages');

session_destroy();
