<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\View;
use App\Services\UiFoundationService;
use App\Models\AuthenticatedPrincipal;

final class WorkspaceController
{
    public function __construct(private ?UiFoundationService $ui = null)
    {
        $this->ui ??= new UiFoundationService();
    }

    /** Minimal authenticated landing page; role-specific business modules remain separate work. */
    public function dashboard(AuthenticatedPrincipal $principal): void
    {
        View::renderWithWorkspaceLayout('auth/dashboard', [
            'pageTitle'=>'พื้นที่ผู้ใช้',
            'activeNav'=>'dashboard',
            'roles'=>$principal->roles,
            'workspaceAuthenticated'=>true,
            'workspaceRoles'=>$principal->roles,
        ]);
    }

    public function previewDashboards(): void
    {
        View::renderWithWorkspaceLayout('previews/dashboards', [
            'pageTitle'=>'ตัวอย่าง Dashboard ตามบทบาท',
            'activeNav'=>'dashboards',
            'dashboards'=>$this->ui->dashboardPreviews(),
        ]);
    }
}
