<?php
declare(strict_types=1);
namespace App\Contracts;

interface MicrosoftIdentityRepository
{
    public function forUser(int $userId): ?array;
    public function forIdentity(string $tenantId, string $objectId): ?array;
    public function insert(int $userId, string $tenantId, string $objectId, int $actorId): void;
    public function remove(int $userId, string $tenantId, string $objectId): void;
}
