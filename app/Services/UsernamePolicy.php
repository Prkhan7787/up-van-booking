<?php
declare(strict_types=1);
namespace App\Services;

final class UsernamePolicy
{
    public static function normalize(string $username): string
    {
        return strtolower(trim($username));
    }

    public static function isValid(string $username): bool
    {
        return preg_match('/\A[A-Za-z0-9_.-]{3,50}\z/D', trim($username)) === 1;
    }
}
