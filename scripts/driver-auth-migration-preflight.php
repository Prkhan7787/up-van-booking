<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/cli.php';

use App\Database\ConnectionFactory;
use App\Database\DriverDatabaseIdentity;
use App\Database\MigrationRunner;
use App\Database\Migration010ReadOnlyGrantPlan;
use App\Database\MigrationTarget;
use App\Database\SafetyException;
use App\Database\Migration010SchemaSnapshot;

// Read-only entry point. Apply is deliberately not an accepted argument.
$runner = null;
try {
    $args = array_slice($argv, 1);
    if (!in_array($args, [['--preflight'], ['--postflight']], true)) throw new SafetyException('USAGE_PREFLIGHT_OR_POSTFLIGHT_ONLY');
    DriverDatabaseIdentity::assertConfig(ConnectionFactory::config('migration_preflight_test'), Migration010ReadOnlyGrantPlan::ACCOUNT);
    $db=ConnectionFactory::connect('migration_preflight_test');
    $snapshotDirectory=getenv('MIGRATION_PREFLIGHT_SCHEMA_SNAPSHOT');
    $snapshot=null;
    if($snapshotDirectory!==false && $snapshotDirectory!==''){
        $snapshot=Migration010SchemaSnapshot::load($snapshotDirectory);
        $db=$snapshot->connection($db);
    }
    $runner = new MigrationRunner($db, MigrationTarget::TEST);
    $result=$runner->driver010Inspect($args === ['--postflight']);
    if($snapshot!==null)$result=array_merge($result,$snapshot->evidence());
    echo json_encode($result, JSON_THROW_ON_ERROR), PHP_EOL;
} catch (Throwable $error) {
    // Never echo PDO messages/configuration or credentials.
    echo json_encode(['status'=>'FAIL','step'=>$runner?->lastSafetyCheck() ?? 'IDENTITY_OR_CONFIG',
        'reason'=>$error instanceof SafetyException && preg_match('/\A[A-Z0-9_]+\z/D', $error->getMessage()) ? $error->getMessage() : 'READ_ONLY_PREFLIGHT_FAILED',
        'partial_state'=>$runner?->lastSafetyDetail() ?? []], JSON_THROW_ON_ERROR), PHP_EOL;
    exit(1);
}
