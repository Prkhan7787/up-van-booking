<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') {http_response_code(404);exit;}
require dirname(__DIR__,2).'/bootstrap/cli.php';
require dirname(__DIR__,2).'/app/helpers.php';
$_SESSION=[];
$mode=$argv[1] ?? 'requester';
$role=$mode==='admin' ? 'admin' : 'requester';
$_GET=$mode==='overview' ? ['month'=>'2026-11'] : ['month'=>'2026-11','start_date'=>'2026-11-20','end_date'=>$mode==='full' || $mode==='no-fleet' ? '2026-11-20' : '2026-11-22','start_time'=>'08:00','end_time'=>'17:00'];
if($mode!=='overview' && $mode!=='no-fleet') $_GET['vehicle_id']='1';
$repo=new class($mode) implements App\Modules\Booking\BookingCalendarRepository {
    public function __construct(private string $mode) {}
    public function monthWindows(string $startUtc,string $endUtc):array {return ['pending'=>[['start_at'=>'2026-11-20 01:00:00','end_at'=>'2026-11-20 10:00:00']],'confirmed'=>[['start_at'=>'2026-11-21 01:00:00','end_at'=>'2026-11-21 10:00:00']]];}
    public function eligibleVehicles(?int $passengers):int {return $this->mode==='no-fleet'?0:2;}
    public function availableVehicles(App\Modules\Booking\BookingCalendarWindow $window):int {return $this->mode==='full' || $this->mode==='no-fleet' ? 0 : 1;}
    public function pendingRequests(App\Modules\Booking\BookingCalendarWindow $window):int {return 1;}
};
$vehicleRepo=new class($mode) implements App\Modules\Booking\VehicleCalendarRepository {
    public function __construct(private string $mode) {}
    public function activeVehicles():array {return $this->mode==='no-fleet'?[]:[['id'=>1,'registration_number'=>'กข 1234','registration_province'=>'พะเยา','label'=>'รถทดลอง','passenger_capacity'=>12,'is_active'=>1],['id'=>2,'registration_number'=>'กข 5678','registration_province'=>'พะเยา','label'=>'รถสำรอง','passenger_capacity'=>9,'is_active'=>1]];}
    public function activeVehicle(int $id):?array {foreach($this->activeVehicles() as $vehicle)if($vehicle['id']===$id)return $vehicle;return null;}
    public function monthWindows(int $vehicleId,string $startUtc,string $endUtc):array {return ['availability_confirmed'=>true,'pending'=>$this->mode==='requester'?[]:[['start_at'=>'2026-11-20 01:00:00','end_at'=>'2026-11-20 10:00:00']],'confirmed'=>$this->mode==='requester'?[]:[['start_at'=>'2026-11-21 01:00:00','end_at'=>'2026-11-21 10:00:00']]];}
    public function available(int $vehicleId,App\Modules\Booking\BookingCalendarWindow $window):bool {return $this->mode!=='full' && $this->mode!=='no-fleet';}
    public function pending(int $vehicleId,App\Modules\Booking\BookingCalendarWindow $window):int {return $this->mode==='requester'?0:1;}
};
(new App\Controllers\BookingCalendarController(new App\Modules\Booking\BookingCalendarService($repo),new App\Modules\Booking\VehicleCalendarService($vehicleRepo)))->index(new App\Models\AuthenticatedPrincipal(10,1,[$role]));
