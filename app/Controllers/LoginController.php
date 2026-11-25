<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\View;
use App\Core\AuthFeature;
use App\Services\AuthServiceFactory;
use App\Core\AuthRuntime;

final class LoginController
{
    public function __construct(private readonly ?AuthRuntime $runtime = null) {}

    public function show(?\App\Models\AuthenticatedPrincipal $principal = null): void
    {
        if ($principal !== null) { header('Location: /', true, 303); return; }
        View::render('auth/login', $this->loginViewData());
    }

    public function login(): void
    {
        if (!AuthFeature::testExecutionEnabled()) {
            http_response_code(503);
            View::render('auth/login', $this->loginViewData());
            return;
        }
        $username = $_POST['username'] ?? null;
        $password = $_POST['password'] ?? null;
        if (!is_string($username) || !is_string($password)) {
            http_response_code(422);
            View::render('auth/login', $this->loginViewData(['error'=>'กรุณากรอกชื่อผู้ใช้หรือเบอร์โทรศัพท์ และรหัสผ่าน']));
            return;
        }
        try {
            $principal = ($this->runtime ?? AuthServiceFactory::createTestRuntime())->auth->login($username, $password, $this->clientAddress());
            $location = $principal->roles === ['driver'] ? '/driver/dashboard' : '/';
            header('Location: '.$location, true, 303);
        } catch (\App\Core\AuthenticationException) {
            http_response_code(401);
            View::render('auth/login', $this->loginViewData(['error'=>'ชื่อผู้ใช้ เบอร์โทรศัพท์ หรือรหัสผ่านไม่ถูกต้อง']));
        }
    }

    public function logout(): void
    {
        if (!AuthFeature::testExecutionEnabled()) { $this->unavailable(); return; }
        ($this->runtime ?? AuthServiceFactory::createTestRuntime())->auth->logout();
        header('Location: /', true, 303);
    }

    private function clientAddress(): string
    {
        $address = $_SERVER['REMOTE_ADDR'] ?? '';
        return is_string($address) ? $address : '';
    }

    private function unavailable(): void
    {
        http_response_code(503);
        View::render('auth/unavailable', [
            'pageTitle'=>'ยังไม่เปิดใช้การเข้าสู่ระบบ',
            'message'=>'การยืนยันตัวตนบนฐานทดสอบยังปิดอยู่ จึงไม่มีการเชื่อมต่อหรือบันทึกข้อมูลบัญชี',
        ]);
    }

    private function loginViewData(array $extra=[]): array
    {
        return $extra + ['testAuthEnabled'=>AuthFeature::testExecutionEnabled()];
    }
}
