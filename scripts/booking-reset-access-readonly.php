<?php
declare(strict_types=1);

// Independent diagnostic. Import helpers without invoking the reset entry point.
if(!defined('BOOKING_RESET_RUNNER_TEST'))define('BOOKING_RESET_RUNNER_TEST',true);
require_once __DIR__.'/booking-reset-runner.php';

function resetRuntimeDiagnosticConnect(): PDO {
    $c=\App\Database\ConnectionFactory::config('auth_test');
    if($c['host']!=='127.0.0.1' || $c['port']!=='3306' || $c['database']!==RESET_DB || $c['username']!=='up_van_auth_test')throw new RuntimeException('RUNTIME_DIAGNOSTIC_CONFIG_REJECTED');
    return new PDO('mysql:host=127.0.0.1;port=3306;dbname='.RESET_DB.';charset=utf8mb4',$c['username'],$c['password'],[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_TIMEOUT=>5,PDO::MYSQL_ATTR_MULTI_STATEMENTS=>false,
    ]);
}

function resetAccessProbe(PDO $db): array {
    $GLOBALS['resetStep']='runtime_diagnostic_identity';
    $identity=$db->query('SELECT @@hostname host,@@port port,@@server_uuid uuid,DATABASE() db,VERSION() version,CURRENT_USER() account')->fetch();
    if(!is_array($identity) || (int)$identity['port']!==3306 || $identity['uuid']!==RESET_UUID || $identity['db']!==RESET_DB || $identity['account']!=='up_van_auth_test@127.0.0.1')throw new RuntimeException('RUNTIME_DIAGNOSTIC_IDENTITY_REJECTED');
    $GLOBALS['resetStep']='runtime_metadata_visibility';
    $rows=$db->query("SELECT TABLE_SCHEMA,TABLE_NAME FROM information_schema.VIEW_TABLE_USAGE WHERE VIEW_SCHEMA='up_van_booking_test' AND VIEW_NAME='calendar_events' ORDER BY TABLE_SCHEMA,TABLE_NAME")->fetchAll(PDO::FETCH_NUM);
    $select=[];
    foreach(['deleted_by','deletion_reason','updated_by'] as $column){
        $GLOBALS['resetStep']='rollback_select_'.$column;
        // Fixed identifiers, no booking values returned. Verify actual effective SELECT permissions.
        try{$db->query("SELECT `$column` FROM booking_requests WHERE 1=0")->fetchAll();$select[$column]=['status'=>'READABLE'];}
        catch(PDOException $e){
            $code=(int)($e->errorInfo[1]??0);
            if(!in_array($code,[1142,1143],true))throw $e;
            $select[$column]=['status'=>'DENIED','sqlstate'=>(string)($e->errorInfo[0]??''),'mysql_code'=>$code];
        }
    }
    return ['identity'=>$identity,'dependencies'=>resetAssessViewDependencies($rows),'rollback_select'=>$select];
}

function resetCompareMetadataReport(array $runtime,array $metadata,int $now): array {
    $reasons=[];
    foreach(['runtime'=>$runtime['identity']??[], 'metadata'=>$metadata['identity']??[]] as $label=>$identity){
        if(($identity['uuid']??null)!==RESET_UUID || ($identity['db']??null)!==RESET_DB || (string)($identity['port']??'')!=='3306' || !preg_match('/^8\.4\.\d+(?:[-.]|$)/D',(string)($identity['version']??'')))$reasons[]=$label.'_identity_mismatch';
    }
    if(($runtime['identity']['account']??null)!=='up_van_auth_test@127.0.0.1')$reasons[]='runtime_account_mismatch';
    if(!is_string($metadata['identity']['account']??null) || $metadata['identity']['account']==='' || $metadata['identity']['account']===($runtime['identity']['account']??null))$reasons[]='metadata_account_not_separate';
    $at=$metadata['observed_at_epoch']??null;
    if(!is_int($at) || $now-$at<0 || $now-$at>60)$reasons[]='metadata_report_not_fresh';
    $deps=resetAssessViewDependencies($metadata['dependencies']??[]);
    if(!$deps['pass'])$reasons[]='metadata_dependencies_incomplete';
    $hash=$metadata['view_definition_sha256']??'';
    if(!is_string($hash) || !preg_match('/\A[0-9a-f]{64}\z/D',$hash) || $hash!==($metadata['view_definition_sha256_after']??null))$reasons[]='metadata_definition_changed_or_unavailable';
    return ['diagnostic_only'=>true,'reasons'=>$reasons,'evidence_matches'=>$reasons===[],
        'runtime_dependency_count'=>$runtime['dependencies']['actual_count']??null,'metadata_dependencies'=>$deps,
        'reset_authorized'=>false,'ddl_change_protection'=>'NOT_ESTABLISHED_BY_REPORT'];
}

if(!defined('BOOKING_RESET_ACCESS_TEST')){
    try{
        if(count($argv)>2 || (isset($argv[1]) && !str_starts_with($argv[1],'--metadata-report=')))throw new RuntimeException('USAGE: booking-reset-access-readonly.php [--metadata-report=PATH]');
        $report=resetAccessProbe(resetRuntimeDiagnosticConnect());
        if(isset($argv[1])){
            $path=substr($argv[1],strlen('--metadata-report='));
            $input=file_get_contents($path);if($input===false)throw new RuntimeException('METADATA_REPORT_UNREADABLE');
            if(str_starts_with($input,"\xEF\xBB\xBF"))$input=substr($input,3);
            $metadata=json_decode($input,true,512,JSON_THROW_ON_ERROR);if(!is_array($metadata))throw new RuntimeException('METADATA_REPORT_INVALID');
            $report['comparison']=resetCompareMetadataReport($report,$metadata,time());
        }
        $report['diagnostic_only']=true;$report['reset_authorized']=false;
        echo json_encode($report,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
    }catch(Throwable $e){resetFail(resetDiagnostic($e));}
}
