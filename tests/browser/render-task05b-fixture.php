<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit(1);}
require dirname(__DIR__,2).'/bootstrap/cli.php';
require dirname(__DIR__,2).'/app/helpers.php';
$_SESSION=[];
$mode=$argv[1] ?? 'logs';
if($mode!=='logs') {
    $isRoleFixture=str_starts_with($mode,'role-');
    $isTransferFixture=$mode==='transfer';
    $isRequesterSample=$mode==='requester-sample';
    $role=$isRoleFixture?substr($mode,5):(in_array($mode,['requester','transfer','requester-sample'],true)?'requester':'approver');
    if(!in_array($role,['requester','vehicle_officer','approver','admin'],true)){http_response_code(404);exit(1);}
    $_GET=$isTransferFixture?[
        'vehicle_id'=>'1','vehicle_label'=>'รถตู้ทดสอบ','vehicle_registration'=>'นข 1234','vehicle_province'=>'พะเยา',
        'start_date'=>'2026-11-18','start_time'=>'08:00','end_date'=>'2026-11-18','end_time'=>'17:00',
        'origin'=>'สถาบันนวัตกรรมการเรียนรู้ มหาวิทยาลัยพะเยา','origin_lat'=>'19.031572','origin_lng'=>'99.893832',
        'destination'=>'','destination_source'=>'map','destination_lat'=>'18.7883','destination_lng'=>'98.9853',
        'route_outbound_m'=>'100000','route_return_m'=>'100000','route_duration_ms'=>'3600000','route_return_duration_ms'=>'3600000','route_source'=>'osrm','fuel_type'=>'',
    ]:['example'=>($isRoleFixture||$mode==='requester')?'blank':($isRequesterSample?'completed':$mode)];
    $roles=[$role];
    $vehiclePreview=$isTransferFixture?require dirname(__DIR__).'/fixtures/vehicle-preview-service.php':null;
    (new App\Controllers\FormPreviewController($vehiclePreview))->index(new App\Models\AuthenticatedPrincipal(1,1,$roles)); return;
}
$_GET=[];
$repo=new class implements App\Contracts\SystemLogRepository {
    public function search(App\Models\SystemLogFilter $filter):array {return array_fill(0,26,['id'=>1,'user_id'=>42,'action'=>'admin.user.roles_changed','entity_type'=>'user','entity_id'=>'200','created_at'=>'2026-11-09 21:05:00','metadata'=>['before'=>['roles'=>['requester'],'active'=>true],'after'=>['roles'=>['requester','vehicle_officer'],'active'=>true],'password'=>'DO_NOT_RENDER']]);}
};
(new App\Controllers\SystemLogController(new App\Services\SystemLogService($repo)))->index(new App\Models\AuthenticatedPrincipal(42,1,['admin']));
