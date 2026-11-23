<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/cli.php';

use App\Database\ConnectionFactory;
use App\Database\BookingResetMaintenanceGrantPlan;
use App\Database\GrantVerifier;

const MAINTENANCE_UUID='77218fbd-d66d-11f1-9a0a-5c2886f057d5';
const MAINTENANCE_DB='up_van_booking_test';
const MAINTENANCE_ACCOUNT='up_van_reset_maintenance@127.0.0.1';

function maintenanceIdentityValid(array $row): bool {
    return (string)($row['port']??'')==='3306'
        && ($row['uuid']??null)===MAINTENANCE_UUID
        && ($row['db']??null)===MAINTENANCE_DB
        && ($row['account']??null)===MAINTENANCE_ACCOUNT
        && preg_match('/^8\.4\.\d+(?:[-.]|$)/D',(string)($row['version']??''))===1;
}
function maintenanceDependenciesValid(array $rows): bool {
    if(count($rows)!==2)return false;
    $tables=[];
    foreach($rows as $row){
        if(!is_array($row) || count($row)!==2 || ($row[0]??null)!==MAINTENANCE_DB || !is_string($row[1]??null))return false;
        $tables[]=$row[1];
    }
    sort($tables,SORT_STRING);
    return $tables===['booking_requests','vehicle_assignments'];
}
function maintenancePreflight(): void {
    global $maintenanceStep;
    if(PHP_SAPI!=='cli' || getenv('UPVAN_MAINTENANCE_PREFLIGHT_WRAPPER')!=='1' || getenv('RESET_MAINTENANCE_DB_PASSWORD')===false)throw new RuntimeException('WRAPPER_REQUIRED');
    $maintenanceStep='connection';
    $db=ConnectionFactory::connect('reset_maintenance');
    $maintenanceStep='identity';
    $row=$db->query('SELECT @@port port,@@server_uuid uuid,DATABASE() db,CURRENT_USER() account,VERSION() version')->fetch(PDO::FETCH_ASSOC);
    if(!is_array($row) || !maintenanceIdentityValid($row))throw new RuntimeException('IDENTITY_MISMATCH');
    $maintenanceStep='grants';
    $resolved=GrantVerifier::currentUserGrants($db);
    $partial=(bool)$db->query('SELECT @@GLOBAL.partial_revokes')->fetchColumn();
    $evaluation=GrantVerifier::evaluate($resolved['grants'],MAINTENANCE_DB,BookingResetMaintenanceGrantPlan::rules(),$partial,$resolved['roles_resolved']);
    if($evaluation['status']!=='PASS' || $evaluation['missing_privileges']!==[] || $evaluation['unexpected_privileges']!==[])throw new RuntimeException('GRANTS_MISMATCH');
    $maintenanceStep='view_dependencies';
    $rows=$db->query("SELECT TABLE_SCHEMA,TABLE_NAME FROM information_schema.VIEW_TABLE_USAGE WHERE VIEW_SCHEMA='up_van_booking_test' AND VIEW_NAME='calendar_events' ORDER BY TABLE_SCHEMA,TABLE_NAME")->fetchAll(PDO::FETCH_NUM);
    if(!maintenanceDependenciesValid($rows))throw new RuntimeException('VIEW_DEPENDENCIES_MISMATCH');
    $maintenanceStep='view_definition';
    $view=$db->query('SHOW CREATE VIEW calendar_events')->fetch(PDO::FETCH_NUM);
    if(!is_array($view) || ($view[0]??null)!=='calendar_events' || !is_string($view[1]??null) || !str_contains($view[1],'VIEW `calendar_events`'))throw new RuntimeException('VIEW_DEFINITION_UNVERIFIED');
    $maintenanceStep='calendar_view_read';
    $db->query('SELECT COUNT(*) FROM calendar_events')->fetchColumn();
    $maintenanceStep='rollback_columns_read';
    $db->query('SELECT deleted_by,deletion_reason,updated_by FROM booking_requests LIMIT 0')->fetchAll(PDO::FETCH_NUM);
    echo "MAINTENANCE_ACCOUNT_PREFLIGHT=PASS account=".MAINTENANCE_ACCOUNT." database=".MAINTENANCE_DB." uuid=".MAINTENANCE_UUID." grants=PASS dependencies=PASS calendar=PASS rollback_columns=PASS\n";
}

if(!defined('BOOKING_MAINTENANCE_PREFLIGHT_TEST')){
    $maintenanceStep='startup';
    try {maintenancePreflight();}
    catch(Throwable $error){
        $state='UNKNOWN';$native='UNKNOWN';
        if($error instanceof PDOException){$state=(string)($error->errorInfo[0]??$error->getCode()?:'UNKNOWN');$native=(string)($error->errorInfo[1]??'UNKNOWN');}
        fwrite(STDERR,"MAINTENANCE_ACCOUNT_PREFLIGHT=FAIL step=$maintenanceStep sqlstate=$state mysql_code=$native\n");
        exit(1);
    }
}
