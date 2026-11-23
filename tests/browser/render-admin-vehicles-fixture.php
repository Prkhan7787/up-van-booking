<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') {http_response_code(404);exit;}
require dirname(__DIR__,2).'/bootstrap/cli.php';
require dirname(__DIR__,2).'/app/helpers.php';
$_SESSION=[];
$scenario=$argv[1]??'normal';
// Fixture process only: every repository write throws, and no PDO exists here.
putenv('TEST_VEHICLE_MANAGEMENT_APPROVED='.(in_array($scenario,['cards005','cards006'],true)?'true':'false'));
if($scenario==='save-error') $_SESSION['admin_vehicle_save_error']='หมายเลขทะเบียนซ้ำ';
$repo=new class(in_array($scenario,['province','cards006'],true),in_array($scenario,['cards005','cards006'],true)) implements App\Modules\Booking\VehicleRepository {
    public bool $failListing=false;
    public function __construct(private bool $provinceEnabled,private bool $twoCards){}
    public function supportsRegistrationProvince():bool{return $this->provinceEnabled;}
    public function list(int $limit,int $offset):array {if($this->failListing)throw new RuntimeException('OFFLINE_LOAD_ERROR');$rows=[['id'=>1,'label'=>'รถทดลอง','registration_number'=>'กข 1234','registration_province'=>$this->provinceEnabled?'พะเยา':null,'passenger_capacity'=>12,'is_active'=>1]];if($this->twoCards)$rows[]=['id'=>2,'label'=>'รถคันที่2','registration_number'=>'กท36512(ทดสอบ)','registration_province'=>$this->provinceEnabled?'เชียงใหม่':null,'passenger_capacity'=>9,'is_active'=>0];return $rows;}
    public function find(int $id,bool $forUpdate=false):?array {return null;}
    public function registrationExists(string $registration,int $exceptId=0):bool {return false;}
    public function hasAssignments(int $id):bool {return false;}
    public function transaction(callable $operation):mixed {throw new RuntimeException('FIXTURE_READ_ONLY');}
    public function insert(App\Modules\Booking\VehicleInput $input,int $actorId):int {throw new RuntimeException('FIXTURE_READ_ONLY');}
    public function update(int $id,App\Modules\Booking\VehicleInput $input,int $actorId):void {throw new RuntimeException('FIXTURE_READ_ONLY');}
    public function deactivate(int $id,int $actorId):void {throw new RuntimeException('FIXTURE_READ_ONLY');}
    public function audit(int $actorId,string $action,int $vehicleId,array $before,array $after):void {throw new RuntimeException('FIXTURE_READ_ONLY');}
};
$repo->failListing=$scenario==='load-error';
(new App\Controllers\AdminVehicleController(new App\Modules\Booking\VehicleManagementService($repo)))->index(new App\Models\AuthenticatedPrincipal(1,1,['admin']));
