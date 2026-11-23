<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/cli.php';
require dirname(__DIR__).'/app/helpers.php';

use App\Models\AuthenticatedPrincipal;
use App\Modules\Booking\{BookingState,BookingSubmissionInput,BookingSubmissionRepository,BookingSubmissionService};
use App\Core\AuthorizationException;

$checks=0;
function submitCheck(bool $ok,string $message):void{global $checks;if(!$ok){fwrite(STDERR,'FAIL: '.$message.PHP_EOL);throw new RuntimeException($message);}$checks++;}
function rejected(callable $fn,string $class):bool{try{$fn();}catch(Throwable $e){return $e instanceof $class;}return false;}
final class SubmissionMemory implements BookingSubmissionRepository
{
    public array $rows=[],$history=[],$audits=[];public bool $requestConflict=false,$assignmentConflict=false,$active=true,$auditFails=false;public int $capacity=12;public array $vehicleIds=[7];
    public function transaction(callable $operation):mixed{$before=[$this->rows,$this->history,$this->audits];try{return $operation();}catch(Throwable $e){[$this->rows,$this->history,$this->audits]=$before;throw $e;}}
    public function lockVehicle(int $id):?array{return in_array($id,$this->vehicleIds,true)?['id'=>$id,'is_active'=>$this->active?1:0,'passenger_capacity'=>$this->capacity]:null;}
    public function hasRequestConflict(int $id,string $start,string $end,?int $excludeBookingId=null):bool{return $this->requestConflict;}
    public function hasAssignmentConflict(int $id,string $start,string $end):bool{return $this->assignmentConflict;}
    public function insertDraft(int $owner,?int $vehicle,string $number,array $fields):int{$id=count($this->rows)+1;$this->rows[$id]=['id'=>$id,'requester_id'=>$owner,'requested_vehicle_id'=>$vehicle,'request_number'=>$number,'workflow_status'=>'draft','version'=>1,'submitted_at'=>null]+$fields;return $id;}
    public function appendDraftHistory(int $id,int $actor):void{$this->history[]=[$id,null,'draft',$actor,1];}
    public function auditDraftCreated(int $id,int $actor):void{$this->audits[]=['booking.draft.created',$id,$actor];}
    public function lock(int $id):?BookingState{$r=$this->rows[$id]??null;return $r?new BookingState($id,(int)$r['requester_id'],$r['workflow_status'],(int)$r['version']):null;}
    public function save(BookingState $before,BookingState $after):bool{if(!isset($this->rows[$before->id])||$this->rows[$before->id]['workflow_status']!==$before->status||$this->rows[$before->id]['version']!==$before->version)return false;$this->rows[$before->id]['workflow_status']=$after->status;$this->rows[$before->id]['version']=$after->version;$this->rows[$before->id]['submitted_at']='2026-11-17 01:00:00';return true;}
    public function appendHistory(int $actor,BookingState $before,BookingState $after):void{$this->history[]=[$before->id,$before->status,$after->status,$actor,$after->version];}
    public function audit(string $event,int $actor,BookingState $before,BookingState $after):void{if($this->auditFails)throw new RuntimeException('synthetic audit failure');$this->audits[]=[$event,$before->id,$actor,$before->status,$after->status,$after->version];}
    public function officerVehicles():array{return [['id'=>7,'label'=>'Test van','registration_number'=>'test','registration_province'=>'test']];}
    public function officerList(int $limit,int $offset):array{return array_slice(array_values(array_filter($this->rows,static fn($r)=>$r['workflow_status']==='submitted')),$offset,$limit);}
    public function officerDetail(int $id):?array{return ($this->rows[$id]['workflow_status']??null)==='submitted'?$this->rows[$id]:null;}
    public function officerEditSnapshot(int $id):?array{return $this->rows[$id]??null;}
    public function lockOfficerEditableRequest(int $id):?array{return $this->rows[$id]??null;}
    public function saveOfficerEdit(int $id,int $expectedVersion,int $actorId,?int $vehicleId,array $fields):bool{if(!isset($this->rows[$id])||$this->rows[$id]['version']!==$expectedVersion||!in_array($this->rows[$id]['workflow_status'],['submitted','under_review'],true))return false;$this->rows[$id]=array_replace($this->rows[$id],$fields,['requested_vehicle_id'=>$vehicleId,'updated_by'=>$actorId,'version'=>$expectedVersion+1]);return true;}
    public function auditOfficerEdit(int $id,int $actorId,array $changedFields,int $fromVersion,int $toVersion):void{if($this->auditFails)throw new RuntimeException('synthetic audit failure');$this->audits[]=['booking.officer_request.updated',$id,$actorId,$changedFields,$fromVersion,$toVersion];}
}

$now=new DateTimeImmutable('2026-11-17 08:00:00',new DateTimeZone('Asia/Bangkok'));
$thaiMonths=[1=>'มกราคม',2=>'กุมภาพันธ์',3=>'มีนาคม',4=>'เมษายน',5=>'พฤษภาคม',6=>'มิถุนายน',7=>'กรกฎาคม',8=>'สิงหาคม',9=>'กันยายน',10=>'ตุลาคม',11=>'พฤศจิกายน',12=>'ธันวาคม'];
$travelDate=$now->modify('+3 days');
$travelDateLabel=(int)$travelDate->format('j').' '.$thaiMonths[(int)$travelDate->format('n')].' '.((int)$travelDate->format('Y')+543);
$base=['vehicle_id'=>'7','requester_name'=>'ผู้ขอทดสอบ','requester_position'=>'นักวิชาการ','requester_affiliation'=>'สถาบัน','requester_affiliation_continued'=>'งานยานพาหนะ','requester_phone'=>'0801234567','purpose'=>'เข้าร่วมประชุม','destination'=>'ลำปาง','passenger_count'=>'8','travel_type'=>'outside_province','expense_source'=>'project_budget','project_expense_details'=>'งบโครงการ','expense_source_details'=>'รายละเอียดอื่น','departure_date'=>$travelDateLabel,'departure_time'=>'08:00','return_date'=>$travelDateLabel,'return_time'=>'17:00'];
$input=BookingSubmissionInput::fromA4($base,$now);
$forged=BookingSubmissionInput::fromA4(array_replace($base,['approval_outcome'=>'approved','approver_printed_name'=>'forged','rejection_reason'=>'forged','decision_year'=>'2569']),$now);
submitCheck(!array_intersect(['approval_outcome','approver_printed_name','rejection_reason','decision_year'],array_keys($forged->fields)),'Requester submission discards forged Section 3 fields before repository writes');

submitCheck(rejected(fn()=>BookingSubmissionInput::fromA4(array_replace($base,['departure_date'=>'16 พฤศจิกายน 2569']),$now),InvalidArgumentException::class)&&BookingSubmissionInput::fromA4(array_replace($base,['departure_date'=>'16 พฤศจิกายน 2569']),$now,true)->fields['requested_start_at']==='2026-11-16 01:00:00','submission rejects past travel windows while authorized officer edits can preserve historical windows');
submitCheck($input->vehicleId===7&&$input->fields['requested_start_at']==='2026-11-20 01:00:00'&&$input->fields['requested_end_at']==='2026-11-20 10:00:00','A4 Thai date/time converts from Asia/Bangkok to UTC');
submitCheck($input->fields['application_date']==='2026-11-17'&&$input->fields['requester_affiliation']==='สถาบัน งานยานพาหนะ','application date is server-created and affiliation continuation is retained');
submitCheck($input->fields['expense_source_details']==='งบโครงการ'&&$input->fields['additional_details']==='รายละเอียดอื่น','both A4 expense detail fields map to distinct schema columns');
foreach([
    ['vehicle_id'=>'0'],
    ['passenger_count'=>'0'],['travel_type'=>'bad'],['expense_source'=>'bad'],['departure_date'=>'31 กุมภาพันธ์ 2569'],
    ['departure_time'=>'27:00'],['return_date'=>'19 พฤศจิกายน 2569'],['return_time'=>'07:00'],
    ['requester_name'=>['tampered']],
] as $bad)submitCheck(rejected(fn()=>BookingSubmissionInput::fromA4(array_replace($base,$bad),$now),InvalidArgumentException::class),'invalid or incomplete A4 submission is rejected');
$future=['departure_date'=>'17 พฤศจิกายน 2569','departure_time'=>'07:59'];
submitCheck(rejected(fn()=>BookingSubmissionInput::fromA4(array_replace($base,$future),$now),InvalidArgumentException::class),'past/elapsed start time is rejected');
$routes=require dirname(__DIR__).'/routes/web.php';$policy=new App\Services\AuthorizationPolicy();
submitCheck(($routes['POST /bookings/submit'][2]??null)==='booking.submit_own'&&!$policy->allows([],'booking.submit_own'),'submission route requires an authorized authenticated role');
foreach(['requester','admin','vehicle_officer','approver'] as $role)submitCheck($policy->allows([$role],'booking.submit_own'),$role.' can submit their own request');
submitCheck($policy->allows(['vehicle_officer'],'booking.officer_queue.view')&&$policy->allows(['admin'],'booking.officer_queue.view')&&!$policy->allows(['requester'],'booking.officer_queue.view')&&!$policy->allows(['approver'],'booking.officer_queue.view'),'officer queue is limited to Vehicle Officer and Admin');
submitCheck($policy->allows(['vehicle_officer'],'booking.officer_request.edit')&&$policy->allows(['admin'],'booking.officer_request.edit')&&!$policy->allows(['requester'],'booking.officer_request.edit')&&!$policy->allows(['approver'],'booking.officer_request.edit'),'only Vehicle Officer and Admin can update intake data');
$repo=new SubmissionMemory();$service=new BookingSubmissionService($repo);$requester=new AuthenticatedPrincipal(41,1,['requester']);$admin=new AuthenticatedPrincipal(42,1,['admin']);
$result=$service->submit($requester,$input);
submitCheck($result['status']==='submitted'&&preg_match('/^UPV-'.date('Ymd').'-[A-F0-9]{8}$/D',$result['request_number'])===1,'server creates request number and finishes the existing Draft → Submitted transition');
submitCheck($repo->rows[1]['requester_id']===41&&$repo->rows[1]['requested_vehicle_id']===7&&$repo->rows[1]['workflow_status']==='submitted'&&$repo->rows[1]['version']===2,'request owner is bound to authenticated principal and stored request reaches Submitted');
submitCheck(count($repo->history)===2&&$repo->history[0][2]==='draft'&&$repo->history[1][1]==='draft'&&$repo->history[1][2]==='submitted','initial and Submitted status history are appended');
submitCheck(count($repo->audits)===2&&$repo->audits[1][0]==='booking.status_changed','submission and status transition are audited in the transaction');
$_POST=array_replace($base,['purpose'=>'ปรับวัตถุประสงค์โดยเจ้าหน้าที่','approval_outcome'=>'approved','rejection_reason'=>'ปลอม','approver_printed_name'=>'ปลอม']);
$edit=$service->updateOfficerRequest(new AuthenticatedPrincipal(52,1,['vehicle_officer']),1,2,$_POST);
submitCheck($repo->rows[1]['purpose']==='ปรับวัตถุประสงค์โดยเจ้าหน้าที่'&&$repo->rows[1]['version']===3&&$edit['version']===3,'authorized officer can update an editable submitted request with optimistic version check');
submitCheck(!isset($repo->rows[1]['approval_outcome'])&&!isset($repo->rows[1]['rejection_reason'])&&!isset($repo->rows[1]['approver_printed_name']),'officer update ignores all forged Section 3 payload fields');
submitCheck($repo->audits[2][0]==='booking.officer_request.updated'&&in_array('purpose',$repo->audits[2][3],true),'officer edit records actor, field names and versions in Audit Log');
submitCheck(rejected(fn()=>$service->updateOfficerRequest($requester,1,3,$_POST),AuthorizationException::class)&&rejected(fn()=>$service->updateOfficerRequest(new AuthenticatedPrincipal(53,1,['approver']),1,3,$_POST),AuthorizationException::class),'Requester and Director/Delegate cannot mutate through the officer edit service');
$repo->rows[1]['workflow_status']='assigned';submitCheck(rejected(fn()=>$service->updateOfficerRequest(new AuthenticatedPrincipal(52,1,['vehicle_officer']),1,3,$_POST),AuthorizationException::class),'assigned/closed requests are outside the officer edit status scope');$repo->rows[1]['workflow_status']='submitted';
submitCheck(rejected(fn()=>$service->updateOfficerRequest(new AuthenticatedPrincipal(52,1,['vehicle_officer']),1,2,$_POST),DomainException::class)&&$repo->rows[1]['version']===3,'stale optimistic version cannot overwrite a concurrent edit');
$repo->auditFails=true;$beforeEdit=$repo->rows[1];$editPayload=array_replace($_POST,['purpose'=>'rollback edit']);submitCheck(rejected(fn()=>$service->updateOfficerRequest(new AuthenticatedPrincipal(52,1,['vehicle_officer']),1,3,$editPayload),RuntimeException::class)&&$repo->rows[1]===$beforeEdit,'officer audit failure rolls back changed fields and version');$repo->auditFails=false;
$repo->requestConflict=true;$_POST=array_replace($_POST,['return_time'=>'18:00']);submitCheck(rejected(fn()=>$service->updateOfficerRequest(new AuthenticatedPrincipal(53,1,['admin']),1,3,$_POST),DomainException::class)&&$repo->rows[1]['version']===3,'route/time edit rechecks conflict and rolls back on overlap');$repo->requestConflict=false;
foreach(['admin','vehicle_officer','approver'] as $role) {
    $ownRepo=new SubmissionMemory();
    $ownActor=new AuthenticatedPrincipal(42,1,[$role]);
    $ownInput=BookingSubmissionInput::fromA4(['requester_id'=>'999','approval_outcome'=>'approved'],$now);
    $ownReceipt=(new BookingSubmissionService($ownRepo))->submit($ownActor,$ownInput);
    $ownRow=$ownRepo->rows[$ownReceipt['id']];
    submitCheck($ownRow['requester_id']===42 && $ownRow['workflow_status']==='submitted' && !isset($ownRow['approval_outcome']),
        $role.' submits as the signed-in account without approving the request');
}
$before=count($repo->rows);$repo->active=false;submitCheck(rejected(fn()=>$service->submit($requester,$input),DomainException::class)&&count($repo->rows)===$before,'inactive requested vehicle is rejected and rolled back');$repo->active=true;
$repo->capacity=7;submitCheck(rejected(fn()=>$service->submit($requester,$input),DomainException::class)&&count($repo->rows)===$before,'vehicle capacity is rechecked and a failing transaction rolls back');$repo->capacity=12;
$repo->requestConflict=true;submitCheck(rejected(fn()=>$service->submit($requester,$input),DomainException::class)&&count($repo->rows)===$before,'overlapping submitted request prevents insert');$repo->requestConflict=false;
$repo->assignmentConflict=true;submitCheck(rejected(fn()=>$service->submit($requester,$input),DomainException::class)&&count($repo->rows)===$before,'overlapping assignment prevents insert');$repo->assignmentConflict=false;
$repo->auditFails=true;submitCheck(rejected(fn()=>$service->submit($requester,$input),RuntimeException::class)&&count($repo->rows)===$before&&count($repo->history)===2,'audit failure rolls back request and history atomically');$repo->auditFails=false;
submitCheck(count($service->officerList(new AuthenticatedPrincipal(9,1,['vehicle_officer'])))===1,'Vehicle Officer sees submitted requests in the intake queue');
submitCheck($service->officerDetail($admin,1)['destination']==='ลำปาง','Admin can read stored submitted request detail');
submitCheck(rejected(fn()=>$service->officerList($requester),AuthorizationException::class)&&rejected(fn()=>$service->officerDetail(new AuthenticatedPrincipal(4,1,['approver']),1),AuthorizationException::class),'Requester and Director/Delegate roles are denied officer list and detail');
$csrfRepo=new SubmissionMemory();$csrfService=new BookingSubmissionService($csrfRepo);$_SESSION=['csrf_token'=>'correct','booking_submission_keys'=>[]];$_POST=['_token'=>'wrong'];http_response_code(200);ob_start();(new App\Controllers\BookingSubmissionController($csrfService))->submit($requester);$csrfBody=ob_get_clean();
submitCheck(http_response_code()===419&&json_decode($csrfBody,true)['error']==='CSRF_INVALID','invalid CSRF token is rejected before service execution');
$_POST=['_token'=>'correct'];http_response_code(200);ob_start();(new App\Controllers\BookingSubmissionController())->submit();$guestBody=ob_get_clean();
submitCheck(http_response_code()===403&&json_decode($guestBody,true)['error']==='FORBIDDEN','unauthenticated POST is denied');
http_response_code(200);ob_start();(new App\Controllers\BookingSubmissionController())->submit($admin);$adminBody=ob_get_clean();
submitCheck(http_response_code()===503&&json_decode($adminBody,true)['error']==='SUBMISSION_GATE_CLOSED','Admin submission also requires the write gate');
$_POST=['_token'=>'correct'];http_response_code(200);ob_start();(new App\Controllers\BookingSubmissionController())->submit($requester);$closedBody=ob_get_clean();
submitCheck(http_response_code()===503&&json_decode($closedBody,true)['error']==='SUBMISSION_GATE_CLOSED','valid Requester POST stays closed without the approved Write Gate');
$key=str_repeat('a',64);$_SESSION=['csrf_token'=>'correct','booking_submission_keys'=>[$key=>time()]];$_POST=array_replace($base,['_token'=>'correct','submission_key'=>$key]);$controller=new App\Controllers\BookingSubmissionController($csrfService,static fn(): DateTimeImmutable=>$now);
http_response_code(200);ob_start();$controller->submit($requester);$first=json_decode(ob_get_clean(),true);
http_response_code(200);ob_start();$controller->submit($requester);$again=json_decode(ob_get_clean(),true);
submitCheck(http_response_code()===200&&($first['status']??null)==='submitted'&&($again['duplicate']??false)===true&&count($csrfRepo->rows)===1,'offline controller idempotency returns the same receipt without a duplicate insert');
$changed=$_POST;$changed['destination']='แก้ไขหลังส่ง';$_POST=$changed;http_response_code(200);ob_start();$controller->submit($requester);$mismatch=json_decode(ob_get_clean(),true);
submitCheck(http_response_code()===409&&($mismatch['error']??null)==='DUPLICATE_PAYLOAD_MISMATCH'&&count($csrfRepo->rows)===1,'replay key cannot be reused with a changed payload');
$readPlan=App\Database\BookingOfficerReadGrantPlan::rules();$writePlan=App\Database\BookingSubmissionGrantPlan::rules();$editPlan=App\Database\BookingOfficerEditGrantPlan::rules();
submitCheck($readPlan['database']===[]&&array_keys($readPlan['tables']['booking_requests'])===['SELECT'],'officer Read Capability requires no booking write privileges');
submitCheck(isset($writePlan['tables']['booking_requests']['INSERT'],$writePlan['tables']['booking_requests']['UPDATE'],$writePlan['tables']['booking_status_history']['INSERT'],$writePlan['tables']['audit_logs']['INSERT']),'submission Grant Plan scopes only necessary booking, history and audit columns');
submitCheck(isset($editPlan['tables']['booking_requests']['UPDATE'],$editPlan['tables']['audit_logs']['INSERT'])&&!in_array('approval_outcome',$editPlan['tables']['booking_requests']['UPDATE'],true),'officer edit Grant Plan excludes approval fields and limits writes to A4 request fields plus audit');
$grantSql=implode(' ',App\Database\BookingSubmissionGrantPlan::proposedSql());
submitCheck(!preg_match('/GRANT\\s+.+\\s+ON\\s+\\*\\.|WITH\\s+GRANT\\s+OPTION|3307|lost_found/i',$grantSql),'proposed SQL grants no global rights or unrelated database privileges');
$pdo=file_get_contents(dirname(__DIR__).'/app/Repositories/PdoBookingSubmissionRepository.php');
submitCheck(str_contains($pdo,'SELECT id,is_active,passenger_capacity FROM vehicles WHERE id=:id FOR UPDATE')&&str_contains($pdo,'requested_start_at<:ends AND requested_end_at>:starts')&&str_contains($pdo,'starts_at<:ends AND ends_at>:starts'),'server-side PDO rechecks vehicle state and half-open conflict intervals inside transaction');
submitCheck(!str_contains($pdo,'SELECT *')&&!preg_match('/(?:^|[;\\n])\\s*(?:DROP|TRUNCATE|ALTER|GRANT|REVOKE)\\s+/i',$pdo),'PDO adapter uses explicit projections and contains no DDL/grant mutation');
$runtime=file_get_contents(dirname(__DIR__).'/app/Core/BookingRuntimeFeature.php');
submitCheck(str_contains($runtime,'TEST_BOOKING_SUBMISSION_APPROVED')&&str_contains($runtime,'TEST_BOOKING_OFFICER_READ_APPROVED')&&str_contains($runtime,'TEST_BOOKING_OFFICER_EDIT_APPROVED')&&str_contains($runtime,'TEST_DB_EXECUTION_APPROVED'),'separate test-only submit, officer-read and officer-edit gates default closed');
$runtimeService=file_get_contents(dirname(__DIR__).'/app/Core/BookingRuntimeFeature.php');
submitCheck(str_contains($runtimeService,"new PdoBookingSubmissionRepository(ConnectionFactory::connect('auth_test'),'submit')")&&str_contains($runtimeService,"new PdoBookingSubmissionRepository(ConnectionFactory::connect('auth_test'),'read')"),'runtime factories pair submission and officer read with separate exact grant profiles');
$officerController=new App\Controllers\OfficerBookingController($service,null);$_SESSION=['csrf_token'=>'correct'];$_POST=['_token'=>'correct','booking_id'=>'1','booking_version'=>'3'];http_response_code(200);ob_start();$officerController->update(new AuthenticatedPrincipal(52,1,['vehicle_officer']));$editClosed=json_decode(ob_get_clean(),true);
submitCheck(http_response_code()===503&&($editClosed['error']??null)==='OFFICER_EDIT_GATE_CLOSED','officer update POST remains denied while the edit gate is closed');
$directorController=new App\Controllers\OfficerBookingController($service,null);http_response_code(200);ob_start();$directorController->update(new AuthenticatedPrincipal(54,1,['approver']));$directorEdit=json_decode(ob_get_clean(),true);submitCheck(http_response_code()===403&&($directorEdit['error']??null)==='FORBIDDEN','Director/Delegate cannot invoke the officer edit POST');
$view=file_get_contents(dirname(__DIR__).'/app/Views/form-preview.php');
submitCheck(str_contains($view,'id="booking-submit-form"')&&str_contains($view,'id="submit-booking-request"')&&str_contains($view,'/assets/sweetalert2.min.js'),'existing A4 view contains gated submit UI and uses bundled SweetAlert2');
echo "PASS: $checks booking submission/officer intake offline checks; memory repository only, no database connection or HTTP POST.\n";
