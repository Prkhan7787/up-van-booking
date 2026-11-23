<?php
declare(strict_types=1);
require __DIR__.'/booking-submission-offline.php';
use App\Modules\Booking\{BookingSubmissionInput,BookingSubmissionService,RequesterA4Presenter,BookingRequestCompleteness,A4RequestSnapshot};
use App\Models\AuthenticatedPrincipal;
$partialChecks=0;
function partialCheck(bool $ok,string $message):void{global $partialChecks;if(!$ok)throw new RuntimeException('PARTIAL: '.$message);$partialChecks++;}
$empty=BookingSubmissionInput::fromA4([],$now);
partialCheck($empty->vehicleId===null&&$empty->fields['requested_start_at']===null&&$empty->fields['passenger_count']===null,'blank input has no fabricated vehicle, passenger count or window');
$partial=BookingSubmissionInput::fromA4(['purpose'=>'meeting','departure_date'=>'2026-11-20','pickup_day'=>'20','approval_outcome'=>'approved'],$now);
partialCheck($partial->fields['requested_start_at']===null&&RequesterA4Presenter::formData($partial->fields)['departure_date']==='2026-11-20','partial date survives exactly without inventing a time');
partialCheck(RequesterA4Presenter::formData($partial->fields)['pickup_day']==='20','partial pickup date survives');
partialCheck(!array_key_exists('approval_outcome',A4RequestSnapshot::restore($partial->fields['a4_request_data'])),'Section 3 is excluded from snapshot as well as normalized fields');
foreach([['vehicle_id'=>'-1'],['passenger_count'=>'x'],['departure_time'=>'28:00'],['departure_date'=>'2026-02-31'],['pickup_day'=>'32'],['purpose'=>['tampered']]] as $invalid)partialCheck(rejected(fn()=>BookingSubmissionInput::fromA4($invalid,$now),InvalidArgumentException::class),'nonblank malformed value rejected');
$partialRepo=new SubmissionMemory();$partialService=new BookingSubmissionService($partialRepo);$owner=new AuthenticatedPrincipal(61,1,['requester']);$officer=new AuthenticatedPrincipal(62,1,['vehicle_officer']);
$blankReceipt=$partialService->submit($owner,$empty);
partialCheck($blankReceipt['status']==='submitted'&&!$blankReceipt['information_complete']&&!$blankReceipt['schedule_reserved'],'blank request reaches Submitted without reserving vehicle');
partialCheck($partialRepo->rows[1]['requester_id']===61&&count($partialRepo->history)===2&&count($partialRepo->audits)===2,'blank request still has authenticated owner/history/audit');
$datesWithoutVehicle=$base;unset($datesWithoutVehicle['vehicle_id']);$datesOnly=BookingSubmissionInput::fromA4($datesWithoutVehicle,$now);
partialCheck($datesOnly->fields['requested_start_at']===null&&$datesOnly->fields['requested_end_at']===null&&RequesterA4Presenter::formData($datesOnly->fields)['departure_date']===$base['departure_date'],'missing vehicle cannot reserve time even through aggregate calendar, while original dates survive');
$partialReceipt=$partialService->submit($owner,$partial);
partialCheck(!$partialReceipt['schedule_reserved']&&$partialRepo->rows[2]['purpose']==='meeting','partial data is retained in submitted request');
$withVehicle=BookingSubmissionInput::fromA4(['vehicle_id'=>'7','departure_date'=>'2026-11-20'],$now);
$partialRepo->requestConflict=true;$noWindow=$partialService->submit($owner,$withVehicle);
partialCheck(!$noWindow['schedule_reserved'],'no complete window means no time reservation');$partialRepo->requestConflict=false;
$full=BookingSubmissionInput::fromA4($base,$now);$fullReceipt=$partialService->submit($owner,$full);
partialCheck($fullReceipt['information_complete']&&$fullReceipt['schedule_reserved'],'complete request reserves checked interval');
$rows=$partialService->officerList($officer);
partialCheck($rows[0]['information_incomplete']&&!$rows[0]['schedule_reserved'],'queue derives incomplete/unconfirmed warnings from persisted data');
partialCheck(rejected(fn()=>BookingRequestCompleteness::assertReadyForProposal($partialRepo->rows[1]),DomainException::class),'blank request cannot be proposed to director');
$partialService->updateOfficerRequest($officer,1,2,['purpose'=>'first officer edit']);
partialCheck($partialRepo->rows[1]['purpose']==='first officer edit'&&$partialRepo->rows[1]['version']===3,'officer can fill some missing fields and retain remaining blanks');
$partialRepo->requestConflict=true;
partialCheck(rejected(fn()=>$partialService->updateOfficerRequest($officer,1,3,$base),DomainException::class)&&$partialRepo->rows[1]['version']===3,'adding vehicle/window is rechecked and overlap rolls back');$partialRepo->requestConflict=false;
$partialService->updateOfficerRequest(new AuthenticatedPrincipal(63,1,['admin']),1,3,$base+['approval_outcome'=>'approved']);
BookingRequestCompleteness::assertReadyForProposal($partialRepo->rows[1]);
partialCheck($partialRepo->rows[1]['version']===4&&!isset($partialRepo->rows[1]['approval_outcome']),'admin can complete request but cannot populate decision');
$partialService->updateOfficerRequest($officer,1,4,[]);
partialCheck($partialRepo->rows[1]['requested_vehicle_id']===null&&$partialRepo->rows[1]['requested_start_at']===null,'clearing schedule removes request reservation instead of retaining stale values');
// Exercise the existing HTTP controller in-process using memory only, no network or gates.
$_SESSION=['csrf_token'=>'partial-csrf','booking_submission_keys'=>[str_repeat('b',64)=>time()]];
$_POST=['_token'=>'partial-csrf','submission_key'=>str_repeat('b',64)];ob_start();(new App\Controllers\BookingSubmissionController($partialService))->submit($owner);$response=json_decode(ob_get_clean(),true);
partialCheck(($response['status']??null)==='submitted'&&($response['information_complete']??true)===false,'blank controller payload can submit after CSRF/RBAC checks in memory');
$restored=RequesterA4Presenter::formData(['a4_request_data'=>A4RequestSnapshot::encode(['purpose'=>'<script>alert(1)</script>','officer_printed_name'=>'advisory'])]);
partialCheck($restored['officer_printed_name']==='advisory'&&str_contains(e($restored['purpose']),'&lt;script&gt;'),'restored advisory fields remain strings escaped by the existing A4 renderer');
partialCheck(BookingRequestCompleteness::hasSchedule($partialRepo->rows[2])===false,'missing schedule cannot be interpreted as reserved');
$legacy=A4RequestSnapshot::restore('{"version":1,"fields":{"request_number_suffix":"2569"}}');
partialCheck($legacy['document_number_prefix']==='' && $legacy['request_number_suffix']==='2569','legacy snapshot keeps its suffix and has no automatic prefix');
$singleNumber=A4RequestSnapshot::restore(A4RequestSnapshot::encode(['document_number_prefix'=>'0042']));
partialCheck($singleNumber['document_number_prefix']==='0042' && $singleNumber['request_number_suffix']==='','one entered number keeps leading zeros and leaves the other field blank');
$receipt=$partialService->submit($owner,BookingSubmissionInput::fromA4([
    'request_number'=>'UPV-FORGED', 'document_number_prefix'=>'กจ 007', 'request_number_suffix'=>'2569',
],$now));
$row=$partialRepo->rows[$receipt['id']];
$numberFields=RequesterA4Presenter::formData($row);
partialCheck($row['request_number']===$receipt['request_number'] && $row['request_number']!=='UPV-FORGED'
    && $numberFields['document_number_prefix']==='กจ 007' && $numberFields['request_number_suffix']==='2569'
    && !array_key_exists('request_number',$numberFields),'submitted document numbers round-trip separately from server identity');
foreach ([
    ['submitted', 'รอตรวจสอบ', 'bg-blue-600'],
    ['under_review', 'รอตรวจสอบ', 'bg-blue-600'],
    ['pending_approval', 'รออนุมัติ', 'bg-yellow-500'],
    ['awaiting_assignment', 'อนุมัติแล้ว', 'bg-green-600'],
    ['closed_rejected', 'ไม่อนุมัติ', 'bg-red-600'],
] as [$workflow, $label, $dot]) {
    partialCheck(App\Modules\Booking\OfficerBookingDisplay::status($workflow)===[$label,$dot],'queue maps workflow to one status label and color');
}
echo "PASS: $partialChecks blank/partial/full intake and officer edit checks; memory only, no database writes.\n";
