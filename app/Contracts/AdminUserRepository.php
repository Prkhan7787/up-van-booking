<?php
declare(strict_types=1);
namespace App\Contracts;

interface AdminUserRepository
{
    public function listUsers(string $search, string $role, int $page): array;
    public function findUser(int $id): ?array;
    public function transaction(callable $operation): mixed;
    public function lockUsers(array $ids): void;
    public function activeAdminCount(): int;
    public function createUser(string $username, string $email, string $displayName, string $hash): int;
    public function setRoles(int $id, array $roles): void;
    public function setStatus(int $id, bool $active): void;
    public function bumpAuthVersion(int $id): void;
    public function setPasswordHash(int $id, string $hash): void;
    public function audit(int $actor, string $action, int $target, array $metadata): void;
}
