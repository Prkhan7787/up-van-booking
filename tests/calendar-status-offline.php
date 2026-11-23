<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/cli.php';
require dirname(__DIR__).'/app/helpers.php';
use App\Modules\Booking\{VehicleCalendarService,VehicleCalendarRepository,BookingCalendarWindow};
use App\Repositories\PdoVehicleCalendarRepository;
use App\Models\AuthenticatedPrincipal;
$checks=0;
function statusCheck(bool $ok,string $name): void {global $checks;if(!$ok)throw new RuntimeException('FAIL: '.$name);$checks++;}
final class StatusStatement extends PDOStatement {
    public function __construct(private StatusPdo $pdo,private string $sql) {}
    private array $params=[];
    public function execute(?array $params=null):bool {$this->params=$params??[];return true;}
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array {
        if(($this->params['vehicle_id']??0)!==7)return [];
        if(str_contains($this->sql,'FROM vehicle_assignments'))return $this->pdo->assignments;
        if(!str_contains($this->sql,'workflow_status IN'))throw new RuntimeException('Unexpected SQL');
        return $this->pdo->requests;
    }
    public function fetchColumn(int $column=0):mixed {return str_contains($this->sql,'FROM vehicles v')?$this->pdo->available:$this->pdo->invalidCount;}
}
final class StatusPdo extends PDO {
    public array $requests=[],$assignments=[]; public int $invalidCount=0,$available=1;
    public function __construct() {}
    public function prepare(string $query,array $options=[]):PDOStatement|false {
        if(!str_starts_with($query,'SELECT '))throw new RuntimeException('Mutation rejected');
        return new StatusStatement($this,$query);
    }
}
$pdo=new StatusPdo();
$reflection=new ReflectionClass(PdoVehicleCalendarRepository::class);
$read=$reflection->newInstanceWithoutConstructor();
foreach(['db'=>$pdo,'scheduleReadable'=>true,'requestedVehicleEnabled'=>true,'activeRequest'=>''] as $key=>$value)$reflection->getProperty($key)->setValue($read,$value);
$repo=new class($read) implements VehicleCalendarRepository {
    public function __construct(private PdoVehicleCalendarRepository $read) {}
    public function activeVehicles():array {return [['id'=>7,'label'=>'fixture 7','registration_number'=>'TEST 7','passenger_capacity'=>12,'is_active'=>1],['id'=>8,'label'=>'fixture 8','registration_number'=>'TEST 8','passenger_capacity'=>12,'is_active'=>1]];}
    public function activeVehicle(int $id):?array {foreach($this->activeVehicles() as $vehicle)if($vehicle['id']===$id)return $vehicle;return null;}
    public function monthWindows(int $id,string $a,string $b):array {return $this->read->monthWindows($id,$a,$b);}
    public function available(int $id,BookingCalendarWindow $window):bool {return $this->read->available($id,$window);}
    public function pending(int $id,BookingCalendarWindow $window):int {return 0;}
};
$service=new VehicleCalendarService($repo);$admin=new AuthenticatedPrincipal(1,1,['admin']);
$month=fn(int $id=7)=>$service->month($admin,$id,'2026-11')['days'];
statusCheck($month()[13]['status']==='free','empty verified schedule is free, not a hardcoded label');
$request=['start_at'=>'2026-11-14 01:00:00.000000','end_at'=>'2026-11-14 05:00:00.000000','workflow_status'=>'submitted'];
foreach(['submitted','under_review','pending_approval'] as $state) {
    $pdo->requests=[array_replace($request,['workflow_status'=>$state])];
    statusCheck($month()[13]['status']==='pending','actual pending workflow reserves its day');
}
statusCheck($month()[12]['status']==='free' && $month(8)[13]['status']==='free','other dates and other vehicles remain independent');
$window=BookingCalendarWindow::fromQuery(['start'=>'2026-11-14T12:00','end'=>'2026-11-14T16:00']);
$quote=$service->quote($admin,7,$window);
statusCheck($quote['pending']===0 && $quote['availability_confirmed'],'adjacent 08–12 and 12–16 do not overlap, including microseconds');
$quote=$service->quote($admin,7,BookingCalendarWindow::fromQuery(['start'=>'2026-11-14T11:59','end'=>'2026-11-14T16:00']));
statusCheck($quote['pending']===1,'one minute overlap is detected');
foreach(['awaiting_assignment','assigned'] as $state) {
    $pdo->requests=[array_replace($request,['workflow_status'=>$state])];$pdo->available=0;
    statusCheck($month()[13]['status']==='unchecked','approval or booking status alone never implies an actual assignment');
    $quote=$service->quote($admin,7,BookingCalendarWindow::fromQuery(['start'=>'2026-11-14T08:00','end'=>'2026-11-14T12:00']));
    statusCheck($quote['pending']===0 && $quote['unconfirmed']===1 && $quote['available']===0,'approved unassigned interval remains blocked without a pending/confirmed label');
}
$pdo->assignments=[['start_at'=>$request['start_at'],'end_at'=>$request['end_at']]];
statusCheck($month()[13]['status']==='assigned','actual assignment is booked');
$pdo->requests=[];$pdo->assignments=[];$pdo->invalidCount=1;
statusCheck($month()[13]['status']==='unchecked','requests with missing/invalid times cannot prove free');
$pdo->invalidCount=0;$reflection->getProperty('requestedVehicleEnabled')->setValue($read,false);
statusCheck($month()[13]['status']==='unchecked','missing requested-vehicle schema cannot prove free');
$reflection->getProperty('requestedVehicleEnabled')->setValue($read,true);
$pdo->requests=[array_replace($request,['start_at'=>'2026-11-14 17:00:00','end_at'=>'2026-11-15 01:00:00'])];
statusCheck($month()[13]['status']==='free' && $month()[14]['status']==='pending','Bangkok midnight boundaries and multi-day times are respected');
$pdo->requests=[array_replace($request,['start_at'=>'2026-02-30 01:00:00'])];
$rejected=false;try{$month();}catch(RuntimeException){$rejected=true;}
statusCheck($rejected,'malformed timestamps fail instead of becoming free days');
$_SESSION=[];$_GET=['month'=>'2026-11','vehicle_id'=>'7'];
ob_start();(new App\Controllers\BookingCalendarController(null,$service))->index($admin);$failedPage=ob_get_clean();
statusCheck(http_response_code()===503 && !str_contains($failedPage,'>ยังไม่ยืนยัน</span>') && str_contains($failedPage,'CALENDAR_READ_FAILED'),'failed read renders unknown days and keeps the safe error visible');
$pdo->requests=[];$pdo->available=1;http_response_code(200);
ob_start();(new App\Controllers\BookingCalendarController(null,$service))->index($admin);$freePage=ob_get_clean();
statusCheck(!str_contains($freePage,'>ว่าง</span>') && !str_contains($freePage,'bg-emerald-50') && !str_contains($freePage,'จากรถที่เปิดใช้งาน'),'free-day labels/green backgrounds and summary UI removed while service still reports availability');
$source=file_get_contents(dirname(__DIR__).'/app/Repositories/PdoVehicleCalendarRepository.php');
statusCheck(str_contains($source,"'closed_rejected'"),'display includes rejected requests');
$pdo->requests=[];
foreach(['submitted','pending_approval','awaiting_assignment','closed_rejected'] as $i=>$state) $pdo->requests[]=array_replace($request,['booking_id'=>$i+1,'workflow_status'=>$state]);
ob_start();(new App\Controllers\BookingCalendarController(null,$service))->index($admin);$workflowPage=ob_get_clean();
foreach (['รอตรวจสอบ'=>'bg-blue-600','รออนุมัติ'=>'bg-yellow-500','อนุมัติแล้ว'=>'bg-green-600','ไม่อนุมัติ'=>'bg-red-600'] as $label=>$color) statusCheck(str_contains($workflowPage,$label) && str_contains($workflowPage,$color),'workflow label and color rendered: '.$label);
statusCheck(substr_count($workflowPage,'data-calendar-request=')===4,'render all four requests on the same day');
$days=$month();
statusCheck(count($days[13]['requests'])===4,'all four workflows coexist on the same day');
statusCheck($days[12]['requests']===[],'empty day has no synthetic event');
$pdo->requests[0]['workflow_status']='closed_rejected';
statusCheck($month()[13]['requests'][0]['workflow_status']==='closed_rejected','reload reflects changed workflow');
$pdo->requests=[array_replace($request,['booking_id'=>9,'start_at'=>'2026-11-14 16:00:00','end_at'=>'2026-11-15 01:00:00'])];
$days=$month();statusCheck(count($days[13]['requests'])===1 && count($days[14]['requests'])===1,'multi-day request appears on both Bangkok days');

echo "PASS: $checks calendar status checks; real repository classification with fake PDO; no database access.\n";
