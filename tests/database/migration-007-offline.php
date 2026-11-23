<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/bootstrap/cli.php';

use App\Database\{ConnectionFactory,GrantVerifier,Migration006GrantPlan,Migration007BackupValidator,Migration007GrantPlan,Migration007TargetIdentity,Migration007VehicleValidator,MigrationPlan,MigrationRunner,MigrationTarget,SafetyException,VehicleAdminGrantPlan};

$n=0;
$check=static function(bool $ok,string $name)use(&$n):void{if(!$ok){fwrite(STDERR,'FAIL Migration 007: '.$name.PHP_EOL);exit(1);}$n++;};
$reject=static function(callable $fn):bool{try{$fn();return false;}catch(Throwable){return true;}};
$plan=MigrationPlan::load(true,true);
$check(count($plan)===7 && $plan[5]['version']===MigrationPlan::VEHICLE006['version'] && $plan[6]['version']===MigrationPlan::VEHICLE007['version'],'controlled sequence includes pinned 006 then 007');
$check(count($plan[6]['statements'])===1 && hash_equals(MigrationPlan::VEHICLE007['sha256'],hash_file('sha256',dirname(__DIR__,2).'/database/drafts/007_vehicle_fuel_details.sql')),'007 byte checksum and one statement are pinned');
$check(MigrationPlan::load()[4]['sha256']===$plan[4]['sha256'],'001–005 default plan remains unchanged');
$check(count(MigrationPlan::load(true)[5]['statements'])===2,'006 statement count and plan remain unchanged');
$check($reject(fn()=>MigrationPlan::assertStatementAllowed(MigrationPlan::VEHICLE007['version'],'forward_test','ALTER TABLE vehicles DROP COLUMN fuel_type')),'unexpected 007 SQL rejected');
$check(Migration007VehicleValidator::assertPreconditions(['id','registration_number','registration_province','label','passenger_capacity','is_active','created_by','updated_by','created_at','updated_at'],[],[])===null,'clean Schema 006 passes 007 preconditions');
$check($reject(fn()=>Migration007VehicleValidator::assertPreconditions(['id','registration_number'],[],[])),'schema drift rejected');
$check($reject(fn()=>Migration007VehicleValidator::assertPreconditions(['id','registration_number','registration_province','label','passenger_capacity','is_active','created_by','updated_by','created_at','updated_at'],[['fuel_type','varchar(100)','YES']],[])),'partial or already-present fuel column rejected');
$good=[['fuel_type','varchar(100)','YES']];
Migration007VehicleValidator::assertPostconditions($good,[]);$check(true,'nullable fuel type only accepted');
$check($reject(fn()=>Migration007VehicleValidator::assertPostconditions([['fuel_type','varchar(100)','NO'],['fuel_efficiency_km_per_l','decimal(8,3)','YES']],[])),'NOT NULL fuel schema rejected');
$check($reject(fn()=>Migration007VehicleValidator::assertPostconditions([...$good,['fuel_efficiency_km_per_l','decimal(8,3)','YES']],[])),'obsolete efficiency column rejected as drift');
$check(!in_array('fuel_efficiency_km_per_l',VehicleAdminGrantPlan::rules(false,false,true,false,true)['tables']['vehicles']['SELECT'],true),'obsolete efficiency runtime grant not allowed');
$input=App\Modules\Booking\VehicleInput::fromArray(['label'=>'test','registration_number'=>'TEST 007','passenger_capacity'=>'12','fuel_type'=>'ไฮดีเซล S','fuel_efficiency_km_per_l'=>'999']);
$check($input->fuelType==='ไฮดีเซล S' && !property_exists($input,'fuelEfficiencyKmPerL'),'fuel type accepted alone; obsolete form efficiency ignored');
$vehicleData=['label'=>'test','registration_number'=>'TEST 007','passenger_capacity'=>'12'];
$check(App\Modules\Booking\VehicleInput::fromArray($vehicleData)->fuelType===null && App\Modules\Booking\VehicleInput::fromArray($vehicleData+['fuel_type'=>''])->fuelType===null,'existing vehicles without fuel type remain valid with NULL');
foreach(App\Modules\Booking\FuelTypeCatalog::TYPES as $type) {
    $check(App\Modules\Booking\VehicleInput::fromArray($vehicleData+['fuel_type'=>$type])->fuelType===$type,'each dropdown catalog fuel type is accepted by backend');
}
$check($reject(fn()=>App\Modules\Booking\VehicleInput::fromArray($vehicleData+['fuel_type'=>'UNRECOGNIZED_FUEL'])),'fuel type outside dropdown catalog rejected');
$check($reject(fn()=>App\Modules\Booking\VehicleInput::fromArray($vehicleData+['fuel_type'=>['invalid']])),'array injection for fuel type rejected');
foreach(['SELECT','INSERT','UPDATE'] as $privilege) {
    $delta=VehicleAdminGrantPlan::deltaSql(['missing_privileges'=>['column.vehicles.fuel_type:'.$privilege],'unexpected_privileges'=>[],'role_privileges_resolved'=>true],true,true,true);
    $check($delta===["GRANT $privilege (`fuel_type`) ON `up_van_booking_test`.`vehicles` TO 'up_van_auth_test'@'127.0.0.1'"],'fuel delta proposes only the individual missing column privilege');
}
$check(VehicleAdminGrantPlan::deltaSql(['missing_privileges'=>[],'unexpected_privileges'=>[],'role_privileges_resolved'=>true],true,true,true)===[],'no grant proposed when nothing is missing');
$check(str_contains(file_get_contents(dirname(__DIR__,2).'/scripts/backup-and-preflight-007.ps1'),MigrationPlan::VEHICLE007['sha256']),'backup script pins current 007 draft');
$preLedger=array_map(static fn(array $m):array=>['version'=>$m['version'],'checksum'=>$m['sha256'],'status'=>'applied','completed_statements'=>count($m['statements']),'total_statements'=>count($m['statements'])],array_slice($plan,0,6));
$postLedger=[...$preLedger,['version'=>$plan[6]['version'],'checksum'=>$plan[6]['sha256'],'status'=>'applied','completed_statements'=>1,'total_statements'=>1]];
$plan008=MigrationPlan::load(true,true,true);
$ledger008=[...$postLedger,['version'=>$plan008[7]['version'],'checksum'=>$plan008[7]['sha256'],'status'=>'applied','completed_statements'=>1,'total_statements'=>1]];
$check(Migration007GrantPlan::phaseFromLedger($preLedger)==='pre' && Migration007GrantPlan::phaseFromLedger($postLedger)==='post','grant phase follows exact applied 001–006/001–007 sequence');
$check(Migration007GrantPlan::phaseFromLedger($ledger008)==='post' && Migration007GrantPlan::profiles(Migration007GrantPlan::phaseFromLedger($ledger008))['schema_007_after_apply']===Migration006GrantPlan::postRules(true),'Ledger 008 applied is accepted while retaining the same least-privilege 007 grant profile');
$check($reject(fn()=>Migration007GrantPlan::phaseFromLedger([...$postLedger,['version'=>$plan008[7]['version'],'checksum'=>$plan008[7]['sha256'],'status'=>'started','completed_statements'=>0,'total_statements'=>1]])),'started 008 ledger rejected');
$check($reject(fn()=>Migration007GrantPlan::phaseFromLedger([...$postLedger,['version'=>$plan008[7]['version'],'checksum'=>str_repeat('0',64),'status'=>'applied','completed_statements'=>1,'total_statements'=>1]])),'incorrect 008 checksum rejected');
$check(Migration007GrantPlan::phaseFromLedger(array_slice($ledger008,0,7))==='post','exact 001–007 Ledger remains a valid 007 post-phase');
$check($reject(fn()=>Migration007GrantPlan::phaseFromLedger([...$preLedger,$ledger008[7]])),'Ledger missing Migration 007 but containing 008 is rejected');
$dirty=$ledger008;$dirty[6]=$dirty[7];$check($reject(fn()=>Migration007GrantPlan::phaseFromLedger($dirty)),'out-of-order Migration 007/008 rows rejected');
$check($reject(fn()=>Migration007GrantPlan::phaseFromLedger([...$ledger008,['version'=>'009_unpinned','checksum'=>str_repeat('0',64),'status'=>'applied','completed_statements'=>1,'total_statements'=>1]])),'extra unpinned ledger row rejected');
$grantAccount="'up_van_test_migrator'@'127.0.0.1'";
$exactGrants=["GRANT USAGE ON *.* TO $grantAccount",'GRANT CREATE ON `up\\_van\\_booking\\_test`.* TO '.$grantAccount,"GRANT SELECT ON `up_van_booking_test`.`auth_login_buckets` TO $grantAccount","GRANT SELECT (`id`) ON `up_van_booking_test`.`booking_requests` TO $grantAccount","GRANT SELECT, INSERT, UPDATE ON `up_van_booking_test`.`schema_migrations` TO $grantAccount","GRANT INSERT, ALTER ON `up_van_booking_test`.`users` TO $grantAccount","GRANT SELECT (`id`) ON `up_van_booking_test`.`vehicle_assignments` TO $grantAccount","GRANT SELECT (`id`, `registration_number`, `registration_province`, `label`, `passenger_capacity`, `is_active`, `created_by`, `updated_by`, `created_at`, `updated_at`), INSERT, ALTER ON `up_van_booking_test`.`vehicles` TO $grantAccount","GRANT ALTER, INSERT ON `up_van_booking_test`.`booking_requests` TO $grantAccount","GRANT REFERENCES ON `up_van_booking_test`.`vehicles` TO $grantAccount"];
$accepted008=GrantVerifier::evaluateProfiles($exactGrants,MigrationTarget::TEST,Migration007GrantPlan::profiles(Migration007GrantPlan::phaseFromLedger($ledger008)),false,true);
$check($accepted008['status']==='PASS' && $accepted008['unexpected_privileges']===[],'exact reviewed grants pass with Ledger 008 without expanding privileges');
$excess008=GrantVerifier::evaluateProfiles([...$exactGrants,"GRANT DROP ON `up_van_booking_test`.`vehicles` TO $grantAccount"],MigrationTarget::TEST,Migration007GrantPlan::profiles(Migration007GrantPlan::phaseFromLedger($ledger008)),false,true);
$check($excess008['status']==='FAIL' && $excess008['unexpected_privileges']!==[],'excess privilege still fails Grant Guard with Ledger 008');
$dirty=$preLedger;$dirty[5]['status']='failed';$check($reject(fn()=>Migration007GrantPlan::phaseFromLedger($dirty)),'failed 006 ledger cannot pass 007 grant guard');
$dirty=$postLedger;$dirty[6]['checksum']=str_repeat('0',64);$check($reject(fn()=>Migration007GrantPlan::phaseFromLedger($dirty)),'altered 007 checksum cannot pass post grant guard');
$check(Migration007GrantPlan::profiles('pre')['schema_006_before_007']===Migration006GrantPlan::postRules(true) && Migration007GrantPlan::profiles('post')['schema_007_after_apply']===Migration006GrantPlan::postRules(true),'007 uses same least-privilege migrator profile before and after');
$check($reject(fn()=>Migration007GrantPlan::profiles('unknown')),'unknown grant phase rejected');
$check(in_array('fuel_type',VehicleAdminGrantPlan::rules(false,false,true,false,true)['tables']['vehicles']['SELECT'],true)
    && in_array('fuel_type',VehicleAdminGrantPlan::rules(false,false,true,false,true)['tables']['vehicles']['UPDATE'],true)
    && !in_array('fuel_type',VehicleAdminGrantPlan::rules(false,false,true)['tables']['vehicles']['SELECT'],true),'runtime grant profile requires fuel columns only in Schema 007');
$check(str_contains(implode('',VehicleAdminGrantPlan::supplementalFuelSql()),'`fuel_type`') && !str_contains(implode('',VehicleAdminGrantPlan::supplementalFuelSql()),'GRANT ALTER'),'runtime delta is limited to the fuel type column');
$check($reject(fn()=>Migration007TargetIdentity::assertConfig(['host'=>'127.0.0.1','port'=>'3307','database'=>MigrationTarget::TEST,'username'=>'up_van_test_migrator'])),'restore instance 3307 rejected');
$temp=tempnam(sys_get_temp_dir(),'upvan007-');file_put_contents($temp,'offline-backup-fixture');$hash=hash_file('sha256',$temp);
$check(Migration007BackupValidator::inspect($temp,$hash)['sha256']===$hash,'backup hash guard accepts exact file');
$check($reject(fn()=>Migration007BackupValidator::inspect($temp,str_repeat('0',64))),'backup hash mismatch rejected');unlink($temp);
$savedTest=getenv('TEST_DB_EXECUTION_APPROVED');$savedProd=getenv('DB_PHASE_B_APPROVED');putenv('TEST_DB_EXECUTION_APPROVED=false');putenv('DB_PHASE_B_APPROVED=false');
$fake=new class extends PDO { public function __construct(){} };$temp=tempnam(sys_get_temp_dir(),'upvan007-gate-');file_put_contents($temp,'offline-backup-fixture');$gateHash=hash_file('sha256',$temp);
$check($reject(fn()=>(new MigrationRunner($fake,MigrationTarget::TEST))->apply(true,$temp,true,$gateHash)),'closed gate blocks direct 007 execution before connection writes');unlink($temp);
if($savedTest===false)putenv('TEST_DB_EXECUTION_APPROVED');else putenv('TEST_DB_EXECUTION_APPROVED='.$savedTest);
if($savedProd===false)putenv('DB_PHASE_B_APPROVED');else putenv('DB_PHASE_B_APPROVED='.$savedProd);
$check(str_contains(file_get_contents(dirname(__DIR__,2).'/bin/db-migration-007.php'),'--preflight') && !str_contains(file_get_contents(dirname(__DIR__,2).'/bin/db-migration-007.php'),'--resume'),'007 CLI exposes only preflight/apply/postflight; no resume path');
echo 'PASS: '.$n.' Migration 007 offline checks; no database connection or DDL.'.PHP_EOL;
