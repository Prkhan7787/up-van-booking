<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/cli.php';
require dirname(__DIR__).'/app/helpers.php';
set_exception_handler(static function(Throwable $error):never {fwrite(STDERR,'Offline Calendar test failed: '.get_class($error).' at line '.$error->getLine().PHP_EOL);exit(1);});

use App\Database\{AuthRuntimeGrantPlan,AdminUserGrantPlan,SystemLogGrantPlan,RequesterBookingReadGrantPlan,BookingCalendarGrantPlan,GrantVerifier,ConnectionFactory};
use App\Modules\Booking\{BookingCalendarWindow,BookingCalendarService,BookingCalendarRepository,RequesterDraftService};
use App\Models\AuthenticatedPrincipal;
use App\Repositories\{PdoBookingCalendarRepository,PdoRequesterDraftRepository,PdoAdminUserRepository,PdoSystemLogRepository};

$checks=0;
function calCheck(bool $ok,string $label):void {global $checks;if(!$ok){fwrite(STDERR,'FAILED CHECK: '.$label.PHP_EOL);throw new RuntimeException('Offline assertion failed');}$checks++;}
function calReject(callable $action,string $type=Throwable::class):bool {try{$action();}catch(Throwable $error){return $error instanceof $type;}return false;}

$migration=file_get_contents(dirname(__DIR__).'/database/migrations/002_booking_design.sql');
foreach (['booking_requests'=>RequesterBookingReadGrantPlan::COLUMNS,'vehicles'=>['id','is_active','passenger_capacity'],'vehicle_assignments'=>['vehicle_id','status','starts_at','ends_at']] as $table=>$columns) {
    preg_match('/CREATE TABLE '.preg_quote($table,'/').' \((.*?)\) ENGINE=InnoDB/s',$migration,$match);
    calCheck(isset($match[1]),'Migration 002 has '.$table);
    foreach ($columns as $column) calCheck((bool)preg_match('/^\s*'.preg_quote($column,'/').'\s+[A-Z]/mi',$match[1]),'Migration 002 column '.$table.'.'.$column);
}
$readProjection=(new ReflectionClass(PdoRequesterDraftRepository::class))->getReflectionConstant('READ')->getValue();
calCheck(array_map('trim',explode(',',$readProjection))===RequesterBookingReadGrantPlan::COLUMNS,'detail/document SELECT projection exactly matches read grant plan');
$base=array_merge(AuthRuntimeGrantPlan::grantSql(false),AdminUserGrantPlan::supplementalSql());
$read=[...$base,RequesterBookingReadGrantPlan::supplementalSql()];
$calendar=[...$base,...BookingCalendarGrantPlan::supplementalSql()];
$withLogs=[...$base,SystemLogGrantPlan::supplementalSql(),...BookingCalendarGrantPlan::supplementalSql()];
$approvedBeforeBooking=[...$base,SystemLogGrantPlan::supplementalSql()];
$approvedPlan=BookingCalendarGrantPlan::rules(true);
calCheck($approvedPlan['tables']['audit_logs']['SELECT']===SystemLogGrantPlan::COLUMNS && $approvedPlan['tables']['booking_requests']['SELECT']===RequesterBookingReadGrantPlan::COLUMNS,'approved plan is the Auth, Admin, System Log, and Booking union');
$pendingBookingGrants=GrantVerifier::evaluate($approvedBeforeBooking,'up_van_booking_test',$approvedPlan,false,true);
calCheck($pendingBookingGrants['status']==='FAIL' && $pendingBookingGrants['unexpected_privileges']===[],'approved System Log SELECT is not unexpected while Booking SELECT is missing');
calCheck(count($pendingBookingGrants['missing_privileges'])===count(RequesterBookingReadGrantPlan::COLUMNS)+7,'missing list contains only Booking and Calendar read columns');
$diagnosticSource=file_get_contents(dirname(__DIR__).'/scripts/verify-booking-read-capability.php');
calCheck(str_contains($diagnosticSource,'$evaluation=GrantVerifier::evaluate($resolved[\'grants\'],\'up_van_booking_test\',BookingCalendarGrantPlan::rules(true)') && !str_contains($diagnosticSource,'$evaluation=$calendar['),'read-only CLI reports against approved module union even on failure');
calCheck(GrantVerifier::evaluate($base,'up_van_booking_test',RequesterBookingReadGrantPlan::rules(),false,true)['status']==='FAIL','existing Admin grants lack Booking read');
calCheck(GrantVerifier::evaluate($read,'up_van_booking_test',RequesterBookingReadGrantPlan::rules(),false,true)['status']==='PASS','Booking read-only extension accepted');
calCheck(GrantVerifier::evaluate($read,'up_van_booking_test',BookingCalendarGrantPlan::rules(),false,true)['status']==='FAIL','calendar still needs fleet SELECT');
calCheck(GrantVerifier::evaluate($calendar,'up_van_booking_test',BookingCalendarGrantPlan::rules(),false,true)['status']==='PASS','exact Booking and Calendar read grants accepted');
calCheck(GrantVerifier::evaluate($withLogs,'up_van_booking_test',BookingCalendarGrantPlan::rules(true),false,true)['status']==='PASS','System Log extension composes with Calendar grants');
calCheck(GrantVerifier::evaluate([...$withLogs,"GRANT SELECT (`password_hash`) ON `up_van_booking_test`.`audit_logs` TO 'up_van_auth_test'@'127.0.0.1'"],'up_van_booking_test',$approvedPlan,false,true)['status']==='FAIL','unapproved extra audit column still denied');
foreach (["GRANT ALTER ON `up_van_booking_test`.`booking_requests` TO 'up_van_auth_test'@'127.0.0.1'","GRANT SELECT ON *.* TO 'up_van_auth_test'@'127.0.0.1'","GRANT SELECT (`id`) ON `up_van_booking_test`.`vehicles` TO 'up_van_auth_test'@'127.0.0.1' WITH GRANT OPTION"] as $unsafeGrant) {
    calCheck(GrantVerifier::evaluate([...$withLogs,$unsafeGrant],'up_van_booking_test',$approvedPlan,false,true)['status']==='FAIL','union still rejects DDL, global grants, and grant option');
}
calCheck(GrantVerifier::evaluate($calendar,'up_van_booking_test',BookingCalendarGrantPlan::rules(),false,false)['status']==='UNVERIFIED','unresolved roles never pass');
calCheck(GrantVerifier::evaluate([...$calendar,"GRANT DELETE ON `up_van_booking_test`.`booking_requests` TO 'up_van_auth_test'@'127.0.0.1'"],'up_van_booking_test',BookingCalendarGrantPlan::rules(),false,true)['status']==='FAIL','write privilege rejected by read-only plan');
calCheck(GrantVerifier::evaluate([...$calendar,"GRANT SELECT ON *.* TO 'up_van_auth_test'@'127.0.0.1'"],'up_van_booking_test',BookingCalendarGrantPlan::rules(),false,true)['status']==='FAIL','global SELECT rejected');
calCheck(count(BookingCalendarGrantPlan::supplementalSql())===3 && !str_contains(implode(' ',BookingCalendarGrantPlan::supplementalSql()),'lost_found'),'plan is test schema table/column scoped');

final class CalendarStatement extends PDOStatement {
    public array $bound=[],$executed=[];
    public function __construct(public string $sql,private array $rows=[],private mixed $scalar=null) {}
    public function bindValue(string|int $param,mixed $value,int $type=PDO::PARAM_STR):bool {$this->bound[$param]=[$value,$type];return true;}
    public function execute(?array $params=null):bool {$this->executed=$params??[];return true;}
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0):mixed {return $this->rows[0]??false;}
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array {return $this->rows;}
    public function fetchColumn(int $column=0):mixed {return $this->scalar;}
}
final class CalendarPdo extends PDO {
    public array $prepared=[];
    public function __construct(public array $grants,public string $database='up_van_booking_test',public string $account='up_van_auth_test@127.0.0.1',private bool $softDeletePresent=false) {}
    public function getAttribute(int $attribute):mixed {return match($attribute){PDO::ATTR_DRIVER_NAME=>'mysql',PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,default=>null};}
    public function query(string $sql,?int $fetchMode=null,mixed ...$args):PDOStatement|false {
        if($sql==='SELECT `deleted_at` FROM `booking_requests` LIMIT 0'){
            if($this->softDeletePresent)return new CalendarStatement($sql);
            $error=new PDOException();
            $error->errorInfo=['42000',1143];
            throw $error;
        }
        if(str_starts_with($sql,'SELECT DATABASE() AS'))return new CalendarStatement($sql,[['db_name'=>$this->database,'account_name'=>$this->account,'partial_revokes'=>0]]);
        return new CalendarStatement($sql,str_starts_with($sql,'SHOW')?$this->grants:[],match($sql){'SELECT CURRENT_USER()'=>$this->account,'SELECT @@GLOBAL.partial_revokes'=>0,'SELECT COUNT(*) FROM vehicles WHERE is_active=1'=>2,default=>'NONE'});
    }
    public function prepare(string $sql,array $options=[]):PDOStatement|false {
        $pending=str_contains($sql,'FROM booking_requests WHERE workflow_status IN') && str_contains($sql,'AS start_at');
        $confirmed=str_contains($sql,'FROM vehicle_assignments WHERE status=');
        $rows=$pending?[['start_at'=>'2026-11-19 18:00:00','end_at'=>'2026-11-20 18:00:00']]:($confirmed?[['start_at'=>'2026-11-20 01:00:00','end_at'=>'2026-11-20 03:00:00']]:[]);
        $scalar=str_contains($sql,'NOT EXISTS')?1:(str_contains($sql,'FROM vehicles')?2:1);
        $stmt=new CalendarStatement($sql,$rows,$scalar);$this->prepared[]=$stmt;return $stmt;
    }
}
$pdo=new CalendarPdo($calendar);
ConnectionFactory::assertScopedGrants($pdo,'up_van_booking_test');
new PdoAdminUserRepository(new CalendarPdo($calendar));
new PdoRequesterDraftRepository(new CalendarPdo($calendar));
$repo=new PdoBookingCalendarRepository($pdo);
calCheck(true,'all existing repositories and connection factory accept exact extension');
calCheck(calReject(fn()=>new PdoBookingCalendarRepository(new CalendarPdo($read))) && calReject(fn()=>new PdoBookingCalendarRepository(new CalendarPdo($calendar,'up_van_booking'))) && calReject(fn()=>new PdoBookingCalendarRepository(new CalendarPdo($calendar,'up_van_booking_test','root@localhost'))),'calendar rejects missing grant and wrong database/account before reads');
new PdoSystemLogRepository(new CalendarPdo($withLogs));
ConnectionFactory::assertScopedGrants(new CalendarPdo($withLogs),'up_van_booking_test');
new PdoAdminUserRepository(new CalendarPdo($withLogs));
new PdoRequesterDraftRepository(new CalendarPdo($withLogs));
new PdoBookingCalendarRepository(new CalendarPdo($withLogs));
calCheck(true,'Login connection, Admin, System Log, Booking, and Calendar accept combined grants');
$pdo->prepared=[]; // Measure only the month operation, not constructor metadata probes.
$windows=$repo->monthWindows('2026-10-31 17:00:00','2026-11-30 17:00:00');
calCheck(count($windows['pending'])===1 && count($windows['confirmed'])===1 && count($windows['requests'])===1 && count($pdo->prepared)===3,'month queries separate display workflow from availability intervals');
calCheck(str_contains($pdo->prepared[0]->sql,'workflow_status IN') && !str_contains($pdo->prepared[0]->sql,'requester_id') && !str_contains($pdo->prepared[0]->sql,'requester_name'),'month pending query omits personal data');
calCheck(!str_contains($pdo->prepared[0]->sql,'awaiting_assignment') && str_contains($pdo->prepared[0]->sql,'pending_approval'),'pending consideration excludes already approved awaiting assignment');
calCheck(str_contains($pdo->prepared[1]->sql,"status='active'") && !str_contains($pdo->prepared[1]->sql,'driver_id'),'confirmed window query excludes completed and driver identity');
calCheck(array_reduce($pdo->prepared,static fn(bool $safe,CalendarStatement $stmt):bool=>$safe && str_starts_with($stmt->sql,'SELECT '),true),'calendar repository executes SELECT only');
$window=BookingCalendarWindow::fromQuery(['start'=>'2026-11-20T08:00','end'=>'2026-11-22T17:00','passengers'=>'8']);
calCheck($window->startUtc==='2026-11-20 01:00:00' && $window->endUtc==='2026-11-22 10:00:00','multi-day Bangkok interval converted to UTC');
$timeOnly=BookingCalendarWindow::fromQuery(['start'=>'2026-11-20T08:00','end'=>'2026-11-22T17:00']);
calCheck(App\Modules\Booking\BookingAdvancePolicy::earliest(null,false)===null && App\Modules\Booking\BookingAdvancePolicy::earliest([],true,new DateTimeImmutable('2026-11-16',new DateTimeZone('Asia/Bangkok')))==='2026-11-23' && App\Modules\Booking\BookingAdvancePolicy::earliest(['2026-11-19'],true,new DateTimeImmutable('2026-11-16',new DateTimeZone('Asia/Bangkok')))==='2026-11-24','five weekdays are calculable only with confirmed holiday and exception policy');
calCheck($timeOnly->passengers===null && $timeOnly->startUtc===$window->startUtc,'calendar accepts date/time window without passenger count');
foreach ([['start'=>'2026-11-20T08:00','end'=>'2026-11-20T08:00','passengers'=>'1'],['start'=>'2026-02-30T08:00','end'=>'2026-03-01T09:00','passengers'=>'1'],['start'=>'2026-11-20T08:00','end'=>'2026-11-20T09:00','passengers'=>'0']] as $bad) calCheck(calReject(fn()=>BookingCalendarWindow::fromQuery($bad),InvalidArgumentException::class),'invalid interval or count rejected');
calCheck($repo->availableVehicles($window)===1 && $repo->eligibleVehicles(8)===2 && $repo->pendingRequests($window)===1,'availability counts only eligible fleet minus active assignment overlap; pending separate');
calCheck(str_contains($pdo->prepared[3]->sql,'NOT EXISTS') && str_contains($pdo->prepared[3]->sql,'passenger_capacity>=:passengers'),'availability SQL uses capacity and overlap exclusion');
$repo->availableVehicles($timeOnly);
calCheck(!str_contains($pdo->prepared[5]->sql,'passenger_capacity') && !array_key_exists('passengers',$pdo->prepared[5]->executed),'time-only availability query omits capacity filter and passenger parameter');
calCheck($repo->eligibleVehicles(null)===2,'time-only eligibility counts active fleet without capacity filter');
$bookingDb=new CalendarPdo($calendar);$bookingRepo=new PdoRequesterDraftRepository($bookingDb);
$bookingDb->prepared=[]; // Exclude constructor probes when asserting request queries.
$bookingRepo->listOwn(10,26,0);$bookingRepo->findOwned(5,10);$bookingRepo->find(5);
calCheck(str_contains($bookingDb->prepared[0]->sql,'WHERE requester_id=:owner') && $bookingDb->prepared[0]->bound[':owner']===[10,PDO::PARAM_INT],'Requester list filters owner in prepared SQL');
calCheck(str_contains($bookingDb->prepared[1]->sql,'WHERE id=:id AND requester_id=:owner') && $bookingDb->prepared[1]->executed===['id'=>5,'owner'=>10],'Requester detail filters ID and owner before fetching');
calCheck(str_contains($bookingDb->prepared[2]->sql,'WHERE id=:id') && !str_contains($bookingDb->prepared[2]->sql,'requester_id=:owner'),'Admin read path can fetch by ID; service protects with booking.view_all');
calCheck(array_reduce($bookingDb->prepared,static fn(bool $safe,CalendarStatement $stmt):bool=>$safe && str_starts_with($stmt->sql,'SELECT '),true),'booking GET paths issue SELECT only and do not write audit or status');

$softGrants=[
    ...$withLogs,
    ...App\Database\BookingOfficerReadGrantPlan::proposedSql(true),
    ...App\Database\BookingSubmissionGrantPlan::proposedSql(true),
    "GRANT SELECT (`deleted_at`) ON `up_van_booking_test`.`booking_requests` TO 'up_van_auth_test'@'127.0.0.1'",
];
$softDb=new CalendarPdo($softGrants,softDeletePresent:true);
$softCalendar=new PdoBookingCalendarRepository($softDb);
$softBooking=new PdoRequesterDraftRepository($softDb);
$softDb->prepared=[];
$softCalendar->monthWindows($window->startUtc,$window->endUtc);
$softCalendar->pendingRequests($window);
$softBooking->listOwn(10,26,0);
$softBooking->listAll(26,0);
$softBooking->findOwned(5,10);
$softBooking->find(5);
foreach ($softDb->prepared as $statement) {
    if (str_contains($statement->sql,'FROM booking_requests')) {
        calCheck(str_contains($statement->sql,'deleted_at IS NULL'),'calendar and ordinary booking reads exclude tombstones after migration');
    }
}
$schemaFlag=getenv('TEST_BOOKING_SOFT_DELETE_SCHEMA_ACTIVE');
putenv('TEST_BOOKING_SOFT_DELETE_SCHEMA_ACTIVE=true');
try {
    calCheck(calReject(fn()=>App\Database\BookingSoftDelete::available(new CalendarPdo($calendar)), RuntimeException::class),
        'a deployed soft-delete schema with denied column reads fails closed');
    calCheck(App\Database\BookingSoftDelete::available($softDb), 'a deployed and readable soft-delete column enables filtering');
} finally {
    putenv($schemaFlag===false ? 'TEST_BOOKING_SOFT_DELETE_SCHEMA_ACTIVE' : 'TEST_BOOKING_SOFT_DELETE_SCHEMA_ACTIVE='.$schemaFlag);
}

final class CalendarMemory implements BookingCalendarRepository {
    public function monthWindows(string $startUtc,string $endUtc):array {return ['pending'=>[['start_at'=>'2026-11-19 18:00:00','end_at'=>'2026-11-20 18:00:00']],'confirmed'=>[['start_at'=>'2026-11-20 01:00:00','end_at'=>'2026-11-20 03:00:00']]];}
    public function eligibleVehicles(?int $passengers):int {return 2;}
    public function availableVehicles(BookingCalendarWindow $window):int {return 1;}
    public function pendingRequests(BookingCalendarWindow $window):int {return 1;}
}
$service=new BookingCalendarService(new CalendarMemory());$requester=new AuthenticatedPrincipal(10,1,['requester']);$admin=new AuthenticatedPrincipal(11,1,['admin']);
$vehicleFixture=new class implements App\Modules\Booking\VehicleCalendarRepository {
    public int $pendingCount=0;
    public function activeVehicles():array {return [['id'=>1,'registration_number'=>'กข 1234','label'=>'รถทดลอง','passenger_capacity'=>12,'is_active'=>1,'fuel_type'=>'แก๊สโซฮอล์ 95 S EVO']];}
    public function activeVehicle(int $id):?array {return $id===1?$this->activeVehicles()[0]:null;}
    public function monthWindows(int $vehicleId,string $startUtc,string $endUtc):array {return ['availability_confirmed'=>true,'pending'=>$this->pendingCount>0?[['start_at'=>'2026-11-20 01:00:00','end_at'=>'2026-11-22 10:00:00']]:[],'confirmed'=>[]];}
    public function available(int $vehicleId,BookingCalendarWindow $window):bool {return $this->pendingCount===0;}
    public function pending(int $vehicleId,BookingCalendarWindow $window):int {return $this->pendingCount;}
};
$vehicleService=new App\Modules\Booking\VehicleCalendarService($vehicleFixture);
$month=$service->month($requester,'2026-11');
calCheck(count($month['days'])===30 && $month['days'][19]['status']==='assigned' && $month['days'][20]['status']==='pending' && $month['days'][21]['status']==='unchecked','month overview never claims availability before exact time check');
calCheck($month['showCounts']===false && $service->month($admin,'2026-11')['showCounts']===true,'Requester sees coarse status; Admin sees aggregate counts');
calCheck($service->quote($requester,$window)['canPrepareForm'] && !$service->quote($admin,$window)['canPrepareForm'],'only Requester receives form action');
calCheck(calReject(fn()=>$service->month($requester,'2026-13'),InvalidArgumentException::class),'invalid month denied');
$_GET=['month'=>'2026-11','vehicle_id'=>'1','start_date'=>'2026-11-20','end_date'=>'2026-11-22','start_time'=>'08:00','end_time'=>'17:00'];
ob_start();(new App\Controllers\BookingCalendarController($service,$vehicleService))->index($requester);$html=ob_get_clean();
calCheck(str_contains($html,'action="/bookings/travel-estimate"') && str_contains($html,'name="vehicle_id" value="1"') && str_contains($html,'data-booking-start') && str_contains($html,'data-booking-end') && !str_contains($html,'passengers=') && !str_contains($html,'requester_name'),'selected vehicle and date/time fields reach combined travel page without passenger count or PII');
calCheck(!str_contains($html,'name="passengers"') && str_contains($html,'ถัดไป: เลือกเส้นทางและคำนวณค่าเดินทาง') && str_contains($html,'grid grid-cols-1 gap-4 sm:grid-cols-2'),'calendar request form has two-column responsive fields and a separate travel-page action');
calCheck(substr_count($html,'placeholder="วว/ดด/ปปปป"')===2 && substr_count($html,'placeholder="08:00"')===1 && substr_count($html,'placeholder="17:00"')===1 && str_contains($html,'value="20/11/2026"') && str_contains($html,'value="22/11/2026"') && !preg_match('/placeholder="\d{1,2}\/\d{1,2}\/\d{4}"/',$html),'calendar start/end display Gregorian dates consistently as dd/mm/yyyy with controlled date/time placeholders');
calCheck(!str_contains($html,'วันนี้</a>') && !str_contains($html,'ค.ศ. ') && !str_contains($html,'พ.ศ. ') && str_contains($html,'เดือนก่อนหน้า') && str_contains($html,'เดือนถัดไป'),'month controls omit today/year caption and retain previous/next controls');
ob_start();(new App\Controllers\BookingCalendarController($service,$vehicleService))->index($admin);$adminHtml=ob_get_clean();
calCheck(str_contains($adminHtml,'ถัดไป: เลือกเส้นทางและคำนวณค่าเดินทาง') && str_contains($adminHtml,'data-booking-can-submit="true"') && str_contains($adminHtml,'disabled') && str_contains($adminHtml,'รออนุมัติ'),'Admin receives map-preview permission independently of booking creation');
$_GET=['month'=>'2026-11','start_date'=>'2026-02-30','end_date'=>'2026-11-20','start_time'=>'08:00','end_time'=>'17:00','passengers'=>'8'];
ob_start();(new App\Controllers\BookingCalendarController($service))->index($requester);$invalidHtml=ob_get_clean();
calCheck(http_response_code()===422 && str_contains($invalidHtml,'name="start_date"') && !str_contains($invalidHtml,'/form-preview?'),'invalid date is reported and does not create a query-string shortcut');
$_GET=['vehicle_id'=>'1','start'=>'2026-11-20T08:00','end'=>'2026-11-22T17:00'];
ob_start();(new App\Controllers\RequesterBookingController(null,false,$vehicleService))->travelEstimate($requester);$travelPage=ob_get_clean();
calCheck(str_contains($travelPage,'แผนที่และคำนวณค่าเดินทาง') && str_contains($travelPage,'action="/form-preview"') && str_contains($travelPage,'name="vehicle_id" value="1"') && str_contains($travelPage,'name="start" value="2026-11-20T08:00"') && str_contains($travelPage,'ยังไม่มีข้อมูลที่ตรวจสอบได้'),'combined travel page forwards booking context to the original A4 preview');
$yesterday=(new DateTimeImmutable('now',new DateTimeZone('Asia/Bangkok')))->modify('-1 day')->format('Y-m-d');
$_GET=['vehicle_id'=>'1','start'=>$yesterday.'T08:00','end'=>$yesterday.'T17:00'];ob_start();(new App\Controllers\RequesterBookingController(null,false,$vehicleService))->travelEstimate($requester);$pastTravel=ob_get_clean();
calCheck(http_response_code()===422 && str_contains($pastTravel,'รถหรือช่วงวันเวลาไม่ถูกต้อง') && str_contains($pastTravel,'disabled'),'travel step rejects backdated GET selection on the server');
$_GET=['vehicle_id'=>'1','start'=>'2026-11-20T08:00','end'=>'2026-11-22T17:00'];
ob_start();(new App\Controllers\RequesterBookingController(null,false,$vehicleService))->travelEstimate($admin);$adminTravel=ob_get_clean();
calCheck(str_contains($adminTravel,'แผนที่และคำนวณค่าเดินทาง') && str_contains($adminTravel,'data-can-open-form="true"') && str_contains($adminTravel,'โหมดตัวอย่างสำหรับผู้ดูแลระบบ') && !str_contains($adminTravel,'action="/bookings/new"'),'Admin can open A4 preview without permission to create bookings');
$ref=App\Modules\Booking\FuelEconomyReferenceCatalog::forFuelType('แก๊สโซฮอล์ 95 S EVO');
$_GET=['vehicle_id'=>'1','start'=>'2026-11-20T08:00','end'=>'2026-11-22T17:00','start_date'=>'2026-11-20','start_time'=>'08:00','end_date'=>'2026-11-22','end_time'=>'17:00','origin'=>'มหาวิทยาลัยพะเยา','destination'=>'เชียงใหม่','destination_source'=>'search','origin_lat'=>'19.031572','origin_lng'=>'99.893832','destination_lat'=>'18.7883','destination_lng'=>'98.9853','route_outbound_m'=>'120000.5','route_return_m'=>'125000.5','route_duration_ms'=>'7200000.4','route_return_duration_ms'=>'7500000.4','route_source'=>'osrm','fuel_type'=>'แก๊สโซฮอล์ 95 S EVO','fuel_efficiency_reference_km_per_l'=>(string)$ref['mean'],'fuel_price_baht_per_l'=>'40','fuel_price_date'=>'11 พ.ย. 69 เวลา 05.00 น.','fuel_price_source'=>'bangchak_oil_price_api'];
$_GET['vehicle_label']='รถคันที่ 1';$_GET['vehicle_registration']='กท3651';$_GET['vehicle_province']='พะเยา';
ob_start();(new App\Controllers\FormPreviewController($vehicleService))->index($admin);$adminEstimateForm=ob_get_clean();
calCheck(!str_contains($adminEstimateForm,'travel-transfer-notice') && str_contains($adminEstimateForm,'<textarea name="destination" id="destination"') && str_contains($adminEstimateForm,'>เชียงใหม่</textarea>') && str_contains($adminEstimateForm,'name="departure_time"') && str_contains($adminEstimateForm,'value="08:00"') && str_contains($adminEstimateForm,'action="/bookings/submit"'),'searched destination and dates populate the original A4 fields without a summary and with the gated submission form');
$mapOnlyQuery=$_GET;$mapOnlyQuery['destination']='';$mapOnlyQuery['destination_source']='map';$_GET=$mapOnlyQuery;http_response_code(200);ob_start();(new App\Controllers\FormPreviewController($vehicleService))->index($admin);$mapOnlyForm=ob_get_clean();
calCheck(http_response_code()!==422 && str_contains($mapOnlyForm,'<textarea name="destination" id="destination"') && str_contains($mapOnlyForm,'</textarea>') && str_contains($mapOnlyForm,'name="departure_time"') && !str_contains($mapOnlyForm,'จุดที่เลือกบนแผนที่'),'map-pin destination preserves valid coordinate routing but leaves the A4 destination blank');
$tampered=$_GET;$tampered['start_date']='2026-11-21';$_GET=$tampered;ob_start();(new App\Controllers\FormPreviewController($vehicleService))->index($admin);$tamperedForm=ob_get_clean();
calCheck(http_response_code()===422 && str_contains($tamperedForm,'ข้อมูลรถ วันเวลา หรือเส้นทางใน URL ไม่ครบหรือไม่ถูกต้อง') && !str_contains($tamperedForm,'id="travel-transfer-notice"'),'modified query date is rejected and unverified transfer values are not displayed');
$_GET=['vehicle_id'=>'1','start'=>'2026-11-20T08:00','end'=>'2026-11-22T17:00'];
$split=['start_date'=>'2026-11-20','start_time'=>'08:00','end_date'=>'2026-11-22','end_time'=>'17:00'];
calCheck(BookingCalendarWindow::fromTravelQuery($split)->startLocal==='2026-11-20T08:00','travel endpoint accepts split ISO date and time fields');
calCheck(calReject(fn()=>BookingCalendarWindow::fromTravelQuery($split+['start'=>'2026-11-21T08:00'])),'conflicting combined and split date values rejected');
$policy=new App\Services\AuthorizationPolicy();
calCheck($policy->allows(['admin'],'travel_estimate.view') && !$policy->allows(['admin'],'booking.draft.create') && $policy->allows(['admin'],'booking.submit_own'),'Admin can submit own requests via A4 while draft creation permission stays separate');
$formRoutes=require dirname(__DIR__).'/routes/web.php';
calCheck(isset($formRoutes['GET /form-preview']) && !isset($formRoutes['GET /bookings/new']) && !isset($formRoutes['POST /bookings/new']),'legacy /bookings/new is gone and the A4 preview remains GET-only');
$routes=require dirname(__DIR__).'/routes/web.php';
calCheck($routes['GET /bookings/calendar'][2]==='calendar.view' && $routes['GET /bookings/travel-estimate'][2]==='travel_estimate.view' && isset($routes['GET /form-preview']) && !isset($routes['GET /bookings/new']) && !isset($routes['POST /bookings/calendar']) && !isset($routes['POST /bookings/travel-estimate']) && !isset($routes['POST /bookings/new']) && ($routes['POST /bookings/submit'][2]??null)==='booking.submit_own','three-step flow retains one own-submission route behind the write gate');
$_GET=['id'=>'1'];$officer=new AuthenticatedPrincipal(12,1,['vehicle_officer']);
calCheck(calReject(fn()=>(new App\Controllers\RequesterBookingController())->detail($officer),App\Core\AuthorizationException::class) && calReject(fn()=>(new App\Controllers\RequesterBookingController())->document($officer),App\Core\AuthorizationException::class),'unscoped role denied before booking repository connection');
$pendingGuard=new App\Modules\Booking\PendingBookingRequirements();
$draftState=new App\Modules\Booking\BookingState(1,10,'draft',1);
calCheck(calReject(fn()=>$pendingGuard->assertSatisfied('submission',$requester,$draftState,'submitted'),DomainException::class) && calReject(fn()=>$pendingGuard->assertSatisfied('assignment',$admin,$draftState,'assigned'),DomainException::class),'submit and assignment remain fail-closed until transactional overlap policy is approved');
$preflight=file_get_contents(dirname(__DIR__).'/scripts/verify-booking-read-capability.php');
calCheck(str_contains($preflight,'WHERE 1=0') && !str_contains($preflight,'->exec('),'capability CLI checks metadata/projection only');

$fleet=new class implements App\Modules\Booking\VehicleCalendarRepository {
    public array $cars=[['id'=>7,'registration_number'=>'กข 7','label'=>'คันแรก','passenger_capacity'=>12,'is_active'=>1],['id'=>9,'registration_number'=>'กข 9','label'=>'คันสอง','passenger_capacity'=>9,'is_active'=>1]];
    public function activeVehicles():array{return $this->cars;}
    public function activeVehicle(int $id):?array{foreach($this->cars as $car)if($car['id']===$id)return $car;return null;}
    public function monthWindows(int $vehicleId,string $startUtc,string $endUtc):array{return ['pending'=>[],'confirmed'=>$vehicleId===9?[['start_at'=>'2026-11-20 01:00:00','end_at'=>'2026-11-20 03:00:00']]:[]];}
    public function available(int $vehicleId,BookingCalendarWindow $window):bool{return $vehicleId===7;}
    public function pending(int $vehicleId,BookingCalendarWindow $window):int{return 0;}
};
$fleetController=new App\Controllers\BookingCalendarController(null,new App\Modules\Booking\VehicleCalendarService($fleet));
foreach ([[],['vehicle_id'=>'999'],['vehicle_id'=>'bad']] as $query) {
    $_GET=['month'=>'2026-11']+$query;ob_start();$fleetController->index($requester);$page=ob_get_clean();
    calCheck(str_contains($page,'กำลังดู คันแรก · กข 7') && str_contains($page,'คันแรก · กข 7') && str_contains($page,'value="7" selected') && !str_contains($page,'ภาพรวมรถทุกคัน') && !str_contains($page,'กรองคันนี้'),'missing or invalid vehicle ID safely selects first active car with reordered label');
}
$_GET=['month'=>'2026-11','vehicle_id'=>'9'];ob_start();$fleetController->index($requester);$secondPage=ob_get_clean();
calCheck(str_contains($secondPage,'กำลังดู คันสอง · กข 9') && str_contains($secondPage,'คันสอง · กข 9') && str_contains($secondPage,'value="9" selected') && str_contains($secondPage,'name="month" value="2026-11"'),'direct link selects second vehicle and retains month');
$fleet->cars=[];$_GET=['month'=>'2026-11','vehicle_id'=>'9'];ob_start();$fleetController->index($requester);$emptyPage=ob_get_clean();
calCheck(str_contains($emptyPage,'ยังไม่มีรถตู้ในระบบ') && !str_contains($emptyPage,'<option value=') && !str_contains($emptyPage,'/bookings/new?'),'empty fleet has no invalid option or booking action');
$fleet->cars=[['id'=>7,'registration_number'=>'กข 7','label'=>'คันแรก','passenger_capacity'=>12,'is_active'=>1]];
foreach(['2026-02'=>28,'2028-02'=>29,'2026-04'=>30,'2026-11'=>30,'2026-12'=>31] as $testMonth=>$length){
    $_GET=['month'=>$testMonth];ob_start();$fleetController->index($requester);$page=ob_get_clean();
    calCheck(count((new App\Modules\Booking\VehicleCalendarService($fleet))->month($requester,7,$testMonth)['days'])===$length && str_contains($page,'name="month" value="'.$testMonth.'"') && substr_count($page,'href="/bookings/calendar?month=')>0 && !str_contains($page,'ค.ศ. ') && !str_contains($page,'พ.ศ. '),'calendar month length follows selected month and controls omit year caption');
}
$_GET=['month'=>'2026-11','vehicle_id'=>'1','start_date'=>'2026-11-19','end_date'=>'2026-11-19'];ob_start();$fleetController->index($requester);$nov19Page=ob_get_clean();
calCheck(str_contains($nov19Page,'value="19/11/2026"') && substr_count($nov19Page,'value="19/11/2026"')===2,'calendar selection of 19 November 2026 displays 19/11/2026 in both date inputs');
$_GET=[];ob_start();$fleetController->index($requester);$todayPage=ob_get_clean();
$bangkokToday=(new DateTimeImmutable('now',new DateTimeZone('Asia/Bangkok')))->format('Y-m-d');
calCheck(str_contains($todayPage,'name="month" value="'.substr($bangkokToday,0,7).'"') && str_contains($todayPage,'aria-current="date"') && !str_contains($todayPage,'>วันนี้</a>'),'first calendar load uses Bangkok current month and highlights today without a Today button');
$calendarSource=file_get_contents(dirname(__DIR__).'/app/Repositories/PdoVehicleCalendarRepository.php');
$availabilitySource=explode('public function pending(',explode('public function available(',$calendarSource,2)[1],2)[0];
preg_match_all('/:([a-z_]+)/',$availabilitySource,$availabilityParameters);
calCheck(count($availabilityParameters[1])===count(array_unique($availabilityParameters[1])) && str_contains($availabilitySource,"'assignment_start'=>\$window->startUtc") && str_contains($availabilitySource,"'assignment_end'=>\$window->endUtc"),'availability query uses unique native-PDO placeholders with the same requested interval');
calCheck(str_contains($calendarSource,'NOT EXISTS (SELECT 1 FROM booking_requests b WHERE b.requested_vehicle_id=v.id') && str_contains($calendarSource,'b.requested_start_at<:window_end AND b.requested_end_at>:window_start') && !str_contains(substr($calendarSource,strpos($calendarSource,'public function available(')),"'closed_rejected'") && !str_contains(substr($calendarSource,strpos($calendarSource,'public function available(')),"'cancelled'"),'availability SQL excludes overlapping submitted requests on the selected vehicle and releases rejected/cancelled requests');
echo "PASS: $checks Booking Read/Calendar offline checks; fake PDO only.\n";
