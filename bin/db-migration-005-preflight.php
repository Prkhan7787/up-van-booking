<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/cli.php';

use App\Database\ConnectionFactory;
use App\Database\Migration005VehicleValidator;
use App\Database\MigrationPlan;
use App\Database\MigrationRunner;
use App\Database\MigrationTarget;
use App\Database\SafetyException;

$report=['mode'=>'read_only','target'=>'up_van_booking_test','status'=>'FAIL','checks'=>[]];
$runner=null;
$backup='C:\\Users\\User\\up_van_booking_test_20261111_085849.sql';
try {
    Migration005VehicleValidator::assertTarget(MigrationTarget::TEST);
    $report['checks']['target']='PASS';
    $plan=MigrationPlan::load();$entry=$plan[4]??null;
    if (!is_array($entry) || $entry['version']!==Migration005VehicleValidator::VERSION || !hash_equals(Migration005VehicleValidator::SHA256,$entry['sha256'])) throw new SafetyException('Migration 005 manifest mismatch.');
    $report['checks']['checksum']='PASS';$report['migration_005_sha256']=$entry['sha256'];
    $report['checks']['backup']=is_file($backup) && filesize($backup)>0 ? 'PASS' : 'FAIL';
    if ($report['checks']['backup']!=='PASS') throw new SafetyException('Backup missing or empty.');
    $report['step']='PDO_CONNECT';
    // Reuse the pinned test migration profile so this read-only CLI also exercises
    // the same scoped privilege validation as the apply command before inspecting schema.
    $pdo=ConnectionFactory::connect('migration_test');
    $identity=$pdo->query('SELECT DATABASE() AS db_name, CURRENT_USER() AS account_name')->fetch(\PDO::FETCH_ASSOC);
    if (($identity['db_name']??null)!==MigrationTarget::TEST || ($identity['account_name']??null)!=='up_van_test_migrator@127.0.0.1') throw new SafetyException('Connected identity rejected.');
    $report['checks']['connection_identity']='PASS';$report['step']='RUNNER_STATUS';
    $runner=new MigrationRunner($pdo,MigrationTarget::TEST);
    $report['migration_account']=$identity['account_name'];
    $report['step']='RUNNER_STATUS';
    $preflight=$runner->vehicle005Preflight();
    $report['checks']=array_merge($report['checks'],$preflight['checks']);$report['counts']=$preflight['counts'];
    $report['status']='PASS';$report['step']='COMPLETE';
} catch (\PDOException $e) {
    $report['status']='FAIL';$report['step']=$runner instanceof MigrationRunner?$runner->lastReadCheck():($report['step']??'PDO_CONNECT');$report['code']='PDO_ERROR';
    $report['sqlstate']=(string)$e->getCode();$report['native_code']=isset($e->errorInfo[1])?(int)$e->errorInfo[1]:null;
} catch (Throwable $e) {
    $report['status']='FAIL';
    if ($runner instanceof MigrationRunner) {
        $report['step']=$runner->lastSafetyCheck();
        $details=$runner->lastSafetyDetail();
        if ($details!==[]) $report['details']=$details;
    }
    $report['code']=$e instanceof SafetyException?Migration005VehicleValidator::safetyCode($e->getMessage()):'PREFLIGHT_FAILED';
    if (!isset($report['step'])) $report['step']='CONFIG_BACKUP_OR_MANIFEST';
}
echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR),PHP_EOL;
exit($report['status']==='PASS'?0:1);
