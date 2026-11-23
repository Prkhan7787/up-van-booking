<?php
declare(strict_types=1);
namespace App\Core;

final class SessionCookiePolicy
{
    /** @param array<string, mixed> $server */
    public static function params(array $server, bool $secureOverride = false): array
    {
        $https = strtolower((string)($server['HTTPS'] ?? ''));
        $scheme = strtolower((string)($server['REQUEST_SCHEME'] ?? ''));
        $isHttps = in_array($https, ['on', '1', 'true'], true) || $scheme === 'https';
        return [
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => $secureOverride || $isHttps,
            'httponly' => true,
            'samesite' => 'Lax',
        ];
    }
}
