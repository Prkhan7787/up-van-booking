<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/cli.php';
require dirname(__DIR__).'/app/helpers.php';
use App\Core\AuthorizationException;
use App\Models\AuthenticatedPrincipal;
use App\Modules\Booking\RequesterA4Presenter;
use App\Modules\Booking\RequesterDraftInput;
use App\Modules\Booking\RequesterDraftRepository;
use App\Modules\Booking\RequesterDraftService;

$checks=0;
function c(bool $yes,string $message):void {global $checks;if(!$yes){fwrite(STDERR,'FAIL: '.$message.PHP_EOL);throw new RuntimeException($message);}$checks++;}
function denied(callable $run,string $class):bool {try{$run();}catch(Throwable $e){return $e instanceof $class;}return false;}
final class DraftMemory implements RequesterDraftRepository {
    public array $rows=[],$history=[],$audits=[]; public bool $failAudit=false;
    public function transaction(callable $operation):mixed {$snapshot=[$this->rows,$this->history,$this->audits];try{return $operation();}catch(Throwable $e){[$this->rows,$this->history,$this->audits]=$snapshot;throw $e;}}
    public function listOwn(int $ownerId,int $limit,int $offset):array {return array_slice(array_values(array_filter($this->rows,static fn($r)=>(int)$r['requester_id']===$ownerId)),$offset,$limit);}
    public function listAll(int $limit,int $offset):array {return array_slice(array_values($this->rows),$offset,$limit);}
    public function find(int $id):?array {return $this->rows[$id]??null;}
    public function findOwned(int $id,int $ownerId):?array {$row=$this->find($id);return $row!==null && (int)$row['requester_id']===$ownerId ? $row : null;}
    public function lock(int $id):?array {return $this->find($id);}
    public function insert(int $ownerId,array $fields):int {$id=count($this->rows)+1;$this->rows[$id]=['id'=>$id,'requester_id'=>$ownerId,'workflow_status'=>'draft','version'=>1]+$fields;return $id;}
    public function update(int $id,int $ownerId,int $expectedVersion,array $fields):bool {if(!isset($this->rows[$id]) || (int)$this->rows[$id]['requester_id']!==$ownerId || $this->rows[$id]['version']!==$expectedVersion || $this->rows[$id]['workflow_status']!=='draft')return false;$this->rows[$id]=array_replace($this->rows[$id],$fields);$this->rows[$id]['version']++;return true;}
    public function appendDraftHistory(int $id,int $actorId,int $version):void {$this->history[]=[$id,$actorId,$version];}
    public function auditDraft(string $action,int $id,int $actorId,int $version):void {if($this->failAudit)throw new RuntimeException('synthetic audit failure');$this->audits[]=[$action,$id,$actorId,$version];}
}
$repo=new DraftMemory();$service=new RequesterDraftService($repo);
$owner=new AuthenticatedPrincipal(10,1,['requester']);$other=new AuthenticatedPrincipal(11,1,['requester']);$admin=new AuthenticatedPrincipal(12,1,['admin']);
$input=RequesterDraftInput::fromArray(['requester_name'=>'ผู้ขอ','destination'=>'พะเยา','passenger_count'=>'2','requested_start_at'=>'2026-11-20T08:00','requested_end_at'=>'2026-11-20T17:00','expense_source'=>'project_budget']);
c($input->fields['requested_start_at']==='2026-11-20 01:00:00' && $input->fields['requested_end_at']==='2026-11-20 10:00:00','Bangkok times convert to UTC');
c($input->fields['passenger_count']===2 && $input->fields['expense_source']==='project_budget','typed draft fields');
foreach ([['passenger_count'=>'0'],['requested_start_at'=>'2026-11-20T08:00'],['requested_start_at'=>'2026-11-20T18:00','requested_end_at'=>'2026-11-20T17:00'],['travel_type'=>'fake'],['application_date'=>'2026-02-30'],['destination'=>str_repeat('x',501)]] as $bad) c(denied(fn()=>RequesterDraftInput::fromArray($bad),InvalidArgumentException::class),'invalid field rejected');
c(denied(fn()=>$service->create($admin,$input),AuthorizationException::class),'Admin cannot create requester draft');
$id=$service->create($owner,$input);
c($id===1 && $repo->rows[1]['requester_id']===10 && $repo->rows[1]['workflow_status']==='draft','draft binds to authenticated owner');
c($repo->history===[[1,10,1]] && $repo->audits===[['booking.draft.created',1,10,1]],'create appends status history and audit atomically');
c(count($service->listOwn($owner,1))===1 && count($service->listOwn($other,1))===0,'my requests filter owner');
c(count($service->listAll($admin,1))===1 && denied(fn()=>$service->listAll($owner,1),AuthorizationException::class),'Admin list-all is read-only permission; Requester denied');
c(denied(fn()=>$service->detail($other,1),AuthorizationException::class),'other requester IDOR denied');
c($service->detail($admin,1)['requester_id']===10,'Admin can read booking only');
c(denied(fn()=>$service->update($admin,1,1,$input),AuthorizationException::class),'Admin cannot edit requester draft');
c(denied(fn()=>$service->update($other,1,1,$input),AuthorizationException::class),'other requester cannot edit');
$service->update($owner,1,1,$input);
c($repo->rows[1]['version']===2 && count($repo->history)===1 && $repo->audits[1][0]==='booking.draft.updated','update increments version and does not fabricate status history');
c(denied(fn()=>$service->update($owner,1,1,$input),DomainException::class),'stale expected version denied');
$repo->rows[1]['workflow_status']='submitted';
c(denied(fn()=>$service->update($owner,1,2,$input),AuthorizationException::class),'submitted booking cannot be edited as draft');
$repo->rows[1]['workflow_status']='draft';$repo->failAudit=true;$before=$repo->rows;
c(denied(fn()=>$service->update($owner,1,2,$input),RuntimeException::class) && $repo->rows===$before,'audit failure rolls back draft change');
$mapped=RequesterA4Presenter::formData($repo->rows[1]+['application_date'=>'2026-11-10','requester_name'=>'ผู้ขอ']);
c(($mapped['departure_date'] ?? '')==='20 พฤศจิกายน 2569' && ($mapped['departure_time'] ?? '')==='08:00','A4 presenter maps UTC to Thai date');
c(!isset($mapped['officer_signature'],$mapped['approval_outcome'],$mapped['driver_signature']),'A4 presenter does not invent decisions or signatures');
$routes=require dirname(__DIR__).'/routes/web.php';
c(!isset($routes['GET /bookings/new']) && isset($routes['GET /form-preview'],$routes['GET /bookings'],$routes['GET /bookings/admin'],$routes['GET /bookings/detail'],$routes['GET /bookings/document']) && $routes['GET /bookings/admin'][2]==='booking.view_all','old form route removed; A4 preview and booking read routes remain');
c(!isset($routes['POST /bookings'],$routes['POST /bookings/new'],$routes['POST /bookings/detail']) && $routes['POST /bookings/submit'][2]==='booking.submit_own','only the explicit requester submit endpoint exists; legacy write routes remain absent');
$grant=App\Database\RequesterDraftGrantPlan::rules();
c($grant['database']===[] && isset($grant['tables']['booking_requests']['SELECT'],$grant['tables']['booking_requests']['INSERT'],$grant['tables']['booking_requests']['UPDATE'],$grant['tables']['booking_status_history']['INSERT']),'grant plan is table/column scoped');
c(count(App\Database\RequesterDraftGrantPlan::supplementalSql())===2 && !str_contains(implode(' ',App\Database\RequesterDraftGrantPlan::supplementalSql()),'lost_found'),'grant SQL is test-only and limited to booking tables');
$pdoSource=file_get_contents(dirname(__DIR__).'/app/Repositories/PdoRequesterDraftRepository.php');
c(!preg_match('/\b(?:DROP|TRUNCATE|ALTER|GRANT|REVOKE)\b/i',$pdoSource),'booking PDO repository contains no DDL or grant operations');
c(str_contains($pdoSource,'WHERE id=:id AND requester_id=:owner AND workflow_status=') && str_contains($pdoSource,'version=:expected'),'update SQL enforces owner, draft state and expected version');
$_GET=[];$requesterActor=new AuthenticatedPrincipal(10,1,['requester']);ob_start();(new App\Controllers\FormPreviewController())->index($requesterActor);$formHtml=ob_get_clean();
c(str_contains($formHtml,'ส่วนที่ 1') && str_contains($formHtml,'id="document"') && str_contains($formHtml,'action="/bookings/submit"') && preg_match('/<button[^>]*id="submit-booking-request"[^>]*disabled/',$formHtml)===1,'existing A4 is the submit form and its write button remains disabled behind the closed gate');
preg_match_all('~<a href="/form-preview\?example=[^"]+"[^>]*>([^<]+)</a>~u',$formHtml,$exampleActions);
c(count($exampleActions[0])===1 && str_contains($exampleActions[0][0],'/form-preview?example=completed') && trim($exampleActions[1][0])==='ตัวอย่างคำขอ','only the renamed example action remains and still targets completed fixture');
$_GET=['example'=>'completed'];ob_start();(new App\Controllers\FormPreviewController())->index(new AuthenticatedPrincipal(12,1,['admin']));$completedExample=ob_get_clean();
c(str_contains($completedExample,'id="actual_return_time"') && str_contains($completedExample,'value="16:20"') && str_contains($completedExample,'นายทดสอบ ใจดี'),'renamed example preserves the original university-return fixture data');
ob_start();(new App\Controllers\RequesterBookingController())->index($owner);$unavailable=ob_get_clean();
c(http_response_code()===503 && str_contains($unavailable,'ยังไม่พร้อมใช้งาน'),'unwired list fails closed without DB');
ob_start();App\Core\View::render('form-preview',['formData'=>$mapped,'example'=>'','documentMode'=>true]);$document=ob_get_clean();
c(str_contains($document,'readonly') && !str_contains($document,'/form-preview?example=') && str_contains($document,'20 พฤศจิกายน 2569'),'persisted-model A4 reuses existing renderer without fixture menu');
ob_start();App\Core\View::render('form-preview',['formData'=>[],'example'=>'blank','formPermissions'=>['requestFields'=>true,'vehicleFields'=>true,'section3'=>false]]);$preview=ob_get_clean();
c(str_contains($preview,'ตัวอย่างสำหรับตรวจแบบฟอร์ม') && preg_match('/<input[^>]+id="requester_name"[^>]*>/',$preview,$requesterNameField)===1 && !str_contains($requesterNameField[0],'readonly') && !str_contains($preview,'ต้นแบบเท่านั้น · ข้อมูลตัวอย่างเป็นข้อมูลจำลอง'),'requester identity field remains editable without the removed prototype notice');
$transferSnapshot=['origin'=>'มหาวิทยาลัยพะเยา','destination'=>'เชียงใหม่','origin_lat'=>19.03,'origin_lng'=>99.89,'destination_lat'=>18.78,'destination_lng'=>98.98,'one_way_km'=>120.0,'return_km'=>125.0,'round_trip_km'=>245.0,'outbound_duration_minutes'=>120,'return_duration_minutes'=>125,'duration_minutes'=>245,'fuel_type'=>'แก๊สโซฮอล์ 95 S EVO','fuel_price_baht_per_l'=>40.0,'fuel_price_date'=>'17 พ.ย. 69','fuel_litres'=>null,'fuel_cost_baht'=>null];
ob_start();App\Core\View::render('form-preview',['formData'=>['destination'=>'เชียงใหม่','departure_date'=>'20 พฤศจิกายน 2569','departure_time'=>'08:00','return_date'=>'20 พฤศจิกายน 2569','return_time'=>'17:00'],'example'=>'','transferContext'=>['vehicle_id'=>1,'vehicle_label'=>'รถคันที่ 1','vehicle_registration'=>'กท3651','vehicle_province'=>'พะเยา','origin'=>'มหาวิทยาลัยพะเยา','start'=>'2026-11-20 08:00','end'=>'2026-11-20 17:00'],'travelSnapshot'=>$transferSnapshot]);$transferPreview=ob_get_clean();
c(str_contains($transferPreview,'<textarea name="destination" id="destination"') && str_contains($transferPreview,'>เชียงใหม่</textarea>') && str_contains($transferPreview,'id="departure_time"') && str_contains($transferPreview,'value="08:00"'),'travel-transfer data remains bound to editable A4 fields');
c(!str_contains($transferPreview,'travel-transfer-notice') && !str_contains($transferPreview,'ต้นแบบเท่านั้น · ข้อมูลตัวอย่างเป็นข้อมูลจำลอง') && !str_contains($transferPreview,'ระยะทางขาไป/กลับ/รวม'),'transfer and prototype summary blocks are absent from the UI');
echo "PASS: $checks Task 05C-1 offline checks; no database connection.\n";
