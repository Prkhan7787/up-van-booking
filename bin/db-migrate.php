<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap/cli.php';
use App\Database\ConnectionFactory;
use App\Database\MigrationPlan;
use App\Database\MigrationRunner;
use App\Database\MigrationTarget;
use App\Database\SafetyException;

$flags = array_slice($argv,1);
if ($flags === [] || in_array($flags[0] ?? '', ['--plan','--plan=up_van_booking','--plan=up_van_booking_test'], true)) {
    if (count($flags) > 1 || ($flags !== [] && !in_array($flags[0], ['--plan','--plan=up_van_booking','--plan=up_van_booking_test'], true))) throw new SafetyException('Usage: --plan[=up_van_booking|up_van_booking_test] OR --apply --confirm=up_van_booking[_test].');
    $target = match ($flags[0] ?? '--plan') {
        '--plan','--plan=up_van_booking' => MigrationTarget::PRODUCTION,
        '--plan=up_van_booking_test' => MigrationTarget::TEST,
    };
    echo json_encode(['mode'=>'offline-plan','database'=>$target,'database_accessed'=>false,'plan'=>MigrationPlan::summary()], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE), PHP_EOL;
    exit;
}
$target = MigrationTarget::fromApplyFlags($flags);
ConnectionFactory::requireMigrationApproval($target);
$runner = new MigrationRunner(
    ConnectionFactory::connect(MigrationTarget::connectionPurpose($target)),
    $target
);
echo json_encode($runner->apply(), JSON_PRETTY_PRINT), PHP_EOL;
