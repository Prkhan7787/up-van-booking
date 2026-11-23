<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/bootstrap/cli.php';
require dirname(__DIR__,2).'/app/helpers.php';

$mode = $argv[1] ?? 'queue';
$row = ['id'=>8,'requester_name'=>'ผู้ขอทดสอบ','requester_username'=>'fixture','destination'=>'เชียงใหม่',
    'workflow_status'=>'pending_approval','submitted_at'=>'2026-11-18 01:00:00'];
if ($mode === 'queue') {
    App\Core\View::renderWithWorkspaceLayout('director/approval-requests',[
        'rows'=>[$row],'page'=>1,'pageTitle'=>'คำร้องรออนุมัติ',
        'workspaceRoles'=>['approver'],'workspaceAuthenticated'=>true,'activeNav'=>'director-approval',
    ]);
    return;
}
if ($mode === 'owner-list') {
    App\Core\View::renderWithWorkspaceLayout('booking/my-requests', [
        'rows'=>[
            array_replace($row,['workflow_status'=>'awaiting_assignment','notified_outcome'=>null]),
            array_replace($row,['id'=>9,'workflow_status'=>'awaiting_rejection_notice','notified_outcome'=>'rejected']),
        ],
        'page'=>1,'workspaceAuthenticated'=>true,'workspaceRoles'=>['requester'],'activeNav'=>'my-requests',
    ]);
    return;
}
if (in_array($mode,['owner-wait','owner-notified','officer-result'],true)) {
    $row['workflow_status']='awaiting_rejection_notice';
    $row['a4_request_data']=App\Modules\Booking\A4RequestSnapshot::withSection3(null,'section3_decision',
        ['approval_outcome'=>'rejected','rejection_reason'=>'เหตุผลทดสอบจากผลพิจารณา']);
    $row['notified_outcome']=$mode==='owner-notified'?'rejected':null;
    App\Core\View::render('form-preview',[
        'formData'=>App\Modules\Booking\RequesterA4Presenter::formData($row, ownerView:$mode!=='officer-result'),
        'documentMode'=>true,'documentBack'=>'/my/booking-requests','ownerAwaitingResult'=>$mode==='owner-wait',
        'showNotify'=>$mode==='officer-result','notifyEnabled'=>true,'bookingId'=>8,'bookingVersion'=>6,'csrfToken'=>'fixture-token',
    ]);
    return;
}
$edit = $mode === 'officer' || $mode === 'admin';
$draft = $mode === 'admin';
$director = in_array($mode,['director','director-gated','director-delegate','director-admin','director-admin-gated'],true);
$directorDraft = in_array($mode,['director-admin','director-delegate','director-admin-gated'],true);
if ($directorDraft) $row += ['approver_printed_name'=>'ร่างเดิม','approval_outcome'=>'rejected'];
App\Core\View::render('form-preview', [
    'formData'=>$row+['vehicle_registration'=>'กท3651 พะเยา','purpose'=>'ประชุม'],
    'documentMode'=>!$edit && !$director && !$directorDraft, 'officerEditMode'=>$edit,'adminDraftMode'=>$draft,
    'directorDecisionMode'=>$director,'decisionEnabled'=>in_array($mode,['director','director-delegate','director-admin'],true),
    'directorDraftMode'=>$directorDraft,'directorDraftEnabled'=>$mode!=='director-admin-gated',
    'showForward'=>$edit,'forwardEnabled'=>$edit,
    'formPermissions'=>['requestFields'=>$edit,'vehicleFields'=>$edit,'section3'=>$draft || $director || $directorDraft],
    'bookingId'=>8,'bookingVersion'=>6,'csrfToken'=>'fixture-token','vehicleId'=>14,
    'officerVehicles'=>[['id'=>14,'label'=>'รถตู้ทดสอบ','registration_number'=>'กท3651','registration_province'=>'พะเยา']],
]);
