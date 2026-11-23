<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/cli.php';
require dirname(__DIR__).'/app/helpers.php';

use App\Controllers\BookingApprovalController;
use App\Core\AuthorizationException;
use App\Core\BookingRuntimeFeature;
use App\Models\AuthenticatedPrincipal;
use App\Modules\Booking\{A4RequestSnapshot,BookingApprovalRepository,BookingApprovalService,BookingState,RequesterA4Presenter};

final class ApprovalFixture implements BookingApprovalRepository
{
    public array $rows = [], $reviews = [], $decisions = [], $history = [], $audits = [], $locks = [], $notifications = [];
    public bool $conflict = false, $auditFailure = false, $vehicleActive = true;

    public function transaction(callable $operation): mixed
    {
        $before = [$this->rows,$this->reviews,$this->decisions,$this->history,$this->audits,$this->notifications];
        try { return $operation(); }
        catch (Throwable $error) {
            [$this->rows,$this->reviews,$this->decisions,$this->history,$this->audits,$this->notifications] = $before;
            throw $error;
        }
    }
    public function lockVehicle(int $id): ?array
    {
        $this->locks[] = 'vehicle';
        return ['id'=>$id,'is_active'=>$this->vehicleActive,'passenger_capacity'=>12];
    }
    public function hasRequestConflict(int $id, string $start, string $end, ?int $excludeBookingId = null): bool { return $this->conflict; }
    public function hasAssignmentConflict(int $id, string $start, string $end): bool { return false; }
    public function officerDetail(int $id): ?array { return $this->rows[$id] ?? null; }
    public function officerEditSnapshot(int $id): ?array { return $this->officerDetail($id); }
    public function lockOfficerEditableRequest(int $id): ?array { $this->locks[] = 'request'; return $this->officerDetail($id); }
    public function saveOfficerEdit(int $id, int $expectedVersion, int $actorId, ?int $vehicleId, array $fields): bool
    {
        if ($this->rows[$id]['version'] !== $expectedVersion) return false;
        $this->rows[$id] = array_replace($this->rows[$id], $fields, ['requested_vehicle_id'=>$vehicleId, 'version'=>$expectedVersion+1]);
        return true;
    }
    public function auditOfficerEdit(int $id, int $actorId, array $changedFields, int $fromVersion, int $toVersion): void
    {
        $this->audits[] = ['edit', $actorId, $changedFields];
    }
    public function approvalList(int $limit, int $offset): array
    {
        return array_slice(array_filter($this->rows, fn($row)=>$row['workflow_status']==='pending_approval'), $offset, $limit);
    }
    public function recordProposal(int $id, int $actorId): void { $this->reviews[$id] = $actorId; }
    public function ownedList(int $owner, int $limit, int $offset): array
    {
        return array_slice(array_filter($this->rows,fn($row)=>(int)$row['requester_id']===$owner),$offset,$limit);
    }
    public function ownedDetail(int $id, int $owner): ?array
    {
        $row = $this->rows[$id] ?? null;
        if (!$row || (int)$row['requester_id']!==$owner) return null;
        return $row + ['notified_outcome'=>$this->notifications[$id]['outcome'] ?? null];
    }
    public function decisionOutcome(int $id): ?string { return $this->decisions[$id]['outcome'] ?? null; }
    public function publishNotification(int $id, int $actorId, string $outcome): bool
    {
        if (isset($this->notifications[$id])) return false;
        $this->notifications[$id] = ['actor'=>$actorId,'outcome'=>$outcome];
        return true;
    }
    public function recordDecision(int $id, int $actorId, array $authority, string $outcome, ?string $reason): void
    {
        $this->decisions[$id] = compact('actorId','authority','outcome','reason');
    }
    public function saveApprovalState(BookingState $before, BookingState $after, int $actorId, ?string $snapshot = null): bool
    {
        if ($this->rows[$before->id]['version'] !== $before->version) return false;
        $this->rows[$before->id]['workflow_status'] = $after->status;
        $this->rows[$before->id]['version'] = $after->version;
        $this->rows[$before->id]['updated_by'] = $actorId;
        if ($snapshot !== null) $this->rows[$before->id]['a4_request_data'] = $snapshot;
        return true;
    }
    public function saveSection3(int $id, int $version, int $actorId, string $snapshot): bool
    {
        if ($this->rows[$id]['version'] !== $version) return false;
        $this->rows[$id]['a4_request_data'] = $snapshot;
        $this->rows[$id]['version']++;
        return true;
    }
    public function appendHistory(int $actor, BookingState $before, BookingState $after): void
    {
        $this->history[] = [$actor,$before->status,$after->status,$after->version];
    }
    public function audit(string $event, int $actor, BookingState $before, BookingState $after): void
    {
        if ($this->auditFailure) throw new RuntimeException('fixture-audit-error');
        $this->audits[] = [$event,$actor,$after->version];
    }
    public function auditSection3(int $id, int $actorId, int $version): void
    {
        if ($this->auditFailure) throw new RuntimeException('fixture-audit-error');
        $this->audits[] = ['draft',$actorId,$version];
    }
}

$checks = 0;
function checkApproval(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}
function deniesApproval(callable $operation, string $type, ?string $message = null): bool
{
    try { $operation(); }
    catch (Throwable $error) { return $error instanceof $type && ($message === null || $message === $error->getMessage()); }
    return false;
}
$repo = new ApprovalFixture();
$service = new BookingApprovalService($repo, [1=>['kind'=>'director'],4=>['kind'=>'delegate','reference'=>'Order A'],2=>['kind'=>'director'],7=>['kind'=>'delegate']]);
$director = new AuthenticatedPrincipal(1,1,['approver']);
$admin = new AuthenticatedPrincipal(2,1,['admin']);
$officer = new AuthenticatedPrincipal(3,1,['vehicle_officer']);
$delegate = new AuthenticatedPrincipal(4,1,['approver','admin']);
$requester = new AuthenticatedPrincipal(5,1,['requester']);
$unassigned = new AuthenticatedPrincipal(7,1,['approver']);
$approverWithoutAuthority = new AuthenticatedPrincipal(8,1,['approver']);
$row = [
    'id'=>1,'requester_id'=>5,'version'=>2,'workflow_status'=>'submitted','requested_vehicle_id'=>14,
    'requested_start_at'=>'2030-01-10 01:00:00','requested_end_at'=>'2030-01-10 10:00:00','passenger_count'=>4,
    'destination'=>'เชียงใหม่','purpose'=>'ประชุม','requester_name'=>'ทดสอบ','requester_phone'=>'0801234567',
    'travel_type'=>'outside_province','expense_source'=>'institute_budget','a4_request_data'=>A4RequestSnapshot::encode(['destination'=>'เชียงใหม่']),
];
$post = [
    'vehicle_id'=>'14','requester_name'=>'ทดสอบ','requester_phone'=>'0801234567','destination'=>'เชียงใหม่','purpose'=>'ประชุม',
    'travel_type'=>'outside_province','expense_source'=>'institute_budget','passenger_count'=>'4',
    'departure_date'=>'2030-01-10','departure_time'=>'08:00','return_date'=>'2030-01-10','return_time'=>'17:00',
];
$row = array_replace($row, App\Modules\Booking\BookingSubmissionInput::fromA4($post,null,true)->fields);
$repo->rows[1] = $row;
checkApproval(deniesApproval(fn()=>$service->forward($requester,1,2,$post),AuthorizationException::class),'requester cannot forward');
foreach ([[], ['destination'=>'ลำปาง', 'departure_date'=>'2030-01-10'], array_replace($post,['destination'=>"\u{00A0}"])] as $document) {
    checkApproval(deniesApproval(fn()=>$service->forward($officer,1,2,$document),DomainException::class,'BOOKING_FORWARD_FIELDS_BLANK')
        && $repo->rows[1]===$row && $repo->history===[], 'missing required text stops forwarding without writes');
}
foreach ([
    8=>array_replace($post,['departure_date'=>'31/02/2569','departure_time'=>'เวลาเช้า','passenger_count'=>'หลายคน','vehicle_registration'=>'รอตรวจทะเบียน']),
    9=>$post,
    10=>$post,
] as $id=>$document) {
    $repo->rows[$id] = array_replace($row, ['id'=>$id,'requested_vehicle_id'=>$id===9?null:14]);
    $repo->vehicleActive = $id !== 10;
    $service->forward($officer,$id,2,$document);
    $forwarded = $repo->rows[$id];
    $form = RequesterA4Presenter::formData($forwarded);
    checkApproval($forwarded['workflow_status']==='pending_approval'
        && $forwarded['requested_start_at']===null && $forwarded['requested_end_at']===null,
        'nonempty malformed, vehicle-less or inactive-vehicle A4 forwards without reserving time');
    checkApproval($form['destination']===($document['destination']??'') && $form['departure_date']===($document['departure_date']??''),
        'A4 retains entered text');
    if ($id===8) checkApproval($forwarded['passenger_count']===null && $form['passenger_count']==='หลายคน'
        && $form['vehicle_registration']==='รอตรวจทะเบียน' && $form['departure_time']==='เวลาเช้า','malformed values survive as document text only');
    unset($repo->rows[$id]);
}
$repo->vehicleActive = true;
$repo->history=[]; $repo->audits=[]; $repo->reviews=[];
$repo->conflict = true;
checkApproval(deniesApproval(fn()=>$service->forward($admin,1,2,$post),DomainException::class,'BOOKING_WINDOW_CONFLICT'),'valid overlapping window still blocks forward');
$repo->conflict = false;
checkApproval(deniesApproval(fn()=>$service->saveAdminDraft($officer,1,2,[]),AuthorizationException::class),'officer cannot edit section 3');
checkApproval(deniesApproval(fn()=>$service->saveAdminDraft($requester,1,2,[]),AuthorizationException::class),'requester cannot edit section 3');
$service->saveAdminDraft($admin,1,2,['approval_outcome'=>'approved','approver_printed_name'=>'ร่าง','workflow_status'=>'approved']);
checkApproval($repo->rows[1]['workflow_status']==='submitted' && $repo->decisions===[] && count($repo->audits)===1,'admin draft leaves state unchanged and is audited');
checkApproval(A4RequestSnapshot::section3($repo->rows[1]['a4_request_data'],'section3_draft')['approver_printed_name']==='ร่าง','draft stored separately');
checkApproval(!isset(RequesterA4Presenter::formData($repo->rows[1])['approval_outcome']),'draft never prints as decision');
$edited = array_replace($post, ['purpose'=>'แก้ไขก่อนส่ง','vehicle_registration'=>'ทะเบียนบน A4 พะเยา','vehicle_id'=>'999','approval_outcome'=>'rejected','rejection_reason'=>'ร่างโดย Admin']);
$repo->rows[3] = array_replace($row,['id'=>3]);
$beforeRollback = [$repo->rows,$repo->history,$repo->reviews,$repo->audits];
$repo->auditFailure = true;
checkApproval(deniesApproval(fn()=>$service->forward($admin,3,2,$edited),RuntimeException::class,'fixture-audit-error')
    && [$repo->rows,$repo->history,$repo->reviews,$repo->audits] === $beforeRollback,'edit and draft roll back on workflow failure');
$repo->auditFailure = false;
$service->forward($admin,3,2,$edited);
checkApproval($repo->rows[3]['requested_vehicle_id']===14 && RequesterA4Presenter::formData($repo->rows[3])['vehicle_registration']==='ทะเบียนบน A4 พะเยา','document text persisted independently of forged vehicle ID');
checkApproval($repo->rows[3]['purpose']===$edited['purpose'] && $repo->rows[3]['workflow_status']==='pending_approval' && $repo->rows[3]['requested_start_at']==='2030-01-10 01:00:00'
    && A4RequestSnapshot::section3($repo->rows[3]['a4_request_data'],'section3_draft')['rejection_reason']===$edited['rejection_reason']
    && $repo->decisions===[], 'latest edit and admin draft committed without decision');
unset($repo->rows[3]);
$repo->history=[]; $repo->audits=[];
$repo->locks = [];
$service->forward($officer,1,3,$post + ['approval_outcome'=>'rejected']);
checkApproval(count($repo->audits)===2 && A4RequestSnapshot::section3($repo->rows[1]['a4_request_data'],'section3_draft')['approval_outcome']==='approved','no false edit audit; officer cannot change existing admin draft');
checkApproval($repo->locks === ['vehicle','request','request'],'workflow takes the vehicle lock before booking row');
checkApproval($repo->history === [[3,'submitted','under_review',4],[3,'under_review','pending_approval',5]] && $repo->reviews[1]===3,'records review and forward with real actor');
checkApproval(deniesApproval(fn()=>$service->forward($officer,1,3,$post + ['approval_outcome'=>'rejected']),DomainException::class,'BOOKING_VERSION_CONFLICT'),'stale replay blocked');
checkApproval(count($service->pendingList($director,1))===1 && count($service->pendingList($admin,1))===1,'queue sees only pending requests');
checkApproval(count($service->pendingList($approverWithoutAuthority,1))===1
    && $service->canDecide($approverWithoutAuthority,$repo->rows[1])===false,
    'approver can read queue without Decision Authority but cannot decide');
checkApproval($service->decisionDiagnostic($approverWithoutAuthority,$repo->rows[1])===['user_id'=>8,'roles'=>['approver'],
    'booking_decision'=>true,'authority'=>false,'booking_status'=>'pending_approval','is_booking_owner'=>false,
    'can_decide'=>false,'reasons'=>['decision_authority_missing_or_invalid']],
    'read-only diagnostic explains missing authority without granting decision');
checkApproval($service->decisionDiagnostic($director,$repo->rows[1])['can_decide']===true
    && $service->decisionDiagnostic($director,$repo->rows[1])['reasons']===[],
    'read-only diagnostic reports authorized director conditions');
checkApproval(count($service->pendingList($unassigned,1))===1
    && $service->canDecide($unassigned,$repo->rows[1])===false,
    'approver with malformed authority configuration remains read-only');
foreach ([$requester,$officer] as $forbidden) {
    checkApproval(deniesApproval(fn()=>$service->pendingList($forbidden,1),AuthorizationException::class),'queue rejects unauthorized role or delegate');
}
$adminRepo = clone $repo;
$adminService = new BookingApprovalService($adminRepo, [16=>['kind'=>'director']]);
checkApproval($adminService->canDecide(new AuthenticatedPrincipal(16,1,['approver']),$adminRepo->rows[1]), 'configured director 16 can decide');
foreach ([$requester,$officer] as $actor) {
    checkApproval(deniesApproval(fn()=>$adminService->decide($actor,1,5,['approval_outcome'=>'approved']),AuthorizationException::class),'non-approving role denied');
}
$adminRepo->rows[1]['requester_id']=$admin->userId;
checkApproval(deniesApproval(fn()=>$adminService->decide($admin,1,5,['approval_outcome'=>'approved']),AuthorizationException::class),'admin self-approval denied');
$adminRepo->rows[1] = array_replace($adminRepo->rows[1], ['requester_id'=>5, 'requested_vehicle_id'=>null, 'requested_start_at'=>null, 'requested_end_at'=>null, 'purpose'=>'', 'passenger_count'=>null]);
$adminService->decide($admin,1,5,['approval_outcome'=>'approved']);
checkApproval($adminRepo->rows[1]['workflow_status']==='awaiting_assignment' && $adminRepo->decisions[1]['actorId']===2
    && $adminRepo->decisions[1]['authority']===['kind'=>'delegate','reference'=>'UP Van Booking policy: role admin'], 'admin approval records role delegation and actor');
foreach ([$admin, new AuthenticatedPrincipal(16,1,['approver'])] as $actor) {
    $blankRepo = clone $repo;
    $blankService = new BookingApprovalService($blankRepo, [16=>['kind'=>'director']]);
    $blankService->decide($actor,1,5,['approval_outcome'=>'rejected']);
    checkApproval($blankRepo->decisions[1]['reason'] === null && $blankRepo->rows[1]['workflow_status'] === 'awaiting_rejection_notice'
        && $blankRepo->notifications === [], 'empty rejection stores NULL and returns to officer');
}
$repo->rows[1]['requester_id'] = 1;
checkApproval(deniesApproval(fn()=>$service->decide($director,1,5,['approval_outcome'=>'approved']),AuthorizationException::class),'no self approval');
$repo->rows[1]['requester_id'] = 5;
$repo->conflict=true;
checkApproval(deniesApproval(fn()=>$service->decide($director,1,5,['approval_outcome'=>'approved']),DomainException::class,'BOOKING_WINDOW_CONFLICT'),'approval rechecks overlap');
$repo->conflict=false;
$repo->auditFailure=true;
$before=$repo->rows[1];
checkApproval(deniesApproval(fn()=>$service->decide($director,1,5,['approval_outcome'=>'approved']),RuntimeException::class,'fixture-audit-error')
    && $repo->rows[1]===$before && $repo->decisions===[] && count($repo->history)===2,'audit failure rolls back decision, status and snapshot');
$repo->auditFailure=false;
$service->decide($director,1,5,['approval_outcome'=>'approved','approver_printed_name'=>'ทดสอบ','authority_kind'=>'delegate']);
checkApproval($repo->rows[1]['workflow_status']==='awaiting_assignment' && $repo->rows[1]['version']===6 && $repo->rows[1]['updated_by']===1,'approval returns to officer without making assignment');
checkApproval($repo->decisions[1]['authority']['kind']==='director' && RequesterA4Presenter::formData($repo->rows[1])['approval_outcome']==='approved','persisted actual outcome prints; authority comes from server');
checkApproval(deniesApproval(fn()=>$service->decide($director,1,5,['approval_outcome'=>'approved']),DomainException::class),'duplicate approval rejected');
$repo->rows[2] = array_replace($row,['id'=>2,'workflow_status'=>'pending_approval']);
$pendingDraft = ['approval_outcome'=>'rejected','approver_printed_name'=>'ร่างจาก Admin','requester_name'=>'ห้ามแก้'];
$beforeDraft = $repo->rows[2];
$repo->auditFailure = true;
checkApproval(deniesApproval(fn()=>$service->saveAdminDraft($admin,2,2,$pendingDraft),RuntimeException::class,'fixture-audit-error')
    && $repo->rows[2]===$beforeDraft,'pending draft rolls back on audit failure');
$repo->auditFailure = false;
$service->saveAdminDraft($admin,2,2,$pendingDraft);
checkApproval($repo->rows[2]['workflow_status']==='pending_approval' && $repo->rows[2]['version']===3
    && $repo->rows[2]['requester_name']===$beforeDraft['requester_name'] && !isset($repo->decisions[2])
    && end($repo->audits)===['draft',2,2], 'pending draft versions and audits only section 3');
checkApproval(A4RequestSnapshot::section3($repo->rows[2]['a4_request_data'],'section3_draft')['approver_printed_name']==='ร่างจาก Admin',
    'pending draft persisted');
checkApproval(deniesApproval(fn()=>$service->saveAdminDraft($admin,2,2,$pendingDraft),DomainException::class,'BOOKING_VERSION_CONFLICT'),
    'pending draft rejects stale version');

$service->decide($delegate,2,3,['approval_outcome'=>'rejected','rejection_reason_continued'=>'ทดสอบเหตุผล','approver_printed_name'=>'ผู้รับมอบอำนาจ']);
checkApproval(RequesterA4Presenter::formData($repo->rows[2])['approver_printed_name']==='ผู้รับมอบอำนาจ','decision takes current section 3 instead of old draft');
checkApproval($repo->rows[2]['workflow_status']==='awaiting_rejection_notice' && $repo->decisions[2]['authority']['reference']==='Order A' && $repo->decisions[2]['reason']==='ทดสอบเหตุผล','delegate rejection records delegated authority and returns to officer');
checkApproval($service->pendingList($director,1)===[],'decided cases leave pending queue');
checkApproval(deniesApproval(fn()=>$service->pendingDetail($director,1),AuthorizationException::class),'direct URL cannot reopen a decided case from pending queue');

$draft = ['approval_outcome'=>'rejected','rejection_reason'=>'ร่างของ Admin'];
$snapshot = A4RequestSnapshot::withSection3($row['a4_request_data'],'section3_draft',$draft);
$snapshot = A4RequestSnapshot::withSection3($snapshot,'section3_decision',['approval_outcome'=>'approved']);
$repo->rows[11] = array_replace($row,['id'=>11,'workflow_status'=>'pending_approval','a4_request_data'=>$snapshot]);
checkApproval(deniesApproval(fn()=>$service->recall($requester,11,2),AuthorizationException::class)
    && deniesApproval(fn()=>$service->recall($director,11,2),AuthorizationException::class),'recall denies requester and director');
checkApproval(deniesApproval(fn()=>$service->recall($officer,11,1),DomainException::class,'BOOKING_VERSION_CONFLICT'),'recall rejects stale version');
$beforeRecall = [$repo->rows,$repo->history,$repo->audits];
$repo->auditFailure = true;
checkApproval(deniesApproval(fn()=>$service->recall($officer,11,2),RuntimeException::class,'fixture-audit-error')
    && [$repo->rows,$repo->history,$repo->audits]===$beforeRecall,'recall rolls back state, snapshot and history on audit failure');
$repo->auditFailure = false;
$repo->locks = [];
$service->recall($officer,11,2);
checkApproval($repo->locks===['vehicle','request'] && $repo->rows[11]['workflow_status']==='under_review'
    && $repo->rows[11]['version']===3 && $service->pendingList($director,1)===[], 'recall removes from queue with decision lock order');
checkApproval(end($repo->history)===[3,'pending_approval','under_review',3] && end($repo->audits)===['booking.forward_recalled',3,3],
    'recall records history and audit with real actor');
checkApproval(A4RequestSnapshot::section3($repo->rows[11]['a4_request_data'],'section3_draft')===$draft
    && A4RequestSnapshot::section3($repo->rows[11]['a4_request_data'],'section3_decision')===[], 'draft survives, stray decision snapshot cleared');
checkApproval(deniesApproval(fn()=>$service->recall($officer,11,3),DomainException::class,'BOOKING_VERSION_CONFLICT')
    && deniesApproval(fn()=>$service->decide($director,11,2,['approval_outcome'=>'approved']),DomainException::class,'BOOKING_VERSION_CONFLICT'),
    'duplicate recall and stale concurrent decision fail');
checkApproval(deniesApproval(fn()=>$service->recall($officer,1,6),DomainException::class,'BOOKING_VERSION_CONFLICT')
    && deniesApproval(fn()=>$service->recall($officer,2,4),DomainException::class,'BOOKING_VERSION_CONFLICT'), 'completed decisions cannot be recalled');
$service->forward($officer,11,3,array_replace($post,['purpose'=>'แก้ไขหลังดึงกลับ']));
checkApproval(count($service->pendingList($director,1))===1 && $repo->rows[11]['purpose']==='แก้ไขหลังดึงกลับ'
    && A4RequestSnapshot::section3($repo->rows[11]['a4_request_data'],'section3_decision')===[], 'revised A4 returns to queue without prior decision');
$service->recall($admin,11,(int)$repo->rows[11]['version']);
checkApproval($repo->rows[11]['workflow_status']==='under_review','admin can recall');
$source = file_get_contents(dirname(__DIR__).'/app/Repositories/PdoBookingSubmissionRepository.php');
checkApproval(str_contains($source,"'officer_requested_revision_before_approval'"),'production recall audit supplies a reason');

$owner = new AuthenticatedPrincipal(5,1,['requester']);
$other = new AuthenticatedPrincipal(60,1,['requester']);
$initialRows = count($repo->rows);
checkApproval(deniesApproval(fn()=>$service->ownedDetail($other,1),AuthorizationException::class)
    && $service->ownedList($other,1)===[], 'owner scope rejects other IDs and filters list');
checkApproval(!isset(RequesterA4Presenter::formData($service->ownedDetail($owner,1),ownerView:true)['approval_outcome']),
    'a real decision stays hidden from its owner before officer publication');
checkApproval(deniesApproval(fn()=>$service->notifyRequester($director,1,6),AuthorizationException::class)
    && deniesApproval(fn()=>$service->notifyRequester($owner,1,6),AuthorizationException::class), 'notify RBAC');
checkApproval(deniesApproval(fn()=>$service->notifyRequester($officer,1,5),DomainException::class,'BOOKING_VERSION_CONFLICT'),
    'notice rejects stale version');
checkApproval(deniesApproval(fn()=>$service->notifyRequester($officer,11,(int)$repo->rows[11]['version']),DomainException::class),
    'cannot notify before decision');
$beforeNotice = $repo->rows[1];
$repo->auditFailure = true;
checkApproval(deniesApproval(fn()=>$service->notifyRequester($officer,1,6),RuntimeException::class,'fixture-audit-error')
    && $repo->notifications===[] && $repo->rows[1]===$beforeNotice,'notification and version roll back on audit failure');
$repo->auditFailure = false;
$service->notifyRequester($officer,1,6);
checkApproval($repo->rows[1]['workflow_status']==='awaiting_assignment' && $repo->rows[1]['version']===7
    && $repo->notifications[1]===['actor'=>3,'outcome'=>'approved'] && end($repo->audits)===['booking.requester_notified',3,7]
    && count($repo->rows)===$initialRows,'publish versioned, audited approval on the existing booking without assigning it');
checkApproval(deniesApproval(fn()=>$service->notifyRequester($officer,1,7),DomainException::class,'BOOKING_NOTIFICATION_ALREADY_PUBLISHED'),
    'new version cannot publish a duplicate');
$service->notifyRequester($admin,2,(int)$repo->rows[2]['version']);
checkApproval(RequesterA4Presenter::formData($service->ownedDetail($owner,1),ownerView:true)['approval_outcome']==='approved'
    && RequesterA4Presenter::formData($service->ownedDetail($owner,2),ownerView:true)['approval_outcome']==='rejected'
    && $repo->rows[2]['workflow_status']==='awaiting_rejection_notice','owner receives both outcomes and rejection stays rejected');

foreach (['TEST_BOOKING_OFFICER_EDIT_APPROVED','TEST_BOOKING_APPROVAL_APPROVED'] as $env) putenv($env.'=false');
$_SESSION=['csrf_token'=>'fixture-token'];
$_POST=['_token'=>'incorrect','booking_id'=>'1','booking_version'=>'2'];
$controller=new BookingApprovalController();
ob_start(); $controller->forward($officer); $response=ob_get_clean();
checkApproval(http_response_code()===419 && json_decode($response,true)['error']==='CSRF_INVALID','invalid CSRF returns 419 before write gate');
ob_start(); $controller->draft($admin); ob_end_clean();
checkApproval(http_response_code()===419,'draft validates CSRF');
ob_start(); $controller->notifyRequester($officer); ob_end_clean();
checkApproval(http_response_code()===419,'notice validates CSRF');
ob_start(); $controller->recall($officer); ob_end_clean();
checkApproval(http_response_code()===419,'recall validates CSRF');
$_POST['_token']='fixture-token';
ob_start(); $controller->forward($officer); $response=ob_get_clean();
checkApproval(http_response_code()===503 && json_decode($response,true)['error']==='BOOKING_GATE_CLOSED','valid forward remains gated');
ob_start(); $controller->draft($officer); ob_end_clean();
checkApproval(http_response_code()===403,'direct officer POST to admin draft denied');
ob_start(); $controller->decide($director); ob_end_clean();
checkApproval(http_response_code()===503,'decision remains independently gated');
ob_start(); $controller->recall($officer); ob_end_clean();
checkApproval(http_response_code()===503,'recall uses existing officer edit gate');
ob_start(); $controller->recall($director); ob_end_clean();
checkApproval(http_response_code()===403,'recall controller rejects director');
$savedServer = $_SERVER;
putenv('TEST_AUTH_EXECUTION_APPROVED=true');
putenv('TEST_BOOKING_DECISION_DIAGNOSTICS=true');
$_SERVER['REMOTE_ADDR']='::ffff:127.0.0.1';
$_SERVER['SERVER_ADDR']='::ffff:127.0.0.1';
$_SERVER['HTTP_HOST']='127.0.0.1:8088';
checkApproval(BookingRuntimeFeature::decisionDiagnosticsEnabled(), 'local diagnostic accepts IPv4-mapped loopback addresses');
$_SERVER['REMOTE_ADDR']='192.0.2.10';
checkApproval(!BookingRuntimeFeature::decisionDiagnosticsEnabled(), 'local diagnostic rejects non-loopback clients');
$_SERVER['REMOTE_ADDR']='127.0.0.1';
$_SERVER['HTTP_HOST']='127.0.0.1:8089';
checkApproval(!BookingRuntimeFeature::decisionDiagnosticsEnabled(), 'local diagnostic rejects unexpected ports');
$_SERVER['HTTP_HOST']='localhost:8088';
checkApproval(BookingRuntimeFeature::decisionDiagnosticsEnabled(), 'local diagnostic accepts localhost on the test port');
putenv('TEST_AUTH_EXECUTION_APPROVED=false');
putenv('TEST_BOOKING_DECISION_DIAGNOSTICS=true');
checkApproval(!BookingRuntimeFeature::decisionDiagnosticsEnabled(), 'local diagnostic requires test authentication to be enabled');
putenv('TEST_AUTH_EXECUTION_APPROVED=true');
putenv('TEST_BOOKING_DECISION_DIAGNOSTICS=false');
checkApproval(!BookingRuntimeFeature::decisionDiagnosticsEnabled(), 'local diagnostic stays off unless explicitly enabled');
putenv('TEST_BOOKING_DECISION_DIAGNOSTICS=true');
putenv('TEST_AUTH_EXECUTION_APPROVED=true');
$_SERVER['REMOTE_ADDR']='127.0.0.1';
$_SERVER['SERVER_ADDR']='127.0.0.1';
$_SERVER['HTTP_HOST']='127.0.0.1:8088';
$diagnosticData = BookingRuntimeFeature::decisionDiagnosticsEnabled()
    && (new App\Services\AuthorizationPolicy())->allows($approverWithoutAuthority->roles,'booking.director_queue.view')
    ? $service->decisionDiagnostic($approverWithoutAuthority,$repo->rows[1]) : null;
ob_start();
App\Core\View::render('form-preview',['formData'=>[],'documentMode'=>true,'decisionDiagnostic'=>$diagnosticData]);
$diagnosticHtml=ob_get_clean();
checkApproval(str_contains($diagnosticHtml,'class="decision-diagnostic"') && str_contains($diagnosticHtml,'decision_authority_missing_or_invalid'),
    'enabled local diagnostic is rendered into the A4 detail view with the actual denial reason');
putenv('TEST_BOOKING_DECISION_DIAGNOSTICS=false');
$diagnosticData = BookingRuntimeFeature::decisionDiagnosticsEnabled()
    && (new App\Services\AuthorizationPolicy())->allows($approverWithoutAuthority->roles,'booking.director_queue.view')
    ? $service->decisionDiagnostic($approverWithoutAuthority,$repo->rows[1]) : null;
ob_start();
App\Core\View::render('form-preview',['formData'=>[],'documentMode'=>true,'decisionDiagnostic'=>$diagnosticData]);
$diagnosticHtml=ob_get_clean();
checkApproval(!str_contains($diagnosticHtml,'class="decision-diagnostic"'), 'disabled local diagnostic is omitted from the A4 detail view');
$_SERVER=$savedServer;
foreach ([1=>'อนุมัติแล้ว',2=>'ไม่อนุมัติ'] as $resultId=>$label) {
    foreach ([null,$repo->notifications[$resultId]['outcome']] as $notification) {
        $resultRow = array_replace($repo->rows[$resultId], ['notified_outcome'=>$notification]);
        ob_start();
        App\Core\View::render('officer/booking-requests', ['rows'=>[$resultRow]]);
        $html = ob_get_clean();
        checkApproval(substr_count($html,'/officer/booking-requests/detail?id='.$resultId.'"') === 1
            && str_contains($html, '>'.$label.'<')
            && str_contains($html, '>'.($notification === null ? 'ยังไม่แจ้งผู้ขอ' : 'แจ้งผู้ขอแล้ว').'<'),
            'officer sees the original request, decision and separate notification status');
        ob_start();
        App\Core\View::render('booking/my-requests', ['rows'=>[$resultRow + ['submitted_at'=>null]], 'page'=>1]);
        $ownerHtml = ob_get_clean();
        checkApproval(str_contains($ownerHtml, $notification === null ? 'อยู่ระหว่างดำเนินการ' : $label)
            && str_contains($ownerHtml, $notification === null ? 'ยังไม่แจ้งผู้ขอ' : '>แจ้งผู้ขอแล้ว<')
            && ($notification !== null || !str_contains($ownerHtml,$label)), 'owner list reveals only published outcomes');
    }
    foreach (['requester','vehicle_officer','approver','admin'] as $role) {
        $account = new AuthenticatedPrincipal($owner->userId,1,[$role]);
        $foreign = new AuthenticatedPrincipal($other->userId,1,[$role]);
        checkApproval(RequesterA4Presenter::formData($service->ownedDetail($account,$resultId),ownerView:true)['approval_outcome'] === $repo->notifications[$resultId]['outcome']
            && deniesApproval(fn()=>$service->ownedDetail($foreign,$resultId),AuthorizationException::class),
            'my A4 is restricted to its owner for role '.$role);
    }
}
echo "PASS: $checks booking approval offline checks\n";

