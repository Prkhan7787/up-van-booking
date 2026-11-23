<?php
declare(strict_types=1);
// Offline catalog only. No MySQL or production runtime is used.
return new App\Modules\Booking\VehicleCalendarService(new class implements App\Modules\Booking\VehicleCalendarRepository {
    public function activeVehicles():array{return [
        ['id'=>1,'registration_number'=>'TEST-001','registration_province'=>'Test province','label'=>'Fixture van 1','passenger_capacity'=>12,'is_active'=>1],
        ['id'=>2,'registration_number'=>'TEST-002','registration_province'=>'Test province','label'=>'Fixture van 2','passenger_capacity'=>12,'is_active'=>1],
    ];}
    public function activeVehicle(int $id):?array{foreach($this->activeVehicles() as $row)if($row['id']===$id)return $row;return null;}
    public function monthWindows(int $vehicleId,string $startUtc,string $endUtc):array{return [];}
    public function available(int $vehicleId,App\Modules\Booking\BookingCalendarWindow $window):bool{return true;}
    public function pending(int $vehicleId,App\Modules\Booking\BookingCalendarWindow $window):int{return 0;}
});
