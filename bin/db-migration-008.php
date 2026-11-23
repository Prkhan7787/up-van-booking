<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/cli.php';

use App\Core\Env;
use App\Database\{ConnectionFactory,Migration007BackupValidator,Migration007TargetIdentity,MigrationPlan,MigrationRunner,MigrationTarget,SafetyException};

$step='ARGUMENTS';
try {
    $args=array_slice($argv,1);$mode=$args[0]??'';$backup=null;$hash=null;$confirm=false;
    if(!in_array($mode,['--preflight','--postflight','--apply'],true))throw new SafetyException('MIGRATION_008_INVALID_MODE');
    foreach(array_slice($args,1) as $arg) {
        if(str_starts_with($arg,'--backup=') && $backup===null)$backup=substr($arg,9);
        elseif(str_starts_with($arg,'--backup-sha256=') && $hash===null)$hash=strtolower(substr($arg,16));
        elseif($arg==='--confirm=up_van_booking_test' && !$confirm)$confirm=true;
        else throw new SafetyException('MIGRATION_008_INVALID_ARGUMENT');
    }
    if($backup===null || $hash===null)throw new SafetyException('MIGRATION_008_BACKUP_REQUIRED');
    if($mode==='--apply' && !$confirm)throw new SafetyException('MIGRATION_008_CONFIRM_TARGET_REQUIRED');
    if($mode!=='--apply' && $confirm)throw new SafetyException('MIGRATION_008_INVALID_ARGUMENT');
    if($mode!=='--apply' && (Env::get('DB_PHASE_B_APPROVED')==='true' || Env::get('TEST_DB_EXECUTION_APPROVED')==='true'))throw new SafetyException('MIGRATION_008_PREFLIGHT_REQUIRES_CLOSED_GATES');
    $backupInfo=Migration007BackupValidator::inspect($backup,$hash);
    $step='CONFIG';$config=ConnectionFactory::config('migration_test_008');Migration007TargetIdentity::assertConfig($config);
    $step='PDO_CONNECT';$db=ConnectionFactory::connect('migration_test_008');
    $step='TARGET_IDENTITY';$identity=Migration007TargetIdentity::inspect($db);
    $runner=new MigrationRunner($db,MigrationTarget::TEST);$step='MIGRATION_008';
    $result=match($mode) {
        '--preflight'=>$runner->booking008Preflight(),
        '--postflight'=>$runner->booking008Postflight(),
        '--apply'=>$runner->apply(false,$backup,false,$hash,true),
    };
    echo json_encode(['status'=>'PASS','target'=>MigrationTarget::TEST,'mode'=>$mode,'identity'=>$identity,'backup'=>$backupInfo,'checks'=>$result],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE),PHP_EOL;
} catch(App\Database\MigrationExecutionException $error) {
    echo json_encode(['step'=>$step]+$error->diagnostics()),PHP_EOL;exit(1);
} catch(App\Database\GrantGuardException $error) {
    echo json_encode(['status'=>'FAIL','step'=>'GRANTS','code'=>'MIGRATION_008_GRANT_GUARD_FAILED','diagnostics'=>$error->diagnostics]),PHP_EOL;exit(1);
} catch(PDOException $error) {
    echo json_encode(['status'=>'FAIL','step'=>$step,'code'=>'PDO_ERROR','sqlstate'=>$error->errorInfo[0]??null,'native_code'=>$error->errorInfo[1]??null]),PHP_EOL;exit(1);
} catch(Throwable $error) {
    $code=preg_match('/^MIGRATION_008_[A-Z_]+$/D',$error->getMessage())?$error->getMessage():'MIGRATION_008_SAFETY_CHECK_FAILED';
    echo json_encode(['status'=>'FAIL','step'=>$step,'code'=>$code]),PHP_EOL;exit(1);
}
