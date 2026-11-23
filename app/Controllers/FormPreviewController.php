<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\View;
use App\Core\AuthorizationException;
use App\Modules\Booking\BookingCalendarWindow;
use App\Modules\Booking\FuelTypeCatalog;
use App\Modules\Booking\TravelSnapshot;
use App\Modules\Booking\VehicleRegistrationDisplay;
use App\Models\AuthenticatedPrincipal;
use App\Services\AuthorizationPolicy;

/** Authenticated role-aware A4 preview. It contains no save or approval action. */
final class FormPreviewController
{
    public function __construct(private readonly ?\App\Modules\Booking\VehicleCalendarService $vehicleCalendar=null) {}

    private const SECTION3_FIELDS=['approval_outcome','rejection_reason','rejection_reason_continued','approver_printed_name','approver_position','decision_day','decision_month','decision_year'];

    public function index(?AuthenticatedPrincipal $actor=null): void
    {
        $policy=new AuthorizationPolicy();
        if($actor===null || !$policy->allows($actor->roles,'form.preview.view'))throw new AuthorizationException();
        $canEditPreview=$policy->allows($actor->roles,'form.preview.fields.edit');
        $formPermissions=[
            'requestFields'=>$canEditPreview,
            'vehicleFields'=>$canEditPreview,
            'section3'=>$policy->allows($actor->roles,'form.section3.edit') && $policy->allows($actor->roles,'booking.decision'),
            'canSubmit'=>$policy->allows($actor->roles,'booking.submit_own'),
            'previewNotice'=>'โหมดตัวอย่าง · การกรอกข้อมูลไม่บันทึกคำขอหรือการอนุมัติ',
        ];
        $examples = require dirname(__DIR__, 2) . '/resources/fixtures/form-preview.php';
        $example = $_GET['example'] ?? 'blank';
        if (!is_string($example) || !isset($examples[$example])) $example = 'blank';
        $contextKeys=['vehicle_id','vehicle_label','vehicle_registration','start','end','origin','destination','origin_lat','origin_lng','destination_lat','destination_lng','route_outbound_m','route_return_m','route_duration_ms','route_return_duration_ms'];
        $hasTransfer=array_intersect($contextKeys,array_keys($_GET))!==[];
        // The one user-facing sample action points to the completed fixture.
        $exampleReadOnly=!$hasTransfer && $example==='completed';
        // Sample fixtures are for viewing/printing only; never expose a submit action or key.
        $formPermissions['canSubmit']=$formPermissions['canSubmit'] && !$exampleReadOnly;
        $formData=$hasTransfer?[]:$examples[$example];$transferContext=null;$travelSnapshot=null;$transferError=null;
        if ($hasTransfer) {
            try {
                $vehicleId=$_GET['vehicle_id']??null;
                if(!is_string($vehicleId) || !ctype_digit($vehicleId) || strlen($vehicleId)>10 || (int)$vehicleId<1)throw new \InvalidArgumentException('VEHICLE_ID_INVALID');
                $vehicles=$this->vehicleCalendar??\App\Services\VehicleCalendarFactory::create();
                $vehicle=$vehicles->vehicle($actor,(int)$vehicleId);
                $vehicleLabel=(string)$vehicle['label'];
                $vehicleRegistration=VehicleRegistrationDisplay::format($vehicle['registration_number']??null,$vehicle['registration_province']??null);
                $vehicleProvince=(string)($vehicle['registration_province']??'');
                $formData=['vehicle_registration'=>$vehicleRegistration];
                $transferContext=['vehicle_id'=>(int)$vehicleId,'vehicle_label'=>$vehicleLabel,'vehicle_registration'=>$vehicleRegistration,'vehicle_province'=>$vehicleProvince];
                if(array_intersect(['start','end','start_date','end_date','start_time','end_time','origin_lat','destination_lat'],array_keys($_GET))!==[]) {
                $window=BookingCalendarWindow::fromTravelQuery($_GET);
                if(substr($window->startLocal,0,10)<(new \DateTimeImmutable('now',new \DateTimeZone('Asia/Bangkok')))->format('Y-m-d'))throw new \InvalidArgumentException('TRAVEL_DATE_IN_PAST');
                $fuelType=$_GET['fuel_type']??'';
                if(!is_string($fuelType) || ($fuelType!=='' && !FuelTypeCatalog::contains($fuelType)))throw new \InvalidArgumentException('FUEL_TYPE_INVALID');
                $travelSnapshot=TravelSnapshot::preview($_GET,$fuelType);
                if($travelSnapshot===null)throw new \InvalidArgumentException('TRAVEL_PREVIEW_INVALID');
                $startDate=substr($window->startLocal,0,10);$endDate=substr($window->endLocal,0,10);
                $formData=[
                    'vehicle_registration'=>$vehicleRegistration,
                    'destination'=>$travelSnapshot['destination'],
                    'departure_date'=>self::thaiDate($startDate),'departure_time'=>substr($window->startLocal,11,5),
                    'return_date'=>self::thaiDate($endDate),'return_time'=>substr($window->endLocal,11,5),
                ];
                $transferContext=['vehicle_id'=>(int)$vehicleId,'vehicle_label'=>$vehicleLabel,'vehicle_registration'=>$vehicleRegistration,'vehicle_province'=>$vehicleProvince,
                    'origin'=>$travelSnapshot['origin'],'start'=>$window->startLocal,'end'=>$window->endLocal];
                }
            } catch (\InvalidArgumentException) {
                http_response_code(422);$formData=[];$transferContext=null;$travelSnapshot=null;$transferError='ข้อมูลรถ วันเวลา หรือเส้นทางใน URL ไม่ครบหรือไม่ถูกต้อง โปรดกลับไปเลือกข้อมูลจากขั้นตอนก่อนหน้า';
            } catch (\Throwable) {
                http_response_code(422);$formData=[];$travelSnapshot=null;$transferContext=null;$transferError='ข้อมูลประมาณการไม่ถูกต้อง โปรดกลับไปเลือกเส้นทางใหม่';
            }
        }
        // Approval fields are fixture-owned, never URL-owned, and are omitted for roles without decision authority.
        if(!$formPermissions['section3'])foreach(self::SECTION3_FIELDS as $key)$formData[$key]='';
        $csrfToken=session_status()===PHP_SESSION_ACTIVE?\App\Core\Csrf::token():'';
        $submissionKey='';
        if($formPermissions['canSubmit']&&session_status()===PHP_SESSION_ACTIVE){
            $submissionKey=bin2hex(random_bytes(32));
            $_SESSION['booking_submission_keys'][$submissionKey]=time();
            $_SESSION['booking_submission_keys']=array_slice($_SESSION['booking_submission_keys'],-20,null,true);
        }
        View::render('form-preview', ['formData'=>$formData,'example'=>$hasTransfer?'':$example,'exampleReadOnly'=>$exampleReadOnly,'transferContext'=>$transferContext,'travelSnapshot'=>$travelSnapshot,'transferError'=>$transferError,'formPermissions'=>$formPermissions,'submissionGateOpen'=>\App\Core\BookingRuntimeFeature::submissionEnabled(),'csrfToken'=>$csrfToken,'submissionKey'=>$submissionKey]);
    }

    private static function text(mixed $value,int $limit,bool $required): string
    {
        if(!is_string($value) || self::length($value)>$limit || ($required && trim($value)===''))throw new \InvalidArgumentException('TRAVEL_TEXT_INVALID');
        return trim($value);
    }

    private static function length(string $value): int
    {
        if(function_exists('mb_strlen'))return mb_strlen($value);
        $matches=preg_match_all('/./us',$value);
        return $matches===false?PHP_INT_MAX:$matches;
    }
    private static function thaiDate(string $date): string
    {
        $parsed=\DateTimeImmutable::createFromFormat('!Y-m-d',$date,new \DateTimeZone('Asia/Bangkok'));
        if(!$parsed || $parsed->format('Y-m-d')!==$date)throw new \InvalidArgumentException('TRAVEL_DATE_INVALID');
        $months=['มกราคม','กุมภาพันธ์','มีนาคม','เมษายน','พฤษภาคม','มิถุนายน','กรกฎาคม','สิงหาคม','กันยายน','ตุลาคม','พฤศจิกายน','ธันวาคม'];
        return $parsed->format('j').' '.$months[(int)$parsed->format('n')-1].' '.((int)$parsed->format('Y')+543);
    }
}
