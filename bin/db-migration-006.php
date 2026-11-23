<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/cli.php';

use App\Database\{ConnectionFactory,MigrationRunner,MigrationTarget,SafetyException,Migration006RestoreIdentity};

$step='ARGUMENTS';
try {
    $args=array_slice($argv,1);$mode=$args[0]??'';$backup=null;$confirm=false;$restoreTest=false;$partialBackup=null;$partialHash=null;
    if(!in_array($mode,['--preflight','--postflight','--apply','--resume-preflight','--resume'],true))throw new SafetyException('INVALID_MODE');
    foreach(array_slice($args,1) as $arg) {
        if(str_starts_with($arg,'--backup=') && $backup===null)$backup=substr($arg,9);
        elseif($arg==='--confirm=up_van_booking_test' && !$confirm)$confirm=true;
        elseif($arg==='--restore-test-3307' && !$restoreTest)$restoreTest=true;
        elseif(str_starts_with($arg,'--partial-backup=') && $partialBackup===null)$partialBackup=substr($arg,strlen('--partial-backup='));
        elseif(str_starts_with($arg,'--partial-backup-sha256=') && $partialHash===null)$partialHash=strtolower(substr($arg,strlen('--partial-backup-sha256=')));
        else throw new SafetyException('INVALID_ARGUMENT');
    }
    $resumeMode=in_array($mode,['--resume-preflight','--resume'],true);
    if(in_array($mode,['--apply','--resume'],true) && !$confirm)throw new SafetyException('CONFIRM_TARGET_REQUIRED');
    if($resumeMode && !$restoreTest)throw new SafetyException('MIGRATION_006_RESUME_RESTORE_FLAG_REQUIRED');
    if(!$resumeMode && ($partialBackup!==null || $partialHash!==null))throw new SafetyException('INVALID_ARGUMENT');
    if($mode==='--resume' && ($partialBackup===null || $partialHash===null))throw new SafetyException('MIGRATION_006_RESUME_BACKUP_REQUIRED');
    $step='BACKUP';
    if($backup===null || !is_file($backup) || filesize($backup)===false || filesize($backup)<1)throw new SafetyException('BACKUP_REQUIRED');
    if($resumeMode && (filesize($backup)!==44529 || !hash_equals(App\Database\Migration006ResumeValidator::ORIGINAL_BACKUP_HASH,hash_file('sha256',$backup))))throw new SafetyException('MIGRATION_006_RESUME_BACKUP_REJECTED');
    if($resumeMode && ($partialBackup!==null || $partialHash!==null)) {
        if($partialBackup===null || $partialHash===null)throw new SafetyException('MIGRATION_006_RESUME_BACKUP_REQUIRED');
        App\Database\Migration006ResumeValidator::assertBackups($backup,$partialBackup,$partialHash);
    }
    $step='CONNECTION';
    if($restoreTest) Migration006RestoreIdentity::assertConfig(ConnectionFactory::config('migration_test_006'));
    $db=$resumeMode?ConnectionFactory::connect('migration_test_006_resume'):ConnectionFactory::connect('migration_test_006');
    $identity=null;
    if($restoreTest) {
        $step='RESTORE_IDENTITY';
        $identity=Migration006RestoreIdentity::inspect($db);
    }
    $runner=new MigrationRunner($db,MigrationTarget::TEST);
    $step='MIGRATION_006';
    // Recheck on the very same PDO immediately before entering the apply path.
    if($restoreTest && $mode==='--apply') Migration006RestoreIdentity::inspect($db);
    $result=match($mode) {
        '--preflight'=>$runner->vehicle006Preflight(),
        '--apply'=>$runner->apply(true,$backup),
        '--postflight'=>$runner->status(),
        '--resume-preflight'=>$runner->vehicle006ResumePreflight(),
        '--resume'=>$runner->resumeVehicle006($backup,$partialBackup,$partialHash),
    };
    if($mode==='--postflight' && !in_array(App\Database\MigrationPlan::VEHICLE006['version'],array_column($result['migrations'],'version'),true))throw new SafetyException('MIGRATION_006_NOT_APPLIED');
    echo json_encode(['status'=>'PASS','target'=>MigrationTarget::TEST,'mode'=>$mode,'restore_identity'=>$identity,'checks'=>$result],JSON_PRETTY_PRINT),PHP_EOL;
} catch(App\Database\MigrationExecutionException $error) {
    echo json_encode(['step'=>$step]+$error->diagnostics()),PHP_EOL;exit(1);
} catch(App\Database\GrantGuardException $error) {
    echo json_encode(['status'=>'FAIL','step'=>'GRANTS','code'=>'MIGRATION_006_GRANT_GUARD_FAILED','diagnostics'=>$error->diagnostics]),PHP_EOL;exit(1);
} catch(PDOException $error) {
    echo json_encode(['status'=>'FAIL','step'=>$step,'code'=>'PDO_ERROR','sqlstate'=>$error->errorInfo[0]??null,'native_code'=>$error->errorInfo[1]??null]),PHP_EOL;exit(1);
} catch(Throwable $error) {
    $code=preg_match('/^MIGRATION_006_[A-Z_]+$/D',$error->getMessage())?$error->getMessage():'MIGRATION_006_SAFETY_CHECK_FAILED';
    echo json_encode(['status'=>'FAIL','step'=>$step,'code'=>$code]),PHP_EOL;exit(1);
}
