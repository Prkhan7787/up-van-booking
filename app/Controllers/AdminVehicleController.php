<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\AuthorizationException;
use App\Core\Csrf;
use App\Core\VehicleManagementFeature;
use App\Core\View;
use App\Models\AuthenticatedPrincipal;
use App\Modules\Booking\VehicleInput;
use App\Modules\Booking\VehicleManagementService;
use App\Services\AuthorizationPolicy;
use App\Services\VehicleManagementFactory;

/** Test-only Admin UI. POST cannot reach the repository while its gate is closed. */
final class AdminVehicleController
{
    public function __construct(private readonly ?VehicleManagementService $vehicles=null) {}

    public function index(AuthenticatedPrincipal $actor): void
    {
        if (!(new AuthorizationPolicy())->allows($actor->roles,'vehicles.admin')) throw new AuthorizationException();
        $rows=[];$error=null;$provinceEnabled=false;$fuelEnabled=false;
        try {$service=$this->vehicles ?? VehicleManagementFactory::create();$rows=$service->listing($actor);$provinceEnabled=$service->supportsRegistrationProvince($actor);$fuelEnabled=$service->supportsFuelDetails($actor);}
        catch (\Throwable) {http_response_code(503);$error='ไม่สามารถโหลดรายการรถตู้ได้ ต้องตรวจ Schema และ Grants ของฐานทดสอบ';}
        $notice=$_SESSION['admin_vehicle_notice'] ?? null;unset($_SESSION['admin_vehicle_notice']);
        $saveError=$_SESSION['admin_vehicle_save_error'] ?? null;unset($_SESSION['admin_vehicle_save_error']);
        View::renderWithWorkspaceLayout('admin/vehicles',[
            'pageTitle'=>'จัดการรถตู้','activeNav'=>'admin-vehicles','workspaceAuthenticated'=>true,'workspaceRoles'=>$actor->roles,
            'vehicles'=>array_slice($rows,0,25),'error'=>$error,'notice'=>is_string($notice)?$notice:null,
            'saveError'=>is_string($saveError)?$saveError:null,'provinceEnabled'=>$provinceEnabled,'fuelEnabled'=>$fuelEnabled,
            'fuelTypes'=>\App\Modules\Booking\FuelTypeCatalog::TYPES,
            'actionsEnabled'=>VehicleManagementFeature::writesEnabled() && $error===null,
        ]);
    }

    public function submit(AuthenticatedPrincipal $actor): void
    {
        if (!(new AuthorizationPolicy())->allows($actor->roles,'vehicles.admin')) throw new AuthorizationException();
        if (!VehicleManagementFeature::writesEnabled()) {http_response_code(503);echo 'การจัดการรถตู้ยังไม่เปิดใช้งาน';return;}
        if (!Csrf::valid($_POST['_token'] ?? null)) {http_response_code(419);echo 'คำขอหมดอายุ กรุณาโหลดหน้าใหม่';return;}
        try {
            $service=$this->vehicles ?? VehicleManagementFactory::create();
            $action=$_POST['action'] ?? null;
            if ($action==='create') $service->create($actor,VehicleInput::fromArray($_POST));
            elseif ($action==='update') $service->update($actor,self::id($_POST['vehicle_id'] ?? null),VehicleInput::fromArray($_POST));
            elseif ($action==='delete') $service->delete($actor,self::id($_POST['vehicle_id'] ?? null));
            else throw new \InvalidArgumentException('VEHICLE_ACTION_INVALID');
            $_SESSION['admin_vehicle_notice']=$action==='delete'?'ลบรถตู้สำเร็จ':'บันทึกข้อมูลรถตู้สำเร็จ';
            unset($_SESSION['admin_vehicle_save_error']);
            header('Location: /admin/vehicles',true,303);
        } catch (\InvalidArgumentException) {
            $this->redirectError('ข้อมูลรถตู้ไม่ถูกต้อง');
        } catch (\DomainException $error) {
            $message=match($error->getMessage()) {
                'VEHICLE_REGISTRATION_DUPLICATE'=>'หมายเลขทะเบียนซ้ำ',
                'VEHICLE_ALREADY_INACTIVE'=>'รถตู้ถูกลบออกจากรายการใช้งานแล้ว กรุณาโหลดหน้าใหม่',
                'VEHICLE_DELETE_NOT_APPLIED'=>'ยังลบรถตู้ไม่ได้ กรุณาลองใหม่ภายหลัง',
                'VEHICLE_HAS_ASSIGNMENTS'=>'รถมีรายการจัดรถแล้ว จึงยังแก้ไขหรือปิดใช้งานไม่ได้',
                default=>'ไม่พบรถที่ต้องการจัดการ',
            };
            $this->redirectError($message);
        } catch (\PDOException $error) {
            $duplicate=(int)($error->errorInfo[1] ?? 0)===1062;
            $this->redirectError($duplicate?'หมายเลขทะเบียนซ้ำ':'ยังบันทึกข้อมูลรถตู้ไม่ได้');
        } catch (\Throwable) {
            $this->redirectError('ยังบันทึกข้อมูลรถตู้ไม่ได้');
        }
    }

    private function redirectError(string $message): void
    {
        unset($_SESSION['admin_vehicle_notice']);
        $_SESSION['admin_vehicle_save_error']=$message;
        header('Location: /admin/vehicles',true,303);
    }

    private static function id(mixed $value): int
    {
        if (!is_string($value) || !ctype_digit($value) || strlen($value)>10 || (int)$value<1) throw new \InvalidArgumentException('VEHICLE_ID_INVALID');
        return (int)$value;
    }
}
