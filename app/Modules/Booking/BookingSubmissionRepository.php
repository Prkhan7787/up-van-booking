<?php
declare(strict_types=1);
namespace App\Modules\Booking;

interface BookingSubmissionRepository extends BookingRepository
{
    /** Must lock the selected vehicle row within the active transaction. */
    public function lockVehicle(int $vehicleId): ?array;
    public function hasRequestConflict(int $vehicleId,string $startUtc,string $endUtc,?int $excludeBookingId=null): bool;
    public function hasAssignmentConflict(int $vehicleId,string $startUtc,string $endUtc): bool;
    public function insertDraft(int $ownerId,?int $vehicleId,string $requestNumber,array $fields): int;
    public function appendDraftHistory(int $bookingId,int $actorId): void;
    public function auditDraftCreated(int $bookingId,int $actorId): void;
    public function officerVehicles():array;
    public function officerList(int $limit,int $offset): array;
    public function officerDetail(int $id): ?array;
    public function officerEditSnapshot(int $id):?array;
    public function lockOfficerEditableRequest(int $id):?array;
    public function saveOfficerEdit(int $id,int $expectedVersion,int $actorId,?int $vehicleId,array $fields):bool;
    public function auditOfficerEdit(int $id,int $actorId,array $changedFields,int $fromVersion,int $toVersion):void;
}
