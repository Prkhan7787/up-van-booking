<?php
declare(strict_types=1);

require dirname(__DIR__).'/bootstrap/cli.php';

use App\Database\ConnectionFactory;
use App\Database\BookingResetMaintenanceGrantPlan;
use App\Database\GrantVerifier;
use App\Core\BookingWriteWindow;
const RESET_DB = 'up_van_booking_test';
const RESET_UUID = '77218fbd-d66d-11f1-9a0a-5c2886f057d5';
const RESET_BACKUP_DIR = 'C:\\Users\\User\\upvan-reset-preflight-4148fa9193194648ad3297ff09ad8f26';
const RESET_REASON = 'Reset test booking data for new workflow testing';
const RESET_LOCK = 'upvan:test-booking-reset';
const RESET_APPLY_SCOPE = 'up_van_booking_test:77218fbd-d66d-11f1-9a0a-5c2886f057d5:29=awaiting_assignment.v7,30=awaiting_assignment.v5,31=awaiting_rejection_notice.v7,32=awaiting_assignment.v6,33=submitted.v2,34=submitted.v2';
$resetStep='startup';

function resetFail(string $message): never { fwrite(STDERR, "RESET=FAIL $message\n"); exit(1); }
function resetDiagnostic(Throwable $e): string {
    global $resetStep;
    if($e instanceof PDOException){$info=$e->errorInfo??[];$state=(string)($info[0]??$e->getCode()?:'UNKNOWN');$code=(string)($info[1]??'UNKNOWN');$detail=(string)($info[2]??'');$object='';
        if(preg_match("/(?:column|field) ['`]?([A-Za-z0-9_.]+)['`]?/i",$detail,$m))$object='column='.$m[1];
        elseif(preg_match("/(?:table|view) ['`]?([A-Za-z0-9_.]+)['`]?/i",$detail,$m))$object='object='.$m[1];
        if(!preg_match('/\A[A-Z0-9]{5}\z/D',$state))$state='UNKNOWN';
        if(!preg_match('/\A[0-9]+\z/D',$code))$code='UNKNOWN';
        return "step=$resetStep sqlstate=$state mysql_code=$code".($object!==''?' '.$object:'');
    }
    $reason=$e->getMessage();
    if(!preg_match('/\A[A-Z][A-Z0-9_:-]{0,99}\z/D',$reason))$reason='UNCLASSIFIED_RUNTIME_ERROR';
    return "step=$resetStep reason=$reason";
}
function resetSnapshot(): array {
    return [29=>['awaiting_assignment',7],30=>['awaiting_assignment',5],31=>['awaiting_rejection_notice',7],32=>['awaiting_assignment',6],33=>['submitted',2],34=>['submitted',2]];
}
function resetHasBlockingAssignments(array $rows): bool {
    foreach($rows as $row)if(in_array((string)($row['status']??''),['active','completed'],true))return true;
    return false;
}
function resetCheckSnapshot(array $rows): void {
    $expected=resetSnapshot();
    if (count($rows)!==count($expected)) throw new RuntimeException('SNAPSHOT_ROW_COUNT_MISMATCH');
    foreach ($expected as $id=>[$status,$version]) {
        $row=$rows[$id]??null;
        if (!is_array($row) || (string)$row['workflow_status']!==$status || (int)$row['version']!==$version || $row['deleted_at']!==null) {
            throw new RuntimeException("SNAPSHOT_MISMATCH_ID_$id");
        }
    }
}
function resetCheckAppliedRows(array $rows,int $actor): void {
    if(count($rows)!==count(resetSnapshot()))throw new RuntimeException('POST_RESET_TARGET_COUNT_MISMATCH');
    foreach(resetSnapshot() as $id=>[$status,$version]){
        $row=$rows[$id]??null;
        if(!is_array($row) || (string)($row['workflow_status']??'')!==$status || (int)($row['version']??0)!==$version+1
            || !is_string($row['deleted_at']??null) || $row['deleted_at']===''
            || (int)($row['deleted_by']??0)!==$actor || (int)($row['updated_by']??0)!==$actor
            || ($row['deletion_reason']??null)!==RESET_REASON)throw new RuntimeException("POST_RESET_TARGET_MISMATCH_ID_$id");
    }
}
function resetCheckPreservedCounts(array $before,array $after,int $auditDelta): void {
    foreach(['decisions','notifications','history'] as $key){
        if(!isset($before[$key],$after[$key]) || (int)$after[$key] !== (int)$before[$key])throw new RuntimeException("PRESERVED_COUNT_CHANGED_$key");
    }
    if(!isset($before['audit'],$after['audit']) || (int)$after['audit'] !== (int)$before['audit']+$auditDelta)throw new RuntimeException('AUDIT_COUNT_MISMATCH');
}
function resetBackupCheck(): array {
    $spec=[
        'base'=>['up_van_booking_test_base_tables.sql','18090c2588775fbf6a907a439fc9f7afb2e6a5604826505f8cbf7ab01153a20b'],
        'view'=>['calendar_events_view_restore.sql','04e37e4a73fc653692eeb249567834d9e8072a28d90c9225a16bd373c8d0c06d'],
    ];
    $result=[];
    foreach($spec as $label=>[$file,$expected]) {
        $path=RESET_BACKUP_DIR.DIRECTORY_SEPARATOR.$file;
        if (!is_file($path) || filesize($path)<1) throw new RuntimeException("BACKUP_MISSING_$label");
        $actual=hash_file('sha256',$path);
        if (!is_string($actual) || !hash_equals($expected,$actual)) throw new RuntimeException("BACKUP_HASH_MISMATCH_$label");
        $result[$label]=['path'=>$path,'bytes'=>filesize($path),'sha256'=>$actual];
    }
    return $result;
}
function resetConnect(): PDO {
    $GLOBALS['resetStep']='connect_config';
    return ConnectionFactory::connect('reset_maintenance');
}
function resetIdentity(PDO $db): array {
    $GLOBALS['resetStep']='database_identity';
    $r=$db->query('SELECT @@hostname host,@@port port,@@server_uuid uuid,DATABASE() db,VERSION() version,CURRENT_USER() account')->fetch();
    if (!is_array($r) || (int)$r['port']!==3306 || $r['uuid']!==RESET_UUID || $r['db']!==RESET_DB || !preg_match('/^8\.4\.\d+(?:[-.]|$)/D',(string)$r['version']) || $r['account']!=='up_van_reset_maintenance@127.0.0.1') throw new RuntimeException('DATABASE_IDENTITY_REJECTED');
    return $r;
}
function resetSchema(PDO $db): void {
    $GLOBALS['resetStep']='schema_columns';
    $required=[
        'booking_requests'=>['id','workflow_status','version','deleted_at','deleted_by','deletion_reason','updated_by'],
        'vehicle_assignments'=>['id','booking_request_id','status'],
        'audit_logs'=>['user_id','action','entity_type','entity_id','metadata'],
        'users'=>['id','is_active'], 'roles'=>['id','name'], 'user_roles'=>['user_id','role_id'],
        'approval_decisions'=>['booking_request_id'], 'booking_notifications'=>['booking_request_id'],
        'booking_status_history'=>['booking_request_id'],
    ];
    $q=$db->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=:db AND TABLE_NAME=:table');
    foreach($required as $table=>$cols){$q->execute(['db'=>RESET_DB,'table'=>$table]);$actual=$q->fetchAll(PDO::FETCH_COLUMN);if(array_diff($cols,$actual)!==[])throw new RuntimeException("SCHEMA_COLUMNS_MISSING_$table");}
    $engine=$db->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=:db AND TABLE_NAME IN ('booking_requests','vehicle_assignments','audit_logs') AND ENGINE='InnoDB'");$engine->execute(['db'=>RESET_DB]);if((int)$engine->fetchColumn()!==3)throw new RuntimeException('REQUIRED_INNODB_TABLES_MISSING');
}
function resetActor(PDO $db,int $actor): array {
    $GLOBALS['resetStep']='actor_roles';
    $q=$db->prepare("SELECT u.id,u.is_active,COALESCE(GROUP_CONCAT(r.name ORDER BY r.name SEPARATOR ','),'') roles FROM users u LEFT JOIN user_roles ur ON ur.user_id=u.id LEFT JOIN roles r ON r.id=ur.role_id WHERE u.id=:id GROUP BY u.id,u.is_active");$q->execute(['id'=>$actor]);$row=$q->fetch();
    if(!$row || (int)$row['is_active']!==1 || !in_array('admin',explode(',',(string)$row['roles']),true))throw new RuntimeException('ACTOR_NOT_ACTIVE_ADMIN');
    return $row;
}
function resetGrantCheck(PDO $db): array {
    $GLOBALS['resetStep']='account_grants';
    $resolved=GrantVerifier::currentUserGrants($db);
    $partialRevokes=(bool)$db->query('SELECT @@GLOBAL.partial_revokes')->fetchColumn();
    $result=GrantVerifier::evaluate($resolved['grants'],RESET_DB,BookingResetMaintenanceGrantPlan::rules(),$partialRevokes,$resolved['roles_resolved']);
    if($result['status']!=='PASS' || $result['missing_privileges']!==[] || $result['unexpected_privileges']!==[])throw new RuntimeException('RESET_MAINTENANCE_GRANTS_REJECTED');
    return $resolved['grants'];
}
function resetWindowsListenerProbe(): array {
    $failed=['exit_code'=>1,'stdout'=>'','stderr'=>'CHECK_FAILED','timed_out'=>false];
    if(PHP_OS_FAMILY!=='Windows')return $failed;
    $powershell=(string)getenv('SystemRoot').'\\System32\\WindowsPowerShell\\v1.0\\powershell.exe';
    if(!is_file($powershell))return $failed;
    $pipes=[];$process=null;
    try {
        // Array arguments bypass the shell. Neither the port nor command is user input.
        // The listener process does not need the maintenance database password.
        $environment=getenv();unset($environment['RESET_MAINTENANCE_DB_PASSWORD']);
        $process=proc_open([$powershell,'-NoProfile','-NonInteractive','-ExecutionPolicy','Bypass','-File',__DIR__.'/booking-reset-listener-check.ps1'],
            [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,__DIR__,$environment,['bypass_shell'=>true]);
        if(!is_resource($process))return $failed;
        fclose($pipes[0]);unset($pipes[0]);
        $deadline=microtime(true)+10.0;
        do {
            $status=proc_get_status($process);
            if(!$status['running'])break;
            if(microtime(true)>=$deadline){proc_terminate($process);$failed['timed_out']=true;return $failed;}
            usleep(20000);
        }while(true);
        // Read after exit; bounded output prevents unexpected command output from
        // becoming a valid result. A blocked/flooding child hits the deadline above.
        $stdout=stream_get_contents($pipes[1],256);
        $stderr=stream_get_contents($pipes[2],256);
        if(!is_string($stdout)||!is_string($stderr))return $failed;
        return ['exit_code'=>$status['exitcode'],'stdout'=>$stdout,'stderr'=>$stderr,'timed_out'=>false];
    } catch(Throwable) {return $failed;}
    finally {
        foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);
        if(is_resource($process))proc_close($process);
    }
}
function resetMaintenanceWindow(?callable $probe=null): void {
    $GLOBALS['resetStep']='maintenance_window';
    if(PHP_SAPI!=='cli')throw new RuntimeException('MAINTENANCE_CLI_REQUIRED');
    $result=($probe??'resetWindowsListenerProbe')();
    if(!is_array($result) || ($result['exit_code']??null)!==0 || ($result['timed_out']??null)!==false
        || !isset($result['stdout'],$result['stderr']) || !is_string($result['stdout']) || $result['stderr']!==''){
        throw new RuntimeException('MAINTENANCE_LISTENER_CHECK_UNVERIFIED');
    }
    if(preg_match('/\ALISTENER_PRESENT(?:\r?\n)?\z/D',$result['stdout']))throw new RuntimeException('MAINTENANCE_WEB_LISTENER_ACTIVE');
    if(!preg_match('/\ALISTENER_ABSENT(?:\r?\n)?\z/D',$result['stdout']))throw new RuntimeException('MAINTENANCE_LISTENER_CHECK_UNVERIFIED');
}
function resetViewDefinition(PDO $db): string {
    $GLOBALS['resetStep']='view_definition';
    $row=$db->query('SHOW CREATE VIEW calendar_events')->fetch(PDO::FETCH_NUM);
    if(!is_array($row) || count($row)<2 || $row[0]!=='calendar_events' || !is_string($row[1]) || !str_contains($row[1],'VIEW `calendar_events`'))throw new RuntimeException('CALENDAR_VIEW_DEFINITION_UNVERIFIED');
    return hash('sha256',$row[1]);
}
function resetViewReadInsideTransaction(PDO $db,string $expectedDefinitionHash): void {
    $GLOBALS['resetStep']='view_transaction_lock';
    if(!$db->inTransaction())throw new RuntimeException('CALENDAR_VIEW_TRANSACTION_REQUIRED');
    // A transaction that reads the view holds its metadata lock until rollback.
    $count=(int)$db->query('SELECT COUNT(*) FROM calendar_events')->fetchColumn();
    if($count!==0 || resetViewDefinition($db)!==$expectedDefinitionHash)throw new RuntimeException('CALENDAR_VIEW_CHANGED_DURING_REHEARSAL');
    resetViewDependencies($db);
}
function resetRows(PDO $db,bool $lock=false): array {
    $GLOBALS['resetStep']=$lock?'snapshot_lock':'snapshot_read';
    $ids=array_keys(resetSnapshot());$marks=implode(',',array_fill(0,count($ids),'?'));
    $sql="SELECT id,workflow_status,version,deleted_at FROM booking_requests WHERE id IN ($marks) ORDER BY id".($lock?' FOR UPDATE':'');
    $q=$db->prepare($sql);$q->execute($ids);$rows=[];foreach($q->fetchAll() as $r)$rows[(int)$r['id']]=$r;return $rows;
}
function resetRollbackRows(PDO $db): array {
    // Rehearsal must verify every explicitly modified field, including write-only tombstone fields.
    // Permission errors propagate before any write; preflight never calls this query.
    $GLOBALS['resetStep']='rollback_state_read';
    $ids=array_keys(resetSnapshot());$marks=implode(',',array_fill(0,count($ids),'?'));
    $q=$db->prepare("SELECT id,workflow_status,version,deleted_at,deleted_by,deletion_reason,updated_by FROM booking_requests WHERE id IN ($marks) ORDER BY id");
    $q->execute($ids);$rows=[];foreach($q->fetchAll() as $r)$rows[(int)$r['id']]=$r;return $rows;
}
function resetCounts(PDO $db): array {
    $GLOBALS['resetStep']='request_counts';
    $r=$db->query('SELECT COUNT(*) total,COALESCE(SUM(deleted_at IS NULL),0) active,COALESCE(SUM(deleted_at IS NOT NULL),0) deleted FROM booking_requests')->fetch();
    return ['total'=>(int)$r['total'],'active'=>(int)$r['active'],'deleted'=>(int)$r['deleted']];
}
function resetBlockers(PDO $db,bool $lock=false): array {
    $GLOBALS['resetStep']=$lock?'assignment_lock':'assignment_inventory';
    // MySQL 8.4 FOR SHARE requires SELECT, and under SERIALIZABLE the full scan
    // must hold shared next-key locks. Verify its insert/update blocking on host.
    $sql="SELECT id,booking_request_id,status FROM vehicle_assignments".($lock?' FORCE INDEX (PRIMARY) ORDER BY id FOR SHARE':' ORDER BY id');$all=$db->query($sql)->fetchAll();
    return ['all'=>$all,'blocking'=>array_values(array_filter($all,static fn($r)=>resetHasBlockingAssignments([$r])))];
}
function resetCalendarCount(PDO $db): int {
    $GLOBALS['resetStep']='calendar_view_read';
    // Equivalent row count to Migration 002 calendar_events; needs only base-table SELECT.
    // Do not use this filtered projection in place of resetBlockers().
    return (int)$db->query("SELECT COUNT(*) FROM vehicle_assignments a
        JOIN booking_requests b ON b.id = a.booking_request_id
        WHERE a.status IN ('active', 'completed') AND b.workflow_status IN ('assigned', 'completed')")->fetchColumn();
}
function resetAssessViewDependencies(array $rows): array {
    $expected=['booking_requests','vehicle_assignments'];$actual=[];$schemas=[];$reasons=[];
    foreach($rows as $row){
        if(!is_array($row) || array_keys($row)!==[0,1] || !is_string($row[0]) || !is_string($row[1])){
            $reasons[]='INVALID_ROW_SHAPE';continue;
        }
        // Only metadata identifiers are printed; never print arbitrary returned values.
        if(!preg_match('/\A[A-Za-z0-9_]+\z/D',$row[0]) || !preg_match('/\A[A-Za-z0-9_]+\z/D',$row[1])){
            $reasons[]='INVALID_IDENTIFIER';continue;
        }
        $schemas[]=$row[0];$actual[]=$row[1];
        if($row[0]!==RESET_DB)$reasons[]='SCHEMA_MISMATCH';
    }
    sort($actual,SORT_STRING);
    if(count($rows)!==2)$reasons[]=count($rows)===0?'NO_VISIBLE_DEPENDENCIES':'ROW_COUNT_MISMATCH';
    if($actual!==$expected)$reasons[]='TABLE_SET_MISMATCH';
    return ['pass'=>$reasons===[],'actual_count'=>count($rows),'actual_tables'=>$actual,
        'actual_schemas'=>$schemas,'expected_tables'=>$expected,'expected_schema'=>RESET_DB,
        'reasons'=>array_values(array_unique($reasons))];
}
function resetViewDependencies(PDO $db): array {
    $GLOBALS['resetStep']='view_dependencies';
    $query="SELECT TABLE_SCHEMA,TABLE_NAME FROM information_schema.VIEW_TABLE_USAGE WHERE VIEW_SCHEMA='up_van_booking_test' AND VIEW_NAME='calendar_events' ORDER BY TABLE_SCHEMA,TABLE_NAME";
    // Numeric fetch makes PDO column-label casing irrelevant. Object names remain case-sensitive.
    $result=resetAssessViewDependencies($db->query($query)->fetchAll(PDO::FETCH_NUM));
    echo 'VIEW_DEPENDENCIES='.json_encode($result,JSON_THROW_ON_ERROR)."\n";
    if(!$result['pass'])throw new RuntimeException('CALENDAR_VIEW_DEPENDENCIES_MISMATCH');
    return $result;
}
function resetPreservedCounts(PDO $db): array {
    $GLOBALS['resetStep']='preserved_record_counts';
    $sql="SELECT (SELECT COUNT(*) FROM approval_decisions) decisions,(SELECT COUNT(*) FROM booking_notifications) notifications,(SELECT COUNT(*) FROM booking_status_history) history,(SELECT COUNT(*) FROM audit_logs) audit";
    return $db->query($sql)->fetch();
}
function resetRunUpdates(PDO $db,int $actor): int {
    $GLOBALS['resetStep']='reset_update';
    $update=$db->prepare("UPDATE booking_requests SET deleted_at=UTC_TIMESTAMP(6),deleted_by=:deleted_actor,deletion_reason=:reason,updated_by=:updated_actor,version=version+1 WHERE id=:id AND deleted_at IS NULL AND version=:version AND workflow_status=:status");
    $audit=$db->prepare("INSERT INTO audit_logs(user_id,action,entity_type,entity_id,metadata) VALUES(:actor,'booking.test_reset','booking_request',:entity,JSON_OBJECT('reason',:reason,'workflow_status',:status,'from_version',:version,'to_version',:next_version))");
    $rows=resetRows($db,true);resetCheckSnapshot($rows);$n=0;
    foreach(resetSnapshot() as $id=>[$status,$version]){$GLOBALS['resetStep']='reset_update';$update->execute(['deleted_actor'=>$actor,'updated_actor'=>$actor,'reason'=>RESET_REASON,'id'=>$id,'version'=>$version,'status'=>$status]);if($update->rowCount()!==1)throw new RuntimeException("UPDATE_ROW_COUNT_ID_$id");$GLOBALS['resetStep']='reset_audit';$audit->execute(['actor'=>$actor,'entity'=>(string)$id,'reason'=>RESET_REASON,'status'=>$status,'version'=>$version,'next_version'=>$version+1]);if($audit->rowCount()!==1)throw new RuntimeException("AUDIT_ROW_COUNT_ID_$id");$n++;}
    if($n!==6)throw new RuntimeException('UPDATED_ROW_COUNT_MISMATCH');return $n;
}
function resetMain(array $argv): int {
    $GLOBALS['resetStep']='arguments';
    $mode=$argv[1]??'';$actor=0;if(isset($argv[2])&&preg_match('/^--actor-id=([1-9][0-9]*)$/D',$argv[2],$m))$actor=(int)$m[1];
    if(!in_array($mode,['preflight','rehearsal','apply'],true))throw new RuntimeException('RESET_MODE_REJECTED');
    if($mode==='apply'){
        if(count($argv)!==4 || $argv[3]!=='--approved-scope='.RESET_APPLY_SCOPE)throw new RuntimeException('EXPLICIT_APPLY_SCOPE_REQUIRED');
    }elseif(count($argv)!==3)throw new RuntimeException('RESET_ARGUMENTS_REJECTED');
    if($actor<1)throw new RuntimeException('ACTIVE_ADMIN_ACTOR_ID_REQUIRED');
    if($actor!==11)throw new RuntimeException('ACTOR_ID_11_REQUIRED');
    resetMaintenanceWindow();
    $GLOBALS['resetStep']='backup_hashes';$backup=resetBackupCheck();$db=resetConnect();$identity=resetIdentity($db);$grants=resetGrantCheck($db);resetSchema($db);$actorRow=resetActor($db,$actor);
    $rows=resetRows($db);resetCheckSnapshot($rows);$counts=resetCounts($db);if($counts!==['total'=>8,'active'=>6,'deleted'=>2])throw new RuntimeException('BOOKING_TOTALS_MISMATCH');
    $assign=resetBlockers($db);if($assign['blocking']!==[])throw new RuntimeException('ACTIVE_OR_COMPLETED_ASSIGNMENT_FOUND');
    $calendar=resetCalendarCount($db);if($calendar!==0)throw new RuntimeException('CALENDAR_EVENTS_NOT_EMPTY');
    $GLOBALS['resetStep']='maintenance_lock_status';
    $maintenance=(int)$db->query("SELECT IS_FREE_LOCK('".RESET_LOCK."')")->fetchColumn();if($maintenance!==1)throw new RuntimeException('RESET_MAINTENANCE_LOCK_BUSY');
    resetViewDependencies($db);
    $viewDefinition=resetViewDefinition($db);
    $preserved=resetPreservedCounts($db);
    echo "PREFLIGHT=PASS host={$identity['host']} port={$identity['port']} uuid={$identity['uuid']} database={$identity['db']} account={$identity['account']} actor_id={$actor} actor_roles={$actorRow['roles']}\n";
    foreach($backup as $name=>$b)echo "BACKUP_$name=PASS bytes={$b['bytes']} sha256={$b['sha256']}\n";
    echo 'COUNTS='.json_encode($counts,JSON_THROW_ON_ERROR)."\n";foreach($rows as $r)echo "TARGET={$r['id']}\t{$r['workflow_status']}\t{$r['version']}\n";
    echo 'ASSIGNMENTS='.count($assign['all']).' CALENDAR_EVENTS='.$calendar.' PRESERVED='.json_encode($preserved,JSON_THROW_ON_ERROR)."\n";
    if($mode==='preflight')return 0;

    // No write mode starts unless its complete before-state is readable.
    resetMaintenanceWindow();
    $rollbackRows=resetRollbackRows($db);
    $lock=$db->prepare('SELECT GET_LOCK(:name,0)');$lock->execute(['name'=>RESET_LOCK]);if((int)$lock->fetchColumn()!==1)throw new RuntimeException('RESET_MAINTENANCE_LOCK_UNAVAILABLE');
    try {
        $beforeCounts=resetCounts($db);$beforePreserved=resetPreservedCounts($db);$db->exec('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');$db->beginTransaction();$rolled=false;$committed=false;
        try{
            // Full ordered locking scans hold InnoDB next-key locks against concurrent request/assignment inserts.
            resetIdentity($db);resetActor($db,$actor);resetSchema($db);resetBackupCheck();
            resetMaintenanceWindow();
            resetViewReadInsideTransaction($db,$viewDefinition);
            $GLOBALS['resetStep']='request_table_lock';
            $db->query('SELECT id FROM booking_requests FORCE INDEX (PRIMARY) ORDER BY id FOR UPDATE')->fetchAll(PDO::FETCH_COLUMN);
            if(resetRollbackRows($db)!==$rollbackRows)throw new RuntimeException('ROLLBACK_BASELINE_CHANGED');
            $again=resetRows($db,true);resetCheckSnapshot($again);if(resetCounts($db)!==$beforeCounts)throw new RuntimeException('COUNTS_CHANGED_DURING_RESET');
            $ab=resetBlockers($db,true);if($ab['blocking']!==[] || count($ab['all'])!==count($assign['all']))throw new RuntimeException('ASSIGNMENT_CHANGED_DURING_RESET');
            if(resetCalendarCount($db)!==0)throw new RuntimeException('CALENDAR_CHANGED_DURING_RESET');
            $changed=resetRunUpdates($db,$actor);if($changed!==6)throw new RuntimeException('RESET_UPDATE_COUNT_MISMATCH');
            if(resetCounts($db)!==['total'=>8,'active'=>0,'deleted'=>8])throw new RuntimeException('RESET_POST_UPDATE_COUNTS_MISMATCH');
            resetCheckAppliedRows(resetRollbackRows($db),$actor);
            resetCheckPreservedCounts($beforePreserved,resetPreservedCounts($db),6);
            if($mode==='apply'){
                resetMaintenanceWindow();
                $GLOBALS['resetStep']='apply_commit';
                try{$db->commit();}catch(Throwable $error){echo "APPLY_COMMIT_OUTCOME=UNVERIFIED\n";throw $error;}
                $committed=true;
            }
        }finally{if($db->inTransaction()){$db->rollBack();$rolled=true;}}
        if($mode==='rehearsal'){
            if(!$rolled)throw new RuntimeException('ROLLBACK_NOT_CONFIRMED');
            if(resetCounts($db)!==$beforeCounts || resetRows($db)!==$rows || resetRollbackRows($db)!==$rollbackRows || resetPreservedCounts($db)!==$beforePreserved)throw new RuntimeException('POST_ROLLBACK_STATE_MISMATCH');
        }else{
            if(!$committed)throw new RuntimeException('APPLY_COMMIT_NOT_CONFIRMED');
            echo "APPLY_COMMITTED=YES updated=6\n";
            $GLOBALS['resetStep']='apply_postflight';
            resetIdentity($db);resetActor($db,$actor);
            $postCounts=resetCounts($db);if($postCounts!==['total'=>8,'active'=>0,'deleted'=>8])throw new RuntimeException('POST_RESET_COUNTS_MISMATCH');
            resetCheckAppliedRows(resetRollbackRows($db),$actor);
            $postAssignments=resetBlockers($db);if(count($postAssignments['all'])!==count($assign['all']) || $postAssignments['blocking']!==[])throw new RuntimeException('POST_RESET_ASSIGNMENT_MISMATCH');
            if(resetCalendarCount($db)!==0)throw new RuntimeException('POST_RESET_CALENDAR_MISMATCH');
            resetViewDependencies($db);
            if(resetViewDefinition($db)!==$viewDefinition || (int)$db->query('SELECT COUNT(*) FROM calendar_events')->fetchColumn()!==0)throw new RuntimeException('POST_RESET_VIEW_MISMATCH');
            $postPreserved=resetPreservedCounts($db);resetCheckPreservedCounts($beforePreserved,$postPreserved,6);
        }
    }finally{
        try { if($db->inTransaction())$db->rollBack(); }
        finally { $db->prepare('SELECT RELEASE_LOCK(:name)')->execute(['name'=>RESET_LOCK]); }
    }
    if($mode==='apply'){
        echo 'POSTFLIGHT=PASS counts='.json_encode($postCounts,JSON_THROW_ON_ERROR).' preserved='.json_encode($postPreserved,JSON_THROW_ON_ERROR)."\n";
        return 0;
    }
    echo "REHEARSAL=PASS updated_inside_transaction=6 rolled_back=true post_rollback_state=UNCHANGED\n";return 0;
}

    if(!defined('BOOKING_RESET_RUNNER_TEST')){
    try {
        $writeWindow=BookingWriteWindow::acquireMaintenance();
        try { $result=resetMain($argv); } finally { fclose($writeWindow); }
        exit($result);
    }
    catch(Throwable $e){resetFail(resetDiagnostic($e));}
}
