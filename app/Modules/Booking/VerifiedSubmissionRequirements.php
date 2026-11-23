<?php
declare(strict_types=1);
namespace App\Modules\Booking;

use App\Models\AuthenticatedPrincipal;
use App\Services\AuthorizationPolicy;

/** Submission-specific checks; the generic PendingBookingRequirements stays fail-closed for other edges. */
final class VerifiedSubmissionRequirements implements BookingRequirements
{
    public function assertSatisfied(string $requirement,AuthenticatedPrincipal $actor,BookingState $booking,string $nextStatus): void
    {
        if($requirement!=='submission'||$nextStatus!=='submitted'||$booking->status!=='draft'||$booking->version!==1
            ||$booking->ownerId!==$actor->userId||!(new AuthorizationPolicy())->allows($actor->roles,'booking.submit_own')){
            throw new \DomainException('BOOKING_REQUIREMENTS_PENDING');
        }
    }
}
