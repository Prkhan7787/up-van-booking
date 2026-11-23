<?php
declare(strict_types=1);
// Reuse existing fake-PDO harnesses. Neither connects to MySQL.
require __DIR__.'/admin-users-repository-offline.php';
require __DIR__.'/task05b-system-logs-offline.php';
use App\Database\{AuthRuntimeGrantPlan,AdminUserGrantPlan,SystemLogGrantPlan,BookingCalendarGrantPlan,VehicleCalendarGrantPlan,VehicleAdminGrantPlan,ConnectionFactory,GrantVerifier};
use App\Repositories\{PdoAdminUserRepository,PdoSystemLogRepository};
$moduleChecks=0;
$assert=static function(bool $ok,string $name)use(&$moduleChecks):void {if(!$ok)throw new RuntimeException('Schema 007 module regression: '.$name);$moduleChecks++;};
$schema007=[...AuthRuntimeGrantPlan::grantSql(false),...AdminUserGrantPlan::supplementalSql(),SystemLogGrantPlan::supplementalSql(),...BookingCalendarGrantPlan::supplementalSql(),...VehicleCalendarGrantPlan::supplementalSql(),...VehicleAdminGrantPlan::supplementalSql(),...VehicleAdminGrantPlan::supplementalProvinceSql(),VehicleAdminGrantPlan::supplementalRequestedVehicleSql(),...VehicleAdminGrantPlan::supplementalFuelSql()];
$assert(ConnectionFactory::evaluateAuthRuntimeGrants($schema007,false,true,true)['status']==='PASS','complete existing approved Schema 007 union passes');
$legacyAdmin=GrantVerifier::evaluate($schema007,'up_van_booking_test',AdminUserGrantPlan::rules(),false,true);
$legacyLog=GrantVerifier::evaluate($schema007,'up_van_booking_test',SystemLogGrantPlan::rules(),false,true);
$assert($legacyAdmin['status']==='FAIL' && $legacyAdmin['missing_privileges']===[] && $legacyLog['status']==='FAIL' && $legacyLog['missing_privileges']===[],'reproduce module false failures from approved additional privileges');
new PdoAdminUserRepository(new AdminPdoFake($schema007));
new PdoSystemLogRepository(new LogPdo($schema007,fuelPresent:true));
$assert(true,'both real repository constructors accept the exact approved union');
$withOfficerRead=[...$schema007,"GRANT SELECT (`submitted_at`) ON `up_van_booking_test`.`booking_requests` TO 'up_van_auth_test'@'127.0.0.1'"];
new PdoAdminUserRepository(new AdminPdoFake($withOfficerRead));
$assert(true,'Admin repository accepts existing Schema 007 union plus Officer Read submitted_at');
$withSubmission=[...$withOfficerRead,...App\Database\BookingSubmissionGrantPlan::proposedSql(true)];
$vehicleCapability=static fn(array $grants):array=>VehicleAdminGrantPlan::evaluateCapability($grants,false,true,true,true);
$assert(VehicleAdminGrantPlan::evaluateForSchema($withSubmission,false,true,true,false,true)['unexpected_privileges']!==[],'legacy vehicle profiles misclassify approved submission privileges');
$assert($vehicleCapability($withSubmission)['status']==='PASS','vehicle capability accepts approved submission union');
new PdoSystemLogRepository(new LogPdo($withSubmission,fuelPresent:true));
$assert(true,'System Log constructor accepts approved submission union');
$missingVehicle=$vehicleCapability(array_values(array_diff($withSubmission,VehicleAdminGrantPlan::supplementalFuelSql())));
$assert($missingVehicle['status']==='FAIL' && $missingVehicle['missing_privileges']!==[],'vehicle capability still requires fuel column grants');
$excess=[...$withSubmission,"GRANT ALTER ON `up_van_booking_test`.`vehicles` TO 'up_van_auth_test'@'127.0.0.1'"];
$assert($vehicleCapability($excess)['status']==='FAIL','vehicle capability rejects an unapproved grant');
$preflight=file_get_contents(dirname(__DIR__).'/scripts/task04c-1a-readonly-preflight.php');
$start=strpos($preflight,'            // Known privileges');
$stop=strpos($preflight,'            $provinceMetadataVisible',$start);
$normalization=substr($preflight,$start,$stop-$start);
$assert($start>strpos($preflight,'$fuelFeature=$fuelTypeProbe'),'module normalization runs after schema-aware fuel evaluation');
$normalize=static function(array $grants)use($normalization):array {
    $evaluation=ConnectionFactory::evaluateAuthRuntimeGrants($grants,false,true,true);
    $adminEvaluation=GrantVerifier::evaluate($grants,'up_van_booking_test',AdminUserGrantPlan::rules(),false,true);
    $logEvaluation=GrantVerifier::evaluate($grants,'up_van_booking_test',SystemLogGrantPlan::capabilityRules(),false,true);
    eval($normalization); // Exact existing preflight normalization; no DB statements.
    return [$adminEvaluation,$logEvaluation];
};
foreach($normalize($schema007) as $capability)$assert($capability['status']==='PASS' && $capability['unexpected_privileges']===[],'preflight agrees with repository on approved privileges');
foreach([
    [...$schema007,"GRANT SELECT ON *.* TO 'up_van_auth_test'@'127.0.0.1'"],
    [...$schema007,"GRANT SELECT ON `up_van_booking_test`.`users` TO 'up_van_auth_test'@'127.0.0.1'"],
    [...$schema007,"GRANT ALTER ON `up_van_booking_test`.`vehicles` TO 'up_van_auth_test'@'127.0.0.1'"],
    array_values(array_diff($schema007,[SystemLogGrantPlan::supplementalSql()])),
    array_values(array_diff($schema007,[AdminUserGrantPlan::supplementalSql()[0]])),
] as $unsafe) {
    foreach([fn()=>new PdoAdminUserRepository(new AdminPdoFake($unsafe)),fn()=>new PdoSystemLogRepository(new LogPdo($unsafe,fuelPresent:true))] as $construct) {
        $rejected=false;try{$construct();}catch(Throwable){$rejected=true;}$assert($rejected,'missing module rights or excess grants still rejected');
    }
    foreach($normalize($unsafe) as $capability)$assert($capability['status']!=='PASS','preflight never normalizes an unsafe/incomplete union into PASS');
}
echo "PASS: $moduleChecks Schema 007 Admin/Log capability regressions; fake PDO only.\n";
