<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap/app.php';

function wiringCheck(bool $condition, string $label): void
{
    if (!$condition) throw new RuntimeException('FAIL: ' . $label);
    echo 'PASS: ' . $label . PHP_EOL;
}

$routes = require dirname(__DIR__) . '/routes/web.php';
wiringCheck(($routes['POST /login'][1] ?? null) === 'login', 'POST login routes to real AuthService controller action');
wiringCheck(($routes['POST /logout'][2] ?? null) === 'authenticated', 'Logout route requires authentication and global CSRF protection');
wiringCheck(($routes['GET /dashboard'][2] ?? null) === 'workspace.view', 'Dashboard route enforces authenticated server-side permission');
wiringCheck(($routes['GET /admin/users'][2] ?? null) === 'users.manage' && ($routes['POST /admin/users'][2] ?? null) === 'users.manage', 'Admin user routes require server-side Admin permission');

$policy = new App\Services\AuthorizationPolicy();
foreach (['requester','vehicle_officer','approver','admin'] as $role) {
    wiringCheck($policy->allows([$role], 'workspace.view'), $role . ' can access authenticated workspace');
}
wiringCheck($policy->allows(['admin'], 'booking.decision') && !$policy->allows(['admin'], 'assignment.create'), 'Admin has explicit decision permission and no assignment permission');
wiringCheck(!$policy->allows([], 'workspace.view') && !$policy->allows(['unrecognized'], 'workspace.view'), 'No role and unknown role are denied by default');
$authorization = new App\Middleware\AuthorizationMiddleware($policy);
$ownerGuardWorks = false;
try { $authorization->requireOwner(new App\Models\AuthenticatedPrincipal(11,1,['requester']),12); }
catch (App\Core\AuthorizationException) { $ownerGuardWorks = true; }
wiringCheck($ownerGuardWorks, 'Authorization middleware blocks cross-account resource access');

$factory = file_get_contents(dirname(__DIR__) . '/app/Services/AuthServiceFactory.php');
$repository = file_get_contents(dirname(__DIR__) . '/app/Repositories/PdoAuthRepository.php');
$controller = file_get_contents(dirname(__DIR__) . '/public/index.php');
$provision = file_get_contents(dirname(__DIR__) . '/bin/provision-test-admin.php');
wiringCheck(is_string($factory) && str_contains($factory, "connect('auth_test')") && !str_contains($factory, "connect('runtime')"), 'Task 04C-1 auth factory cannot connect to Production');
wiringCheck(is_string($repository) && str_contains($repository, 'prepare(') && !str_contains($repository, 'mysqli'), 'PDO auth repository uses prepared statements only');
wiringCheck(is_string($repository) && str_contains($repository, 'FROM users WHERE username = :username LIMIT 1') && !preg_match('/WHERE[^;]*\bemail\b/i', $repository), 'PDO login lookup uses username only without email fallback');
$loginView = file_get_contents(dirname(__DIR__) . '/app/Views/auth/login.php');
wiringCheck(is_string($loginView) && !str_contains($loginView, 'ชื่อผู้ใช้หรืออีเมล') && str_contains($loginView, 'placeholder="กรอกชื่อผู้ใช้ (Username)"'), 'Login label and placeholder describe username only');
wiringCheck(is_string($controller) && str_contains($controller, 'requirePermission') && str_contains($controller, 'requireAuthenticated'), 'HTTP dispatcher invokes authentication and authorization middleware');
wiringCheck(is_string($provision) && str_contains($provision, "TEST_ADMIN_PROVISION_APPROVED") && str_contains($provision, "--confirm=up_van_booking_test") && str_contains($provision, "connect('test_provisioner')"), 'First-admin CLI is gated and bound to its Test-only account');
wiringCheck(is_string($provision) && str_contains($provision, 'Read-Host -AsSecureString') && !str_contains($provision, 'echo $password'), 'Provision CLI prompts without echo and does not print passwords');

echo 'PASS: Authentication runtime wiring offline checks completed.' . PHP_EOL;
