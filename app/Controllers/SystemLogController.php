<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Core\AuthorizationException;
use App\Core\SystemLogUnavailableException;
use App\Core\View;
use App\Models\AuthenticatedPrincipal;
use App\Models\SystemLogFilter;
use App\Services\AuthorizationPolicy;
use App\Services\SystemLogFactory;
use App\Services\SystemLogService;
final class SystemLogController
{
    public function __construct(private ?SystemLogService $service=null) {}
    public function index(?AuthenticatedPrincipal $actor): void
    {
        if ($actor===null || !(new AuthorizationPolicy())->allows($actor->roles,'logs.view')) throw new AuthorizationException();
        $error=null; $listing=['rows'=>[],'has_next'=>false];
        try {
            $filter=new SystemLogFilter($_GET);
            $this->service ??= SystemLogFactory::create();
            $listing=$this->service->listing($actor,$filter);
        } catch (\InvalidArgumentException) {
            http_response_code(422); $error='ตัวกรองไม่ถูกต้อง กรุณาตรวจประเภทเหตุการณ์ รหัสผู้ดำเนินการ วันที่ และเลขหน้า'; $filter=new SystemLogFilter([]);
        } catch (SystemLogUnavailableException) {
            http_response_code(503); $error='System Log ยังไม่พร้อม ต้องตรวจสิทธิ์อ่าน audit_logs ของบัญชีทดสอบก่อน';
        }
        View::renderWithWorkspaceLayout('admin/system-logs',['pageTitle'=>'System Log','activeNav'=>'system-logs','workspaceAuthenticated'=>true,'workspaceRoles'=>$actor->roles,'filter'=>$filter,'rows'=>$listing['rows'],'hasNext'=>$listing['has_next'],'error'=>$error]);
    }
}
