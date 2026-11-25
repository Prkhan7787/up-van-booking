<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/cli.php';
use App\Database\{BookingCapabilityGrantGuard as Guard,BookingSubmissionGrantPlan as Submit,BookingOfficerReadGrantPlan as Read,BookingOfficerEditGrantPlan as Edit,VehicleAdminGrantPlan,ConnectionFactory};
set_exception_handler(static function(Throwable $e):void{fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);});
$checks=0;
function checkGuard(bool $ok,string $message):void{global $checks;if(!$ok)throw new RuntimeException($message);$checks++;}
function grantsFor(array $plan):array{
    $out=["GRANT USAGE ON *.* TO 'up_van_auth_test'@'127.0.0.1'"];
    foreach($plan['tables'] as $table=>$privileges)foreach($privileges as $privilege=>$columns){if($columns===null){$out[]="GRANT $privilege ON `up_van_booking_test`.`$table` TO 'up_van_auth_test'@'127.0.0.1'";continue;}$names=implode(', ',array_map(static fn($v)=>'`'.$v.'`',$columns));$out[]="GRANT $privilege ($names) ON `up_van_booking_test`.`$table` TO 'up_van_auth_test'@'127.0.0.1'";}
    return $out;
}
$base=VehicleAdminGrantPlan::rules(true,true,true,true,true);
$union=Guard::merge($base,Submit::rules(),Edit::rules());$grants=grantsFor($union);
checkGuard(ConnectionFactory::evaluateAuthRuntimeGrants($grants,false,true,true)['status']==='PASS','reviewed complete account union accepted');
foreach([Read::rules(),Submit::rules(),Edit::rules()] as $plan)checkGuard(Guard::evaluate($grants,$plan,false,true,true)['status']==='PASS','other approved module columns do not reject capability');
$readOnly=grantsFor(Guard::merge($base,Read::rules()));
checkGuard(Guard::evaluate($readOnly,Read::rules(),false,true,true)['status']==='PASS','read works without booking writes');
checkGuard(Guard::evaluate($readOnly,Submit::rules(),false,true,true)['missing_privileges']!==[],'submission missing writes reported separately');
checkGuard(Guard::evaluate($readOnly,Edit::rules(),false,true,true)['status']==='FAIL','edit cannot borrow read capability');
foreach(["GRANT DELETE ON `up_van_booking_test`.`booking_requests` TO 'up_van_auth_test'@'127.0.0.1'","GRANT SELECT ON `up_van_booking_test`.* TO 'up_van_auth_test'@'127.0.0.1'","GRANT SELECT ON *.* TO 'up_van_auth_test'@'127.0.0.1'"] as $extra)checkGuard(Guard::evaluate([...$grants,$extra],Read::rules(),false,true,true)['status']==='FAIL','unreviewed privilege is rejected');
checkGuard(Guard::evaluate($grants,Read::rules(),false,false,true)['status']==='FAIL','unresolved roles fail closed');
checkGuard(in_array('submitted_at',Read::rules()['tables']['booking_requests']['SELECT'],true),'read requirements cover submitted_at');
checkGuard(!isset(Read::rules()['tables']['vehicle_assignments']),'read-only queue does not require conflict table');
checkGuard(in_array('id',Submit::rules()['tables']['vehicle_assignments']['SELECT'],true),'conflict projection covered');
checkGuard(!in_array('submitted_at',Submit::INSERT_COLUMNS,true),'unused insert privilege not requested');
$officerUnion=grantsFor(Guard::merge($base,Read::rules()));
foreach(['admin'=>App\Database\AdminUserGrantPlan::rules(),'calendar'=>App\Database\VehicleCalendarGrantPlan::rules(true,true)] as $module=>$requirements){
    $legacy=App\Database\GrantVerifier::evaluate($officerUnion,'up_van_booking_test',$requirements,false,true);
    checkGuard($legacy['status']==='FAIL' && $legacy['missing_privileges']===[] && in_array('column.booking_requests.submitted_at:SELECT',$legacy['unexpected_privileges'],true),'reproduce submitted_at false rejection for '.$module);
    checkGuard(Guard::evaluate($officerUnion,$requirements,false,true,true)['status']==='PASS','Officer Read addition preserves '.$module.' capability');
    checkGuard(Guard::evaluate([...$officerUnion,"GRANT ALTER ON `up_van_booking_test`.`vehicles` TO 'up_van_auth_test'@'127.0.0.1'"],$requirements,false,true,true)['status']==='FAIL','excess ALTER remains rejected for '.$module);
    $missing=Guard::merge($base,Read::rules());
    if($module==='admin')$missing['tables']['users']['UPDATE']=array_values(array_diff($missing['tables']['users']['UPDATE'],['auth_version']));
    else $missing['tables']['vehicle_assignments']['SELECT']=array_values(array_diff($missing['tables']['vehicle_assignments']['SELECT'],['starts_at']));
    checkGuard(Guard::evaluate(grantsFor($missing),$requirements,false,true,true)['status']==='FAIL','real missing privilege still blocks '.$module);
}
$softRead=App\Database\BookingSoftDelete::READ_GRANTS;
$softWrite=App\Database\BookingSoftDelete::WRITE_GRANTS;
$softUnion=Guard::merge($base,Read::rules(true),Submit::rules(true),$softRead,$softWrite);
$softGrants=grantsFor($softUnion);
checkGuard(Guard::evaluate($softGrants,Guard::merge($softRead,$softWrite),false,true,true)['status']==='PASS','reviewed soft delete scope works alongside submission');
checkGuard(Guard::evaluate(grantsFor(Guard::merge($base,Read::rules(true),Submit::rules(true),$softRead)),Guard::merge($softRead,$softWrite),false,true,true)['status']==='FAIL','read-only migration rollout cannot mark deleted rows');
checkGuard(Guard::evaluate([...$softGrants,"GRANT DELETE ON `up_van_booking_test`.`booking_requests` TO 'up_van_auth_test'@'127.0.0.1'"],$softWrite,false,true,true)['status']==='FAIL','soft delete capability still denies hard DELETE');
$driverAdmin=App\Database\AdminUserGrantPlan::driverCreationRules();
$sitePlan=Guard::merge(VehicleAdminGrantPlan::rules(true,false,true,false,true),Guard::additions()['intake_approval_delivery']);
$driverSite=Guard::merge($sitePlan,App\Database\DriverAuthGrantPlan::additions());
$siteDriverGrants=grantsFor(Guard::merge($driverSite,$driverAdmin));
checkGuard(Guard::evaluate($siteDriverGrants,$driverAdmin,false,true,true)['status']==='PASS','Current test-site booking profile accepts the reviewed Driver Admin additions');
checkGuard(Guard::evaluate(grantsFor($driverSite),$driverAdmin,false,true,true)['missing_privileges']===['column.users.nickname:INSERT'],'Driver profile does not borrow the Admin nickname INSERT grant');
checkGuard(Guard::evaluate($siteDriverGrants,Guard::additions()['intake_approval_delivery'],false,true,true)['status']==='PASS','Driver additions preserve the existing booking approvals on the test-site grant profile');
echo "PASS: $checks account union/capability regression checks; no database.\n";
