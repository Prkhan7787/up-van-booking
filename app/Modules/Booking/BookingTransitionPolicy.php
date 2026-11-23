<?php
declare(strict_types=1);
namespace App\Modules\Booking;
use App\Core\AuthorizationException;
use App\Models\AuthenticatedPrincipal;
use App\Services\AuthorizationPolicy;

final class BookingTransitionPolicy
{
    private const EDGES=[
        'draft:submitted'=>['booking.submit_own','submission'],
        'submitted:under_review'=>['booking.review','review'],
        'under_review:pending_approval'=>['booking.propose','proposal'],
        'pending_approval:awaiting_assignment'=>['booking.decision','decision'],
        'pending_approval:awaiting_rejection_notice'=>['booking.decision','decision'],
        'awaiting_assignment:assigned'=>['assignment.create','assignment'],
        'awaiting_rejection_notice:closed_rejected'=>['booking.receive_decision','rejection_notice'],
        'assigned:completed'=>['assignment.create','completion'],
        'awaiting_assignment:cancelled'=>['assignment.create','cancellation'],
        'assigned:cancelled'=>['assignment.create','cancellation'],
    ];
    public function __construct(private readonly BookingScope $scope,private readonly BookingRequirements $requirements) {}
    public function assertDraftCreate(AuthenticatedPrincipal $actor): void
    {
        if (!(new AuthorizationPolicy())->allows($actor->roles,'booking.draft.create')) throw new AuthorizationException();
    }
    public function assertDraftEdit(AuthenticatedPrincipal $actor,BookingState $booking,int $expectedVersion): void
    {
        if (!(new AuthorizationPolicy())->allows($actor->roles,'booking.draft.update_own') || $actor->userId!==$booking->ownerId || $booking->status!=='draft') throw new AuthorizationException();
        if ($expectedVersion!==$booking->version) throw new \DomainException('BOOKING_VERSION_CONFLICT');
    }
    public function canRead(AuthenticatedPrincipal $actor,BookingState $booking): bool
    {
        $policy=new AuthorizationPolicy();
        if ($policy->allows($actor->roles,'booking.view_all')) return true;
        if ($actor->userId===$booking->ownerId && $policy->allows($actor->roles,'booking.view_own')) return true;
        foreach (['booking.review','booking.pending.view'] as $permission) if ($policy->allows($actor->roles,$permission) && $this->scope->allows($actor,$booking,$permission)) return true;
        return false;
    }
    public function assertTransition(AuthenticatedPrincipal $actor,BookingState $booking,int $expectedVersion,string $nextStatus): void
    {
        $edge=self::EDGES[$booking->status.':'.$nextStatus] ?? null;
        if ($edge===null || !(new AuthorizationPolicy())->allows($actor->roles,$edge[0])) throw new AuthorizationException();
        if ($edge[0]==='booking.submit_own') {
            if ($actor->userId!==$booking->ownerId) throw new AuthorizationException();
        } elseif ($edge[0]==='booking.decision' && $actor->userId===$booking->ownerId) {
            throw new AuthorizationException();
        } elseif (!$this->scope->allows($actor,$booking,$edge[0])) throw new AuthorizationException();
        if ($expectedVersion!==$booking->version) throw new \DomainException('BOOKING_VERSION_CONFLICT');
        $this->requirements->assertSatisfied($edge[1],$actor,$booking,$nextStatus);
    }
}
