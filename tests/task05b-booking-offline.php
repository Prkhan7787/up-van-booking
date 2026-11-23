<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/cli.php';
use App\Modules\Booking\{BookingState,BookingScope,BookingRequirements,PendingBookingRequirements,BookingTransitionPolicy,BookingRepository,BookingWorkflowService};
use App\Models\AuthenticatedPrincipal;
$checks=0;
function bookingCheck(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
function bookingDenied(callable $fn):bool{try{$fn();return false;}catch(Throwable){return true;}}
$scope=new class implements BookingScope {public bool $allowed=true;public function allows(AuthenticatedPrincipal $a,BookingState $b,string $p):bool{return $this->allowed;}};
$requirements=new class implements BookingRequirements {public bool $allowed=true;public array $seen=[];public function assertSatisfied(string $r,AuthenticatedPrincipal $a,BookingState $b,string $n):void{$this->seen[]=$r;if(!$this->allowed)throw new DomainException('Missing evidence');}};
$policy=new BookingTransitionPolicy($scope,$requirements);
$requester=new AuthenticatedPrincipal(10,1,['requester']);$officer=new AuthenticatedPrincipal(20,1,['vehicle_officer']);$director=new AuthenticatedPrincipal(30,1,['approver']);$admin=new AuthenticatedPrincipal(40,1,['admin']);
$edges=[['draft','submitted',$requester],['submitted','under_review',$officer],['under_review','pending_approval',$officer],['pending_approval','awaiting_assignment',$director],['pending_approval','awaiting_rejection_notice',$director],['awaiting_assignment','assigned',$officer],['awaiting_rejection_notice','closed_rejected',$officer],['assigned','completed',$officer],['awaiting_assignment','cancelled',$officer],['assigned','cancelled',$officer]];
foreach($edges as [$from,$to,$actor]){$b=new BookingState(1,10,$from,4);$policy->assertTransition($actor,$b,4,$to);bookingCheck(true,'Allowed edge with trusted evidence');if ($from!=='draft') bookingCheck(bookingDenied(fn()=>$policy->assertTransition($admin,$b,4,$to)),'Admin has no general workflow transition authority');bookingCheck(bookingDenied(fn()=>$policy->assertTransition($actor,$b,3,$to)),'Expected version required');}
foreach(BookingState::STATUSES as $from)foreach(BookingState::STATUSES as $to){$found=false;foreach($edges as $e)if($e[0]===$from && $e[1]===$to)$found=true;if(!$found)bookingCheck(bookingDenied(fn()=>$policy->assertTransition(new AuthenticatedPrincipal(10,1,['requester','vehicle_officer','approver','admin']),new BookingState(1,10,$from,1),1,$to)),'Unknown edge denied, including reopening or same-state');}
$draft=new BookingState(1,10,'draft',1);
$policy->assertDraftCreate($requester);$policy->assertDraftEdit($requester,$draft,1);
bookingCheck(bookingDenied(fn()=>$policy->assertDraftCreate($admin)) && bookingDenied(fn()=>$policy->assertDraftEdit($admin,$draft,1)),'Admin read access does not grant draft writes');
bookingCheck(bookingDenied(fn()=>$policy->assertDraftEdit(new AuthenticatedPrincipal(99,1,['requester']),$draft,1)) && bookingDenied(fn()=>$policy->assertDraftEdit($requester,$draft,2)),'Draft ownership and version enforced');
bookingCheck(bookingDenied(fn()=>$policy->assertDraftEdit($requester,new BookingState(1,10,'submitted',1),1)),'Submitted document cannot be edited as draft');
bookingCheck(bookingDenied(fn()=>(new BookingState(1,10,'draft',4294967295))->next('submitted')),'Unsigned INT version cannot overflow');
bookingCheck(bookingDenied(fn()=>$policy->assertTransition(new AuthenticatedPrincipal(99,1,['requester']),$draft,1,'submitted')),'IDOR submit denied');
bookingCheck($policy->canRead($admin,$draft) && $policy->canRead($requester,$draft) && !$policy->canRead(new AuthenticatedPrincipal(99,1,['requester']),$draft),'Read owner/admin scope');
$scope->allowed=false;
bookingCheck(!$policy->canRead($officer,$draft) && !$policy->canRead($director,$draft),'Scope denial on read');
bookingCheck(bookingDenied(fn()=>$policy->assertTransition($officer,new BookingState(1,10,'submitted',1),1,'under_review')),'Officer scope required');$scope->allowed=true;
bookingCheck(bookingDenied(fn()=>(new BookingTransitionPolicy($scope,new PendingBookingRequirements()))->assertTransition($director,new BookingState(1,10,'pending_approval',1),1,'awaiting_assignment')),'Pending authority fails closed');
foreach (['awaiting_assignment','awaiting_rejection_notice'] as $outcome) {
    bookingCheck(bookingDenied(fn()=>$policy->assertTransition($director,new BookingState(1,30,'pending_approval',1),1,$outcome)), 'Approver cannot decide their own request even with trusted scope');
}
$requirements->allowed=false;
bookingCheck(bookingDenied(fn()=>$policy->assertTransition($officer,new BookingState(1,10,'awaiting_assignment',1),1,'assigned')),'Assignment requires resource and approval evidence');$requirements->allowed=true;
$repo=new class implements BookingRepository {
 public BookingState $state;public array $history=[];public array $audits=[];public bool $active=false;public bool $failAudit=false;public bool $failCas=false;public array $order=[];
 public function __construct(){$this->state=new BookingState(1,10,'draft',1);}
 public function transaction(callable $fn):mixed {if($this->active)throw new RuntimeException();$snap=[$this->state,$this->history,$this->audits];$this->active=true;try{return $fn();}catch(Throwable $e){[$this->state,$this->history,$this->audits]=$snap;throw $e;}finally{$this->active=false;}}
 public function lock(int $id):?BookingState {if(!$this->active)throw new RuntimeException();$this->order[]='lock';return $id===$this->state->id?$this->state:null;}
 public function save(BookingState $before,BookingState $after):bool {$this->order[]='save';if($this->failCas || $this->state->version!==$before->version)return false;$this->state=$after;return true;}
 public function appendHistory(int $actorId,BookingState $before,BookingState $after):void{$this->order[]='history';$this->history[]=[$actorId,$before,$after];}
 public function audit(string $event,int $actorId,BookingState $before,BookingState $after):void{$this->order[]='audit';if($this->failAudit)throw new RuntimeException();$this->audits[]=[$actorId,$before,$after,$event];}
};
$workflow=new BookingWorkflowService($repo,$policy);$result=$workflow->transition($requester,1,1,'submitted');
bookingCheck($result->status==='submitted' && $result->version===2 && $repo->order===['lock','save','history','audit'],'Lock, compare/swap, history, audit in one transaction');
bookingCheck($repo->audits[0][0]===10 && $repo->audits[0][1]->status==='draft' && $repo->audits[0][2]->status==='submitted','Audit has actor target and old/new version/state');
bookingCheck($repo->audits[0][3]==='booking.status_changed','Audit event code is server-owned');
bookingCheck(bookingDenied(fn()=>$workflow->transition($requester,1,1,'submitted')) && count($repo->history)===1,'Repeat/stale operation cannot add history');
$repo->state=new BookingState(1,10,'draft',1);$repo->history=[];$repo->audits=[];$repo->failAudit=true;
bookingCheck(bookingDenied(fn()=>$workflow->transition($requester,1,1,'submitted')) && $repo->state->status==='draft' && $repo->history===[],'Audit failure rolls back state and history');
$repo->failAudit=false;$repo->failCas=true;
bookingCheck(bookingDenied(fn()=>$workflow->transition($requester,1,1,'submitted')) && $repo->audits===[],'CAS conflict writes no audit/history');
$routes=require dirname(__DIR__).'/routes/web.php';
$bookingPosts=array_filter(array_keys($routes),static fn(string $route):bool=>preg_match('/^POST .*booking|^POST .*request|^POST .*assignment/',$route)===1);
sort($bookingPosts);
bookingCheck($bookingPosts===[
    'POST /bookings/submit','POST /director/approval-requests/decision','POST /officer/booking-requests/delete',
    'POST /officer/booking-requests/forward','POST /officer/booking-requests/notify-requester','POST /officer/booking-requests/recall','POST /officer/booking-requests/section3-draft','POST /officer/booking-requests/update',
], 'Booking POST routes are limited to submission, officer operations and gated decision');
echo 'PASS: ',$checks,' Booking foundation checks; memory repository only.',PHP_EOL;
