<?php
declare(strict_types=1);
namespace App\Modules\Booking;

interface RequesterDraftRepository
{
    public function transaction(callable $operation): mixed;
    public function listOwn(int $ownerId,int $limit,int $offset): array;
    public function listAll(int $limit,int $offset): array;
    public function find(int $id): ?array;
    /** Restrict requester detail/document reads in SQL, before fetching personal data. */
    public function findOwned(int $id,int $ownerId): ?array;
    public function lock(int $id): ?array;
    public function insert(int $ownerId,array $fields): int;
    public function update(int $id,int $ownerId,int $expectedVersion,array $fields): bool;
    public function appendDraftHistory(int $id,int $actorId,int $version): void;
    public function auditDraft(string $action,int $id,int $actorId,int $version): void;
}
