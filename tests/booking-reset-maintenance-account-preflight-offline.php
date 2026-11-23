<?php
declare(strict_types=1);
define('BOOKING_MAINTENANCE_PREFLIGHT_TEST',true);
require dirname(__DIR__).'/scripts/booking-reset-maintenance-account-preflight.php';

use App\Database\ConnectionFactory;

$checks=0;
$check=static function(bool $pass,string $message)use(&$checks):void{if(!$pass)throw new RuntimeException($message);$checks++;};
$identity=['port'=>'3306','uuid'=>MAINTENANCE_UUID,'db'=>MAINTENANCE_DB,'account'=>MAINTENANCE_ACCOUNT,'version'=>'8.4.9'];
$configVars=['HOST'=>'127.0.0.1','PORT'=>'3306','DATABASE'=>MAINTENANCE_DB,'USERNAME'=>'up_van_reset_maintenance','PASSWORD'=>'offline-synthetic-only'];
foreach($configVars as $key=>$value)putenv('RESET_MAINTENANCE_DB_'.$key.'='.$value);
try {
    $config=ConnectionFactory::config('reset_maintenance');
    $check($config['password']==='offline-synthetic-only' && $config['host']==='127.0.0.1' && $config['username']==='up_van_reset_maintenance','ConnectionFactory accepts wrapper process environment without exposing credential');
} finally {foreach(array_keys($configVars) as $key)putenv('RESET_MAINTENANCE_DB_'.$key);}
$check(maintenanceIdentityValid($identity),'Expected identity accepted');
foreach(['port'=>'3307','uuid'=>'wrong','db'=>'wrong','account'=>'up_van_auth_test@127.0.0.1','version'=>'8.0.42'] as $field=>$wrong){$bad=$identity;$bad[$field]=$wrong;$check(!maintenanceIdentityValid($bad),'Wrong identity rejected: '.$field);}
$deps=[[MAINTENANCE_DB,'vehicle_assignments'],[MAINTENANCE_DB,'booking_requests']];
$check(maintenanceDependenciesValid($deps),'Dependencies accepted in either order');
foreach([[],[[MAINTENANCE_DB,'booking_requests']],[[MAINTENANCE_DB,'booking_requests'],['other_db','vehicle_assignments']],[[MAINTENANCE_DB,'booking_requests'],[MAINTENANCE_DB,'other_table']]] as $bad){$check(!maintenanceDependenciesValid($bad),'Incomplete or foreign dependency rejected');}
$source=file_get_contents(dirname(__DIR__).'/scripts/booking-reset-maintenance-account-preflight.php');
$check(is_string($source) && str_contains($source,"ConnectionFactory::connect('reset_maintenance')") && str_contains($source,'BookingResetMaintenanceGrantPlan::rules()'),'Maintenance connection and exact grants used');
foreach(['VIEW_TABLE_USAGE','SHOW CREATE VIEW calendar_events','SELECT COUNT(*) FROM calendar_events','SELECT deleted_by,deletion_reason,updated_by FROM booking_requests LIMIT 0'] as $marker){$check(str_contains($source,$marker),'Required read-only check present: '.$marker);}
$check(!preg_match('/\b(?:INSERT|UPDATE|DELETE|TRUNCATE|CREATE USER|GRANT)\s+(?:INTO|FROM|TABLE|USER|ON|`booking_requests`)/i',$source),'No data or grant mutation SQL in preflight');
echo "PASS: maintenance account preflight offline ($checks checks); no MySQL connection made.\n";
