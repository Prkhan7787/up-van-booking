<?php
declare(strict_types=1);
namespace App\Modules\Booking;

use App\Core\AuthorizationException;
use App\Models\AuthenticatedPrincipal;
use App\Services\AuthorizationPolicy;
use DomainException;

final class OfficerBookingDeletionService
{
    public function __construct(private readonly OfficerBookingDeletionRepository $repository) {}

    public function delete(AuthenticatedPrincipal $actor, int $id, int $version): void
    {
        if (!(new AuthorizationPolicy())->allows($actor->roles, 'booking.officer_request.delete')) {
            throw new AuthorizationException();
        }
        $this->repository->transaction(function () use ($actor, $id, $version): void {
            $snapshot = $this->repository->officerEditSnapshot($id);
            if ($snapshot === null) throw new DomainException('BOOKING_DELETE_CONFLICT');
            $vehicle = (int)($snapshot['requested_vehicle_id'] ?? 0);
            if ($vehicle > 0 && $this->repository->lockVehicle($vehicle) === null) {
                throw new DomainException('BOOKING_DELETE_CONFLICT');
            }
            $row = $this->repository->lockOfficerEditableRequest($id);
            if ($row === null || (int)$row['version'] !== $version
                || (int)($row['requested_vehicle_id'] ?? 0) !== $vehicle
                || !in_array($row['workflow_status'], ['submitted', 'under_review'], true)
                || $this->repository->hasBlockingBookingAssignment($id)
                || !$this->repository->markOfficerDeleted($id, $version, $actor->userId)) {
                throw new DomainException('BOOKING_DELETE_CONFLICT');
            }
            $this->repository->auditOfficerDeleted($id, $actor->userId, $row['workflow_status'], $version);
        });
    }
}
