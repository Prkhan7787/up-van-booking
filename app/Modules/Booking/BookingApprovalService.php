<?php
declare(strict_types=1);
namespace App\Modules\Booking;

use App\Core\AuthorizationException;
use App\Models\AuthenticatedPrincipal;
use App\Services\AuthorizationPolicy;

final class BookingApprovalService
{
    public const RESULT_STATUSES = ['awaiting_assignment','assigned','completed','awaiting_rejection_notice','closed_rejected'];
    public const EDITABLE = ['submitted', 'under_review'];
    public const OFFICER_VISIBLE = ['submitted', 'under_review', 'pending_approval', 'awaiting_assignment', 'awaiting_rejection_notice', 'assigned', 'completed', 'closed_rejected', 'cancelled'];
    private const SECTION3 = ['approval_outcome', 'rejection_reason', 'rejection_reason_continued', 'approver_printed_name', 'approver_position', 'decision_day', 'decision_month', 'decision_year'];

    /** Authority is provisioned on the server by user ID and never taken from form data. */
    public function __construct(private readonly BookingApprovalRepository $repo, private readonly array $authorities = []) {}

    public function canDecide(AuthenticatedPrincipal $actor, array $row): bool
    {
        return $row['workflow_status'] === 'pending_approval'
            && (int)$row['requester_id'] !== $actor->userId && $this->authority($actor) !== null;
    }

    /** Read-only explanation of existing decision guards; never grants access. */
    public function decisionDiagnostic(AuthenticatedPrincipal $actor, array $row): array
    {
        $permission = (new AuthorizationPolicy())->allows($actor->roles, 'booking.decision');
        $authority = $this->authority($actor) !== null;
        $pending = ($row['workflow_status'] ?? null) === 'pending_approval';
        $owner = (int)($row['requester_id'] ?? 0) === $actor->userId;
        $reasons = [];
        if (!$permission) $reasons[] = 'permission_denied';
        if ($permission && !$authority) $reasons[] = 'decision_authority_missing_or_invalid';
        if (!$pending) $reasons[] = 'booking_status_not_pending_approval';
        if ($owner) $reasons[] = 'actor_is_booking_owner';
        return [
            'user_id'=>$actor->userId,
            'roles'=>array_values($actor->roles),
            'booking_decision'=>$permission,
            'authority'=>$authority,
            'booking_status'=>(string)($row['workflow_status'] ?? ''),
            'is_booking_owner'=>$owner,
            'can_decide'=>$this->canDecide($actor,$row),
            'reasons'=>$reasons,
        ];
    }

    private function authority(AuthenticatedPrincipal $actor): ?array
    {
        if (!(new AuthorizationPolicy())->allows($actor->roles, 'booking.decision')) return null;
        // A dual-role approver keeps their named authority when it is valid.
        if (in_array('approver', $actor->roles, true)) {
            $named = $this->namedAuthority($actor);
            if ($named !== null) return $named;
        }
        // The standing delegation follows the admin role and is recorded with each decision.
        return in_array('admin', $actor->roles, true)
            ? ['kind'=>'delegate', 'reference'=>'UP Van Booking policy: role admin']
            : null;
    }

    private function namedAuthority(AuthenticatedPrincipal $actor): ?array
    {
        $authority = $this->authorities[$actor->userId] ?? null;
        if (!is_array($authority)) return null;
        $kind = $authority['kind'] ?? null;
        $reference = $authority['reference'] ?? null;
        if ($kind === 'director') return ['kind'=>'director', 'reference'=>null];
        if ($kind !== 'delegate' || !is_string($reference) || trim($reference) === ''
            || preg_match('/\A[^\p{C}]{1,255}\z/u', $reference) !== 1) return null;
        return ['kind'=>'delegate', 'reference'=>trim($reference)];
    }

    public function pendingList(AuthenticatedPrincipal $actor, int $page): array
    {
        $this->assertCanView($actor);
        if ($page < 1 || $page > 10000) throw new AuthorizationException();
        return $this->repo->approvalList(26, ($page - 1) * 25);
    }

    public function pendingDetail(AuthenticatedPrincipal $actor, int $id): array
    {
        $this->assertCanView($actor);
        $row = $this->repo->officerDetail($id);
        if (!$row || $row['workflow_status'] !== 'pending_approval') throw new AuthorizationException();
        return $row;
    }

    private function assertCanView(AuthenticatedPrincipal $actor): void
    {
        // Queue visibility follows its own role permission. Decision Authority is
        // checked separately by canDecide()/decide() and must not block read-only access.
        if (!(new AuthorizationPolicy())->allows($actor->roles, 'booking.director_queue.view')) {
            throw new AuthorizationException();
        }
    }

    public function forward(AuthenticatedPrincipal $actor, int $id, int $version, array $post): void
    {
        $this->requirePermission($actor, 'booking.officer_request.forward');
        $this->repo->transaction(function () use ($actor, $id, $version, $post): void {
            $edit = BookingSubmissionService::editInTransaction($this->repo, $actor, $id, $version, $post, true);
            $row = $this->lockedRow($id, $edit['version'], self::EDITABLE);
            $state = $this->state($row);
            if ($state->status === 'submitted') $state = $this->transition($actor, $state, 'under_review');
            $this->repo->recordProposal($id, $actor->userId);
            $this->transition($actor, $state, 'pending_approval');
        });
    }

    public function recall(AuthenticatedPrincipal $actor, int $id, int $version): void
    {
        $this->requirePermission($actor, 'booking.officer_request.recall');
        $this->repo->transaction(function () use ($actor, $id, $version): void {
            // Match decision's vehicle-then-request lock order, then recheck the version and status.
            $row = $this->lockScheduled($id, $version, ['pending_approval']);
            $snapshot = $row['a4_request_data'] ?? null;
            $cleanSnapshot = A4RequestSnapshot::withoutDecision($snapshot);
            $this->transition($actor, $this->state($row), 'under_review',
                $cleanSnapshot !== $snapshot ? $cleanSnapshot : null, 'booking.forward_recalled');
        });
    }

    public function notifyRequester(AuthenticatedPrincipal $actor, int $id, int $version): void
    {
        $this->requirePermission($actor, 'booking.officer_request.notify');
        $this->repo->transaction(function () use ($actor,$id,$version): void {
            $row = $this->lockedRow($id,$version,self::RESULT_STATUSES);
            $outcome = $this->repo->decisionOutcome($id);
            $expected = in_array($row['workflow_status'],['awaiting_rejection_notice','closed_rejected'],true) ? 'rejected' : 'approved';
            if ($outcome !== $expected) throw new \DomainException('BOOKING_NOTIFICATION_NO_DECISION');
            if (!$this->repo->publishNotification($id,$actor->userId,$outcome)) {
                throw new \DomainException('BOOKING_NOTIFICATION_ALREADY_PUBLISHED');
            }
            $before = $this->state($row);
            $after = $before->next($before->status);
            if (!$this->repo->saveApprovalState($before,$after,$actor->userId)) throw new \DomainException('BOOKING_VERSION_CONFLICT');
            $this->repo->audit('booking.requester_notified',$actor->userId,$before,$after);
        });
    }

    public function ownedList(AuthenticatedPrincipal $actor, int $page): array
    {
        $this->requirePermission($actor,'workspace.view');
        return $this->repo->ownedList($actor->userId,26,($page-1)*25);
    }

    public function ownedDetail(AuthenticatedPrincipal $actor, int $id): array
    {
        $this->requirePermission($actor,'workspace.view');
        $row = $this->repo->ownedDetail($id,$actor->userId);
        if ($row === null || (int)$row['requester_id'] !== $actor->userId) throw new AuthorizationException();
        return $row;
    }

    public function saveAdminDraft(AuthenticatedPrincipal $actor, int $id, int $version, array $post): void
    {
        $this->requirePermission($actor, 'booking.section3_draft.edit');
        $fields = self::section3($post);
        $this->repo->transaction(function () use ($actor, $id, $version, $fields): void {
            $row = $this->lockedRow($id, $version, [...self::EDITABLE, 'pending_approval']);
            $this->storeSection3($row, $actor, 'section3_draft', $fields);
            $this->repo->auditSection3($id, $actor->userId, $version);
        });
    }

    public function decide(AuthenticatedPrincipal $actor, int $id, int $version, array $post): void
    {
        $authority = $this->authority($actor);
        if ($authority === null) throw new AuthorizationException();
        $fields = self::section3($post);
        $outcome = $fields['approval_outcome'];
        $reason = trim($fields['rejection_reason']."\n".$fields['rejection_reason_continued']);
        if (!in_array($outcome, ['approved', 'rejected'], true)) {
            throw new \InvalidArgumentException('BOOKING_DECISION_INVALID');
        }
        $this->repo->transaction(function () use ($actor, $id, $version, $authority, $fields, $outcome, $reason): void {
            $row = $this->lockScheduled($id, $version, ['pending_approval']);
            if (!$this->canDecide($actor, $row)) throw new AuthorizationException();
            if ($outcome === 'approved' && BookingRequestCompleteness::hasSchedule($row)) {
                $this->assertNoConflict($row);
            }
            $this->repo->recordDecision($id, $actor->userId, $authority, $outcome, $outcome === 'rejected' && $reason !== '' ? $reason : null);
            $snapshot = A4RequestSnapshot::withSection3($row['a4_request_data'] ?? null, 'section3_decision', $fields);
            $this->transition($actor, $this->state($row), $outcome === 'approved' ? 'awaiting_assignment' : 'awaiting_rejection_notice', $snapshot);
        });
    }

    private function lockScheduled(int $id, int $version, array $statuses): array
    {
        $before = $this->repo->officerEditSnapshot($id);
        if (!$before || (int)$before['version'] !== $version || !in_array($before['workflow_status'], $statuses, true)) {
            throw new \DomainException('BOOKING_VERSION_CONFLICT');
        }
        $vehicleId = (int)($before['requested_vehicle_id'] ?? 0);
        $vehicle = $vehicleId > 0 ? $this->repo->lockVehicle($vehicleId) : null;
        $row = $this->lockedRow($id, $version, $statuses);
        if ((int)($row['requested_vehicle_id'] ?? 0) !== $vehicleId) throw new \DomainException('BOOKING_VERSION_CONFLICT');
        return $row + ['locked_vehicle'=>$vehicle];
    }

    private function lockedRow(int $id, int $version, array $statuses): array
    {
        $row = $this->repo->lockOfficerEditableRequest($id);
        if (!$row || (int)$row['version'] !== $version || !in_array($row['workflow_status'], $statuses, true)) {
            throw new \DomainException('BOOKING_VERSION_CONFLICT');
        }
        return $row;
    }

    private function assertNoConflict(array $row): void
    {
        $vehicle = $row['locked_vehicle'];
        if (empty($vehicle['is_active'])) throw new \DomainException('BOOKING_VEHICLE_UNAVAILABLE');
        if ((int)$vehicle['passenger_capacity'] < (int)($row['passenger_count'] ?? 0)) throw new \DomainException('BOOKING_VEHICLE_CAPACITY');
        $vehicleId = (int)$row['requested_vehicle_id'];
        $start = $row['requested_start_at'];
        $end = $row['requested_end_at'];
        if ($this->repo->hasRequestConflict($vehicleId, $start, $end, (int)$row['id'])
            || $this->repo->hasAssignmentConflict($vehicleId, $start, $end)) throw new \DomainException('BOOKING_WINDOW_CONFLICT');
    }

    private function transition(AuthenticatedPrincipal $actor, BookingState $before, string $status, ?string $snapshot = null, string $event = 'booking.status_changed'): BookingState
    {
        $after = $before->next($status);
        if (!$this->repo->saveApprovalState($before, $after, $actor->userId, $snapshot)) throw new \DomainException('BOOKING_VERSION_CONFLICT');
        $this->repo->appendHistory($actor->userId, $before, $after);
        $this->repo->audit($event, $actor->userId, $before, $after);
        return $after;
    }

    private function state(array $row): BookingState
    {
        return new BookingState((int)$row['id'], (int)$row['requester_id'], $row['workflow_status'], (int)$row['version']);
    }

    private function storeSection3(array $row, AuthenticatedPrincipal $actor, string $key, array $value): void
    {
        $snapshot = A4RequestSnapshot::withSection3($row['a4_request_data'] ?? null, $key, $value);
        if (!$this->repo->saveSection3((int)$row['id'], (int)$row['version'], $actor->userId, $snapshot)) {
            throw new \DomainException('BOOKING_VERSION_CONFLICT');
        }
    }

    public static function section3(array $post, bool $documentDraft = false): array
    {
        $fields = [];
        foreach (self::SECTION3 as $key) {
            $value = $post[$key] ?? '';
            if ($documentDraft ? !A4RequestSnapshot::validDocumentText($value) : (!is_string($value) || preg_match('/\A[^\p{C}]{0,1000}\z/u', $value) !== 1)) {
                throw new \InvalidArgumentException('BOOKING_DECISION_INVALID');
            }
            $fields[$key] = $documentDraft ? $value : trim($value);
        }
        if (!$documentDraft && !in_array($fields['approval_outcome'], ['', 'approved', 'rejected'], true)) {
            throw new \InvalidArgumentException('BOOKING_DECISION_INVALID');
        }
        return $fields;
    }

    private function requirePermission(AuthenticatedPrincipal $actor, string $permission): void
    {
        if (!(new AuthorizationPolicy())->allows($actor->roles, $permission)) throw new AuthorizationException();
    }
}
