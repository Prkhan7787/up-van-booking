<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/bootstrap/cli.php';
require dirname(__DIR__,2).'/app/helpers.php';
if(($argv[1]??'')==='officer'){
    App\Core\View::render('form-preview',['formData'=>['vehicle_registration'=>'TEST-001'],'example'=>'','officerEditMode'=>true,'vehicleId'=>1,
        'officerVehicles'=>[['id'=>1,'label'=>'Offline van 1','registration_number'=>'TEST-001','registration_province'=>'Offline'],['id'=>2,'label'=>'Offline van 2','registration_number'=>'TEST-002','registration_province'=>'Offline']],
        'formPermissions'=>['requestFields'=>true,'vehicleFields'=>true,'section3'=>false,'canSubmit'=>false],'csrfToken'=>'offline-fixture','bookingId'=>1,'bookingVersion'=>2]);
    return;
}
// Render-only approval-state fixture. Never changes an environment gate or connects to MySQL.
App\Core\View::render('form-preview',['formData'=>[],'example'=>'blank','formPermissions'=>['requestFields'=>true,'vehicleFields'=>true,'section3'=>false,'canSubmit'=>true],
    'submissionGateOpen'=>true,'csrfToken'=>'offline-fixture-token','submissionKey'=>str_repeat('e',64),'transferContext'=>null]);
