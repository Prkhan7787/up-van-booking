<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/cli.php';

use App\Database\{Migration006VehicleValidator,VehicleAdminGrantPlan,VehicleCalendarGrantPlan,GrantVerifier};
use App\Modules\Booking\{VehicleInput,VehicleSchemaValidator,BookingCalendarWindow,VehicleCalendarRepository,VehicleCalendarService};
use App\Models\AuthenticatedPrincipal;

$checks=0;
function v6(bool $ok,string $label):void{global $checks;if(!$ok){fwrite(STDERR,'FAIL: '.$label.PHP_EOL);throw new RuntimeException('Vehicle Migration 006 offline check failed');}$checks++;}
function v6Reject(callable $fn):bool{try{$fn();}catch(Throwable){return true;}return false;}

$defaultPlan=App\Database\MigrationPlan::load();$controlledPlan=App\Database\MigrationPlan::load(true);
v6(count($defaultPlan)===5 && count($controlledPlan)===6,'006 requires explicit opt-in and leaves default runner plan unchanged');
$six=$controlledPlan[5];
v6($six['sha256']===hash_file('sha256',dirname(__DIR__).'/'.$six['file']) && count($six['statements'])===2,'006 checksum and both exact SQL statements are pinned');
v6(v6Reject(fn()=>App\Database\MigrationPlan::assertManifestEntry(array_replace($six,['sha256'=>str_repeat('0',64)]))),'006 changed checksum is rejected');
v6(v6Reject(fn()=>App\Database\MigrationPlan::assertStatementAllowed($six['version'],'forward_test','ALTER TABLE vehicles DROP COLUMN label')),'006 unrelated ALTER cannot bypass allowlist');

$old=['id','registration_number','label','passenger_capacity','is_active','created_by','updated_by','created_at','updated_at'];
$unique=[['uq_vehicle_registration_number','registration_number',1,0]];
Migration006VehicleValidator::assertPreconditions($old,$unique,['requester_id','requested_start_at','requested_end_at','workflow_status'],[],[]);
v6(true,'post-005 schema passes migration 006 preflight contract');
v6(v6Reject(fn()=>Migration006VehicleValidator::assertPreconditions($old,$unique,['requested_vehicle_id'],[],[])),'partial/already-added requested vehicle column blocks retry');
v6(v6Reject(fn()=>Migration006VehicleValidator::assertPreconditions($old,$unique,['requester_id'],[],[['กข 1234',2]])),'duplicate registration blocks forward migration');
v6(v6Reject(fn()=>Migration006VehicleValidator::assertPreconditions([...$old,'registration_province'],$unique,['requester_id'],[],[])),'unexpected schema drift blocks migration');
Migration006VehicleValidator::assertPostconditions(['registration_province','varchar(100)','YES'],['requested_vehicle_id','bigint unsigned','YES'],[['idx_booking_requested_vehicle','requested_vehicle_id',1,1]],[['fk_booking_requested_vehicle','requested_vehicle_id','vehicles','id']]);
v6(true,'migration 006 postcondition validates nullable columns, index, and FK');
v6(v6Reject(fn()=>Migration006VehicleValidator::assertPostconditions(['registration_province','varchar(100)','NO'],['requested_vehicle_id','bigint unsigned','YES'],[['idx_booking_requested_vehicle','requested_vehicle_id',1,1]],[['fk_booking_requested_vehicle','requested_vehicle_id','vehicles','id']])),'province NOT NULL is rejected');

VehicleSchemaValidator::assertRegistrationOnly(0,$unique);
VehicleSchemaValidator::assertRegistrationOnly(1,$unique);
v6(true,'vehicle runtime allows pre/post-006 schema while retaining 005 unique index');
$province=VehicleInput::fromArray(['label'=>'รถพะเยา','registration_number'=>'กท 3651','registration_province'=>'พะเยา','passenger_capacity'=>'12']);
$legacy=VehicleInput::fromArray(['label'=>'รถเดิม','registration_number'=>'กข 1234','passenger_capacity'=>'12']);
v6($province->registrationProvince==='พะเยา' && $legacy->registrationProvince===null,'province is distinct and old vehicle data stays null');
v6(v6Reject(fn()=>VehicleInput::fromArray(['label'=>'รถ','registration_number'=>'กท 3651 พะเยา','registration_province'=>'พะเยา','passenger_capacity'=>'12'])),'province duplicated inside plate field is rejected');
v6(v6Reject(fn()=>VehicleInput::fromArray(['label'=>'รถ','registration_number'=>'กท 3651','registration_province'=>str_repeat('ก',101),'passenger_capacity'=>'12'])),'province maximum length is validated');

$migration=file_get_contents(dirname(__DIR__).'/database/drafts/006_vehicle_province_and_requested_vehicle.sql');
$preflight=file_get_contents(dirname(__DIR__).'/database/drafts/006_vehicle_province_and_requested_vehicle.preflight.sql');
$postflight=file_get_contents(dirname(__DIR__).'/database/drafts/006_vehicle_province_and_requested_vehicle.postflight.sql');
$manifest=require dirname(__DIR__).'/database/manifest.php';
v6(str_contains($migration,'ADD COLUMN registration_province VARCHAR(100) NULL') && str_contains($migration,'ADD COLUMN requested_vehicle_id BIGINT UNSIGNED NULL'),'draft adds nullable province and selected vehicle ID');
v6(str_contains($migration,'FOREIGN KEY (requested_vehicle_id) REFERENCES vehicles(id)') && str_contains($migration,'idx_booking_requested_vehicle'),'request vehicle FK and lookup index are explicit');
v6(str_contains($migration,'Preserve Migration 005') && !str_contains($migration,'DROP INDEX') && !str_contains($migration,'DROP COLUMN'),'draft is forward-only and preserves applied migration 005 uniqueness');
v6(!array_filter($manifest,static fn(array $entry):bool=>($entry['version']??'')==='006_vehicle_province_and_requested_vehicle'),'draft is not registered as executable migration');
v6(!preg_match('/(?im)^\s*(?:INSERT|UPDATE|DELETE|ALTER|CREATE|DROP|GRANT|REVOKE)\b/',$preflight),'preflight contains only read-only SQL');
v6(!preg_match('/(?im)^\s*(?:INSERT|UPDATE|DELETE|ALTER|CREATE|DROP|GRANT|REVOKE)\b/',$postflight),'postflight contains only read-only SQL');

$rules=VehicleAdminGrantPlan::rules(true,true,true,true);
v6(in_array('registration_province',$rules['tables']['vehicles']['SELECT'],true) && in_array('registration_province',$rules['tables']['vehicles']['INSERT'],true) && in_array('registration_province',$rules['tables']['vehicles']['UPDATE'],true) && in_array('requested_vehicle_id',$rules['tables']['booking_requests']['SELECT'],true),'grant verifier covers new province writes and requested vehicle read');
$account="'up_van_auth_test'@'127.0.0.1'";
$current=[...App\Database\AuthRuntimeGrantPlan::grantSql(false),...App\Database\AdminUserGrantPlan::supplementalSql(),App\Database\SystemLogGrantPlan::supplementalSql(),...App\Database\BookingCalendarGrantPlan::supplementalSql(),...VehicleCalendarGrantPlan::supplementalSql(),...VehicleAdminGrantPlan::supplementalSql()];
$provinceGrants=[...$current,...VehicleAdminGrantPlan::supplementalProvinceSql(),VehicleAdminGrantPlan::supplementalRequestedVehicleSql()];
v6(GrantVerifier::evaluate($provinceGrants,'up_van_booking_test',$rules,false,true)['status']==='PASS','exact proposed post-006 grant union is accepted');
v6(VehicleAdminGrantPlan::evaluateForSchema($provinceGrants,false,true,true,true)['status']==='PASS','schema-aware Vehicle preflight accepts province and requested-ID grants');
v6(VehicleAdminGrantPlan::evaluateForSchema($provinceGrants,false,true,true,false)['status']==='PASS','vehicle management recognizes approved Booking grants without probing or requiring Booking schema');
v6(VehicleAdminGrantPlan::evaluateForSchema($current,false,true,true,true)['status']==='FAIL','schema-aware Vehicle preflight requires new-column grants only when columns exist');
v6(GrantVerifier::evaluate([...$provinceGrants,"GRANT ALTER ON `up_van_booking_test`.`vehicles` TO {$account}"],'up_van_booking_test',$rules,false,true)['status']==='FAIL','DDL remains outside runtime grant plan');
v6(App\Database\ConnectionFactory::evaluateAuthRuntimeGrants($provinceGrants,false,true)['status']==='PASS','ConnectionFactory preflight accepts only the approved post-006 privilege union');
v6(VehicleCalendarGrantPlan::evaluate($provinceGrants,false,true)['status']==='PASS','Calendar capability verifier recognizes province and requested-vehicle SELECT grants');
v6(VehicleCalendarGrantPlan::evaluateForSchema($provinceGrants,false,true,true,true)['status']==='PASS','schema-aware calendar verifier accepts new column permissions');
$grantSql=[...VehicleAdminGrantPlan::supplementalProvinceSql(),VehicleAdminGrantPlan::supplementalRequestedVehicleSql()];
v6(count($grantSql)===3 && !str_contains(implode(' ',$grantSql),'*.*') && !str_contains(implode(' ',$grantSql),'GRANT ALL'),'grant plan is table/column scoped');

$vehicleCalendar=file_get_contents(dirname(__DIR__).'/app/Repositories/PdoVehicleCalendarRepository.php');
$vehicleRepository=file_get_contents(dirname(__DIR__).'/app/Repositories/PdoVehicleRepository.php');
$assignmentWorker=file_get_contents(dirname(__DIR__).'/tests/database/assignment-worker.php');
v6(str_contains($vehicleRepository,"\$shape!==['varchar(100)','YES']") && str_contains($vehicleCalendar,"\$this->columnShape('booking_requests','requested_vehicle_id')!==['bigint unsigned','YES']"),'runtime rejects unexpected column type or non-null schema drift');
v6(str_contains($vehicleCalendar,"a.starts_at<:assignment_end AND a.ends_at>:assignment_start") && str_contains($vehicleCalendar,'a.vehicle_id=v.id') && str_contains($vehicleCalendar,'v.id=:vehicle_id'),'calendar uses half-open overlap and selected vehicle ID');
v6(str_contains($vehicleCalendar,"workflow_status IN ('submitted','under_review','pending_approval','awaiting_assignment','assigned')") && str_contains($vehicleCalendar,'REQUESTED_VEHICLE_SCHEMA_REQUIRED') && str_contains($vehicleCalendar,"a.status IN ('active','completed')"),'submitted requests and assigned vehicles block the same vehicle while missing schema fails closed');
v6(str_contains($assignmentWorker,"SELECT id FROM vehicles WHERE id=:id AND is_active=1 FOR UPDATE") && str_contains($assignmentWorker,'$db->beginTransaction()') && str_contains($assignmentWorker,'starts_at < :ends AND ends_at > :starts'),'assignment integration harness serializes by vehicle row then checks overlap transactionally');

final class MappedVehicleCalendar implements VehicleCalendarRepository
{
    private array $cars=[1=>['id'=>1,'registration_number'=>'กท 3651','registration_province'=>'พะเยา','label'=>'รถคัน 1','passenger_capacity'=>12,'is_active'=>1],2=>['id'=>2,'registration_number'=>'กข 9999','registration_province'=>'เชียงใหม่','label'=>'รถคัน 2','passenger_capacity'=>12,'is_active'=>1]];
    public function activeVehicles():array{return array_values($this->cars);}
    public function activeVehicle(int $id):?array{return $this->cars[$id]??null;}
    public function monthWindows(int $vehicleId,string $startUtc,string $endUtc):array{return ['pending'=>[],'confirmed'=>$vehicleId===1?[['start_at'=>'2026-12-02 02:00:00','end_at'=>'2026-12-02 04:00:00']]:[]];}
    public function available(int $vehicleId,BookingCalendarWindow $window):bool{return $vehicleId===2;}
    public function pending(int $vehicleId,BookingCalendarWindow $window):int{return 0;}
}
$mapped=new VehicleCalendarService(new MappedVehicleCalendar());$requester=new AuthenticatedPrincipal(40,1,['requester']);
$dec2=BookingCalendarWindow::fromQuery(['start'=>'2026-12-02T09:00','end'=>'2026-12-02T11:00','passengers'=>'8']);
v6($mapped->quote($requester,1,$dec2)['available']===0 && $mapped->quote($requester,2,$dec2)['available']===1,'same dates are unavailable for the assigned vehicle and available for another vehicle');
$busyMonth=$mapped->month($requester,1,'2026-12');$freeMonth=$mapped->month($requester,2,'2026-12');
v6($busyMonth['days'][1]['status']==='assigned' && $freeMonth['days'][1]['status']==='unchecked','vehicle-specific month status changes by vehicle ID for the same day');

echo 'PASS: '.$checks.' Migration 006/province/vehicle-calendar offline checks; database unchanged.'.PHP_EOL;
