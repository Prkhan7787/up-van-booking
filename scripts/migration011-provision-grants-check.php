<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/cli.php';

use App\Database\GrantVerifier;
use App\Database\Migration011ApplyGuard;
use App\Database\MigrationTarget;
use App\Database\Migration011Validator;
use App\Database\MigrationPlan;
use App\Database\SafetyException;

if(in_array('--verify-011-backup',$argv,true)){
    try{
        $request=json_decode((string)file_get_contents('php://stdin'),true,8,JSON_THROW_ON_ERROR);
        $verified=App\Database\Migration011ApplyBackup::verify($request['directory'],$request['manifest_sha256']);
        $manifest=json_decode((string)file_get_contents($request['directory'].'/backup-manifest.json'),true,32,JSON_THROW_ON_ERROR);
        $expires=(new DateTimeImmutable($manifest['started_utc']))->modify('+1 hour');
        echo json_encode(['backup_verification'=>'PASS','manifest_sha256'=>$verified['manifest_sha256'],
            'base_tables'=>$verified['base_table_count'],'views'=>1,'backup_started_utc'=>$manifest['started_utc'],
            'backup_finished_utc'=>$manifest['finished_utc'],'expires_at_bangkok'=>$expires->setTimezone(new DateTimeZone('Asia/Bangkok'))->format(DateTimeInterface::ATOM),
            'backup_directory'=>$request['directory'],'apply_ready'=>false,'restore_rehearsed'=>false],JSON_THROW_ON_ERROR),PHP_EOL;
        exit(0);
    }catch(Throwable){fwrite(STDERR,"MIGRATION_011_BACKUP_VERIFICATION_FAILED\n");exit(1);}
}

if(in_array('--verify-011-reconciliation-backup',$argv,true)){
    try{
        $request=json_decode((string)file_get_contents('php://stdin'),true,8,JSON_THROW_ON_ERROR);
        $verified=App\Database\Migration011ReconciliationBackup::verify($request['directory'],$request['manifest_sha256']);
        $manifest=json_decode((string)file_get_contents($request['directory'].'/backup-manifest.json'),true,32,JSON_THROW_ON_ERROR);
        $expires=(new DateTimeImmutable($manifest['started_utc']))->modify('+1 hour');
        echo json_encode(['backup_verification'=>'PASS','manifest_sha256'=>$verified['manifest_sha256'],
            'base_tables'=>$verified['base_table_count'],'views'=>1,'backup_started_utc'=>$manifest['started_utc'],'backup_finished_utc'=>$manifest['finished_utc'],
            'expires_at_bangkok'=>$expires->setTimezone(new DateTimeZone('Asia/Bangkok'))->format(DateTimeInterface::ATOM),
            'backup_directory'=>$request['directory'],'ledger_state'=>$verified['ledger_state']],JSON_THROW_ON_ERROR),PHP_EOL;
        exit(0);
    } catch (Throwable $error) {
        $reason = $error instanceof SafetyException && preg_match('/\AMIGRATION_0(?:10|11)_[A-Z0-9_]+\z/', $error->getMessage())
            ? ' reason='.$error->getMessage() : '';
        fwrite(STDERR, 'MIGRATION_011_RECONCILIATION_BACKUP_VERIFICATION_FAILED'.$reason.PHP_EOL);
        exit(1);
    }
}

if (in_array('--preflight-queries', $argv, true)||in_array('--diagnostic-queries',$argv,true)) {
    $queries=Migration011Validator::metadataQueries();
    $queries['ledger']='SELECT version,checksum,status,completed_statements,total_statements FROM schema_migrations ORDER BY version';
    $queries['inventory']='SELECT TABLE_NAME,TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME';
    if(in_array('--diagnostic-queries',$argv,true)){
        $queries['ledger']='SELECT version,checksum,status,completed_statements,total_statements,failure_code FROM schema_migrations ORDER BY version';
        $queries['inventory']='SELECT TABLE_NAME,TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME';
        $queries['account']="SELECT account_locked FROM mysql.user WHERE User='up_van_migration011_apply' AND Host='127.0.0.1'";
        $queries['sessions']="SELECT ID,COMMAND FROM information_schema.PROCESSLIST WHERE USER='up_van_migration011_apply' ORDER BY ID";
    }
    $jsonQueries=[];
    foreach($queries as $key=>$sql){
        if(!preg_match('/^SELECT (.+?) FROM /s',$sql,$match))exit(1);
        $pairs=[];
        foreach(explode(',',$match[1]) as $column){
            $name=trim($column);$name=substr($name,(strrpos($name,'.')===false?-1:strrpos($name,'.'))+1);
            if(!preg_match('/^[a-zA-Z_]+$/D',$name))exit(1);
            $pairs[]="'$name',q.`$name`";
        }
        $jsonQueries[$key]='SELECT COALESCE(JSON_ARRAYAGG(JSON_OBJECT('.implode(',',$pairs).')),JSON_ARRAY()) FROM ('.$sql.') q';
    }
    echo json_encode($jsonQueries,JSON_THROW_ON_ERROR);exit(0);
}

if(in_array('--diagnose-011',$argv,true)){
    try{
        $packet=json_decode((string)file_get_contents('php://stdin'),true,64,JSON_THROW_ON_ERROR);
        $ledger=$packet['ledger'];usort($ledger,static fn($a,$b)=>strcmp($a['version'],$b['version']));
        $own=array_values(array_filter($ledger,static fn($r)=>str_starts_with($r['version'],'011_')));
        $driver=array_values(array_filter($ledger,static fn($r)=>str_starts_with($r['version'],'010_')));
        $baseline=array_values(array_filter($ledger,static fn($r)=>!str_starts_with($r['version'],'010_')&&!str_starts_with($r['version'],'011_')));
        $baseStatus='FAIL';try{Migration011Validator::assertLedger($baseline);$baseStatus='PASS';}catch(Throwable){}
        $ownStatus=$own===[]?'ABSENT':'UNVERIFIED';
        try{if($own!==[]){Migration011Validator::assertLedger($ledger,'after');$ownStatus='APPLIED_CHECKSUM_PASS';}}catch(Throwable){}
        $present=in_array('microsoft_identities',array_column($packet['tables'],'TABLE_NAME'),true);
        // Preserve actual column ordinal order for the existing strict schema validator.
        usort($packet['columns'],static fn($a,$b)=>[$a['TABLE_NAME'],$a['ORDINAL_POSITION']]<=>[$b['TABLE_NAME'],$b['ORDINAL_POSITION']]);
        $schema='FAIL';$schemaError=null;
        try{Migration011Validator::assertSchema($packet,$present);$schema='PASS';}catch(Throwable $error){
            $schemaError=$error instanceof App\Database\SafetyException&&preg_match('/^MIGRATION_011_[A-Z0-9_]+$/D',$error->getMessage())?$error->getMessage():'MIGRATION_011_SCHEMA_DIAGNOSTIC_FAILED';
        }
        $expectedMigration=MigrationPlan::microsoft011()['migration'];
        $ownChecksum=count($own)===1&&($own[0]['version']??null)===$expectedMigration['version']&&
            is_string($own[0]['checksum']??null)&&hash_equals($expectedMigration['sha256'],$own[0]['checksum'])?'PASS':($own===[]?'ABSENT':'FAIL');
        $account=count($packet['account'])===1?($packet['account'][0]['account_locked']??'UNKNOWN'):'UNKNOWN';
        if(!in_array($account,['Y','N'],true))$account='UNKNOWN';
        $expectedBase=['approval_decisions','audit_logs','auth_login_buckets','booking_notifications','booking_passengers','booking_requests',
            'booking_reviews','booking_status_history','drivers','roles','schema_migrations','user_roles','users','vehicle_assignments','vehicles'];
        if($present)$expectedBase[]='microsoft_identities';
        $actualBase=[];$actualViews=[];
        foreach(($packet['inventory']??[]) as $item){
            if(($item['TABLE_TYPE']??null)==='BASE TABLE')$actualBase[]=$item['TABLE_NAME']??'';
            elseif(($item['TABLE_TYPE']??null)==='VIEW')$actualViews[]=$item['TABLE_NAME']??'';
            else{$actualViews[]='__UNEXPECTED_OBJECT_TYPE__';}
        }
        sort($expectedBase);sort($actualBase);sort($actualViews);
        $inventoryPass=$actualBase===$expectedBase&&$actualViews===['calendar_events'];
        $migrationState='PARTIAL_OR_DRIFT_STOP';
        if($baseStatus==='PASS'&&$schema==='PASS'&&$inventoryPass&&$driver===[]){
            if(!$present&&$ownStatus==='ABSENT')$migrationState='NOT_APPLIED';
            if($present&&$ownStatus==='APPLIED_CHECKSUM_PASS')$migrationState='APPLIED_VERIFIED';
        }
        echo json_encode(['status'=>'DIAGNOSTIC_COMPLETE','identity'=>'PASS','account_locked'=>$account,
            'active_apply_sessions'=>count($packet['sessions']),'sessions'=>$packet['sessions'],
            'ledger_001_009'=>$baseStatus,'migration_010'=>$driver===[]?'ABSENT':'PRESENT_STOP',
            'ledger_011_state'=>$ownStatus,'ledger_011'=>$own,'ledger_011_checksum'=>$ownChecksum,
            'inventory_validation'=>$inventoryPass?'PASS':'FAIL','base_table_count'=>count($actualBase),'view_count'=>count($actualViews),
            'expected_011_checksum'=>$expectedMigration['sha256'],'microsoft_identities'=>$present?'PRESENT':'ABSENT',
            'schema_validation'=>$schema,'schema_error_code'=>$schemaError,
            'schema_evidence'=>array_intersect_key($packet,array_flip(['tables','columns','indexes','foreign_keys','checks','triggers','show_create_table'])),
            'migration_state'=>$migrationState,'recovery_required'=>true,'apply_ready'=>false],JSON_THROW_ON_ERROR),PHP_EOL;
        exit(0);
    }catch(Throwable){fwrite(STDERR,"MIGRATION_011_READONLY_DIAGNOSTIC_FAILED\n");exit(1);}
}

if(in_array('--read-only-preflight',$argv,true)){
    try{
        $packet=json_decode((string)file_get_contents('php://stdin'),true,64,JSON_THROW_ON_ERROR);
        Migration011Validator::assertReadOnlyPreflight($packet);
        echo "READ_ONLY_PREFLIGHT=PASS\nLEDGER=PASS\nSCHEMA_PRECONDITIONS=PASS\nINVENTORY=15_BASE_TABLES_AND_CALENDAR_VIEW\n";exit(0);
    }catch(Throwable $error){
        $code=$error instanceof App\Database\SafetyException&&preg_match('/^MIGRATION_011_[A-Z0-9_]+$/D',$error->getMessage())?$error->getMessage():'MIGRATION_011_READONLY_PREFLIGHT_REJECTED';
        fwrite(STDERR,$code.PHP_EOL);exit(1);
    }
}

if (in_array('--runtime-check', $argv, true)) {
    if (PHP_MAJOR_VERSION !== 8 || PHP_MINOR_VERSION !== 4
        || !class_exists(GrantVerifier::class) || !class_exists(Migration011ApplyGuard::class)
        || !class_exists(MigrationTarget::class)) exit(1);
    echo "MIGRATION_011_EVALUATOR_RUNTIME=8.4\n";
    exit(0);
}

$input=file_get_contents('php://stdin');
try{
    $grants=json_decode(is_string($input)?$input:'',true,64,JSON_THROW_ON_ERROR);
    if(!is_array($grants)||!array_is_list($grants)||count($grants)!==4)exit(1);
    foreach($grants as $grant){
        if(!is_string($grant)||preg_match('/\AGRANT\s+(?:`[^`]+`|\'[^\']+\')@/i',$grant)||stripos($grant,'WITH GRANT OPTION')!==false)exit(1);
    }
    $result=GrantVerifier::evaluate($grants,MigrationTarget::TEST,Migration011ApplyGuard::rules(),false,true);
    if($result['status']!=='PASS'||$result['missing_privileges']!==[]||$result['unexpected_privileges']!==[])exit(1);
    echo "MIGRATION_011_GRANTS=PASS\n";
}catch(Throwable){exit(1);}
