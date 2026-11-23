<?php
declare(strict_types=1);
namespace App\Modules\Booking;

interface BookingApprovalRepository
{
    public function transaction(callable $operation): mixed;
    public function lockVehicle(int $id): ?array;
    public function hasRequestConflict(int $id, string $start, string $end, ?int $excludeBookingId = null): bool;
    public function hasAssignmentConflict(int $id, string $start, string $end): bool;
    public function officerDetail(int $id): ?array;
    public function officerEditSnapshot(int $id): ?array;
    public function lockOfficerEditableRequest(int $id): ?array;
    public function appendHistory(int $actor, BookingState $before, BookingState $after): void;
    public function audit(string $event, int $actor, BookingState $before, BookingState $after): void;
    public function saveOfficerEdit(int $id, int $expectedVersion, int $actorId, ?int $vehicleId, array $fields): bool;
    public function auditOfficerEdit(int $id, int $actorId, array $changedFields, int $fromVersion, int $toVersion): void;
    public function approvalList(int $limit, int $offset): array;
    public function recordProposal(int $id, int $actorId): void;
    public function ownedList(int $owner, int $limit, int $offset): array;
    public function ownedDetail(int $id, int $owner): ?array;
    public function decisionOutcome(int $id): ?string;
    public function publishNotification(int $id, int $actorId, string $outcome): bool;
    public function recordDecision(int $id, int $actorId, array $authority, string $outcome, ?string $reason): void;
    public function saveApprovalState(BookingState $before, BookingState $after, int $actorId, ?string $snapshot = null): bool;
    public function saveSection3(int $id, int $version, int $actorId, string $snapshot): bool;
    public function auditSection3(int $id, int $actorId, int $version): void;
}
