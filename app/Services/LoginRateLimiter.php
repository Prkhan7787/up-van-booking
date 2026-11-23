<?php
declare(strict_types=1);
namespace App\Services;

use App\Contracts\LoginRateLimitStore;
use App\Contracts\LoginRateLimitResult;

final class LoginRateLimiter
{
    public const WINDOW_SECONDS = 900;
    public const USERNAME_LIMIT = 5;
    public const SOURCE_LIMIT = 20;

    public function __construct(
        private readonly LoginRateLimitStore $store,
        private readonly string $hmacSecret,
        private readonly int $windowSeconds = self::WINDOW_SECONDS,
        private readonly int $usernameLimit = self::USERNAME_LIMIT,
        private readonly int $sourceLimit = self::SOURCE_LIMIT,
    ) {
        if (strlen($hmacSecret) < 32 || $windowSeconds < 1 || $usernameLimit < 1 || $sourceLimit < 1) {
            throw new \InvalidArgumentException('Login rate-limit policy is incomplete.');
        }
    }

    public function attempt(string $username, string $sourceAddress, callable $authenticate, ?int $now = null): LoginRateLimitResult
    {
        if (filter_var($sourceAddress, FILTER_VALIDATE_IP) === false) return LoginRateLimitResult::limited();
        $now ??= time();
        $normalized = self::normalizeIdentifier($username);
        return $this->store->attempt([
            ['scope'=>'identifier', 'digest'=>$this->digest('identifier', $normalized), 'limit'=>$this->usernameLimit],
            ['scope'=>'source', 'digest'=>$this->digest('source', $sourceAddress), 'limit'=>$this->sourceLimit],
        ], $now, $this->windowSeconds, $authenticate);
    }

    private function digest(string $scope, string $value): string
    {
        return hash_hmac('sha256', $scope . ':' . $value, $this->hmacSecret, true);
    }

    public static function normalizeIdentifier(string $identifier): string
    {
        $identifier = trim($identifier);
        return function_exists('mb_strtolower') ? mb_strtolower($identifier, 'UTF-8') : strtolower($identifier);
    }
}
