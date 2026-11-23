<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') {http_response_code(404);exit;}
require dirname(__DIR__,2).'/bootstrap/cli.php';
require dirname(__DIR__,2).'/app/helpers.php';
$_SESSION=[];
$showHistory=($argv[1] ?? '')==='history';
$showMultipleRoles=($argv[1] ?? '')==='multiple-roles';
$model=(new App\Services\UiFoundationService())->adminUserManagementOptions();
App\Core\View::renderWithWorkspaceLayout('admin/users',[
    'pageTitle'=>'จัดการบัญชีผู้ใช้','activeNav'=>'admin-users','workspaceAuthenticated'=>true,'workspaceRoles'=>['admin'],
    'actionsEnabled'=>true,'roles'=>$model['roles'],'actorId'=>101,'search'=>'','selectedRole'=>'','page'=>1,'hasNext'=>false,
    'historyUser'=>$showHistory ? ['id'=>200,'username'=>'fixture.requester','display_name'=>'ผู้ใช้จำลอง','created_at'=>'2026-11-09 21:05:00'] : null,
    'historyRows'=>$showHistory ? [['user_id'=>101,'action'=>'admin.user.roles_changed','created_at'=>'2026-11-10 00:00:00','details'=>['before'=>['roles'=>['requester'],'active'=>true],'after'=>['roles'=>['requester','vehicle_officer'],'active'=>false]]]] : [],
    'historyPage'=>1,'historyHasNext'=>false,'historyError'=>null,
    'accountRows'=>[
        ['id'=>101,'username'=>'admin1','email'=>'admin@example.test','display_name'=>'ผู้ดูแลจำลอง','roles'=>['admin'],'is_active'=>1,'auth_version'=>1,'created_at'=>'2026-11-09 21:05:00'],
        ['id'=>200,'username'=>'fixture.requester','email'=>'fixture@example.test','display_name'=>'ผู้ใช้จำลอง','roles'=>$showMultipleRoles ? ['requester','vehicle_officer'] : ['requester'],'is_active'=>1,'auth_version'=>1,'created_at'=>'2026-11-09 21:05:00'],
    ],
]);
