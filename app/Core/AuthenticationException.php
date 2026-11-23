<?php
declare(strict_types=1);
namespace App\Core;

final class AuthenticationException extends \RuntimeException
{
    public static function invalidCredentials(): self
    {
        return new self('ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง');
    }
}
