<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap/cli.php';

use App\Database\AuthCredentialReadOnlyCheck;
use App\Database\ConnectionFactory;
use App\Database\SafetyException;

$profiles = [
    'auth_runtime' => ['purpose'=>'auth_test','database'=>'up_van_booking_test','username'=>'up_van_auth_test'],
    'test_provisioner' => ['purpose'=>'test_provisioner','database'=>'up_van_booking_test','username'=>'up_van_test_provisioner'],
];
$report = ['mode'=>'read_only','accounts'=>[]];

foreach ($profiles as $label=>$profile) {
    $stage = 'CONFIG';
    try {
        $passwordKey = $profile['purpose'] === 'auth_test' ? 'AUTH_TEST_DB_PASSWORD' : 'TEST_PROVISIONER_DB_PASSWORD';
        if (getenv($passwordKey) !== false) {
            $report['accounts'][$label] = ['status'=>'FAIL','step'=>'CONFIG','code'=>'PROCESS_PASSWORD_OVERRIDE_PRESENT'];
            continue;
        }
        $config = ConnectionFactory::config($profile['purpose']);
        if ($config['host'] !== '127.0.0.1' || $config['port'] !== '3306'
            || $config['database'] !== $profile['database'] || $config['username'] !== $profile['username']) {
            $report['accounts'][$label] = ['status'=>'FAIL','step'=>'CONFIG','code'=>'TARGET_CONFIGURATION_MISMATCH'];
            continue;
        }
        $stage = 'PDO_CONNECT_OR_VERIFY';
        $result = AuthCredentialReadOnlyCheck::run(
            $profile['database'],
            $profile['username'] . '@127.0.0.1',
            static function () use ($profile): array {
                // ConnectionFactory pins the profile, validates CURRENT_USER()/DATABASE(),
                // and runs the existing GrantVerifier. These extra SELECTs are explicit report checks.
                $pdo = ConnectionFactory::connect($profile['purpose']);
                $identity = $pdo->query('SELECT CURRENT_USER() AS account_identity, DATABASE() AS database_name')->fetch(PDO::FETCH_ASSOC);
                return [
                    'database'=>(string)($identity['database_name'] ?? ''),
                    'identity'=>(string)($identity['account_identity'] ?? ''),
                    'grants_verified'=>true,
                ];
            }
        );
        $report['accounts'][$label] = $result;
    } catch (\App\Database\SafeDatabaseException $error) {
        $report['accounts'][$label] = [
            'status'=>'FAIL','step'=>$stage,'code'=>'PDO_ERROR',
            'sqlstate'=>$error->sqlState,'native_code'=>$error->nativeCode,
        ];
    } catch (SafetyException) {
        $report['accounts'][$label] = ['status'=>'FAIL','step'=>$stage,'code'=>'SAFE_CONNECTION_REJECTED'];
    } catch (Throwable) {
        $report['accounts'][$label] = ['status'=>'FAIL','step'=>$stage,'code'=>'SAFE_CHECK_FAILED'];
    }
}

$statuses = array_column($report['accounts'], 'status');
$report['status'] = count($statuses) === 2 && $statuses === ['PASS','PASS'] ? 'PASS' : 'FAIL';
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
exit($report['status'] === 'PASS' ? 0 : 1);
