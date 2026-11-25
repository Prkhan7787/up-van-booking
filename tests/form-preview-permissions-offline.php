<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/cli.php';
require dirname(__DIR__).'/app/helpers.php';

function previewCheck(bool $condition,string $message):void {if(!$condition){fwrite(STDERR,"FAIL: $message\n");throw new RuntimeException($message);}echo "PASS: $message\n";}
function previewHtml(array $roles,array $query):string {
    $_GET=$query;http_response_code(200);ob_start();
    try {(new App\Controllers\FormPreviewController())->index(new App\Models\AuthenticatedPrincipal(42,1,$roles));}
    catch(Throwable $e){ob_end_clean();throw $e;}
    return (string)ob_get_clean();
}

$policy=new App\Services\AuthorizationPolicy();
previewCheck($policy->allows(['requester'],'form.preview.view') && $policy->allows(['vehicle_officer'],'form.preview.view') && $policy->allows(['approver'],'form.preview.view') && $policy->allows(['admin'],'form.preview.view'),'all four known workspace roles can open the same authenticated A4 preview');
previewCheck($policy->allows(['requester'],'form.preview.fields.edit') && $policy->allows(['vehicle_officer'],'form.preview.fields.edit') && $policy->allows(['approver'],'form.preview.fields.edit') && $policy->allows(['admin'],'form.preview.fields.edit'),'all authenticated roles can edit preview fields');
previewCheck($policy->allows(['requester','vehicle_officer'],'form.vehicle_fields.edit') && !$policy->allows(['approver','admin'],'form.vehicle_fields.edit'),'existing vehicle workflow permissions remain unchanged outside Preview');
previewCheck(!$policy->allows(['requester','vehicle_officer'],'booking.decision') && $policy->allows(['approver'],'booking.decision'),'Preview editing stays separate from real approval authority');
previewCheck($policy->allows(['admin'],'booking.decision') && $policy->allows(['admin'],'booking.submit_own'),'Admin can submit their own request and has decision permission');

$_GET=[];
$denied=false;try{(new App\Controllers\FormPreviewController())->index();}catch(App\Core\AuthorizationException){$denied=true;}
previewCheck($denied,'backend rejects unauthenticated direct controller access');

$requester=previewHtml(['requester'],['example'=>'rejected','role'=>'admin','approval_outcome'=>'approved']);
previewCheck(str_contains($requester,'<textarea name="destination"') && !str_contains($requester,'id="destination" aria-label="สถานที่เดินทาง" rows="1" autocomplete="off" spellcheck="false" readonly'),'Requester can edit the travel destination without a silent character cap');
previewCheck(preg_match('/<input class="circle" id="approval-outcome-approved"[^>]*disabled/',$requester)===1 && preg_match('/<input class="circle" id="approval-outcome-rejected"[^>]*disabled/',$requester)===1 && preg_match('/<input type="text" name="approver_printed_name"[^>]*readonly/',$requester)===1,'Requester cannot edit any Section 3 decision or signature fields');
previewCheck(preg_match('/id="approval-outcome-rejected"[^>]*checked/',$requester)===0,'Requester does not receive Section 3 fixture decisions');
$approverSample=previewHtml(['approver'],['example'=>'rejected','approval_outcome'=>'approved','rejection_reason'=>'URL tamper']);
previewCheck(preg_match('/id="approval-outcome-rejected"[^>]*checked/',$approverSample)===1 && preg_match('/id="approval-outcome-approved"[^>]*checked/',$approverSample)===0 && !str_contains($approverSample,'URL tamper'),'Director/Delegate sample decision remains fixture-owned and URL decision values are ignored');
previewCheck(preg_match('/<input type="text" name="document_number_prefix"[^>]*readonly/',$requester)===0 && preg_match('/<input type="text" name="vehicle_registration"[^>]*readonly/',$requester)===0 && preg_match('/<input type="text" name="driver_signature"[^>]*readonly/',$requester)===0 && str_contains($requester,'action="/bookings/submit"') && preg_match('/<button[^>]*id="submit-booking-request"[^>]*disabled/',$requester)===1,'requester keeps A4 editing and has a disabled submit action while the write gate is closed');

$officer=previewHtml(['vehicle_officer'],['example'=>'blank']);
previewCheck(preg_match('/<input type="text" name="vehicle_registration"[^>]*readonly/',$officer)===0 && preg_match('/<input type="text" name="requester_name"[^>]*readonly/',$officer)===0,'Vehicle Officer can edit request and Section 2 preview fields');
previewCheck(preg_match('/<input class="circle" id="approval-outcome-approved"[^>]*disabled/',$officer)===1 && preg_match('/<input type="text" name="decision_day"[^>]*readonly/',$officer)===1,'Vehicle Officer cannot edit Section 3');

foreach(['approver','admin'] as $role){
    $html=previewHtml([$role],['example'=>'rejected','approval_outcome'=>'approved','rejection_reason'=>'URL tamper']);
    previewCheck(preg_match('/<input type="text" name="requester_name"[^>]*readonly/',$html)===0 && preg_match('/<input class="square" type="radio" name="travel_type"[^>]*disabled/',$html)===0 && preg_match('/<input type="text" name="vehicle_registration"[^>]*readonly/',$html)===0 && preg_match('/<input type="text" name="driver_signature"[^>]*readonly/',$html)===0,'all A4 inputs are editable for '.$role.' in Preview');
    $canDecide=$role==='approver';
    previewCheck((preg_match('/<input class="circle" id="approval-outcome-approved"[^>]*disabled/',$html)===0)===$canDecide && (preg_match('/<input class="circle" id="approval-outcome-rejected"[^>]*disabled/',$html)===0)===$canDecide,'Section 3 decision controls follow Director/Delegate authority for '.$role);
    previewCheck(str_contains($html,'โหมดตัวอย่าง · การกรอกข้อมูลไม่บันทึกคำขอหรือการอนุมัติ'),'all editors remain explicitly in Preview for '.$role);
    previewCheck(str_contains($html,'action="/bookings/submit"') && preg_match('/<button[^>]*id="submit-booking-request"[^>]*disabled/',$html)===1,'authenticated role sees its own gated submission form');
    previewCheck((preg_match('/<input type="text" name="rejection_reason"[^>]*required aria-required="true"/',$html)===1)===$canDecide,'rejection reason is required only in the authorized Director/Delegate preview');
    if(!$canDecide)previewCheck(preg_match('/<input type="text" name="approver_printed_name"[^>]*readonly/',$html)===1 && preg_match('/<input type="text" name="decision_day"[^>]*readonly/',$html)===1,'Admin cannot edit approver name or date in Section 3');
}

foreach(['requester','vehicle_officer','approver','admin'] as $role){
    $sample=previewHtml([$role],['example'=>'completed']);
    previewCheck(str_contains($sample,'data-form-state="sample-readonly"') && preg_match('/<input type="text" name="requester_name"[^>]*readonly/',$sample)===1 && preg_match('/<textarea name="destination"[^>]*readonly/',$sample)===1,'sample text fields are server-rendered read-only for '.$role);
    previewCheck(preg_match('/<input[^>]*type="radio"[^>]*disabled/',$sample)===1 && !str_contains($sample,'action="/bookings/submit"'),'sample choices are disabled and cannot be submitted for '.$role);
}

$routes=require dirname(__DIR__).'/routes/web.php';
previewCheck(($routes['GET /form-preview'][2]??null)==='form.preview.view' && !isset($routes['POST /form-preview'],$routes['POST /bookings'],$routes['POST /bookings/new']) && ($routes['POST /bookings/submit'][2]??null)==='booking.submit_own','A4 uses the existing fail-closed own-submission route');
$view=file_get_contents(dirname(__DIR__).'/app/Views/form-preview.php');
$controller=file_get_contents(dirname(__DIR__).'/app/Controllers/FormPreviewController.php');
previewCheck(str_contains($controller,"\$_GET['example']") && !str_contains($controller,"\$_GET['role']") && str_contains($controller,'SECTION3_FIELDS'),'URL role or decision parameters cannot grant Section 3 editing or set decisions');
previewCheck(str_contains($view,'data-approval-decision="rejected"') && str_contains($view,'data-approval-reason'),'rejection reason validation hooks are rendered for the authorized preview only');
$script=file_get_contents(dirname(__DIR__).'/public/assets/form-preview.js');
$css=file_get_contents(dirname(__DIR__).'/public/assets/form-preview.css');
previewCheck(str_contains($view,"'data-document-destination', true") && str_contains($script,'measureText(line ||') && str_contains($script,'const maxPt = 15') && str_contains($script,'const minPt = 9.375'),'destination uses real rendered text measurement at the A4 normal size with a readable minimum');
previewCheck(str_contains($css,'overflow-wrap: anywhere') && str_contains($css,'.field textarea {') && str_contains($css,'.field input, .field textarea { display: none; }'),'destination supports wrapping on screen and in print instead of clipping or ellipsis');
previewCheck(str_contains($view,'data-booking-validation-summary') && str_contains($script,"label: 'ชื่อผู้ขอใช้รถ'") && str_contains($script,"label: 'แหล่งค่าใช้จ่าย'") && str_contains($script,'ข้อมูลที่ยังไม่ครบ'),'requester submission renders a dedicated accessible validation summary and required-field guidance');
previewCheck(str_contains($script,'booking-field-invalid') && str_contains($script,"departure_date: 'วันที่ออกเดินทาง'") && str_contains($css,'outline: 2px solid #dc2626') && str_contains($script,"window.location.assign('/my/booking-requests')"),'missing fields are highlighted with explanations and successful submission returns to own requests');

echo "PASS: Role-aware A4 preview offline checks; no database connection.\n";
