<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/cli.php';

use App\Database\BookingResetMaintenanceGrantPlan as Plan;
use App\Database\GrantVerifier;

$checks=0;
$check=static function(bool $ok,string $message)use(&$checks):void{if(!$ok)throw new RuntimeException($message);$checks++;};
$sql=file_get_contents(dirname(__DIR__).'/scripts/booking-reset-maintenance-grants.proposed.sql');
$runner=file_get_contents(dirname(__DIR__).'/scripts/booking-reset-runner.php');
$factory=file_get_contents(dirname(__DIR__).'/app/Database/ConnectionFactory.php');
$check(is_string($sql)&&is_string($runner)&&is_string($factory),'Source is readable');
$statements=array_values(array_filter(array_map('trim',explode(';',preg_replace('/^--.*$/m','',$sql)))));
$check(count($statements)===11,'One CREATE USER and ten table/view grants only');
$check(preg_match("/^CREATE USER 'up_van_reset_maintenance'@'127\.0\.0\.1' IDENTIFIED BY RANDOM PASSWORD$/D",$statements[0])===1,'Host-scoped account uses server-generated password');
$grants=array_slice($statements,1);
foreach($grants as $grant){
    $check(str_contains($grant,"TO 'up_van_reset_maintenance'@'127.0.0.1'"),'Grant targets only the maintenance account');
    $check(!preg_match('/\b(?:DELETE|TRUNCATE|CREATE|ALTER|DROP|GRANT OPTION|ALL PRIVILEGES)\b/i',$grant),'No destructive, schema or delegation privilege');
    $check(!str_contains($grant,'`up_van_auth_test`')&&!str_contains($grant,'up_van_auth_test'),'Runtime account is untouched');
}
$evaluation=GrantVerifier::evaluate($grants,'up_van_booking_test',Plan::rules(),false,true);
$check($evaluation['status']==='PASS' && $evaluation['missing_privileges']===[] && $evaluation['unexpected_privileges']===[],'Exact proposed rights pass the existing grant parser');
$withoutView=array_values(array_filter($grants,static fn($grant)=>!str_contains($grant,'`calendar_events`')));
$check(GrantVerifier::evaluate($withoutView,'up_van_booking_test',Plan::rules(),false,true)['status']==='FAIL','Metadata/view privilege cannot be omitted');
$extra=[...$grants,"GRANT DELETE ON `up_van_booking_test`.`booking_requests` TO ".Plan::ACCOUNT];
$check(GrantVerifier::evaluate($extra,'up_van_booking_test',Plan::rules(),false,true)['status']==='FAIL','Extra DELETE privilege is rejected');
$extra=[...$grants,"GRANT SELECT ON `up_van_booking_test`.`users` TO ".Plan::ACCOUNT];
$check(GrantVerifier::evaluate($extra,'up_van_booking_test',Plan::rules(),false,true)['status']==='FAIL','Table-wide SELECT is rejected');
$check(str_contains($factory,"'auth_test' => ['prefix' => 'AUTH_TEST_DB_'") && str_contains($factory,"'reset_maintenance' => ['prefix' => 'RESET_MAINTENANCE_DB_'"),'Dedicated maintenance configuration leaves runtime purpose intact');
$check(str_contains($runner,"ConnectionFactory::connect('reset_maintenance')") && str_contains($runner,"'up_van_reset_maintenance@127.0.0.1'"),'Runner uses only maintenance connection and identity');
$check(str_contains($runner,'BookingResetMaintenanceGrantPlan::rules()') && !str_contains($runner,"ConnectionFactory::connect('auth_test')"),'Runner checks the dedicated exact grant plan');
$check(str_contains($runner,'FORCE INDEX (PRIMARY) ORDER BY id FOR SHARE') && !str_contains($runner,'FROM vehicle_assignments".($lock?\' ORDER BY id FOR UPDATE'),'Assignment lock needs only SELECT and remains a locking read');
$check(in_array('vehicle_id',Plan::rules()['tables']['vehicle_assignments']['SELECT'],true) && in_array('ends_at',Plan::rules()['tables']['vehicle_assignments']['SELECT'],true),'INVOKER view projection columns are explicitly granted');
echo "PASS: booking reset maintenance grant proposal ($checks checks); SQL not executed.\n";
