<?php
declare(strict_types=1);
namespace App\Modules\Booking;

interface OfficerBookingDeletionRepository
{
    public function transaction(callable $operation): mixed;
    public function officerEditSnapshot(int $id): ?array;
    public function lockVehicle(int $vehicleId): ?array;
    public function lockOfficerEditableRequest(int $id): ?array;
    public function hasBlockingBookingAssignment(int $id): bool;
    public function markOfficerDeleted(int $id, int $version, int $actorId): bool;
    public function auditOfficerDeleted(int $id, int $actorId, string $status, int $version): void;
}
