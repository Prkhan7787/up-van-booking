<?php
declare(strict_types=1);
namespace App\Repositories;

/**
 * Persistence contract for a future authenticated Admin User Management service.
 * No implementation is registered in UI Foundation; these methods must never be
 * called until authentication, authorization, database approval and migrations exist.
 */
interface UserManagementRepository
{
    /** @return list<array<string, mixed>> */
    public function searchUsers(string $term, ?string $role, ?bool $active, int $limit, int $offset): array;

    /** Receives a password hash only, never a plaintext password. */
    public function createUser(string $username, string $email, string $displayName, string $passwordHash, string $role, int $actorId): int;

    public function changeRole(int $userId, string $role, int $actorId, string $reason): void;

    public function changeStatus(int $userId, bool $active, int $actorId, string $reason): void;

    /** Receives a password hash only; implementation must invalidate active sessions. */
    public function resetPassword(int $userId, string $passwordHash, int $actorId, string $reason): void;

    /** @return list<array<string, mixed>> */
    public function history(int $userId, int $limit, int $offset): array;
}
