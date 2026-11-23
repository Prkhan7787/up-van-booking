<?php
declare(strict_types=1);
namespace App\Services;

final class PasswordHasher
{
    private static ?string $dummyHash = null;

    public function hash(string $password): string
    {
        if (!PasswordPolicy::isValidForSetting($password)) throw new \InvalidArgumentException('Password does not meet the minimum length.');
        $this->assertArgon2idAvailable();
        $hash = password_hash($password, PASSWORD_ARGON2ID);
        if (!is_string($hash) || password_get_info($hash)['algoName'] !== 'argon2id') {
            throw new \RuntimeException('Password hashing is unavailable.');
        }
        return $hash;
    }

    public function verify(string $password, string $hash): bool
    {
        $this->assertArgon2idAvailable();
        return password_verify($password, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        $this->assertArgon2idAvailable();
        return password_needs_rehash($hash, PASSWORD_ARGON2ID);
    }

    public function dummyHash(): string
    {
        return self::$dummyHash ??= $this->hash(bin2hex(random_bytes(32)));
    }

    private function assertArgon2idAvailable(): void
    {
        if (!defined('PASSWORD_ARGON2ID') || !in_array('argon2id', password_algos(), true)) {
            throw new \RuntimeException('Argon2id is unavailable in this PHP runtime.');
        }
    }
}
