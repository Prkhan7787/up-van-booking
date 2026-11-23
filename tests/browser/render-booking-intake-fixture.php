<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/bootstrap/cli.php';
require dirname(__DIR__,2).'/app/helpers.php';
$mode=$argv[1]??'officer';
if($mode==='requester'||$mode==='admin'){
    $role=$mode==='requester'?'requester':'admin';
    (new App\Controllers\FormPreviewController())->index(new App\Models\AuthenticatedPrincipal($mode==='requester'?101:102,1,[$role]));
    return;
}
if ($mode==='document') {
    $row=['request_number'=>'UPV-SYSTEM-FIXTURE','a4_request_data'=>App\Modules\Booking\A4RequestSnapshot::encode([
        'document_number_prefix'=>'กจ 007','request_number_suffix'=>'2569',
    ])];
    App\Core\View::render('form-preview',[
        'formData'=>App\Modules\Booking\RequesterA4Presenter::formData($row),'documentMode'=>true,
        'officerRequestMeta'=>['request_number'=>$row['request_number'],'status'=>'submitted'],
    ]);
    return;
}
$rows=[];
if (in_array($mode,['officer-filled','officer-delete-enabled','officer-after-delete','officer-recall-enabled','officer-after-recall'],true)) {
    foreach (['submitted','under_review','pending_approval','awaiting_assignment','closed_rejected'] as $index=>$status) {
        $rows[]=['id'=>$index+1,'version'=>2,'workflow_status'=>$status,'information_incomplete'=>$index===0,
            'request_number'=>'UPV-SYSTEM-FIXTURE','requester_name'=>'ผู้ขอทดสอบ',
            'submitted_at'=>'2026-11-18 03:45:00','destination'=>$index===0?'':'ปลายทางทดสอบ'];
    }
    $rows[4]['notified_outcome'] = 'rejected';
    if ($mode==='officer-after-delete') array_shift($rows);
    if ($mode==='officer-after-recall') $rows[2]['workflow_status']='under_review';
}
App\Core\View::renderWithWorkspaceLayout('officer/booking-requests',[
    'pageTitle'=>'คำร้องงานยานพาหนะ','rows'=>$rows,'page'=>1,
    'workspaceAuthenticated'=>false,'workspaceRoles'=>[],'activeNav'=>'booking-officer',
    'canRecall'=>true,'recallEnabled'=>$mode==='officer-recall-enabled','recallCsrf'=>'fixture-csrf',
    'canDelete'=>true,'deleteEnabled'=>$mode==='officer-delete-enabled','deleteCsrf'=>'fixture-csrf',
]);
