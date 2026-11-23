<?php
declare(strict_types=1);
namespace App\Modules\Booking;
use App\Core\AuthorizationException;
use App\Models\AuthenticatedPrincipal;
/** Foundation only: no PDO implementation, HTTP mutation route, or production factory. */
final class BookingWorkflowService implements BookingWorkflow
{
    public function __construct(private readonly BookingRepository $bookings,private readonly BookingTransitionPolicy $policy) {}
    public function transition(AuthenticatedPrincipal $actor,int $id,int $expectedVersion,string $nextStatus): BookingState
    {
        if ($id<1 || $expectedVersion<1) throw new \InvalidArgumentException('Invalid booking identity/version.');
        return $this->bookings->transaction(fn():BookingState=>$this->transitionInTransaction($actor,$id,$expectedVersion,$nextStatus));
    }
    /** Caller owns the surrounding transaction; useful for atomic initial submission. */
    public function transitionInTransaction(AuthenticatedPrincipal $actor,int $id,int $expectedVersion,string $nextStatus): BookingState
    {
        if($id<1||$expectedVersion<1)throw new \InvalidArgumentException('Invalid booking identity/version.');
        $before=$this->bookings->lock($id);
        if($before===null)throw new AuthorizationException();
        $this->policy->assertTransition($actor,$before,$expectedVersion,$nextStatus);
        if($nextStatus==='pending_approval' && $this->bookings instanceof BookingSubmissionRepository){
            $row=$this->bookings->lockOfficerEditableRequest($id);
            if($row===null)throw new AuthorizationException();
            BookingRequestCompleteness::assertReadyForProposal($row);
        }
        $after=$before->next($nextStatus);
        if(!$this->bookings->save($before,$after))throw new \DomainException('BOOKING_VERSION_CONFLICT');
        $this->bookings->appendHistory($actor->userId,$before,$after);
        $this->bookings->audit('booking.status_changed',$actor->userId,$before,$after);
        return $after;
    }
}
