<?php
declare(strict_types=1);
namespace App\Modules\Booking;
use App\Models\AuthenticatedPrincipal;
interface BookingScope
{
    /** Must resolve server-owned officer/approver scope; never accept a posted scope or role. */
    public function allows(AuthenticatedPrincipal $actor,BookingState $booking,string $permission): bool;
}
