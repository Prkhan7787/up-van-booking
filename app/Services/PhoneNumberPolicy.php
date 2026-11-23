<?php
declare(strict_types=1);
namespace App\Services;

final class PhoneNumberPolicy
{
    /** Normalize supported Thai mobile input to a ten-digit local identity. */
    public static function normalize(string $phone): ?string
    {
        $digits = preg_replace('/[\s().-]+/u', '', trim($phone));
        if (!is_string($digits)) return null;
        if (preg_match('/\A\+?66([0-9]{9})\z/D', $digits, $match) === 1) $digits = '0' . $match[1];
        return preg_match('/\A0[0-9]{9}\z/D', $digits) === 1 ? $digits : null;
    }
}
