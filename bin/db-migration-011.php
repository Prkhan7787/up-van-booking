<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/cli.php';
use App\Database\{ConnectionFactory,Migration011ApplyGuard,MigrationRunner,MigrationTarget,MigrationExecutionException,SafetyException};
$runner=null;
try {
    $args=array_slice($argv,1);
    if($args===['--runtime-check']){
        if(PHP_MAJOR_VERSION!==8||PHP_MINOR_VERSION!==4)throw new SafetyException('MIGRATION_011_PHP_VERSION_REJECTED');
        foreach(['pdo_mysql','mbstring','openssl','curl'] as $extension)if(!extension_loaded($extension))throw new SafetyException('MIGRATION_011_PHP_EXTENSION_MISSING');
        App\Database\MigrationPlan::microsoft011();
        echo "MIGRATION_011_APPLY_RUNTIME=8.4\n";exit(0);
    }
    if(count($args)!==4||$args[0]!=='--apply'||$args[1]!=='--confirm=up_van_booking_test'
        ||!str_starts_with($args[2],'--backup-dir=')||!preg_match('/\A--backup-manifest-sha256=([a-f0-9]{64})\z/D',$args[3],$pin))throw new SafetyException('MIGRATION_011_ARGUMENTS_REJECTED');
    Migration011ApplyGuard::requireApproval();
    $runner=new MigrationRunner(ConnectionFactory::connect(Migration011ApplyGuard::PURPOSE),MigrationTarget::TEST);
    echo json_encode($runner->microsoft011Apply(substr($args[2],13),$pin[1]),JSON_THROW_ON_ERROR),PHP_EOL;
} catch(MigrationExecutionException $e){echo json_encode($e->diagnostics(),JSON_THROW_ON_ERROR),PHP_EOL;exit(1);}
catch(Throwable $e){
    $code=$e instanceof SafetyException&&preg_match('/\A(?:MIGRATION_011|MIGRATION_010_BACKUP|DRIVER_DATABASE)_[A-Z0-9_]+\z/D',$e->getMessage())?$e->getMessage():'MIGRATION_011_REJECTED';
    echo json_encode(['status'=>'FAIL','code'=>$code,'stage'=>$runner?->lastSafetyCheck(),'automatic_retry'=>false],JSON_THROW_ON_ERROR),PHP_EOL;exit(1);
}
