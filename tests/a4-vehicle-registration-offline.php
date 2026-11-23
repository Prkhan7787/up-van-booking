<?php
declare(strict_types=1);
ob_start();require __DIR__.'/booking-submission-offline.php';$baselineOutput=ob_get_clean();
set_exception_handler(static function(Throwable $e):void{fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);});
use App\Controllers\FormPreviewController;
use App\Modules\Booking\{A4RequestSnapshot,RequesterA4Presenter,BookingSubmissionInput,BookingSubmissionService,VehicleRegistrationDisplay};
$vehiclePreview=require __DIR__.'/fixtures/vehicle-preview-service.php';
$checksVehicle=0;
$render=static function(array $query)use($vehiclePreview):string{$_GET=$query;http_response_code(200);ob_start();(new FormPreviewController($vehiclePreview))->index(new App\Models\AuthenticatedPrincipal(99,1,['requester']));return ob_get_clean();};
$checkVehicle=static function(bool $ok,string $message)use(&$checksVehicle):void{if(!$ok)throw new RuntimeException($message);$checksVehicle++;};
$one=$render(['vehicle_id'=>'1','vehicle_registration'=>'FORGED','vehicle_label'=>'FORGED']);
$checkVehicle(str_contains($one,'name="vehicle_registration" id="vehicle_registration"')&&preg_match('/id="vehicle_registration"[^>]*value="TEST-001 Test province"[^>]*readonly/',$one)===1&&!str_contains($one,'FORGED'),'preview resolves registration and province from selected server-owned vehicle ID');
$two=$render(['vehicle_id'=>'2','vehicle_registration'=>'TEST-001']);
$checkVehicle(preg_match('/id="vehicle_registration"[^>]*value="TEST-002 Test province"/',$two)===1&&str_contains($two,'name="vehicle_id" value="2"'),'changing selected ID changes rendered registration, province and hidden ID');
$blank=$render([]);
$checkVehicle(preg_match('/id="vehicle_registration"[^>]*value=""/',$blank)===1,'no selected vehicle starts with blank registration');
$invalid=$render(['vehicle_id'=>'999','vehicle_registration'=>'FAKE']);
$checkVehicle(http_response_code()===422&&!str_contains($invalid,'value="FAKE"')&&!str_contains($invalid,'value="999"'),'unknown vehicle fails closed');
$snapshot=A4RequestSnapshot::encode(['vehicle_registration'=>'FORGED','purpose'=>'test']);
$checkVehicle(!array_key_exists('vehicle_registration',A4RequestSnapshot::restore($snapshot)),'browser registration is not persisted in request snapshot');
$row=['requested_vehicle_id'=>2,'requested_vehicle_registration'=>'REAL-DB-PLATE','requested_vehicle_province'=>'พะเยา','a4_request_data'=>'{"version":1,"fields":{"vehicle_registration":"OLD-FORGED"}}'];
$checkVehicle(RequesterA4Presenter::formData($row)['vehicle_registration']==='REAL-DB-PLATE พะเยา','persisted legacy snapshot cannot overwrite joined database registration and province');
$checkVehicle(VehicleRegistrationDisplay::format('ทะเบียน-123','  ')==='ทะเบียน-123','blank province displays registration only');
$checkVehicle(VehicleRegistrationDisplay::format('  ','พะเยา')==='','missing vehicle registration stays blank');
$row['requested_vehicle_id']=null;
$checkVehicle(RequesterA4Presenter::formData($row)['vehicle_registration']==='','no selected ID never displays stale joined or snapshot registration');
$changeRepo=new SubmissionMemory();$changeRepo->vehicleIds=[7,8];$changeService=new BookingSubmissionService($changeRepo);
$owner=new App\Models\AuthenticatedPrincipal(91,1,['requester']);$officer=new App\Models\AuthenticatedPrincipal(92,1,['vehicle_officer']);$administrator=new App\Models\AuthenticatedPrincipal(93,1,['admin']);
$changeService->submit($owner,BookingSubmissionInput::fromA4($base+['vehicle_registration'=>'FORGED'],$now));
$checkVehicle($changeRepo->rows[1]['requested_vehicle_id']===7&&!str_contains($changeRepo->rows[1]['a4_request_data'],'FORGED'),'submission saves checked ID and discards posted plate');
$changeService->updateOfficerRequest($officer,1,2,array_replace($base,['vehicle_id'=>'8','vehicle_registration'=>'FORGED']));
$checkVehicle($changeRepo->rows[1]['requested_vehicle_id']===8&&!str_contains($changeRepo->rows[1]['a4_request_data'],'FORGED'),'officer changes checked ID without storing posted registration');
$changeService->updateOfficerRequest($administrator,1,3,array_replace($base,['vehicle_id'=>'7']));
$checkVehicle($changeRepo->rows[1]['requested_vehicle_id']===7,'admin uses same checked ID update flow');
ob_start();App\Core\View::render('form-preview',['formData'=>['vehicle_registration'=>'TEST-001 Test province'],'example'=>'','officerEditMode'=>true,'formPermissions'=>['requestFields'=>true,'vehicleFields'=>true,'section3'=>false,'canSubmit'=>false],'vehicleId'=>1,'officerVehicles'=>[
    ['id'=>1,'label'=>'Fixture van 1','registration_number'=>'TEST-001','registration_province'=>'Test province'],
    ['id'=>2,'label'=>'Fixture van 2','registration_number'=>'TEST-002','registration_province'=>'จังหวัดใหม่'],
]]);$officerHtml=ob_get_clean();
$checkVehicle(str_contains($officerHtml,'data-vehicle-registration="TEST-002 จังหวัดใหม่"'),'officer vehicle options send the server-rendered registration and province together');
echo $baselineOutput;
echo "PASS: $checksVehicle A4 vehicle registration checks; fixture catalog/memory only, no database writes.\n";
