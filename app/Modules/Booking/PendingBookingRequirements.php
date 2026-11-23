<?php
declare(strict_types=1);
namespace App\Modules\Booking;
use App\Models\AuthenticatedPrincipal;
/** No business rule or delegated authority is fabricated while decisions are pending. */
final class PendingBookingRequirements implements BookingRequirements
{
    public function assertSatisfied(string $requirement,AuthenticatedPrincipal $actor,BookingState $booking,string $nextStatus): void
    {
        throw new \DomainException('BOOKING_REQUIREMENTS_PENDING');
    }
}
