<?php
declare(strict_types=1);
namespace App\Services;

final class PasswordPolicy
{
    public const MIN_LENGTH = 6;

    /** Apply to account creation and password changes; do not block legacy account verification. */
    public static function isValidForSetting(string $password): bool
    {
        $length = function_exists('mb_strlen') ? mb_strlen($password, 'UTF-8') : strlen($password);
        return $length >= self::MIN_LENGTH;
    }
}
