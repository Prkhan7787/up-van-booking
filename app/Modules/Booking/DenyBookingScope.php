<?php
declare(strict_types=1);
namespace App\Modules\Booking;

use App\Models\AuthenticatedPrincipal;

/** Deny all scoped officer and approver reads/transitions unless a module provides an explicit scope. */
final class DenyBookingScope implements BookingScope
{
    public function allows(AuthenticatedPrincipal $actor,BookingState $booking,string $permission): bool {return false;}
}
