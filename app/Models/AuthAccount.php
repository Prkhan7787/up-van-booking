<?php
declare(strict_types=1);
namespace App\Models;

use App\Services\PasswordHasher;

final class AuthAccount
{
    public function __construct(
        public readonly int $id,
        public readonly bool $active,
        private readonly ?string $passwordHash,
    ) {
        if ($id < 1) throw new \InvalidArgumentException('Invalid account identity.');
    }

    public function verify(string $password, PasswordHasher $hasher, string $dummyHash): bool
    {
        return $hasher->verify($password, $this->passwordHash ?? $dummyHash);
    }

    public function requiresRehash(PasswordHasher $hasher): bool
    {
        return $this->passwordHash !== null && $hasher->needsRehash($this->passwordHash);
    }

    public function safeDebugInfo(): array
    {
        return ['id' => $this->id, 'active' => $this->active];
    }

    public function __debugInfo(): array
    {
        return $this->safeDebugInfo();
    }

    public function __serialize(): array
    {
        throw new \LogicException('Authentication account objects cannot be serialized.');
    }
}
