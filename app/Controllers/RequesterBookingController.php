<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\AuthorizationException;
use App\Core\View;
use App\Models\AuthenticatedPrincipal;
use App\Modules\Booking\RequesterA4Presenter;
use App\Modules\Booking\RequesterDraftService;
use App\Modules\Booking\BookingCalendarWindow;
use App\Modules\Booking\BangchakPriceFeed;
use App\Modules\Booking\VehicleCalendarService;
use App\Services\AuthorizationPolicy;
use App\Services\RequesterBookingReadFactory;
use App\Services\VehicleCalendarFactory;
use App\Core\RequesterBookingUnavailableException;

/** GET-only test runtime. No production Booking connection or HTTP mutation route is installed. */
final class RequesterBookingController
{
    private const TRAVEL_ORIGIN='สถาบันนวัตกรรมการเรียนรู้ มหาวิทยาลัยพะเยา';
    public function __construct(private readonly ?RequesterDraftService $drafts=null,private readonly bool $runtimeReadsEnabled=false,private readonly ?VehicleCalendarService $vehicleCalendar=null) {}
    public function travelEstimate(AuthenticatedPrincipal $actor): void
    {
        if (!(new AuthorizationPolicy())->allows($actor->roles,'travel_estimate.view')) throw new AuthorizationException();
        $selection=null;$selectedVehicle=null;$error=null;
        try {
            $selection=BookingCalendarWindow::fromTravelQuery($_GET);
            if (substr($selection->startLocal,0,10)<(new \DateTimeImmutable('now',new \DateTimeZone('Asia/Bangkok')))->format('Y-m-d')) throw new \InvalidArgumentException('BOOKING_DATE_IN_PAST');
            $raw=$_GET['vehicle_id'] ?? null;
            if (!is_string($raw) || !ctype_digit($raw) || strlen($raw)>10 || (int)$raw<1) throw new \InvalidArgumentException();
            $vehicleService=$this->vehicleCalendar ?? VehicleCalendarFactory::create();
            $selectedVehicle=$vehicleService->vehicle($actor,(int)$raw);
        } catch (\InvalidArgumentException) {http_response_code(422);$error='รถหรือช่วงวันเวลาไม่ถูกต้อง';}
        catch (\Throwable) {http_response_code(503);$error='ยังอ่านข้อมูลรถจากฐานทดสอบไม่ได้';}
        $policy=new AuthorizationPolicy();
        View::renderWithWorkspaceLayout('booking/travel-estimate',self::layout($actor,'แผนที่และคำนวณค่าเดินทาง')+['selection'=>$selection,'selectedVehicle'=>$selectedVehicle,'travelError'=>$error,'originLabel'=>self::TRAVEL_ORIGIN,'canPrepareBooking'=>$policy->allows($actor->roles,'booking.draft.create'),'canOpenBookingForm'=>$selection!==null && $selectedVehicle!==null && $policy->allows($actor->roles,'travel_estimate.view')]);
    }
    public function fuelPrices(AuthenticatedPrincipal $actor): void
    {
        if (!(new AuthorizationPolicy())->allows($actor->roles,'travel_estimate.view')) throw new AuthorizationException();
        header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: private, no-store');
        try {
            $result=BangchakPriceFeed::read(dirname(__DIR__,2).'/storage/cache/bangchak-oil-price.json');
            echo json_encode(['status'=>'PASS']+$result,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            http_response_code(503);echo '{"status":"UNAVAILABLE","code":"FUEL_PRICE_SOURCE_UNAVAILABLE"}';
        }
    }
    public function index(AuthenticatedPrincipal $actor): void
    {
        if ($this->runtimeReadsEnabled && $this->drafts === null && !(new AuthorizationPolicy())->allows($actor->roles,'booking.view_all')) {
            header('Location: ' . '/my/booking-requests', true, 303);
            return;
        }
        if (!(new AuthorizationPolicy())->allows($actor->roles,'booking.view_own')) throw new AuthorizationException();
        $page=self::positive($_GET['page'] ?? '1',10000);
        try {$drafts=$this->reader();} catch (RequesterBookingUnavailableException) {$this->unavailable($actor);return;}
        $rows=$drafts->listOwn($actor,$page);
        $hasNext=count($rows)>25;
        View::renderWithWorkspaceLayout('booking/mine',self::layout($actor,'คำขอของฉัน')+['rows'=>array_slice($rows,0,25),'page'=>$page,'hasNext'=>$hasNext]);
    }
    public function adminIndex(AuthenticatedPrincipal $actor): void
    {
        if (!(new AuthorizationPolicy())->allows($actor->roles,'booking.view_all')) throw new AuthorizationException();
        $page=self::positive($_GET['page'] ?? '1',10000);
        try {$drafts=$this->reader();} catch (RequesterBookingUnavailableException) {$this->unavailable($actor);return;}
        $rows=$drafts->listAll($actor,$page);
        View::renderWithWorkspaceLayout('booking/mine',self::layout($actor,'คำขอทั้งหมด')+['rows'=>array_slice($rows,0,25),'page'=>$page,'hasNext'=>count($rows)>25,'listTitle'=>'คำขอทั้งหมด','listPath'=>'/bookings/admin']);
    }
    public function detail(AuthenticatedPrincipal $actor): void
    {
        if ($this->runtimeReadsEnabled && $this->drafts === null && !(new AuthorizationPolicy())->allows($actor->roles,'booking.view_all')) {
            header('Location: ' . '/my/booking-requests/detail' . '?id=' . self::positive($_GET['id'] ?? null), true, 303);
            return;
        }
        $policy=new AuthorizationPolicy();
        if (!$policy->allows($actor->roles,'booking.view_own') && !$policy->allows($actor->roles,'booking.view_all')) throw new AuthorizationException();
        $id=self::positive($_GET['id'] ?? null);
        try {$drafts=$this->reader();} catch (RequesterBookingUnavailableException) {$this->unavailable($actor);return;}
        $row=$drafts->detail($actor,$id);
        View::renderWithWorkspaceLayout('booking/detail',self::layout($actor,'รายละเอียดคำขอ')+['booking'=>$row,'backPath'=>(new AuthorizationPolicy())->allows($actor->roles,'booking.view_own') ? '/bookings' : '/bookings/admin']);
    }
    public function document(AuthenticatedPrincipal $actor): void
    {
        if ($this->runtimeReadsEnabled && $this->drafts === null && !(new AuthorizationPolicy())->allows($actor->roles,'booking.view_all')) {
            header('Location: ' . '/my/booking-requests/detail' . '?id=' . self::positive($_GET['id'] ?? null), true, 303);
            return;
        }
        $policy=new AuthorizationPolicy();
        if (!$policy->allows($actor->roles,'booking.view_own') && !$policy->allows($actor->roles,'booking.view_all')) throw new AuthorizationException();
        $id=self::positive($_GET['id'] ?? null);
        try {$drafts=$this->reader();} catch (RequesterBookingUnavailableException) {$this->unavailable($actor);return;}
        $row=$drafts->detail($actor,$id);
        View::render('form-preview',['formData'=>RequesterA4Presenter::formData($row),'example'=>'','documentMode'=>true,'documentBack'=>(new AuthorizationPolicy())->allows($actor->roles,'booking.view_own') ? '/bookings' : '/bookings/admin']);
    }
    private function unavailable(AuthenticatedPrincipal $actor): void
    {
        http_response_code(503);
        View::renderWithWorkspaceLayout('booking/unavailable',self::layout($actor,'คำขอยังไม่พร้อมใช้งาน'));
    }
    private function reader(): RequesterDraftService
    {
        if ($this->drafts!==null) return $this->drafts;
        if (!$this->runtimeReadsEnabled) throw new RequesterBookingUnavailableException();
        return RequesterBookingReadFactory::create();
    }
    private static function positive(mixed $value,int $maximum=2147483647): int
    {
        if (!is_string($value) || !ctype_digit($value) || strlen($value)>10 || (int)$value<1 || (int)$value>$maximum) throw new AuthorizationException();
        return (int)$value;
    }
    private static function layout(AuthenticatedPrincipal $actor,string $title): array
    {
        return ['pageTitle'=>$title,'activeNav'=>'booking','workspaceAuthenticated'=>true,'workspaceRoles'=>$actor->roles];
    }
}
