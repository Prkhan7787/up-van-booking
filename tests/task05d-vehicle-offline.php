<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/cli.php';
require dirname(__DIR__).'/app/helpers.php';
set_exception_handler(static function(Throwable $error):never {fwrite(STDERR,'Vehicle offline test error: '.get_class($error).' at line '.$error->getLine().PHP_EOL);exit(1);});

use App\Database\{AuthRuntimeGrantPlan,AdminUserGrantPlan,SystemLogGrantPlan,BookingCalendarGrantPlan,VehicleAdminGrantPlan,VehicleCalendarGrantPlan,GrantVerifier,ConnectionFactory,RuntimeColumnProbe};
use App\Models\AuthenticatedPrincipal;
use App\Modules\Booking\{VehicleInput,VehicleRepository,VehicleManagementService,VehicleCalendarRepository,VehicleCalendarService,BookingCalendarWindow,VehicleSchemaValidator};

$checks=0;
function vehicleCheck(bool $ok,string $label):void {global $checks;if(!$ok){fwrite(STDERR,'FAIL: '.$label.PHP_EOL);throw new RuntimeException('Vehicle offline check failed');}$checks++;}
function vehicleReject(callable $action,string $type=Throwable::class):bool {try{$action();}catch(Throwable $error){return $error instanceof $type;}return false;}

$schema=file_get_contents(dirname(__DIR__).'/database/migrations/002_booking_design.sql');
vehicleCheck(str_contains($schema,'CREATE TABLE vehicles (') && str_contains($schema,'UNIQUE KEY uq_vehicle_registration (registration_number, registration_province)'),'existing vehicle schema and composite uniqueness identified');
vehicleCheck(!str_contains($schema,'requested_vehicle_id'),'Migration 002 has no requester-selected vehicle column');
$postMigrationIndexes=[['uq_vehicle_registration_number','registration_number',1,0]];
VehicleSchemaValidator::assertRegistrationOnly(0,$postMigrationIndexes);
vehicleCheck(true,'Migration 005 schema accepts registration_number-only unique index');
VehicleSchemaValidator::assertRegistrationOnly(1,$postMigrationIndexes);
vehicleCheck(true,'Migration 006 schema accepts one nullable province column with the Migration 005 unique index');
vehicleCheck(vehicleReject(fn()=>VehicleSchemaValidator::assertRegistrationOnly(2,$postMigrationIndexes),RuntimeException::class),'duplicate province metadata is rejected');
vehicleCheck(vehicleReject(fn()=>VehicleSchemaValidator::assertRegistrationOnly(0,[['uq_vehicle_registration_number','registration_number',1,0],['uq_vehicle_registration_number','label',2,0]]),RuntimeException::class),'Migration 005 schema rejects composite replacement index');
vehicleCheck(vehicleReject(fn()=>VehicleSchemaValidator::assertRegistrationOnly(0,[['uq_vehicle_registration','registration_number',1,0],['uq_vehicle_registration','registration_province',2,0]]),RuntimeException::class),'Migration 005 schema rejects old composite unique index');
$routes=require dirname(__DIR__).'/routes/web.php';
vehicleCheck(($routes['GET /admin/vehicles'][2] ?? null)==='vehicles.admin' && ($routes['POST /admin/vehicles'][2] ?? null)==='vehicles.admin','Admin vehicle routes both require server RBAC');
putenv('TEST_VEHICLE_MANAGEMENT_APPROVED=false');
vehicleCheck(!App\Core\VehicleManagementFeature::writesEnabled(),'Vehicle POST gate remains closed by default');
vehicleCheck(!isset($routes['POST /bookings/new']) && !isset($routes['POST /bookings/calendar']),'Booking POST remains closed');

$admin=new AuthenticatedPrincipal(1,1,['admin']);$requester=new AuthenticatedPrincipal(2,1,['requester']);
$input=VehicleInput::fromArray(['label'=>'รถทดลอง','registration_number'=>'กข 1234','passenger_capacity'=>'12']);
vehicleCheck($input->passengerCapacity===12 && $input->registrationNumber==='กข 1234','vehicle input validates source fixture without database insert');
foreach ([['label'=>'','registration_number'=>'กข 1234','passenger_capacity'=>'12'],['label'=>'รถทดลอง','registration_number'=>'กข 1234','passenger_capacity'=>'0'],['label'=>'รถทดลอง','registration_number'=>' กข 1234','passenger_capacity'=>'12'],['label'=>'รถทดลอง','registration_number'=>'กท 3651 พะเยา','registration_province'=>'พะเยา','passenger_capacity'=>'12']] as $bad) vehicleCheck(vehicleReject(fn()=>VehicleInput::fromArray($bad),InvalidArgumentException::class),'invalid vehicle input denied');
$provinceInput=VehicleInput::fromArray(['label'=>'รถพะเยา','registration_number'=>'กท 3651','registration_province'=>'พะเยา','passenger_capacity'=>'12']);
vehicleCheck($provinceInput->registrationProvince==='พะเยา','province is accepted as a distinct validated field');

final class MemoryVehicles implements VehicleRepository {
    public array $rows=[],$events=[];public bool $rolledBack=false,$assigned=false,$throwDuplicateKeyOnInsert=false,$failListing=false,$provinceEnabled=false;
    public function supportsFuelDetails():bool{return false;}
    public function supportsRegistrationProvince():bool{return $this->provinceEnabled;}
    public function list(int $limit,int $offset):array{if($this->failListing)throw new RuntimeException('OFFLINE_LIST_FAILURE');return array_slice(array_values(array_filter($this->rows,static fn(array $row):bool=>(bool)$row['is_active'])),$offset,$limit);}
    public function find(int $id,bool $forUpdate=false):?array{return $this->rows[$id]??null;}
    public function registrationExists(string $registration,int $exceptId=0):bool {foreach($this->rows as $id=>$row)if($id!==$exceptId && mb_strtolower($row['registration_number'])===mb_strtolower($registration))return true;return false;}
    public function hasAssignments(int $id):bool {return $this->assigned;}
    public function transaction(callable $operation):mixed {$oldRows=$this->rows;$oldEvents=$this->events;try{return $operation();}catch(Throwable $error){$this->rows=$oldRows;$this->events=$oldEvents;$this->rolledBack=true;throw $error;}}
    public function insert(VehicleInput $input,int $actorId):int {if($this->throwDuplicateKeyOnInsert){$error=new PDOException('duplicate');$error->errorInfo=['23000',1062,'duplicate'];throw $error;}$id=count($this->rows)+1;$this->rows[$id]=['id'=>$id,'registration_number'=>$input->registrationNumber,'registration_province'=>$input->registrationProvince,'label'=>$input->label,'passenger_capacity'=>$input->passengerCapacity,'is_active'=>1];return $id;}
    public function update(int $id,VehicleInput $input,int $actorId):void {$this->rows[$id]=array_replace($this->rows[$id],['registration_number'=>$input->registrationNumber,'registration_province'=>$input->registrationProvince,'label'=>$input->label,'passenger_capacity'=>$input->passengerCapacity]);}
    public bool $ignoreDeactivate=false;
    public function deactivate(int $id,int $actorId):void {if(!$this->ignoreDeactivate)$this->rows[$id]['is_active']=0;}
    public bool $failAudit=false;
    public function audit(int $actorId,string $action,int $vehicleId,array $before,array $after):void {if($this->failAudit)throw new RuntimeException('OFFLINE_AUDIT_FAILURE');$this->events[]=compact('actorId','action','vehicleId','before','after');}
}
$memory=new MemoryVehicles();$service=new VehicleManagementService($memory);
vehicleCheck(vehicleReject(fn()=>$service->create($requester,$input),App\Core\AuthorizationException::class),'non-Admin cannot create vehicle');
vehicleCheck(vehicleReject(fn()=>$service->listing($requester),App\Core\AuthorizationException::class),'non-Admin cannot list admin vehicles');
$id=$service->create($admin,$input);
vehicleCheck($id===1 && count($memory->events)===1 && $memory->events[0]['action']==='vehicle.created','Admin create is transactional and audited');
vehicleCheck($memory->events[0]['actorId']===1 && $memory->events[0]['before']===[] && $memory->events[0]['after']['registration_number']==='กข 1234','create audit includes actor, target, and safe new values');
vehicleCheck(vehicleReject(fn()=>$service->create($admin,$input),DomainException::class) && count($memory->rows)===1,'duplicate registration denied and rolled back');
$memory->throwDuplicateKeyOnInsert=true;
vehicleCheck(vehicleReject(fn()=>$service->create($admin,VehicleInput::fromArray(['label'=>'รถแข่ง','registration_number'=>'ขข 9999','passenger_capacity'=>'12'])),PDOException::class) && $memory->rolledBack && count($memory->events)===1,'concurrent duplicate-key error rolls back transaction without a false audit');
$memory->throwDuplicateKeyOnInsert=false;
$eventCount=count($memory->events);$rowCount=count($memory->rows);
vehicleCheck(vehicleReject(fn()=>$service->update($admin,999,$input),DomainException::class) && count($memory->rows)===$rowCount && count($memory->events)===$eventCount,'unknown vehicle ID cannot be updated (IDOR guard)');
$changed=VehicleInput::fromArray(['label'=>'รถทดลองปรับปรุง','registration_number'=>'กข 1234','passenger_capacity'=>'10']);
$service->update($admin,$id,$changed);
vehicleCheck($memory->rows[$id]['passenger_capacity']===10 && $memory->events[1]['before']['passenger_capacity']===12 && $memory->events[1]['after']['passenger_capacity']===10,'update audits old and new values');
vehicleCheck(vehicleReject(fn()=>$service->update($requester,$id,$changed),App\Core\AuthorizationException::class),'non-Admin cannot update vehicle');
$memory->assigned=true;
vehicleCheck(vehicleReject(fn()=>$service->update($admin,$id,$changed),DomainException::class),'assigned vehicle cannot be edited');
$memory->assigned=false;
$service->delete($admin,$id);
vehicleCheck($memory->rows[$id]['is_active']===0 && $memory->events[2]['action']==='vehicle.deleted','delete soft-disables and audits');
vehicleCheck(vehicleReject(fn()=>$service->delete($admin,$id),DomainException::class) && count($memory->events)===3,'repeat delete cannot create a false audit event');

$retired=new MemoryVehicles();$retireService=new VehicleManagementService($retired);$retireId=$retireService->create($admin,$input);
vehicleCheck(vehicleReject(fn()=>$retireService->delete($requester,$retireId),App\Core\AuthorizationException::class),'non-Admin cannot delete vehicles');
vehicleCheck(vehicleReject(fn()=>$retireService->delete($admin,999),DomainException::class),'unknown delete target is rejected');
$retired->assigned=true;$retireService->delete($admin,$retireId);
vehicleCheck(isset($retired->rows[$retireId]) && $retired->rows[$retireId]['is_active']===0 && $retired->events[1]['after']['has_assignments']===true && $retired->events[1]['action']==='vehicle.deleted','delete with assignment history soft-retires while retaining the row and audit');
$retired->rows[$retireId]['is_active']=1;$retired->failAudit=true;
vehicleCheck(vehicleReject(fn()=>$retireService->delete($admin,$retireId)) && $retired->rows[$retireId]['is_active']===1 && $retired->rolledBack,'delete rolls back when audit fails');
$retired->failAudit=false;$retired->assigned=false;$retireService->delete($admin,$retireId);
vehicleCheck(isset($retired->rows[$retireId]) && $retired->rows[$retireId]['is_active']===0,'no-history delete also retains row until hard-delete policy is approved');
vehicleCheck($retireService->listing($admin)===[] && $retired->find($retireId)!==null,'retired vehicle is hidden from active listing while history lookup retains the row');
$eventCount=count($retired->events);
vehicleCheck(vehicleReject(fn()=>$retireService->delete($admin,$retireId),DomainException::class) && count($retired->events)===$eventCount,'repeat delete fails without a false success audit');
$retired->rows[$retireId]['is_active']=1;$retired->ignoreDeactivate=true;
vehicleCheck(vehicleReject(fn()=>$retireService->delete($admin,$retireId),DomainException::class) && $retired->rows[$retireId]['is_active']===1 && count($retired->events)===$eventCount,'delete verifies persisted inactive state before success and rolls back no-op writes');
$retired->ignoreDeactivate=false;

$controller=new App\Controllers\AdminVehicleController($service);
$_SESSION=[];$_POST=['action'=>'create','label'=>'รถใหม่','registration_number'=>'คค 4321','passenger_capacity'=>'8'];
ob_start();$controller->submit($admin);ob_end_clean();
vehicleCheck(http_response_code()===503 && count($memory->rows)===1,'closed gate rejects POST before service mutation');
putenv('TEST_VEHICLE_MANAGEMENT_APPROVED=true'); // Fake repository only; never opens a real connection.
vehicleCheck(vehicleReject(fn()=>$controller->submit($requester),App\Core\AuthorizationException::class),'HTTP controller denies non-Admin before gate and database');
ob_start();$controller->submit($admin);ob_end_clean();
vehicleCheck(http_response_code()===419 && count($memory->rows)===1,'HTTP controller rejects missing CSRF');
$_POST=['action'=>'deactivate','vehicle_id'=>(string)$id,'_token'=>App\Core\Csrf::token()];
$controller->submit($admin);
vehicleCheck(!isset($_SESSION['admin_vehicle_notice']) && isset($_SESSION['admin_vehicle_save_error']) && $memory->rows[$id]['is_active']===0,'separate deactivate action is rejected without state change');
unset($_SESSION['admin_vehicle_save_error']);
$_POST=['action'=>'create','label'=>'รถใหม่','registration_number'=>'คค 4321','passenger_capacity'=>'8','is_active'=>'0'];
$_POST['_token']=App\Core\Csrf::token();
$controller->submit($admin);
vehicleCheck(count($memory->rows)===2 && $memory->rows[2]['is_active']===1 && ($_SESSION['admin_vehicle_notice'] ?? '')==='บันทึกข้อมูลรถตู้สำเร็จ','authorized create ignores forged inactive field and inserts active vehicle');
$_POST=['action'=>'update','vehicle_id'=>'2','label'=>'รถใหม่แก้ไข','registration_number'=>'คค 4321','passenger_capacity'=>'8','is_active'=>'0','_token'=>App\Core\Csrf::token()];
$controller->submit($admin);
vehicleCheck($memory->rows[2]['is_active']===1 && $memory->rows[2]['label']==='รถใหม่แก้ไข','normal update ignores forged is_active and preserves active vehicle');
vehicleCheck(http_response_code()===303,'successful POST redirects to GET');
ob_start();$controller->index($admin);$successPage=ob_get_clean();
vehicleCheck(str_contains($successPage,'data-admin-notice="success"') && !isset($_SESSION['admin_vehicle_notice']),'GET consumes success flash exactly once');
ob_start();$controller->index($admin);$refreshPage=ob_get_clean();
vehicleCheck(!str_contains($refreshPage,'data-admin-notice="success"'),'GET refresh does not repeat success popup');
$_POST=['action'=>'delete','vehicle_id'=>'2','_token'=>App\Core\Csrf::token()];
$controller->submit($admin);
vehicleCheck(http_response_code()===303 && ($_SESSION['admin_vehicle_notice']??null)==='ลบรถตู้สำเร็จ' && $memory->rows[2]['is_active']===0,'delete POST redirects with action-specific success only after soft retirement');
ob_start();$controller->index($admin);$deletedPage=ob_get_clean();
vehicleCheck(str_contains($deletedPage,'data-admin-notice-text="ลบรถตู้สำเร็จ"') && !str_contains($deletedPage,'value="คค 4321"') && !isset($_SESSION['admin_vehicle_notice']),'delete result hides vehicle and consumes escaped popup message');
ob_start();$controller->index($admin);$deletedRefresh=ob_get_clean();
vehicleCheck(!str_contains($deletedRefresh,'data-admin-notice="success"'),'refresh after delete neither repeats POST nor success flash');
$controller->submit($admin);
vehicleCheck(http_response_code()===303 && !isset($_SESSION['admin_vehicle_notice']) && isset($_SESSION['admin_vehicle_save_error']),'repeated delete has error flash and no success flash');
unset($_SESSION['admin_vehicle_save_error']);
$memory->throwDuplicateKeyOnInsert=true;$_POST=['action'=>'create','label'=>'รถซ้ำ','registration_number'=>'งง 5555','passenger_capacity'=>'12','_token'=>App\Core\Csrf::token()];
ob_start();$controller->submit($admin);$duplicateOutput=ob_get_clean();
vehicleCheck(http_response_code()===303 && $duplicateOutput==='' && ($_SESSION['admin_vehicle_save_error']??null)==='หมายเลขทะเบียนซ้ำ' && count($memory->rows)===2,'failed POST redirects with safe error flash without repeating the write');
ob_start();$controller->index($admin);$failurePage=ob_get_clean();
vehicleCheck(str_contains($failurePage,'data-vehicle-save-error') && str_contains($failurePage,'หมายเลขทะเบียนซ้ำ') && !isset($_SESSION['admin_vehicle_save_error']),'GET after failed POST displays and consumes error flash');
ob_start();$controller->index($admin);$refreshPage=ob_get_clean();
vehicleCheck(!str_contains($refreshPage,'data-vehicle-save-error'),'refresh does not repeat save failure');
$memory->failListing=true;
foreach([1,2] as $refresh){ob_start();$controller->index($admin);$loadFailurePage=ob_get_clean();vehicleCheck(http_response_code()===503 && str_contains($loadFailurePage,'data-vehicle-load-error') && !str_contains($loadFailurePage,'data-vehicle-save-error') && !str_contains($loadFailurePage,'data-admin-notice="success"'),'GET database failure stays inline on every refresh');}
$memory->failListing=false;
$provinceRepo=new MemoryVehicles();$provinceRepo->provinceEnabled=true;$provinceService=new VehicleManagementService($provinceRepo);
vehicleCheck(vehicleReject(fn()=>$provinceService->create($admin,$input),InvalidArgumentException::class) && $provinceRepo->rows===[],'province is required by service once Migration 006 schema is detected');
$provinceId=$provinceService->create($admin,$provinceInput);
vehicleCheck(($provinceRepo->rows[$provinceId]['registration_province']??null)==='พะเยา' && ($provinceRepo->events[0]['after']['registration_province']??null)==='พะเยา','province is persisted and included in audit snapshot');
$provincePageController=new App\Controllers\AdminVehicleController($provinceService);$_SESSION=[];ob_start();$provincePageController->index($admin);$provincePage=ob_get_clean();
vehicleCheck(str_contains($provincePage,'name="registration_province"') && str_contains($provincePage,'value="พะเยา"') && str_contains($provincePage,'ทะเบียน กท 3651 · 12 ที่นั่ง'),'Admin UI loads province into its own input with compact plate/capacity subtitle');
$js=file_get_contents(dirname(__DIR__).'/public/assets/app.js');
vehicleCheck(str_contains($js,"querySelector('[data-vehicle-save-error]')") && !str_contains($js,"querySelector('[data-vehicle-load-error]')"),'SweetAlert only selects POST save error marker');
$memory->throwDuplicateKeyOnInsert=false;
$duplicateRepo=new MemoryVehicles();$duplicateService=new VehicleManagementService($duplicateRepo);
$duplicateId=$duplicateService->create($admin,$input);$duplicateService->delete($admin,$duplicateId);
$before=$duplicateRepo->rows[$duplicateId];$eventCount=count($duplicateRepo->events);
vehicleCheck(vehicleReject(fn()=>$duplicateService->create($admin,$input),DomainException::class) && count($duplicateRepo->rows)===1 && $duplicateRepo->rows[$duplicateId]===$before && count($duplicateRepo->events)===$eventCount,'soft-deleted registration remains unique without creating, restoring or auditing a vehicle');
$duplicateController=new App\Controllers\AdminVehicleController($duplicateService);
$_SESSION=[];$_POST=['action'=>'create','label'=>'รถซ้ำ','registration_number'=>$input->registrationNumber,'passenger_capacity'=>'10','_token'=>App\Core\Csrf::token()];
$duplicateController->submit($admin);
vehicleCheck(http_response_code()===303 && ($_SESSION['admin_vehicle_save_error']??null)==='หมายเลขทะเบียนซ้ำ' && !isset($_SESSION['admin_vehicle_notice']) && !isset($_SESSION['admin_vehicle_restore_id']),'inactive duplicate shows only the duplicate error and no restore offer');
ob_start();$duplicateController->index($admin);$duplicatePage=ob_get_clean();
vehicleCheck(!str_contains($duplicatePage,'data-vehicle-restore-offer') && !str_contains($duplicatePage,'data-vehicle-action="restore"'),'Admin view does not render a restore action');
$_POST=['action'=>'restore','vehicle_id'=>(string)$duplicateId,'_token'=>App\Core\Csrf::token()];
$duplicateController->submit($admin);
vehicleCheck(!isset($_SESSION['admin_vehicle_notice']) && $duplicateRepo->rows[$duplicateId]===$before && count($duplicateRepo->events)===$eventCount,'forged restore action is rejected without mutation or audit');
putenv('TEST_VEHICLE_MANAGEMENT_APPROVED=false');$_POST=[];

$migration=file_get_contents(dirname(__DIR__).'/database/drafts/005_vehicle_registration_only.sql');
vehicleCheck(str_contains($migration,'ADD UNIQUE KEY uq_vehicle_registration_number (registration_number)') && str_contains($migration,'DROP INDEX uq_vehicle_registration') && str_contains($migration,'DROP COLUMN registration_province'),'forward draft changes only vehicle registration schema');
$manifest=require dirname(__DIR__).'/database/manifest.php';
$migration005=array_values(array_filter($manifest,static fn(array $entry):bool=>($entry['version']??null)==='005_vehicle_registration_only'))[0]??null;
vehicleCheck(is_array($migration005) && $migration005['file']==='database/drafts/005_vehicle_registration_only.sql' && hash_equals($migration005['sha256'],hash('sha256',$migration)),'applied Migration 005 remains pinned in manifest with exact checksum');
$vehicleSql=file_get_contents(dirname(__DIR__).'/app/Repositories/PdoVehicleRepository.php');
vehicleCheck(str_contains($vehicleSql,'FROM vehicles WHERE is_active=1 ORDER BY id DESC LIMIT :limit OFFSET :offset'),'production active vehicle SQL filters soft deletions before pagination');
vehicleCheck(str_contains($js,"titleText: adminNotice.dataset.adminNoticeText || 'บันทึกสำเร็จ'"),'SweetAlert uses plain-text action message with shared-page fallback');
vehicleCheck(str_contains($vehicleSql,"'up_van_booking_test'") && str_contains($vehicleSql,"'up_van_auth_test@127.0.0.1'"),'vehicle repository pins test database and runtime account');
$schemaValidator=file_get_contents(dirname(__DIR__).'/app/Modules/Booking/VehicleSchemaValidator.php');
vehicleCheck(str_contains($vehicleSql,'NULL AS registration_province') && !str_contains($vehicleSql,'requested_vehicle_id'),'Schema 005/006 repository projection is independent of Booking calendar schema');
vehicleCheck(!str_contains($vehicleSql,'SELECT `requested_vehicle_id` FROM `booking_requests` LIMIT 0') && str_contains($vehicleSql,'RuntimeColumnProbe::inspect'),'requested vehicle schema probe is isolated from Vehicle Management');
vehicleCheck(str_contains($vehicleSql,'VehicleSchemaValidator::assertRegistrationOnly') && str_contains($schemaValidator,"'uq_vehicle_registration_number'") && str_contains($schemaValidator,"'registration_number',1,0") && !str_contains($schemaValidator,"'uq_vehicle_registration',"),'repository fails closed unless exact Migration 005 metadata is present');
$preflightSource=file_get_contents(dirname(__DIR__).'/scripts/task04c-1a-readonly-preflight.php');
vehicleCheck(str_contains($preflightSource,'VehicleAdminGrantPlan::evaluateCapability(') && str_contains($preflightSource,"'vehicle_management_capability'") && str_contains($preflightSource,"'vehicle_management_missing_privileges'"),'read-only runtime preflight shares the repository capability check and reports it separately');
vehicleCheck(str_contains($preflightSource,'$vehicleCapabilityStatus===\'FAIL\'') && str_contains($preflightSource,'$vehicleCapabilityStatus===\'PASS\''),'read-only preflight cannot report overall PASS while vehicle capability is missing');
vehicleCheck(!preg_match('/(?im)^\s*(?:INSERT|UPDATE|DELETE|ALTER|CREATE|DROP|GRANT|REVOKE)\b/',$preflightSource),'runtime capability diagnostic contains no mutation statement');
vehicleCheck(str_contains($vehicleSql,'SELECT 1 FROM vehicle_assignments WHERE vehicle_id=:id') && str_contains($vehicleSql,' FOR UPDATE'),'assignment guard and vehicle row lock are present');
vehicleCheck(str_contains($vehicleSql,'$this->provinceEnabled') && str_contains($vehicleSql,'registration_province=:province') && !str_contains($vehicleSql,"'synthetic'"),'repository writes province only when schema supports it and never uses a placeholder');
vehicleCheck(str_contains($vehicleSql,'INSERT INTO vehicles') && str_contains($vehicleSql,'UPDATE vehicles SET registration_number=:registration') && str_contains($vehicleSql,'UPDATE vehicles SET is_active=0,updated_by=:actor'),'create, update, deactivate SQL matches detected schema variant');

final class FixtureVehicleCalendar implements VehicleCalendarRepository {
    public array $ids=[];
    public array $activeRows=[['id'=>1,'registration_number'=>'กข 1234','label'=>'รถทดลอง','passenger_capacity'=>12,'is_active'=>1],['id'=>2,'registration_number'=>'ขข 5678','label'=>'รถสำรอง','passenger_capacity'=>10,'is_active'=>1]];
    public function activeVehicles():array{return $this->activeRows;}
    public function activeVehicle(int $id):?array {$this->ids[]=$id;return $id===1 ? $this->activeVehicles()[0] : null;}
    public function monthWindows(int $vehicleId,string $startUtc,string $endUtc):array {$this->ids[]=$vehicleId;return ['pending'=>[['start_at'=>'2026-11-20 01:00:00','end_at'=>'2026-11-20 10:00:00']],'confirmed'=>[['start_at'=>'2026-11-21 01:00:00','end_at'=>'2026-11-21 10:00:00']]];}
    public function available(int $vehicleId,BookingCalendarWindow $window):bool {$this->ids[]=$vehicleId;return $window->startUtc!=='2026-11-21 01:00:00';}
    public function pending(int $vehicleId,BookingCalendarWindow $window):int {$this->ids[]=$vehicleId;return 1;}
}
$calendarRepo=new FixtureVehicleCalendar();$calendar=new VehicleCalendarService($calendarRepo);
vehicleCheck(count($calendar->vehicles($requester))===2 && array_column($calendar->vehicles($requester),'id')===[1,2],'vehicle selector supports multiple active vehicle IDs');
$calendarRepo->activeRows=[];
vehicleCheck($calendar->vehicles($requester)===[],'empty active fleet renders an empty selector source');
$calendarRepo->activeRows=[['id'=>1,'registration_number'=>'กข 1234','label'=>'รถทดลอง','passenger_capacity'=>12,'is_active'=>1]];
vehicleCheck(count($calendar->vehicles($requester))===1,'single active vehicle is selectable');
vehicleCheck(vehicleReject(fn()=>$calendar->vehicle($requester,99),InvalidArgumentException::class) && vehicleReject(fn()=>$calendar->vehicle($requester,0),InvalidArgumentException::class),'invalid, missing or inactive vehicle IDs are denied');
$window=BookingCalendarWindow::fromQuery(['start'=>'2026-11-20T08:00','end'=>'2026-11-20T17:00','passengers'=>'8']);
$quote=$calendar->quote($requester,1,$window);
vehicleCheck($quote['eligible']===1 && $quote['available']===1 && $quote['pending']===1 && $quote['canPrepareForm'],'exact vehicle quote checks capacity and overlap while pending remains separate');
$calendarWindow=BookingCalendarWindow::fromQuery(['start'=>'2026-11-20T08:00','end'=>'2026-11-20T17:00']);
$timeQuote=$calendar->quote($requester,1,$calendarWindow);
vehicleCheck($calendarWindow->passengers===null && $timeQuote['eligible']===1 && $timeQuote['available']===1,'calendar per-vehicle availability uses selected vehicle/time without passenger count');
$tooMany=BookingCalendarWindow::fromQuery(['start'=>'2026-11-20T08:00','end'=>'2026-11-20T17:00','passengers'=>'13']);
vehicleCheck($calendar->quote($requester,1,$tooMany)['available']===0 && $calendar->quote($requester,1,$tooMany)['eligible']===0,'capacity rejects oversized group');
$month=$calendar->month($requester,1,'2026-11');
vehicleCheck($month['days'][19]['status']==='pending' && $month['days'][20]['status']==='assigned','vehicle month separates pending preference and confirmed assignment');
vehicleCheck(vehicleReject(fn()=>$calendar->quote($requester,2,$window),InvalidArgumentException::class),'unknown vehicle ID denied');
vehicleCheck(in_array(1,$calendarRepo->ids,true) && in_array(2,$calendarRepo->ids,true),'repository receives numeric vehicle ID, not plate string');

$base=[...AuthRuntimeGrantPlan::grantSql(false),...AdminUserGrantPlan::supplementalSql(),SystemLogGrantPlan::supplementalSql()];
$account="'up_van_auth_test'@'127.0.0.1'";
$adminGrants=[...$base,...VehicleAdminGrantPlan::supplementalSql()];
vehicleCheck(GrantVerifier::evaluate($adminGrants,'up_van_booking_test',VehicleAdminGrantPlan::rules(),false,true)['status']==='PASS','exact vehicle admin grant plan accepted');
vehicleCheck(VehicleAdminGrantPlan::evaluateForSchema($adminGrants,false,true,false,false)['status']==='PASS','Schema 005 vehicle CRUD passes without province or requested-vehicle grants');
$provinceOnlyGrants=[...$adminGrants,...VehicleAdminGrantPlan::supplementalProvinceSql()];
vehicleCheck(VehicleAdminGrantPlan::evaluateForSchema($provinceOnlyGrants,false,true,true,false)['status']==='PASS','Schema 006 province-enabled CRUD passes without requested-vehicle grant');
vehicleCheck(VehicleAdminGrantPlan::evaluateForSchema($adminGrants,false,true,true,false)['status']==='FAIL','Schema 006 still requires province grants when the column exists');
vehicleCheck(VehicleAdminGrantPlan::evaluateForSchema($adminGrants,false,true,false,true)['status']==='PASS','requested_vehicle_id does not add requirements to Vehicle Management capability');
vehicleCheck(VehicleAdminGrantPlan::evaluateCapability($adminGrants,false,true,false,false)['status']==='PASS','Schema 005 capability permits no province column');
vehicleCheck(VehicleAdminGrantPlan::evaluateCapability($provinceOnlyGrants,false,true,true,false)['status']==='PASS','Schema 006 capability accepts province grants');
vehicleCheck(VehicleAdminGrantPlan::evaluateCapability($adminGrants,false,true,true,false)['status']==='FAIL','Schema 006 capability requires province grants');
vehicleCheck(ConnectionFactory::evaluateAuthRuntimeGrants($adminGrants,false,true)['status']==='PASS' && ConnectionFactory::evaluateAuthRuntimeGrants($provinceOnlyGrants,false,true)['status']==='PASS','ConnectionFactory grant guard accepts Schema 005 and 006 without requiring requested_vehicle_id');
vehicleCheck(VehicleAdminGrantPlan::evaluate($provinceOnlyGrants,false,true)['status']==='PASS','runtime grant verifier recognizes a reviewed residual province grant after column drop');
vehicleCheck(GrantVerifier::evaluate([...$adminGrants,"GRANT ALTER ON `up_van_booking_test`.`vehicles` TO {$account}"],'up_van_booking_test',VehicleAdminGrantPlan::rules(),false,true)['status']==='FAIL','vehicle DDL denied');
$vehicleGrantSql=VehicleAdminGrantPlan::supplementalSql();
vehicleCheck(count($vehicleGrantSql)===2 && str_contains($vehicleGrantSql[0],'INSERT (`registration_number`, `label`, `passenger_capacity`, `is_active`, `created_by`, `updated_by`)') && str_contains($vehicleGrantSql[0],'UPDATE (`registration_number`, `label`, `passenger_capacity`, `is_active`, `updated_by`)') && str_contains($vehicleGrantSql[0],'`up_van_booking_test`.`vehicles`') && str_contains($vehicleGrantSql[1],'`vehicle_id`) ON `up_van_booking_test`.`vehicle_assignments`') ,'grant SQL is limited to reviewed test vehicle columns and assignment lookup');
$fullApprovedUnion=[...$base,...BookingCalendarGrantPlan::supplementalSql(),...VehicleCalendarGrantPlan::supplementalSql(),...$vehicleGrantSql];
vehicleCheck(GrantVerifier::evaluate($fullApprovedUnion,'up_van_booking_test',VehicleAdminGrantPlan::rules(true,true),false,true)['status']==='PASS','full approved Auth/Admin/Logs/Booking/Calendar/Vehicle grant union is accepted');
$withoutUpdate=array_values(array_filter($adminGrants,static fn(string $grant):bool=>!str_contains($grant,'UPDATE (`registration_number`')));
vehicleCheck(GrantVerifier::evaluate($withoutUpdate,'up_van_booking_test',VehicleAdminGrantPlan::rules(),false,true)['status']==='FAIL','vehicle update columns are required for edit/deactivate operations');
$calendarGrants=[...$base,...BookingCalendarGrantPlan::supplementalSql(),...VehicleCalendarGrantPlan::supplementalSql()];
$existingCalendar=[...$base,...BookingCalendarGrantPlan::supplementalSql()];
$runtime=App\Database\ConnectionFactory::evaluateAuthRuntimeGrants($existingCalendar,false,true);
vehicleCheck($runtime['status']==='PASS' && $runtime['unexpected_privileges']===[],'preflight and ConnectionFactory accept existing approved Booking/Calendar grants');
$vehicleMissing=VehicleAdminGrantPlan::evaluate($existingCalendar,false,true);
vehicleCheck($vehicleMissing['status']==='FAIL' && count($vehicleMissing['missing_privileges'])===15 && !str_contains(implode(',',$vehicleMissing['missing_privileges']),'requested_vehicle_id'),'vehicle CRUD is missing only its fifteen columns, not future vehicle-specific Booking');
$delta=VehicleAdminGrantPlan::deltaSql($vehicleMissing);
vehicleCheck(count($delta)===1 && str_contains($delta[0],'SELECT (`registration_number`, `label`, `created_at`, `updated_at`)') && !str_contains(implode(' ',$delta),'requested_vehicle_id') && !str_contains(implode(' ',$delta),'vehicle_assignments'),'delta omits already granted assignment lookup and future Booking column');
$withVehicle=[...$existingCalendar,...$delta];
vehicleCheck(VehicleAdminGrantPlan::evaluate($withVehicle,false,true)['status']==='PASS' && App\Database\ConnectionFactory::evaluateAuthRuntimeGrants($withVehicle,false,true)['status']==='PASS','only proposed vehicle delta enables vehicle CRUD without requested_vehicle_id');
foreach (["GRANT SELECT ON *.* TO {$account}","GRANT ALTER ON `up_van_booking_test`.`vehicles` TO {$account}","GRANT SELECT (`created_by`) ON `up_van_booking_test`.`vehicles` TO {$account}","GRANT SELECT (`id`) ON `up_van_booking_test`.`vehicles` TO {$account} WITH GRANT OPTION"] as $excess) {
    $rejected=VehicleAdminGrantPlan::evaluate([...$withVehicle,$excess],false,true);
    vehicleCheck($rejected['status']==='FAIL' && VehicleAdminGrantPlan::deltaSql($rejected)===[],'unapproved global/DDL/column/grant-option privileges still fail closed');
}
vehicleCheck(VehicleAdminGrantPlan::evaluate($withVehicle,false,false)['status']==='UNVERIFIED','unresolved roles cannot pass vehicle capability');
vehicleCheck(GrantVerifier::evaluate($calendarGrants,'up_van_booking_test',VehicleCalendarGrantPlan::rules(),false,true)['status']==='PASS','vehicle-specific read union accepted');
vehicleCheck(GrantVerifier::evaluate($base,'up_van_booking_test',VehicleCalendarGrantPlan::rules(),false,true)['status']==='FAIL','existing grants cannot enable vehicle-specific calendar');
$sql=file_get_contents(dirname(__DIR__).'/app/Repositories/PdoVehicleCalendarRepository.php');
vehicleCheck(str_contains($sql,'FROM booking_requests WHERE requested_vehicle_id=:vehicle_id') && str_contains($sql,'WHERE is_active=1') && str_contains($sql,'WHERE id=:id AND is_active=1') && str_contains($sql,'WHERE vehicle_id=:vehicle_id') && str_contains($sql,"a.status IN ('active','completed')") && str_contains($sql,'a.starts_at<:assignment_end AND a.ends_at>:assignment_start'),'vehicle calendar filters active fleet and windows by prepared vehicle ID');
vehicleCheck(str_contains($sql,"return ['pending'=>\$pending,'confirmed'=>\$confirmed,'unconfirmed'=>\$unconfirmed"),'vehicle-specific month payload carries pending and confirmed intervals separately');
$vehicleRepoSource=file_get_contents(dirname(__DIR__).'/app/Repositories/PdoVehicleRepository.php');
vehicleCheck(str_contains($vehicleRepoSource,"RuntimeColumnProbe::inspect(\$db,'vehicles','registration_province')") && str_contains($vehicleRepoSource,"\$provinceProbe['status']==='PRESENT' && \$provinceProbe['readable']===true"),'province extension is used only when its presence and read permission are proven');
vehicleCheck(!str_contains($vehicleRepoSource,"RuntimeColumnProbe::inspect(\$db,'booking_requests','requested_vehicle_id')") && !str_contains($vehicleRepoSource,'requestedVehicleReadable'),'Vehicle Management does not probe or depend on Booking requested_vehicle_id');
$preflightSource=file_get_contents(dirname(__DIR__).'/scripts/task04c-1a-readonly-preflight.php');
vehicleCheck(str_contains($preflightSource,'RuntimeColumnProbe::inspect') && !str_contains($preflightSource,'$probeOptionalColumn') && str_contains($preflightSource,"'registration_province_probe_reason'") && str_contains($preflightSource,"'requested_vehicle_probe_reason'"),'read-only preflight reports safe probe states instead of mapping privilege errors to PRESENT');
vehicleCheck(str_contains($sql,"if (!\$this->requestedVehicleEnabled) throw new \\RuntimeException('REQUESTED_VEHICLE_SCHEMA_REQUIRED');"),'availability fails closed when requested vehicle schema is unavailable');
vehicleCheck(str_contains($sql,"workflow_status IN ('submitted','under_review','pending_approval','awaiting_assignment','assigned')"),'submitted through assigned workflow states block only their requested vehicle');
vehicleCheck(!str_contains($sql,'requester_name') && !str_contains($sql,'requester_phone'),'calendar read omits other requesters personal data');
$calendarView=file_get_contents(dirname(__DIR__).'/app/Views/booking/calendar.php');
vehicleCheck(!str_contains($calendarView,'ภาพรวมรถทุกคัน') && str_contains($calendarView,'name="vehicle_id"') && str_contains($calendarView,'vehicleId===(int)$vehicle[\'id\']') && !str_contains($calendarView,'กรองคันนี้'),'calendar selector uses active vehicle ID without an overview or filter button');
echo 'PASS: '.$checks.' Task 05D vehicle offline checks; no database connection or mutation.'.PHP_EOL;

