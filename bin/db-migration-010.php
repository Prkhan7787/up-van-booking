<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/cli.php';

use App\Database\ConnectionFactory;
use App\Database\Migration010ApplyGuard;
use App\Database\MigrationExecutionException;
use App\Database\MigrationRunner;
use App\Database\MigrationTarget;
use App\Database\SafetyException;

$runner = null;
try {
    $args = array_slice($argv, 1);
    if (count($args) !== 4 || $args[0] !== '--apply' || $args[1] !== '--confirm=up_van_booking_test'
        || !str_starts_with($args[2], '--backup-dir=')
        || !preg_match('/\A--backup-manifest-sha256=([0-9a-f]{64})\z/D', $args[3], $pin)) {
        throw new SafetyException('MIGRATION_010_APPLY_ARGUMENTS_REJECTED');
    }
    Migration010ApplyGuard::requireApproval();
    $runner = new MigrationRunner(ConnectionFactory::connect(Migration010ApplyGuard::PURPOSE), MigrationTarget::TEST);
    echo json_encode($runner->driver010Apply(substr($args[2], strlen('--backup-dir=')), $pin[1]), JSON_THROW_ON_ERROR), PHP_EOL;
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
