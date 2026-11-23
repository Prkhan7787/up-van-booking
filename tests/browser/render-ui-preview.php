<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/bootstrap/app.php';

$page = $argv[1] ?? '';
switch ($page) {
    case 'home':
        (new App\Controllers\HomeController())->index();
        break;
    case 'home-admin':
        (new App\Controllers\HomeController())->index(new App\Models\AuthenticatedPrincipal(101, 1, ['requester', 'admin']));
        break;
    case 'dashboard-auth':
        (new App\Controllers\WorkspaceController())->dashboard(new App\Models\AuthenticatedPrincipal(102, 1, ['approver']));
        break;
    case 'login':
        (new App\Controllers\LoginController())->show();
        break;
    case 'dashboards':
        (new App\Controllers\WorkspaceController())->previewDashboards();
        break;
    case 'admin-users':
        $model = (new App\Services\UiFoundationService())->adminUserManagementOptions();
        App\Core\View::renderWithWorkspaceLayout('admin/users', [
            'pageTitle'=>'จัดการบัญชีผู้ใช้',
            'activeNav'=>'admin-users',
            'workspaceAuthenticated'=>true,
            'workspaceRoles'=>['admin'],
            'actionsEnabled'=>true,
            'roles'=>$model['roles'],
            'actorId'=>101,
            'search'=>'',
            'selectedRole'=>'',
            'page'=>1,
            'hasNext'=>false,
            'accountRows'=>[
                ['id'=>101,'username'=>'admin1','email'=>'admin@example.test','display_name'=>'ผู้ดูแลจำลอง','roles'=>['admin'],'is_active'=>1,'auth_version'=>1,'created_at'=>'2026-11-10 10:00:00'],
                ['id'=>200,'username'=>'fixture.requester','email'=>'fixture@example.test','display_name'=>'ผู้ใช้จำลอง','roles'=>['requester','vehicle_officer'],'is_active'=>1,'auth_version'=>1,'created_at'=>'2026-11-10 10:00:00'],
            ],
        ]);
        break;
    default:
        http_response_code(404);
        exit(1);
}
