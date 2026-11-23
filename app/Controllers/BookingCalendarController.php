<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\AuthorizationException;
use App\Core\View;
use App\Models\AuthenticatedPrincipal;
use App\Modules\Booking\BookingCalendarService;
use App\Modules\Booking\BookingCalendarWindow;
use App\Modules\Booking\VehicleCalendarService;
use App\Services\AuthorizationPolicy;
use App\Services\BookingCalendarFactory;
use App\Services\VehicleCalendarFactory;
use DateTimeImmutable;
use DateTimeZone;

final class BookingCalendarController
{
    public function __construct(private readonly ?BookingCalendarService $calendar=null,private readonly ?VehicleCalendarService $vehicleCalendar=null) {}
    public function index(AuthenticatedPrincipal $actor): void
    {
        if (!(new AuthorizationPolicy())->allows($actor->roles,'calendar.view')) throw new AuthorizationException();
        $defaultMonth=(new DateTimeImmutable('now',new DateTimeZone('Asia/Bangkok')))->format('Y-m');
        $month=$_GET['month'] ?? $defaultMonth;
        $error=null;$days=[];$quote=null;$window=null;$showCounts=false;$vehicles=[];$selectedVehicle=null;$vehicleUnavailable=false;
        $vehicleId=null;
        // Real editable defaults, rather than time placeholders that look filled.
        $dates=['start_date'=>'','end_date'=>'','start_time'=>'08:00','end_time'=>'17:00'];
        foreach ($dates as $key=>$_) {
            if (is_string($_GET[$key] ?? null) && ($_GET[$key]!=='' || !in_array($key,['start_time','end_time'],true))) $dates[$key]=$_GET[$key];
        }
        try {
            if (!is_string($month) || !preg_match('/^\d{4}-\d{2}$/D',$month)) throw new \InvalidArgumentException('CALENDAR_MONTH_INVALID');
            $rawVehicle=$_GET['vehicle_id'] ?? '';
            if (is_string($rawVehicle) && ctype_digit($rawVehicle) && strlen($rawVehicle)<=10 && (int)$rawVehicle>0) $vehicleId=(int)$rawVehicle;
            if ($dates['start_date']!=='' && $dates['end_date']!=='' && $dates['start_time']!=='' && $dates['end_time']!=='') {
                $window=BookingCalendarWindow::fromQuery(['start'=>$dates['start_date'].'T'.$dates['start_time'],'end'=>$dates['end_date'].'T'.$dates['end_time']]);
            }
            $vehicleService=$this->vehicleCalendar;
            if ($vehicleService===null) {
                try {$vehicleService=VehicleCalendarFactory::create();}
                catch (\Throwable) {$vehicleUnavailable=true;throw new \RuntimeException('VEHICLE_CALENDAR_NOT_READY');}
            }
            $vehicles=$vehicleService->vehicles($actor);
            if ($vehicles!==[]) {
                $selectedVehicle=$vehicles[0];
                foreach ($vehicles as $vehicle) if ((int)$vehicle['id']===$vehicleId) {$selectedVehicle=$vehicle;break;}
                $vehicleId=(int)$selectedVehicle['id'];
                $result=$vehicleService->month($actor,$vehicleId,$month);
                if ($window!==null) $quote=$vehicleService->quote($actor,$vehicleId,$window);
                $days=$result['days'];$showCounts=$result['showCounts'];
            }
        } catch (\PDOException|\RuntimeException $failure) {
            $code=in_array($failure->getMessage(),['CALENDAR_SCHEDULE_GRANTS_REQUIRED','VEHICLE_CALENDAR_NOT_READY','CALENDAR_COLUMN_PROBE_ERROR','VEHICLE_CALENDAR_GRANTS_REJECTED','REQUESTED_VEHICLE_SCHEMA_REQUIRED'],true)?$failure->getMessage():'CALENDAR_READ_FAILED';
            http_response_code(503);$error='ยังตรวจปฏิทินจากฐานทดสอบไม่ได้ ['.$code.'] ต้องตรวจสิทธิ์อ่านและ Schema';
        } catch (\InvalidArgumentException) {
            http_response_code(422);$error='เดือน วันที่ หรือเวลาไม่ถูกต้อง';
        }
        // Render unknown days after a failed read; never turn a query failure into an empty/free schedule.
        if($days===[] && $selectedVehicle!==null && is_string($month)) {
            $first=DateTimeImmutable::createFromFormat('!Y-m-d',$month.'-01',new DateTimeZone('Asia/Bangkok'));
            if($first!==false && $first->format('Y-m')===$month) {
                $last=$first->modify('first day of next month');
                for($day=$first;$day<$last;$day=$day->modify('+1 day'))$days[]=['date'=>$day->format('Y-m-d'),'status'=>'unchecked','pending'=>0,'confirmed'=>0];
            }
        }
        View::renderWithWorkspaceLayout('booking/calendar',[
            'pageTitle'=>'ปฏิทินการใช้รถ','activeNav'=>'booking-calendar','workspaceAuthenticated'=>true,'workspaceRoles'=>$actor->roles,
            'month'=>is_string($month)?$month:$defaultMonth,'days'=>$days,'showCounts'=>$showCounts,'quote'=>$quote,'window'=>$window,'dates'=>$dates,'error'=>$error,
            'vehicles'=>$vehicles,'selectedVehicle'=>$selectedVehicle,'vehicleId'=>$vehicleId,'vehicleUnavailable'=>$vehicleUnavailable,
            'canPrepareForm'=>(new AuthorizationPolicy())->allows($actor->roles,'travel_estimate.view'),
        ]);
    }
}
