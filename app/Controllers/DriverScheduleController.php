<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\DriverAssignmentNotFoundException;
use App\Core\View;
use App\Models\AuthenticatedPrincipal;
use App\Services\DriverFeatureFactory;
use App\Services\DriverScheduleService;

final class DriverScheduleController
{
    public function __construct(private readonly ?DriverScheduleService $schedule = null) {}

    public function dashboard(AuthenticatedPrincipal $actor): void
    {
        try {
            $data = ($this->schedule ?? DriverFeatureFactory::schedule())->listing($actor);
            View::renderWithWorkspaceLayout('driver/dashboard', ['pageTitle'=>'ภาพรวมพนักงานขับรถ','activeNav'=>'driver-dashboard','workspaceAuthenticated'=>true,'workspaceRoles'=>$actor->roles,'linked'=>$data['linked'],'assignmentCount'=>count($data['assignments'])]);
        } catch (\Throwable) { $this->unavailable(); }
    }

    public function index(AuthenticatedPrincipal $actor): void
    {
        try {
            $data = ($this->schedule ?? DriverFeatureFactory::schedule())->listing($actor);
            View::renderWithWorkspaceLayout('driver/schedule', ['pageTitle'=>'ตารางงานขับรถ','activeNav'=>'driver-schedule','workspaceAuthenticated'=>true,'workspaceRoles'=>$actor->roles,'linked'=>$data['linked'],'assignments'=>$data['assignments'],'detail'=>null]);
        } catch (\Throwable) { $this->unavailable(); }
    }

    public function detail(AuthenticatedPrincipal $actor): void
    {
        try {
            $assignmentId = self::id($_GET['id'] ?? null);
            $service = $this->schedule ?? DriverFeatureFactory::schedule();
            $list = $service->listing($actor);
            $detail = $service->detail($actor, $assignmentId);
            View::renderWithWorkspaceLayout('driver/schedule', ['pageTitle'=>'รายละเอียดงานขับรถ','activeNav'=>'driver-schedule','workspaceAuthenticated'=>true,'workspaceRoles'=>$actor->roles,'linked'=>$list['linked'],'assignments'=>$list['assignments'],'detail'=>$detail]);
        } catch (DriverAssignmentNotFoundException|\InvalidArgumentException) { http_response_code(404); echo 'ไม่พบงานที่ต้องการ'; }
        catch (\Throwable) { $this->unavailable(); }
    }

    private function unavailable(): void
    {
        http_response_code(503);
        View::render('auth/unavailable', ['pageTitle'=>'ยังไม่สามารถโหลดตารางงาน','message'=>'ข้อมูลตารางงานพนักงานขับรถยังไม่พร้อมใช้งาน กรุณาติดต่อผู้ดูแล']);
    }

    private static function id(mixed $value): int
    {
        if (!is_string($value) || !ctype_digit($value) || strlen($value)>10 || (int)$value<1) throw new \InvalidArgumentException('DRIVER_ASSIGNMENT_ID_INVALID');
        return (int)$value;
    }
}
