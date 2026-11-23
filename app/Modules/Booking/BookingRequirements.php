<?php
declare(strict_types=1);
namespace App\Modules\Booking;
use App\Models\AuthenticatedPrincipal;
interface BookingRequirements
{
    /** Called inside the transaction after locking. Implementations must read trusted persisted facts.
     * submission: agreed required fields, attachments, approved business-day policy;
     * proposal: reviewed document, proposed resources (separate from actual assignment);
     * decision: verified director/delegate authority, rejection reason;
     * assignment: approved decision, approved resource locks/availability and overlap recheck;
     * rejection_notice: confirmed notification; completion: actual operational evidence;
     * cancellation: agreed owner-contact/cancellation policy and resource release.
     */
    public function assertSatisfied(string $requirement,AuthenticatedPrincipal $actor,BookingState $booking,string $nextStatus): void;
}
