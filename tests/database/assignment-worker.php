<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/bootstrap/cli.php';
use App\Core\Env;
use App\Database\ConnectionFactory;
use App\Database\SafetyException;
if(Env::get('DB_PHASE_B_APPROVED')!=='false')throw new SafetyException('Production gate must remain false.');
ConnectionFactory::requireMigrationApproval('up_van_booking_test');
if(Env::get('TEST_DB_EXECUTION_APPROVED')!=='true')throw new SafetyException('Test execution is disabled.');
$args=array_slice($argv,1);
if(count($args)!==7 || count(array_filter(array_slice($args,0,4),'ctype_digit'))!==4)throw new SafetyException('Invalid test worker arguments.');
[$booking,$vehicle,$driver,$actor,$start,$end,$tag]=$args;
if(!preg_match('/^[a-f0-9]{24}$/D',$tag))throw new SafetyException('Invalid synthetic run tag.');
foreach([$start,$end] as $time)if(!preg_match('/^2099-01-01 \d{2}:\d{2}:\d{2}$/D',$time))throw new SafetyException('Invalid synthetic test window.');
$db=ConnectionFactory::connect('test');
function query(PDO $db,string $sql,array $params=[]):PDOStatement{$q=$db->prepare($sql);$q->execute($params);return $q;}
if(!$db->query("SELECT IS_USED_LOCK('up_van_booking_test:stage_d_integration')")->fetchColumn() || query($db,'SELECT email FROM users WHERE id=:a',['a'=>$actor])->fetchColumn()!==$tag.'@example.invalid')throw new SafetyException('Worker requires its active synthetic harness.');
echo "READY\n";flush();
if(trim((string)fgets(STDIN))!=='GO')throw new SafetyException('Worker barrier aborted.');
$db->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
$db->exec('SET SESSION innodb_lock_wait_timeout=5');
$db->beginTransaction();
try{
    $b=query($db,'SELECT workflow_status, version, created_by FROM booking_requests WHERE id=:id FOR UPDATE',['id'=>$booking])->fetch();
    if(!$b || (int)$b['created_by']!==(int)$actor || $b['workflow_status']!=='awaiting_assignment')throw new SafetyException('Synthetic booking is not ready.');
    if(query($db,"SELECT outcome FROM approval_decisions WHERE booking_request_id=:id",['id'=>$booking])->fetchColumn()!=='approved')throw new SafetyException('Synthetic booking lacks approval.');
    if(!query($db,'SELECT id FROM vehicles WHERE id=:id AND is_active=1 FOR UPDATE',['id'=>$vehicle])->fetchColumn())throw new SafetyException('Synthetic vehicle unavailable.');
    if(!query($db,'SELECT id FROM drivers WHERE id=:id AND is_active=1 FOR UPDATE',['id'=>$driver])->fetchColumn())throw new SafetyException('Synthetic driver unavailable.');
    $conflict=false;
    foreach([['vehicle_id',$vehicle],['driver_id',$driver]] as [$column,$id]){
        // Column is an internal literal from the fixed list, never user input.
        $conflict=$conflict || (bool)query($db,"SELECT id FROM vehicle_assignments WHERE $column=:id AND status='active' AND starts_at < :ends AND ends_at > :starts FOR UPDATE",['id'=>$id,'ends'=>$end,'starts'=>$start])->fetchColumn();
    }
    if($conflict){$db->rollBack();echo json_encode(['outcome'=>'conflict']),"\n";exit;}
    query($db,'INSERT INTO vehicle_assignments (booking_request_id,vehicle_id,driver_id,starts_at,ends_at,assigned_by,updated_by) VALUES (:b,:v,:d,:s,:e,:a,:u)',['b'=>$booking,'v'=>$vehicle,'d'=>$driver,'s'=>$start,'e'=>$end,'a'=>$actor,'u'=>$actor]);
    $assignmentId=(int)$db->lastInsertId();
    query($db,"UPDATE booking_requests SET workflow_status='assigned', version=version+1, updated_by=:a WHERE id=:b",['a'=>$actor,'b'=>$booking]);
    query($db,"INSERT INTO booking_status_history (booking_request_id,from_status,to_status,changed_by,booking_version) VALUES (:b,'awaiting_assignment','assigned',:a,:v)",['b'=>$booking,'a'=>$actor,'v'=>(int)$b['version']+1]);
    $historyId=(int)$db->lastInsertId();
    query($db,"INSERT INTO audit_logs (user_id,action,entity_type,entity_id,metadata) VALUES (:a,'test.assign','booking',:b,:metadata)",['a'=>$actor,'b'=>(string)$booking,'metadata'=>json_encode(['run'=>$tag,'assignment_id'=>$assignmentId,'history_id'=>$historyId],JSON_THROW_ON_ERROR)]);
    $db->commit();echo json_encode(['outcome'=>'assigned']),"\n";
}catch(Throwable){if($db->inTransaction())$db->rollBack();throw new SafetyException('Concurrency test worker failed; inspect the synthetic test run.');}
