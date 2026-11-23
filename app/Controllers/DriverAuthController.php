<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\AuthFeature;
use App\Core\Csrf;
use App\Core\DriverRegistrationInputException;
use App\Core\View;
use App\Services\AuthServiceFactory;
use App\Services\DriverFeatureFactory;
use App\Services\PhoneNumberPolicy;

final class DriverAuthController
{
    public function registerForm(): void
    {
        View::render('auth/driver-register', ['enabled'=>AuthFeature::testExecutionEnabled(),'errors'=>[],'success'=>false]);
    }

    public function register(): void
    {
        if (!AuthFeature::testExecutionEnabled()) { $this->unavailable(); return; }
        if (!Csrf::valid($_POST['_token'] ?? null)) { http_response_code(419); echo 'คำขอหมดอายุ กรุณาโหลดหน้าใหม่'; return; }
        try {
            DriverFeatureFactory::registration()->register($_POST, $this->clientAddress());
            // Same response for a duplicate identifier and a successful registration.
            View::render('auth/driver-register', ['enabled'=>true,'errors'=>[],'success'=>true]);
        } catch (DriverRegistrationInputException $error) {
            http_response_code(422);
            View::render('auth/driver-register', ['enabled'=>true,'errors'=>$error->errors,'success'=>false,'old'=>self::safeOldValues($_POST)]);
        } catch (\Throwable) {
            http_response_code(503);
            View::render('auth/driver-register', ['enabled'=>true,'errors'=>[],'success'=>false,'unavailable'=>true]);
        }
    }

    public function login(): void
    {
        if (!AuthFeature::testExecutionEnabled()) { $this->unavailable(); return; }
        if (!Csrf::valid($_POST['_token'] ?? null)) { http_response_code(419); echo 'คำขอหมดอายุ กรุณาโหลดหน้าใหม่'; return; }
        $phone = $_POST['phone'] ?? null;
        $password = $_POST['password'] ?? null;
        if (!is_string($phone) || !is_string($password)) { $this->loginFailed(); return; }
        // Invalid phone input uses an invalid identifier so the existing AuthService performs its dummy hash and rate-limit path without account lookup.
        $identifier = PhoneNumberPolicy::normalize($phone) ?? '+';
        try {
            AuthServiceFactory::createTestRuntime(driver: true)->auth->login($identifier, $password, $this->clientAddress(), 'driver');
            header('Location: /driver/dashboard', true, 303);
        } catch (\App\Core\AuthenticationException) {
            $this->loginFailed();
        } catch (\Throwable) {
            http_response_code(503);
            View::render('auth/unavailable', ['pageTitle'=>'ยังไม่เปิดใช้การเข้าสู่ระบบ','message'=>'การเข้าสู่ระบบพนักงานขับรถยังไม่พร้อมใช้งาน กรุณาติดต่อผู้ดูแล']);
        }
    }

    private function loginFailed(): void
    {
        http_response_code(401);
        View::render('auth/login', ['testAuthEnabled'=>true,'error'=>'เบอร์โทรศัพท์หรือรหัสผ่านไม่ถูกต้อง']);
    }

    private function unavailable(): void
    {
        http_response_code(503);
        View::render('auth/unavailable', ['pageTitle'=>'ยังไม่เปิดใช้การเข้าสู่ระบบ','message'=>'การยืนยันตัวตนบนฐานทดสอบยังปิดอยู่ จึงไม่มีการเชื่อมต่อหรือบันทึกข้อมูลบัญชี']);
    }

    private function clientAddress(): string
    {
        $address = $_SERVER['REMOTE_ADDR'] ?? '';
        return is_string($address) ? $address : '';
    }

    private static function safeOldValues(array $input): array
    {
        return [
            'real_name'=>is_string($input['real_name'] ?? null) ? mb_substr($input['real_name'], 0, 150, 'UTF-8') : '',
            'nickname'=>is_string($input['nickname'] ?? null) ? mb_substr($input['nickname'], 0, 80, 'UTF-8') : '',
            'phone'=>is_string($input['phone'] ?? null) ? mb_substr($input['phone'], 0, 20, 'UTF-8') : '',
        ];
    }
}
