<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/bootstrap/cli.php';
set_exception_handler(static function(Throwable $e):void{fwrite(STDERR,$e::class.': '.$e->getMessage().PHP_EOL);exit(1);});

use App\Database\MigrationPlan;

$checks=0;
$check=static function(bool $condition,string $label)use(&$checks):void{
    if(!$condition)throw new RuntimeException('FAIL '.$label);
    $checks++;
};
$root=dirname(__DIR__,2);
$checker=$root.'/scripts/migration010-backup-check.php';
$verifier=$root.'/scripts/migration011-provision-grants-check.php';
$directory=sys_get_temp_dir().'/upvan010-backup-fixture-'.bin2hex(random_bytes(8));
mkdir($directory);
$dumpFile=$directory.'/up_van_booking_test_base_tables.sql';
$viewFile=$directory.'/calendar_events_view_restore.sql';
$manifestFile=$directory.'/backup-manifest.json';
$runPhp=static function(array $arguments,string $stdin=''):array{
    $command=[PHP_BINARY,...$arguments];$pipes=[];
    $process=@proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,null,['bypass_shell'=>true]);
    if(!is_resource($process)){
        // PHP_BINARY may represent the Windows runtime path through a legacy code page.
        $command[0]=basename(PHP_BINARY);$pipes=[];
        $process=@proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,null,['bypass_shell'=>true]);
    }
    if(!is_resource($process))throw new RuntimeException('Could not start offline validator process');
    if($stdin!=='')fwrite($pipes[0],$stdin);
    fclose($pipes[0]);$stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    return ['exit'=>proc_close($process),'stdout'=>$stdout,'stderr'=>$stderr];
};
$base=['approval_decisions','audit_logs','auth_login_buckets','booking_notifications','booking_passengers','booking_requests',
    'booking_reviews','booking_status_history','drivers','roles','schema_migrations','user_roles','users','vehicle_assignments','vehicles'];
$ledger=array_slice(MigrationPlan::driver010LedgerEntries(),0,9);
$identity=MigrationPlan::MICROSOFT011;
$run=static function(array $tables,array $rows,bool $allowFailed011=false)use($dumpFile,$checker,$runPhp):array{
    $lines=['-- Host: 127.0.0.1 Database: up_van_booking_test','-- Server version 8.4.9'];
    foreach($tables as $table){
        $lines[]="CREATE TABLE `$table` (";
        if($table==='users')$lines[]='  `email` varchar(254) NOT NULL,';
        $lines[]=') ENGINE=InnoDB;';
        $lines[]="LOCK TABLES `$table` WRITE;";
        if($table==='schema_migrations')foreach($rows as $row){
            $status=$row['status']??'applied';$completed=$row['completed_statements']??$row['statement_count'];$total=$row['total_statements']??$row['statement_count'];
            $failure=$row['failure_code']??'NULL';
            $failureValue=$failure==='NULL'?'NULL':"'$failure'";
            $lines[]="INSERT INTO `schema_migrations` VALUES ('{$row['version']}','{$row['sha256']}','$status',$completed,$total,NULL,NULL,$failureValue);";
        }
        $lines[]='UNLOCK TABLES;';
    }
    $lines[]='-- Dump completed on 2026-11-25';
    file_put_contents($dumpFile,implode("\r\n",$lines)."\r\n");
    $arguments=[$checker,$dumpFile,...$tables];
    if($allowFailed011)$arguments[]='--allow-failed-011';
    return $runPhp($arguments);
};
try{
    $valid15=$run($base,$ledger);
    $check($valid15['exit']===0&&str_contains($valid15['stdout'],'"base_tables":15')&&str_contains($valid15['stdout'],'001-009 applied'),
        '15-table backup accepted with exact baseline ledger');
    $tables16=[...$base,'microsoft_identities'];
    $valid16=$run($tables16,[...$ledger,['version'=>$identity['version'],'sha256'=>$identity['sha256'],'statement_count'=>1]]);
    $check($valid16['exit']===0&&str_contains($valid16['stdout'],'"base_tables":16')&&str_contains($valid16['stdout'],'011 applied'),
        '16-table backup accepted with exact 011 ledger/checksum');
    $missingTable=$run($base,[...$ledger,['version'=>$identity['version'],'sha256'=>$identity['sha256'],'statement_count'=>1]]);
    $check($missingTable['exit']!==0&&str_contains($missingTable['stderr'],'BACKUP_011_LEDGER_INVENTORY_MISMATCH'),
        '011 ledger without its table is rejected');
    $missingLedger=$run($tables16,$ledger);
    $check($missingLedger['exit']!==0&&str_contains($missingLedger['stderr'],'BACKUP_011_LEDGER_INVENTORY_MISMATCH'),
        '011 table without its ledger row is rejected');
    $failed011=['version'=>$identity['version'],'sha256'=>$identity['sha256'],'statement_count'=>1,'status'=>'failed','completed_statements'=>1,'total_statements'=>1,'failure_code'=>'statement_failed'];
    $validRecovery=$run($tables16,[...$ledger,$failed011],true);
    $recoveryResult=json_decode($validRecovery['stdout'],true);
    $check($validRecovery['exit']===0&&($recoveryResult['ledger']??null)==='001-009 plus 011 failed 1/1','current-state 16-table backup accepts only exact failed 1/1 011 ledger when recovery mode is explicit');
    $failedWithoutMode=$run($tables16,[...$ledger,$failed011]);
    $check($failedWithoutMode['exit']!==0,'normal backup validation cannot silently accept a failed 011 ledger');
    $badFailure=$failed011;$badFailure['failure_code']='unknown';$badState=$run($tables16,[...$ledger,$badFailure],true);
    $check($badState['exit']!==0&&str_contains($badState['stderr'],'BACKUP_011_FAILED_LEDGER_REJECTED'),'unexpected recovery failure code is rejected');
    $badProgress=$failed011;$badProgress['completed_statements']=0;$badProgress=$run($tables16,[...$ledger,$badProgress],true);
    $check($badProgress['exit']!==0&&str_contains($badProgress['stderr'],'BACKUP_011_FAILED_LEDGER_REJECTED'),'partial 0/1 progress is rejected');
    $missingInRecovery=$run($base,$ledger,true);
    $check($missingInRecovery['exit']!==0&&str_contains($missingInRecovery['stderr'],'BACKUP_011_FAILED_LEDGER_REQUIRED'),'recovery mode rejects a pre-011 dump');
    foreach ([
        ['failure_code'=>'NULL'], ['failure_code'=>''],
        ['sha256'=>str_repeat('0',64)], ['status'=>'applied'], ['total_statements'=>2],
    ] as $change) {
        $rejected=$run($tables16,[...$ledger,array_replace($failed011,$change)],true);
        $check($rejected['exit']!==0&&str_contains($rejected['stderr'],'BACKUP_011_FAILED_LEDGER_REJECTED'),
            'recovery dump rejects invalid '.array_key_first($change));
    }
    $postconditionFailure=$run($tables16,[...$ledger,array_replace($failed011,['failure_code'=>'postcondition_failed'])],true);
    $check($postconditionFailure['exit']===0,'recovery dump accepts the second eligible failure code');

    // Exercise the exporter contract end to end through the PHP CLI, including
    // the JSON success response emitted only after the filesystem guard passes.
    $run($tables16,[...$ledger,$failed011],true);
    $calendarSql="CREATE SQL SECURITY INVOKER VIEW `calendar_events` AS SELECT 1;\r\n";
    file_put_contents($viewFile,$calendarSql);
    $now=time();
    $manifest=[
        'format'=>'upvan-pre010-logical-backup-v1','host'=>'127.0.0.1','port'=>3306,'database'=>'up_van_booking_test',
        'uuid'=>'77218fbd-d66d-11f1-9a0a-5c2886f057d5','version'=>'8.4.9',
        'started_utc'=>gmdate('Y-m-d\TH:i:s',$now-10).'.2142879Z',
        'finished_utc'=>gmdate('Y-m-d\TH:i:s',$now-5).'.4410368Z',
        'tables'=>$tables16,'ledger'=>'001-009 applied; 011 failed 1/1; 010 absent',
        'calendar_view'=>'calendar_events','calendar_security'=>'INVOKER','calendar_dependencies'=>['booking_requests','vehicle_assignments'],
        'base_tables_sha256'=>hash_file('sha256',$dumpFile),'base_tables_bytes'=>strlen(file_get_contents($dumpFile)),
        'calendar_view_sha256'=>hash('sha256',$calendarSql),'calendar_view_bytes'=>strlen($calendarSql),
    ];
    $writeManifest=static function(array $values)use($manifestFile):string{
        $json=json_encode($values,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR);
        $bytes=str_replace("\n","\r\n",$json)."\r\n";
        file_put_contents($manifestFile,$bytes);
        return hash('sha256',$bytes);
    };
    $verify=static function(string $pin,string $mode='--verify-011-reconciliation-backup')use($directory,$runPhp,$verifier):array{
        $request=json_encode(['directory'=>$directory,'manifest_sha256'=>$pin],JSON_THROW_ON_ERROR);
        return $runPhp([$verifier,$mode],$request."\r\n");
    };
    $pin=$writeManifest($manifest);
    $result=$verify($pin);
    $check($result['exit']===0&&$result['stderr']==='','CLI reports successful guard result');
    $summary=json_decode($result['stdout'],true,16,JSON_THROW_ON_ERROR);
    $check($summary['backup_verification']==='PASS'&&$summary['ledger_state']===$manifest['ledger'],'CLI emits the verified ledger state in PASS JSON');
    $check($summary['base_tables']===16&&$summary['views']===1&&$summary['manifest_sha256']===$pin,'CLI returns exact inventory and manifest pin');
    $check($summary['backup_started_utc']===$manifest['started_utc']&&$summary['backup_finished_utc']===$manifest['finished_utc'],'CLI handles PowerShell UTC timestamps with seven fractional digits');
    $check($verify($pin,'--verify-011-backup')['exit']!==0,'pre-011 CLI rejects the reconciliation snapshot');

    $rejectBackup=static function(array $result,string $reason)use($check):void{
        $check($result['exit']===1&&$result['stdout']===''&&
            trim($result['stderr'])==='MIGRATION_011_RECONCILIATION_BACKUP_VERIFICATION_FAILED reason='.$reason,$reason);
    };
    $rejectBackup($verify(str_repeat('0',64)),'MIGRATION_010_BACKUP_MANIFEST_HASH_REJECTED');
    file_put_contents($viewFile,str_replace('SELECT 1','SELECT 2',$calendarSql));
    $rejectBackup($verify($pin),'MIGRATION_010_BACKUP_FILE_HASH_REJECTED');
    unlink($viewFile);
    $rejectBackup($verify($pin),'MIGRATION_010_BACKUP_FILE_REJECTED');
    file_put_contents($viewFile,$calendarSql);
    $rejectBackup($verify($writeManifest(array_replace($manifest,['tables'=>[...$tables16,'unexpected_table']]))),
        'MIGRATION_011_RECONCILIATION_BACKUP_INVENTORY_REJECTED');
    $rejectBackup($verify($writeManifest(array_replace($manifest,['started_utc'=>gmdate('Y-m-d\TH:i:s\Z',$now-3700)]))),
        'MIGRATION_011_RECONCILIATION_BACKUP_STALE_OR_STATE_REJECTED');
    $rejectBackup($verify($writeManifest(array_replace($manifest,['ledger'=>'001-009 applied; 011 applied; 010 absent']))),
        'MIGRATION_011_RECONCILIATION_BACKUP_STATE_REJECTED');

    $run($base,$ledger);
    $pre011Pin=$writeManifest(array_replace($manifest,['tables'=>$base,'ledger'=>'001-009 applied; 010 absent',
        'base_tables_sha256'=>hash_file('sha256',$dumpFile),'base_tables_bytes'=>strlen(file_get_contents($dumpFile))]));
    $rejectBackup($verify($pre011Pin),'MIGRATION_011_RECONCILIATION_BACKUP_STATE_REJECTED');
    $check($verify($pre011Pin,'--verify-011-backup')['exit']===0,'normal pre-011 verification accepts the 15-table snapshot');
    echo "PASS: $checks 15/16-table dump and Migration 011 backup verifier CLI checks; no database connected.",PHP_EOL;
}finally{
    foreach ([$dumpFile,$viewFile,$manifestFile] as $path) if(is_file($path)) unlink($path);
    rmdir($directory);
}
