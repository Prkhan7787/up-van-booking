<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\Csrf;
use App\Core\View;
use App\Models\AuthenticatedPrincipal;
use App\Services\DriverAccountLinkService;
use App\Services\DriverFeatureFactory;

final class DriverAccountLinkController
{
    public function __construct(private readonly ?DriverAccountLinkService $links = null) {}

    public function index(AuthenticatedPrincipal $actor): void
    {
        try {
            $options = ($this->links ?? DriverFeatureFactory::accountLinks())->options($actor);
            View::renderWithWorkspaceLayout('admin/driver-account-links', ['pageTitle'=>'ผูกบัญชีพนักงานขับรถ','activeNav'=>'driver-links','workspaceAuthenticated'=>true,'workspaceRoles'=>$actor->roles,'drivers'=>$options['drivers'],'accounts'=>$options['accounts'],'notice'=>(($_GET['linked'] ?? null)==='1'),'error'=>null]);
        } catch (\Throwable) { $this->unavailable(); }
    }

    public function link(AuthenticatedPrincipal $actor): void
    {
        if (!Csrf::valid($_POST['_token'] ?? null)) { http_response_code(419); echo 'คำขอหมดอายุ กรุณาโหลดหน้าใหม่'; return; }
        try {
            $driverId = self::id($_POST['driver_id'] ?? null);
            $userId = self::id($_POST['user_id'] ?? null);
            ($this->links ?? DriverFeatureFactory::accountLinks())->link($actor, $driverId, $userId, ($_POST['staff_verified'] ?? null)==='1');
            header('Location: /admin/driver-account-links?linked=1', true, 303);
        } catch (\InvalidArgumentException|\DomainException) {
            http_response_code(422);
            try {
                $options = ($this->links ?? DriverFeatureFactory::accountLinks())->options($actor);
                View::renderWithWorkspaceLayout('admin/driver-account-links', ['pageTitle'=>'ผูกบัญชีพนักงานขับรถ','activeNav'=>'driver-links','workspaceAuthenticated'=>true,'workspaceRoles'=>$actor->roles,'drivers'=>$options['drivers'],'accounts'=>$options['accounts'],'notice'=>false,'error'=>'ข้อมูลเปลี่ยนแปลงหรือไม่ผ่านเงื่อนไข กรุณาตรวจสอบและโหลดหน้าใหม่']);
            } catch (\Throwable) { $this->unavailable(); }
        } catch (\Throwable) { $this->unavailable(); }
    }

    private function unavailable(): void
    {
        http_response_code(503);
        View::render('auth/unavailable', ['pageTitle'=>'ยังไม่สามารถจัดการการผูกบัญชี','message'=>'ข้อมูลบัญชีหรือสิทธิ์ที่จำเป็นยังไม่พร้อม กรุณาติดต่อผู้ดูแล']);
    }

    private static function id(mixed $value): int
    {
        if (!is_string($value) || !ctype_digit($value) || strlen($value)>10 || (int)$value<1) throw new \InvalidArgumentException('DRIVER_LINK_ID_INVALID');
        return (int)$value;
    }
}
