<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/cli.php';

use App\Database\ConnectionFactory;
use App\Database\Migration010ApplyBackup;
use App\Database\Migration010ApplyGuard;
use App\Database\MigrationExecutionException;
use App\Database\MigrationRunner;
use App\Database\MigrationTarget;
use App\Database\SafetyException;

$runner = null;
$password = null;
try {
    $args = array_slice($argv, 1);
    if ($args === ['--runtime-check']) {
        if (PHP_MAJOR_VERSION !== 8 || PHP_MINOR_VERSION !== 4 || !extension_loaded('pdo_mysql') || !extension_loaded('mbstring')) {
            throw new SafetyException('MIGRATION_010_RUNTIME_REJECTED');
        }
        App\Database\MigrationPlan::load(include010: true);
        echo "MIGRATION_010_APPLY_RUNTIME=8.4\n";
        exit(0);
    }
    if (count($args) !== 4 || $args[0] !== '--apply' || $args[1] !== '--confirm=up_van_booking_test'
        || !str_starts_with($args[2], '--backup-dir=')
        || !preg_match('/\A--backup-manifest-sha256=([0-9a-f]{64})\z/D', $args[3], $pin)) {
        throw new SafetyException('MIGRATION_010_APPLY_ARGUMENTS_REJECTED');
    }
    Migration010ApplyGuard::requireApproval();
    $backupDirectory = substr($args[2], strlen('--backup-dir='));
    Migration010ApplyBackup::verify($backupDirectory, $pin[1], requireFresh: true);
    $password = Migration010ApplyGuard::readPassword(STDIN);
    try {
        $connection = ConnectionFactory::connectDriver010($password);
    } finally {
        $password = null;
    }
    $runner = new MigrationRunner($connection, MigrationTarget::TEST);
    echo json_encode($runner->driver010Apply($backupDirectory, $pin[1]), JSON_THROW_ON_ERROR), PHP_EOL;
} catch (MigrationExecutionException $error) {
    echo json_encode($error->diagnostics(), JSON_THROW_ON_ERROR), PHP_EOL;
    exit(1);
} catch (Throwable $error) {
    $code = $error instanceof SafetyException && preg_match('/\A(?:MIGRATION_010|DRIVER_DATABASE)_[A-Z0-9_]+\z/D', $error->getMessage())
        ? $error->getMessage() : 'MIGRATION_010_APPLY_REJECTED';
    echo json_encode(['status'=>'FAIL','code'=>$code,'stage'=>$runner?->lastSafetyCheck(),
        'partial_state'=>$runner?->lastSafetyDetail() ?? [],'automatic_retry'=>false], JSON_THROW_ON_ERROR), PHP_EOL;
    exit(1);
}
