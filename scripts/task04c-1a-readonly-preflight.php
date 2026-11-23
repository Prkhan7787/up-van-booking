<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap/cli.php';

use App\Core\Env;
use App\Database\AuthRuntimeGrantPlan;
use App\Database\ConnectionFactory;
use App\Database\GrantVerifier;
use App\Database\TestProvisionerGrantPlan;
use App\Database\VehicleAdminGrantPlan;
use App\Database\RuntimeColumnProbe;
$report = [
    'mode'=>'read_only', 'target'=>'up_van_booking_test', 'database_accessed'=>false,
    'hmac_key'=>Env::get('AUTH_RATE_LIMIT_HMAC_KEY') !== '' ? 'SET' : 'NOT SET',
    'test_auth_gate'=>Env::get('TEST_AUTH_EXECUTION_APPROVED') === 'true' ? 'OPEN' : 'CLOSED',
    'test_admin_gate'=>Env::get('TEST_ADMIN_PROVISION_APPROVED') === 'true' ? 'OPEN' : 'CLOSED',
    'test_vehicle_gate'=>Env::get('TEST_VEHICLE_MANAGEMENT_APPROVED') === 'true' ? 'OPEN' : 'CLOSED',
    'production_migration_gate'=>Env::get('DB_PHASE_B_APPROVED') === 'true' ? 'OPEN' : 'CLOSED',
    'test_migration_gate'=>Env::get('TEST_DB_EXECUTION_APPROVED') === 'true' ? 'OPEN' : 'CLOSED',
    'accounts'=>[],
];
$accountPlan = [
    'auth_test'=>['database'=>'up_van_booking_test','username'=>'up_van_auth_test','rules'=>AuthRuntimeGrantPlan::rules()],
    'test_provisioner'=>['database'=>'up_van_booking_test','username'=>'up_van_test_provisioner','rules'=>TestProvisionerGrantPlan::rules()],
];
if (in_array('OPEN', [$report['test_auth_gate'],$report['test_admin_gate'],$report['test_vehicle_gate'],$report['production_migration_gate'],$report['test_migration_gate']], true)) {
    $report['status']='BLOCKED';
    $report['error']=['type'=>'gate_open'];
} else {
    foreach ($accountPlan as $purpose=>$expected) {
        $phase = 'configuration';
        try {
            $config = ConnectionFactory::config($purpose);
            if ($config['database'] !== $expected['database'] || $config['username'] !== $expected['username'] || $config['host'] !== '127.0.0.1') {
                throw new RuntimeException('Account target mismatch.');
            }
            $dsn = 'mysql:host='.$config['host'].';port='.$config['port'].';dbname='.$config['database'].';charset=utf8mb4';
            $phase = 'pdo_connect';
            $pdo = new PDO($dsn, $config['username'], $config['password'], [
                PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES=>false,
                PDO::ATTR_TIMEOUT=>5,
                PDO::MYSQL_ATTR_MULTI_STATEMENTS=>false,
            ]);
            $report['database_accessed'] = true;
            $phase = 'identity';
            $identity = $pdo->query('SELECT DATABASE() AS database_name, CURRENT_USER() AS account_name, VERSION() AS server_version, @@GLOBAL.partial_revokes AS partial_revokes')->fetch();
            if (!is_array($identity) || $identity['database_name'] !== $expected['database'] || $identity['account_name'] !== $expected['username'].'@127.0.0.1') {
                throw new RuntimeException('Connected identity mismatch.');
            }
            $phase = 'grants';
            $direct = $pdo->query('SHOW GRANTS FOR CURRENT_USER')->fetchAll(PDO::FETCH_COLUMN);
            $resolved = GrantVerifier::currentUserGrants($pdo);
            $evaluation = GrantVerifier::evaluate($resolved['grants'], $expected['database'], $expected['rules'], (bool)$identity['partial_revokes'], $resolved['roles_resolved']);
            if ($purpose==='auth_test') $evaluation=ConnectionFactory::evaluateAuthRuntimeGrants($resolved['grants'],(bool)$identity['partial_revokes'],$resolved['roles_resolved']);
            $adminEvaluation = $purpose==='auth_test'
                ? GrantVerifier::evaluate($resolved['grants'], $expected['database'], App\Database\AdminUserGrantPlan::rules(), (bool)$identity['partial_revokes'], $resolved['roles_resolved'])
                : null;
            $logEvaluation=$purpose==='auth_test'
                ? GrantVerifier::evaluate($resolved['grants'], $expected['database'], App\Database\SystemLogGrantPlan::capabilityRules(), (bool)$identity['partial_revokes'], $resolved['roles_resolved'])
                : null;
            $vehicleEvaluation=null;
            $vehicleCalendarEvaluation=$purpose==='auth_test'
                ? App\Database\VehicleCalendarGrantPlan::evaluate($resolved['grants'],(bool)$identity['partial_revokes'],$resolved['roles_resolved']) : null;
            if ($vehicleCalendarEvaluation!==null && $evaluation['status']==='PASS' && $vehicleCalendarEvaluation['missing_privileges']===[]) $vehicleCalendarEvaluation['status']='PASS';
            $phase = 'schema_inventory';
            $q = $pdo->prepare('SELECT TABLE_NAME, TABLE_TYPE, ENGINE FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=:schema AND TABLE_NAME IN (\'users\',\'roles\',\'user_roles\',\'auth_login_buckets\',\'audit_logs\') ORDER BY TABLE_NAME');
            $q->execute(['schema'=>$expected['database']]);
            $tables = $q->fetchAll();
            $q = $pdo->prepare('SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=:schema AND TABLE_NAME IN (\'users\',\'roles\',\'user_roles\',\'auth_login_buckets\',\'audit_logs\',\'vehicles\',\'booking_requests\') ORDER BY TABLE_NAME, ORDINAL_POSITION');
            $q->execute(['schema'=>$expected['database']]);
            $columns = $q->fetchAll();
            $provinceProbe=['status'=>'NOT_APPLICABLE','readable'=>null,'reason_code'=>'NOT_APPLICABLE','sqlstate'=>null,'native_code'=>null];
            $requestedVehicleProbe=['status'=>'NOT_APPLICABLE','readable'=>null,'reason_code'=>'NOT_APPLICABLE','sqlstate'=>null,'native_code'=>null];
            if($purpose==='auth_test') {
                $provinceProbe=RuntimeColumnProbe::inspect($pdo,'vehicles','registration_province');
                $requestedVehicleProbe=RuntimeColumnProbe::inspect($pdo,'booking_requests','requested_vehicle_id');
                $fuelTypeProbe=RuntimeColumnProbe::inspect($pdo,'vehicles','fuel_type');
                if($fuelTypeProbe['status']==='ERROR')throw new RuntimeException('Fuel schema is inconsistent or not visible.');
                // Province grants are required only if the optional column is
                // confirmed present. NOT_VISIBLE uses the known M005 baseline;
                // Calendar's optional column is evaluated only in its own plan.
                $provinceFeature=$provinceProbe['status']==='PRESENT';
                $requestedVehicleFeature=$requestedVehicleProbe['status']==='PRESENT';
                $fuelFeature=$fuelTypeProbe['status']==='PRESENT';
                $vehicleEvaluation=VehicleAdminGrantPlan::evaluateCapability($resolved['grants'],(bool)$identity['partial_revokes'],$resolved['roles_resolved'],$provinceFeature,$fuelFeature);
                $vehicleCalendarEvaluation=App\Database\VehicleCalendarGrantPlan::evaluateForSchema($resolved['grants'],(bool)$identity['partial_revokes'],$resolved['roles_resolved'],$provinceFeature,$requestedVehicleFeature);
                $evaluation=ConnectionFactory::evaluateAuthRuntimeGrants($resolved['grants'],(bool)$identity['partial_revokes'],$resolved['roles_resolved'],$fuelFeature);
            }
            // Known privileges from other approved modules are not excess module privileges.
            foreach ([&$adminEvaluation,&$logEvaluation] as &$moduleResult) {
                if ($moduleResult!==null && $evaluation['status']==='PASS' && $moduleResult['missing_privileges']===[]) {
                    $moduleResult['status']='PASS';
                    $moduleResult['unexpected_privileges']=[]; // Entire union was verified against reviewed profiles.
                }
            }
            unset($moduleResult);

            $provinceMetadataVisible=count(array_filter($columns,static fn(array $column):bool=>$column['TABLE_NAME']==='vehicles' && $column['COLUMN_NAME']==='registration_province'))>0;
            $requestedVehicleMetadataVisible=count(array_filter($columns,static fn(array $column):bool=>$column['TABLE_NAME']==='booking_requests' && $column['COLUMN_NAME']==='requested_vehicle_id'))>0;
            $report['accounts'][$purpose] = [
                'status'=>$evaluation['status'],
                'identity'=>['database'=>$identity['database_name'],'account'=>$identity['account_name'],'mysql'=>$identity['server_version'],'partial_revokes'=>(bool)$identity['partial_revokes']],
                'active_roles'=>$resolved['active_roles'],
                'role_privileges_resolved'=>$resolved['roles_resolved'],
                'direct_grants'=>$direct,
                'missing_privileges'=>$evaluation['missing_privileges'],
                'unexpected_privileges'=>$evaluation['unexpected_privileges'],
                'grant_reason_codes'=>$evaluation['reason_codes'],
                'runtime_grant_profile'=>$evaluation['profile'] ?? null,
                'requested_vehicle_id_metadata'=>$requestedVehicleMetadataVisible ? 'PRESENT' : ($requestedVehicleProbe['status']==='ABSENT'?'ABSENT':'NOT_VISIBLE'),
                'registration_province_metadata'=>$provinceMetadataVisible ? 'PRESENT' : ($provinceProbe['status']==='ABSENT'?'ABSENT':'NOT_VISIBLE'),
                'registration_province_runtime_probe'=>$provinceProbe['status'],
                'registration_province_probe_readable'=>$provinceProbe['readable'],
                'registration_province_probe_reason'=>$provinceProbe['reason_code'],
                'registration_province_probe_error'=>['sqlstate'=>$provinceProbe['sqlstate'],'native_code'=>$provinceProbe['native_code']],
                'requested_vehicle_runtime_probe'=>$requestedVehicleProbe['status'],
                'requested_vehicle_probe_readable'=>$requestedVehicleProbe['readable'],
                'requested_vehicle_probe_reason'=>$requestedVehicleProbe['reason_code'],
                'requested_vehicle_probe_error'=>['sqlstate'=>$requestedVehicleProbe['sqlstate'],'native_code'=>$requestedVehicleProbe['native_code']],
                'fuel_type_probe_status'=>$fuelTypeProbe['status']??'NOT_APPLICABLE',
                'fuel_type_probe_reason'=>$fuelTypeProbe['reason_code']??'NOT_APPLICABLE',
                'vehicle_calendar_capability'=>$vehicleCalendarEvaluation['status'] ?? 'NOT_APPLICABLE',
                'admin_users_capability'=>$adminEvaluation===null ? 'NOT_APPLICABLE' : $adminEvaluation['status'],
                'system_logs_capability'=>$logEvaluation===null ? 'NOT_APPLICABLE' : $logEvaluation['status'],
                'vehicle_management_capability'=>$vehicleEvaluation===null ? 'NOT_APPLICABLE' : $vehicleEvaluation['status'],
                'vehicle_management_grant_profile'=>$vehicleEvaluation['profile'] ?? null,
                'vehicle_management_missing_privileges'=>$vehicleEvaluation['missing_privileges'] ?? [],
                'vehicle_management_unexpected_privileges'=>$vehicleEvaluation['unexpected_privileges'] ?? [],
                'vehicle_management_proposed_grant_delta'=>$vehicleEvaluation===null || ($vehicleEvaluation['status']??'')==='UNVERIFIED' ? [] : VehicleAdminGrantPlan::deltaSql($vehicleEvaluation,(bool)($provinceFeature??false),false,(bool)($fuelFeature??false)),
                'vehicle_province_proposed_grant_sql'=>VehicleAdminGrantPlan::supplementalProvinceSql(),
                'requested_vehicle_proposed_grant_sql'=>VehicleAdminGrantPlan::supplementalRequestedVehicleSql(),
                'admin_users_missing_privileges'=>$adminEvaluation['missing_privileges'] ?? [],
                'admin_users_unexpected_privileges'=>$adminEvaluation['unexpected_privileges'] ?? [],
                'system_logs_missing_privileges'=>$logEvaluation['missing_privileges'] ?? [],
                'system_logs_unexpected_privileges'=>$logEvaluation['unexpected_privileges'] ?? [],
                'tables'=>$tables,
                'columns'=>$columns,
            ];
        } catch (PDOException $error) {
            $report['accounts'][$purpose] = ['status'=>'BLOCKED','failed_phase'=>$phase,'sqlstate'=>(string)$error->getCode(),'native_code'=>isset($error->errorInfo[1])?(int)$error->errorInfo[1]:null];
        } catch (Throwable $error) {
            $report['accounts'][$purpose] = ['status'=>'BLOCKED','failed_phase'=>$phase,'error_type'=>$error instanceof RuntimeException?'precondition':'verification'];
        }
    }
    $statuses = array_column($report['accounts'], 'status');
    $vehicleCapabilityStatus=$report['accounts']['auth_test']['vehicle_management_capability'] ?? 'NOT_VERIFIED';
    $report['status'] = in_array('FAIL',$statuses,true) || $vehicleCapabilityStatus==='FAIL'
        ? 'FAIL'
        : (count($statuses)===2 && $statuses===['PASS','PASS'] && $vehicleCapabilityStatus==='PASS' ? 'PASS' : 'BLOCKED');
}

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), PHP_EOL;
exit($report['status']==='PASS'?0:1);
