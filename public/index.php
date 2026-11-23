<?php
declare(strict_types=1);
try {
    require dirname(__DIR__) . '/bootstrap/app.php';
    header('Content-Type: text/html; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    $mapPage=($_SERVER['REQUEST_METHOD'] ?? 'GET')==='GET' && parse_url($_SERVER['REQUEST_URI'] ?? '/',PHP_URL_PATH)==='/bookings/travel-estimate';
    if($mapPage){
        $nonce=base64_encode(random_bytes(18));$_SERVER['TRAVEL_MAP_NONCE']=$nonce;
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-{$nonce}' 'strict-dynamic'; style-src 'self' 'nonce-{$nonce}' https://unpkg.com; img-src 'self' https://tile.openstreetmap.org https://unpkg.com data: blob:; connect-src 'self' https://photon.komoot.io https://router.project-osrm.org; font-src 'self'; object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");
    }else header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self'; object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");
    header('Cache-Control: no-store');
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method === 'POST' && !App\Core\BookingWriteWindow::enterWebWrite()) {
        http_response_code(503);
        echo 'ระบบกำลังปิดรับการเปลี่ยนข้อมูลชั่วคราว';
        return;
    }
    if ($method === 'POST' && !App\Core\Csrf::valid($_POST['_token'] ?? null)) {
        http_response_code(419);
        echo 'คำขอหมดอายุ กรุณาโหลดหน้าใหม่';
        return;
    }
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $routes = require dirname(__DIR__) . '/routes/web.php';
    $route = $routes[$method . ' ' . $path] ?? null;
    if ($route === null) {
        http_response_code(404);
        echo 'ไม่พบหน้าที่ต้องการ';
        return;
    }
    [$controller, $action] = $route;
    $runtime = null;
    $principal = null;
    if ($method === 'GET' && in_array($path, ['/', '/login'], true) && isset($_SESSION['auth'])) {
        if (App\Core\AuthFeature::testExecutionEnabled()) {
            $runtime = App\Services\AuthServiceFactory::createTestRuntime();
            $principal = $runtime->authentication->principal();
        } else {
            (new App\Core\SessionManager())->invalidateAuthentication();
        }
    }
    $required = $route[2] ?? null;
    if (is_string($required)) {
        if (!App\Core\AuthFeature::testExecutionEnabled()) {
            http_response_code(503);
            App\Core\View::render('auth/unavailable', ['pageTitle'=>'ยังไม่เปิดใช้ Authentication', 'message'=>'Test Authentication ถูกปิดอยู่ คำขอนี้ไม่ได้เชื่อมต่อฐานข้อมูล']);
            return;
        }
        $driverRoute = in_array($controller, [App\Controllers\DriverScheduleController::class, App\Controllers\DriverAccountLinkController::class], true);
        $runtime = App\Services\AuthServiceFactory::createTestRuntime(driver: $driverRoute);
        $principal = $runtime->authentication->requireAuthenticated();
        if ($required !== 'authenticated') $runtime->authorization->requirePermission($principal, $required);
    }
    if ($controller === App\Controllers\LoginController::class) $instance = new $controller($runtime);
    elseif ($controller === App\Controllers\RequesterBookingController::class) $instance = new $controller(null,true);
    elseif ($controller === App\Controllers\BookingSubmissionController::class) $instance = new $controller(App\Core\BookingRuntimeFeature::submissionService());
    elseif ($controller === App\Controllers\OfficerBookingController::class) $instance = new $controller(
        App\Core\BookingRuntimeFeature::officerService(),
        in_array($action,['detail','update'],true) ? App\Core\BookingRuntimeFeature::officerEditService() : null,
        in_array($action,['index','delete'],true) ? App\Core\BookingRuntimeFeature::officerDeleteService() : null
    );
    else $instance = new $controller();
    if ($controller === App\Controllers\BookingCalendarController::class || $controller === App\Controllers\RequesterBookingController::class || $controller === App\Controllers\BookingSubmissionController::class || $controller === App\Controllers\OfficerBookingController::class || $controller === App\Controllers\BookingApprovalController::class || $controller === App\Controllers\AdminVehicleController::class || $controller === App\Controllers\SystemLogController::class || $controller === App\Controllers\FormPreviewController::class || $controller === App\Controllers\DriverScheduleController::class || $controller === App\Controllers\DriverAccountLinkController::class || $action === 'dashboard' || $controller === App\Controllers\HomeController::class || ($controller === App\Controllers\LoginController::class && $action === 'show') || ($controller === App\Controllers\AdminUserManagementController::class && in_array($action,['index','submit'],true))) $instance->$action($principal);
    else $instance->$action();
} catch (App\Core\AuthenticationRequiredException) {
    header('Location: /login', true, 303);
} catch (App\Core\AuthorizationException) {
    http_response_code(403);
    echo 'ไม่อนุญาตให้เข้าถึงรายการนี้';
} catch (Throwable $error) {
    $correlationId = App\Core\ErrorReporter::report($error);
    if (!headers_sent()) header('X-Request-ID: ' . $correlationId);
    http_response_code(500);
    echo 'ระบบไม่พร้อมใช้งานชั่วคราว กรุณาติดต่อผู้ดูแล โดยแจ้งรหัสอ้างอิง ' . e($correlationId);
}
