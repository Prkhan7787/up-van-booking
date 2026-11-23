<?php
declare(strict_types=1);
namespace App\Services;

use App\Contracts\DriverRegistrationRepository;
use App\Contracts\LoginRateLimitStore;
use App\Core\DriverRegistrationInputException;
use PDOException;

final class DriverRegistrationService
{
    private readonly LoginRateLimiter $rateLimiter;

    public function __construct(
        private readonly DriverRegistrationRepository $accounts,
        LoginRateLimitStore $rateLimitStore,
        string $hmacSecret,
        private readonly PasswordHasher $hasher,
    ) {
        $this->rateLimiter = new LoginRateLimiter($rateLimitStore, $hmacSecret, namespace: 'driver-registration');
    }

    /** Returns true only when a new account was created; false is deliberately generic for duplicate/rate-limited identifiers. */
    public function register(array $input, string $sourceAddress): bool
    {
        $errors = [];
        $realName = $this->text($input['real_name'] ?? null, 'real_name', 150, $errors);
        $nickname = $this->text($input['nickname'] ?? null, 'nickname', 80, $errors);
        $phoneInput = $input['phone'] ?? null;
        $phone = is_string($phoneInput) ? PhoneNumberPolicy::normalize($phoneInput) : null;
        if ($phone === null) $errors['phone'] = 'กรุณากรอกเบอร์โทรศัพท์มือถือให้ถูกต้อง';
        $password = $input['password'] ?? null;
        $confirmation = $input['password_confirmation'] ?? null;
        if (!is_string($password) || !PasswordPolicy::isValidForSetting($password) || strlen($password) > 1024) {
            $errors['password'] = 'รหัสผ่านต้องมีอย่างน้อย 6 ตัวอักษรและไม่เกิน 1,024 ไบต์';
        }
        if (!is_string($password) || !is_string($confirmation) || !hash_equals($password, $confirmation)) {
            $errors['password_confirmation'] = 'กรุณายืนยันรหัสผ่านให้ตรงกัน';
        }
        $result = $this->rateLimiter->attempt($phone ?? 'invalid-phone', $sourceAddress, function () use ($phone, $realName, $nickname, $password, $errors): bool {
            if ($errors !== []) return false;
            try {
                $this->accounts->createDriverAccount($phone, $realName, $nickname, $this->hasher->hash($password));
                return true;
            } catch (PDOException $error) {
                if ((int)($error->errorInfo[1] ?? 0) === 1062) return false;
                throw $error;
            }
        });

        if ($result->allowed && $errors !== []) throw new DriverRegistrationInputException($errors);
        return $result->allowed && $result->authenticated;
    }

    /** @param array<string,string> $errors */
    private function text(mixed $value, string $field, int $maxLength, array &$errors): string
    {
        if (!is_string($value)) {
            $errors[$field] = 'กรุณากรอกข้อมูล';
            return '';
        }
        $value = trim($value);
        $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
        if ($value === '' || $length > $maxLength || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            $errors[$field] = 'ข้อมูลไม่ถูกต้อง';
            return '';
        }
        return $value;
    }
}
