<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap/app.php';
ob_start();

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
wiringCheck(is_string($loginView) && str_contains($loginView, '>ชื่อผู้ใช้ หรือ เบอร์โทรศัพท์</label>')
    && str_contains($loginView, 'placeholder="กรอกชื่อผู้ใช้หรือเบอร์โทรศัพท์"'), 'Shared login uses the neutral username or phone label and placeholder');
wiringCheck(str_contains($loginView, 'ใช้บัญชีภายในระบบชั่วคราว ระหว่างรอเชื่อม Microsoft 365')
    && str_contains($loginView, 'ขณะนี้ระบบยังไม่ได้เชื่อม Microsoft 365')
    && str_contains($loginView, 'กรุณาเข้าสู่ระบบด้วยบัญชีภายในระบบ'), 'Login explains temporary local account access');
wiringCheck(substr_count($loginView, '<form ') === 1 && !str_contains($loginView, 'action="/driver/login"')
    && !str_contains($loginView, 'พนักงานขับรถ') && !str_contains($loginView, 'บัญชีทดสอบและผู้ดูแลระบบ'), 'The view renders one shared form with neutral copy');
wiringCheck(is_string($loginView) && !str_contains($loginView, '/driver/register') && !isset($routes['GET /driver/register'], $routes['POST /driver/register']), 'Public driver self-registration is unavailable in UI and routes');
wiringCheck(is_string($loginView) && str_contains($loginView, 'action="/login" method="post"') && str_contains($loginView, 'name="_token"') && str_contains($loginView, 'name="username"') && str_contains($loginView, 'name="password"'), 'Existing CSRF-protected local username or phone login remains intact');
$previousAuthGate = getenv('TEST_AUTH_EXECUTION_APPROVED');
try {
    putenv('TEST_AUTH_EXECUTION_APPROVED=false');
    $loginController = new App\Controllers\LoginController();
    ob_start();
    $loginController->show();
    $closedLogin = (string)ob_get_clean();
    wiringCheck(str_contains($closedLogin,'id="local-login-status"') && substr_count($closedLogin,'<form ')===1
        && !preg_match('/<fieldset[^>]*\bdisabled\b/i',$closedLogin), 'Closed auth gate keeps one form and shows its status');
    foreach (['username','password'] as $field) {
        $pattern = '/<input\b[^>]*\bid="'.$field.'"[^>]*>/i';
        wiringCheck(preg_match($pattern,$closedLogin,$input)===1 && !preg_match('/\b(disabled|readonly)\b/i',$input[0]),
            $field.' is editable with the auth gate closed');
    }
    ob_start();
    $loginController->login();
    $rejectedLogin = (string)ob_get_clean();
    wiringCheck(http_response_code()===503 && str_contains($rejectedLogin,'id="local-login-status"')
        && substr_count($rejectedLogin,'<form ')===1 && !isset($_SESSION['auth']), 'Closed auth gate rejects login and keeps the form visible');
} finally {
    putenv($previousAuthGate===false?'TEST_AUTH_EXECUTION_APPROVED':'TEST_AUTH_EXECUTION_APPROVED='.$previousAuthGate);
}
wiringCheck(($routes['POST /driver/login'][1] ?? null) === 'login', 'Legacy Driver endpoint remains compatible with existing clients');
wiringCheck(is_string($controller) && str_contains($controller, 'requirePermission') && str_contains($controller, 'requireAuthenticated'), 'HTTP dispatcher invokes authentication and authorization middleware');
wiringCheck(is_string($provision) && str_contains($provision, "TEST_ADMIN_PROVISION_APPROVED") && str_contains($provision, "--confirm=up_van_booking_test") && str_contains($provision, "connect('test_provisioner')"), 'First-admin CLI is gated and bound to its Test-only account');
wiringCheck(is_string($provision) && str_contains($provision, 'Read-Host -AsSecureString') && !str_contains($provision, 'echo $password'), 'Provision CLI prompts without echo and does not print passwords');

echo 'PASS: Authentication runtime wiring offline checks completed.' . PHP_EOL;
ob_end_flush();
