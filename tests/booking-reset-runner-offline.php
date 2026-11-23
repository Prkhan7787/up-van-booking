<?php
declare(strict_types=1);
define('BOOKING_RESET_RUNNER_TEST',true);
require dirname(__DIR__).'/scripts/booking-reset-runner.php';
set_exception_handler(static function(Throwable $error): void {fwrite(STDERR,$error->getMessage().PHP_EOL);exit(1);});

// Simulate a column-scoped account: snapshot SELECT works, tombstone SELECT is denied.
final class ResetSnapshotStatement extends PDOStatement {
    public array $parameters=[];
    public function __construct(private array $rows) {}
    public function execute(?array $params=null): bool {$this->parameters=$params??[];return true;}
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args): array {return $this->rows;}
}
final class ResetSnapshotPdo extends PDO {
    public array $queries=[];
    public ?ResetSnapshotStatement $statement=null;
    public function __construct(private array $rows,private bool $allowRollbackRead=false) {}
    public function prepare(string $query,array $options=[]): PDOStatement|false {
        $this->queries[]=$query;
        if(!preg_match('/^SELECT (.+) FROM booking_requests WHERE id IN \(([^)]+)\) ORDER BY id(?: FOR UPDATE)?$/D',$query,$m))throw new RuntimeException('Unexpected snapshot SQL');
        if(!$this->allowRollbackRead && preg_match('/\b(deleted_by|deletion_reason|updated_by)\b/',$m[1],$column)){
            $error=new PDOException('column SELECT denied');$error->errorInfo=['42000',1143,"SELECT command denied for column '".$column[1]."' in table 'booking_requests'"];throw $error;
        }
        return $this->statement=new ResetSnapshotStatement($this->rows);
    }
}
final class ResetCalendarStatement extends PDOStatement {
    public function __construct(private array $rows,private int $count=0) {}
    public function fetchColumn(int $column=0): mixed {return $this->count;}
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args): array {return $this->rows;}
}
final class ResetCalendarPdo extends PDO {
    public function __construct(private array $assignments,private array $bookings,private string $viewFromClause,private bool $denyBase=false) {}
    public function query(string $query,?int $fetchMode=null,mixed ...$fetchModeArgs): PDOStatement|false {
        if(preg_match('/\bFROM calendar_events\b/i',$query)){
            $error=new PDOException('view SELECT denied');$error->errorInfo=['42000',1142,"SELECT command denied for table 'calendar_events'"];throw $error;
        }
        if($this->denyBase){$error=new PDOException('base SELECT denied');$error->errorInfo=['42000',1143,"SELECT command denied for column 'booking_request_id' in table 'vehicle_assignments'"];throw $error;}
        if($query==='SELECT id,booking_request_id,status FROM vehicle_assignments ORDER BY id')return new ResetCalendarStatement($this->assignments);
        $normal=static fn(string $sql)=>preg_replace('/\s+/',' ',trim($sql));
        if($normal($query)!==$normal('SELECT COUNT(*) '.$this->viewFromClause))throw new RuntimeException('Calendar query differs from the migration view join/filter');
        $count=0;foreach($this->assignments as $row){$booking=$this->bookings[$row['booking_request_id']]??null;if($booking!==null && in_array($row['status'],['active','completed'],true) && in_array($booking,['assigned','completed'],true))$count++;}
        return new ResetCalendarStatement([],$count);
    }
}
final class ResetDependencyStatement extends PDOStatement {
    public function __construct(private array $rows) {}
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args): array {
        if($mode!==PDO::FETCH_NUM)throw new RuntimeException('Dependency reader must not depend on column-label casing');
        return $this->rows;
    }
}
final class ResetDependencyPdo extends PDO {
    public function __construct(private array $rows) {}
    public function query(string $query,?int $fetchMode=null,mixed ...$fetchModeArgs): PDOStatement|false {
        $expected="SELECT TABLE_SCHEMA,TABLE_NAME FROM information_schema.VIEW_TABLE_USAGE WHERE VIEW_SCHEMA='up_van_booking_test' AND VIEW_NAME='calendar_events' ORDER BY TABLE_SCHEMA,TABLE_NAME";
        if($query!==$expected)throw new RuntimeException('Unexpected dependency SQL');
        return new ResetDependencyStatement($this->rows);
    }
}

$checks=0;
$assert=static function(bool $ok,string $message)use(&$checks):void{if(!$ok)throw new RuntimeException($message);$checks++;};
$expected=resetSnapshot();
$assert($expected===[29=>['awaiting_assignment',7],30=>['awaiting_assignment',5],31=>['awaiting_rejection_notice',7],32=>['awaiting_assignment',6],33=>['submitted',2],34=>['submitted',2]],'Reviewed host snapshot updates only ID 32');
$rows=[];foreach($expected as $id=>[$status,$version])$rows[$id]=['id'=>$id,'workflow_status'=>$status,'version'=>$version,'deleted_at'=>null];
resetCheckSnapshot($rows);$checks++;
foreach(['workflow_status'=>'submitted','version'=>2] as $field=>$oldValue){
    $bad=$rows;$bad[32][$field]=$oldValue;
    try{resetCheckSnapshot($bad);throw new LogicException('Stale ID 32 snapshot accepted');}
    catch(RuntimeException $e){$assert($e->getMessage()==='SNAPSHOT_MISMATCH_ID_32','ID 32 stale '.$field.' remains rejected');}
}
$snapshotDb=new ResetSnapshotPdo(array_values($rows));
foreach([false,true] as $lock){
    $actual=resetRows($snapshotDb,$lock);resetCheckSnapshot($actual);
    $assert($actual===$rows,'Column-scoped snapshot SELECT preserves the complete guard input');
    $assert($snapshotDb->statement?->parameters===array_keys($expected),'All six IDs remain bound parameters');
    $assert(str_ends_with($snapshotDb->queries[array_key_last($snapshotDb->queries)],$lock?' FOR UPDATE':'ORDER BY id'),'Snapshot lock mode is preserved');
}
try{resetRollbackRows($snapshotDb);throw new RuntimeException('Unreadable rollback fields accepted');}catch(PDOException $error){
    $assert(($error->errorInfo[1]??null)===1143,'Rollback permission error propagates');
    $assert($GLOBALS['resetStep']==='rollback_state_read','Missing rollback SELECT is identified separately from preflight');
}
$fullRows=$rows;foreach($fullRows as &$full){$full['deleted_by']=null;$full['deletion_reason']=null;$full['updated_by']=1;}unset($full);
$assert(resetRollbackRows(new ResetSnapshotPdo(array_values($fullRows),true))===$fullRows,'Readable rollback baseline retains deleted_by, deletion_reason and updated_by');
$bad=$rows;$bad[29]['version']=8;
try{resetCheckSnapshot($bad);throw new RuntimeException('Version mismatch was accepted.');}catch(RuntimeException $e){$assert($e->getMessage()==='SNAPSHOT_MISMATCH_ID_29','Version mismatch guard');}
$bad=$rows;unset($bad[34]);
try{resetCheckSnapshot($bad);throw new RuntimeException('Missing target was accepted.');}catch(RuntimeException $e){$assert($e->getMessage()==='SNAPSHOT_ROW_COUNT_MISMATCH','Target list count guard');}
$bad=$rows;$bad[32]['deleted_at']='2026-11-20 10:00:00';
try{resetCheckSnapshot($bad);throw new RuntimeException('Deleted target was accepted.');}catch(RuntimeException $e){$assert($e->getMessage()==='SNAPSHOT_MISMATCH_ID_32','Soft-deleted target guard');}
$assert(!resetHasBlockingAssignments([['status'=>'cancelled'],['status'=>'planned']]),'Nonblocking assignment statuses are allowed');
$assert(resetHasBlockingAssignments([['status'=>'active']]),'Active assignment blocks reset');
$assert(resetHasBlockingAssignments([['status'=>'completed']]),'Completed assignment blocks reset');
$bad=$rows;$bad[29]['deleted_at']='2026-11-20 10:00:00';
try{resetCheckSnapshot($bad);throw new RuntimeException('Repeated reset was accepted.');}catch(RuntimeException $e){$assert($e->getMessage()==='SNAPSHOT_MISMATCH_ID_29','Idempotency guard rejects already reset rows');}
$source=file_get_contents(dirname(__DIR__).'/scripts/booking-reset-runner.php');
$assert(is_string($source),'Runner source readable');
$assert(!preg_match('/\b(?:TRUNCATE|DELETE\s+FROM)\b/i',$source),'Runner has no truncate or hard delete');
$assert(str_contains($source,"'preflight','rehearsal','apply'"),'Only reviewed reset modes exist');
$assert(str_contains($source,'$db->rollBack()') && str_contains($source,'REHEARSAL=PASS'),'Rehearsal always rolls back and verifies');
$assert(str_contains($source,'SELECT GET_LOCK(:name,0)') && str_contains($source,'FOR UPDATE'),'Maintenance lock and row locks exist');
$assert(str_contains($source,'booking.test_reset') && str_contains($source,'deletion_reason'),'Audit plus soft-delete reason recorded');
$preflightEnd=strpos($source,"if(\$mode==='preflight')return 0;");
$assert($preflightEnd!==false,'Preflight exits before rehearsal');
$preflightStart=strpos($source,'function resetMain(array $argv)');
$assert($preflightStart!==false && $preflightEnd>$preflightStart,'Preflight source range is valid');
$preflightSource=substr($source,$preflightStart,$preflightEnd-$preflightStart);
$assert(!preg_match('/\b(?:INSERT\s+INTO|UPDATE\s+\w+\s+SET|DELETE\s+FROM|TRUNCATE\s+TABLE)\b/i',$preflightSource),'Preflight path contains no writes');
$GLOBALS['resetStep']='actor_roles';$denied=new PDOException('permission denied');$denied->errorInfo=['42000',1143,"SELECT command denied for column 'name' in table 'roles'"];$diagnostic=resetDiagnostic($denied);
$assert(str_contains($diagnostic,'step=actor_roles')&&str_contains($diagnostic,'sqlstate=42000')&&str_contains($diagnostic,'mysql_code=1143')&&str_contains($diagnostic,'column=name'),'Permission 1143 diagnostic is safe and actionable');
$assert(!str_contains($diagnostic,'permission denied'),'Raw database message is not exposed');
$GLOBALS['resetStep']='rehearsal_update';$clientError=new PDOException('parameter mismatch');$clientError->errorInfo=['HY093',null,null];
$clientDiagnostic=resetDiagnostic($clientError);
$assert($clientDiagnostic==='step=rehearsal_update sqlstate=HY093 mysql_code=UNKNOWN','Client-side PDO errors retain a safe step and SQLSTATE');
$assert(!str_contains($clientDiagnostic,'parameter mismatch'),'Client-side PDO message is not exposed');
$secretError=new RuntimeException('Password=do_not_print');
$assert(resetDiagnostic($secretError)==='step=rehearsal_update reason=UNCLASSIFIED_RUNTIME_ERROR','Unknown exception text is never copied into diagnostics');
$assert(preg_match('/\$update=\$db->prepare\("([^"]+)"\);/',$source,$updateSql)===1,'Rehearsal UPDATE SQL is available for parameter validation');
preg_match_all('/:[A-Za-z][A-Za-z0-9_]*/',$updateSql[1],$updateMarkers);
$assert(count($updateMarkers[0])===count(array_unique($updateMarkers[0])),'Native PDO UPDATE has no repeated named parameters');
$assert(str_contains($source,"'deleted_actor'=>\$actor,'updated_actor'=>\$actor"),'Both actor columns bind the same authorized actor independently');
$migration=file_get_contents(dirname(__DIR__).'/database/migrations/002_booking_design.sql');
if(!preg_match('/CREATE SQL SECURITY INVOKER VIEW calendar_events AS.*?(FROM vehicle_assignments a.*?);/s',$migration,$view))throw new RuntimeException('Calendar definition missing');
$calendarDb=new ResetCalendarPdo([],[],$view[1]);
try{$calendarDb->query('SELECT COUNT(*) FROM calendar_events');throw new RuntimeException('View SELECT unexpectedly allowed');}catch(PDOException $error){$assert(($error->errorInfo[1]??null)===1142,'Fixture rejects direct calendar view access with 1142');}
$assert(resetCalendarCount($calendarDb)===0,'Empty base-table projection works without View SELECT');
$assignments=[['id'=>1,'booking_request_id'=>29,'status'=>'active'],['id'=>2,'booking_request_id'=>30,'status'=>'completed'],['id'=>3,'booking_request_id'=>31,'status'=>'active'],['id'=>4,'booking_request_id'=>32,'status'=>'cancelled']];
$calendarDb=new ResetCalendarPdo($assignments,[29=>'assigned',30=>'completed',31=>'submitted',32=>'assigned'],$view[1]);
$assert(resetCalendarCount($calendarDb)===2,'Join/filter matches migration and counts multiple visible assignments');
$invisibleDb=new ResetCalendarPdo([['id'=>5,'booking_request_id'=>31,'status'=>'active']],[31=>'submitted'],$view[1]);
$assert(resetCalendarCount($invisibleDb)===0,'Active assignment for submitted booking is outside Calendar projection');
$assert(count(resetBlockers($invisibleDb)['blocking'])===1,'Separate guard still blocks an assignment invisible in Calendar');
try{resetCalendarCount(new ResetCalendarPdo([],[],$view[1],true));throw new RuntimeException('Missing base-table SELECT bypassed');}catch(PDOException $error){$assert(($error->errorInfo[1]??null)===1143,'Base-table permission errors stop the Calendar guard');}
$dependencies=[[RESET_DB,'vehicle_assignments'],[RESET_DB,'booking_requests']];
$assert(resetAssessViewDependencies($dependencies)['pass'],'Correct dependencies pass in reverse order');
$assert(resetAssessViewDependencies(array_reverse($dependencies))['pass'],'Correct dependencies pass in forward order');
foreach([
    [[], 'NO_VISIBLE_DEPENDENCIES'],
    [[[RESET_DB,'booking_requests']], 'ROW_COUNT_MISMATCH'],
    [[['another_db','vehicle_assignments'],[RESET_DB,'booking_requests']], 'SCHEMA_MISMATCH'],
    [[[RESET_DB,'BOOKING_REQUESTS'],[RESET_DB,'vehicle_assignments']], 'TABLE_SET_MISMATCH'],
    [[[RESET_DB,'booking_requests'],[RESET_DB,'booking_requests']], 'TABLE_SET_MISMATCH'],
    [[['TABLE_SCHEMA'=>RESET_DB,'TABLE_NAME'=>'booking_requests']], 'INVALID_ROW_SHAPE'],
] as [$dependencyRows,$reason]){
    $assessment=resetAssessViewDependencies($dependencyRows);
    $assert(!$assessment['pass'] && in_array($reason,$assessment['reasons'],true),'Dependency guard rejects '.$reason);
}
ob_start();try{$assessment=resetViewDependencies(new ResetDependencyPdo($dependencies));}finally{$output=ob_get_clean();}
$assert($assessment['pass'] && str_contains($output,'"actual_count":2') && str_contains($output,'"expected_tables"'),'Numeric PDO fetch and safe dependency diagnostic work together');
ob_start();try{
    try{resetViewDependencies(new ResetDependencyPdo([]));throw new LogicException('Invisible metadata was accepted');}
    catch(RuntimeException $error){$assert($error->getMessage()==='CALENDAR_VIEW_DEPENDENCIES_MISMATCH','Empty runtime metadata remains a hard failure');}
}finally{$output=ob_get_clean();}
$assert(str_contains($output,'"actual_tables":[]') && str_contains($output,'NO_VISIBLE_DEPENDENCIES'),'Invisible metadata is explained without bypassing the guard');
echo "PASS: booking reset runner offline checks ($checks assertions); no MySQL execution performed.\n";
