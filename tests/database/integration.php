<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/bootstrap/cli.php';
use App\Core\Env;
use App\Database\ConnectionFactory;
use App\Database\MigrationPlan;
use App\Database\MigrationRunner;
use App\Database\SafetyException;

if(array_slice($argv,1)!==['--execute','--confirm=up_van_booking_test']){
    echo "OFFLINE PLAN ONLY: FK, unique, CHECK, status, replacement history, boundary windows, vehicle/driver concurrency.\nNo database connection attempted.\n";exit;
}
if (Env::get('DB_PHASE_B_APPROVED') !== 'false') throw new SafetyException('Production gate must remain false.');
ConnectionFactory::requireMigrationApproval('up_van_booking_test');
$db=ConnectionFactory::connect('test');
function q(PDO $db,string $sql,array $params=[]):PDOStatement{
    global $runRows;
    $s=$db->prepare($sql);$s->execute($params);
    if(preg_match('/^INSERT INTO ([a-z_]+) /',$sql,$m) && $m[1]!=='user_roles')
        $runRows[$m[1]][]=(int)$db->lastInsertId();
    return $s;
}
function expectError(PDO $db,callable $action,int $code):void{
    $db->exec('SAVEPOINT constraint_case');$failed=false;
    try{$action();}catch(PDOException $e){$failed=(int)($e->errorInfo[1]??0)===$code;}
    $db->exec('ROLLBACK TO SAVEPOINT constraint_case');
    if(!$failed)throw new SafetyException('A constraint did not reject the synthetic test case as expected.');
}
function assign(PDO $db,int $booking,int $vehicle,int $driver,int $actor,?int $replaces=null):int{
    q($db,"INSERT INTO vehicle_assignments (booking_request_id,vehicle_id,driver_id,starts_at,ends_at,assigned_by,updated_by,replaces_assignment_id) VALUES (:b,:v,:d,'2099-01-01 09:00:00','2099-01-01 10:00:00',:a,:u,:r)",['b'=>$booking,'v'=>$vehicle,'d'=>$driver,'a'=>$actor,'u'=>$actor,'r'=>$replaces]);return(int)$db->lastInsertId();
}
function cancel(PDO $db,int $assignment,int $actor):void{
    q($db,"UPDATE vehicle_assignments SET status='cancelled',cancelled_at=CURRENT_TIMESTAMP(6),cancelled_by=:a,cancellation_reason='synthetic replacement',updated_by=:u WHERE id=:id",['a'=>$actor,'u'=>$actor,'id'=>$assignment]);
}
function pair(array $first,array $second):array{
    global $tag;
    $root=dirname(__DIR__,2);$processes=[];
    if(!function_exists('proc_open'))throw new SafetyException('Test worker startup failed [process_api_unavailable].');
    try{
        foreach([$first,$second] as $args){
            // PHP_BINARY is lossy under this Windows runtime when the project path contains Thai.
            // A project-relative executable is resolved from $root and avoids that broken path.
            $binary='.runtime/php84/php.exe';
            if(!is_file($root.DIRECTORY_SEPARATOR.$binary))throw new SafetyException('Test worker startup failed [runtime_binary_missing].');
            $command=[$binary,'-n','-d','extension_dir=.runtime/php84/ext','-d','extension=pdo_mysql','tests/database/assignment-worker.php',...array_map('strval',$args),$tag];
            $spawnWarning=null;
            set_error_handler(static function(int $severity,string $message)use(&$spawnWarning):bool{$spawnWarning=$message;return true;});
            try{$process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$root);}
            finally{restore_error_handler();}
            if(!is_resource($process)){
                $category=$spawnWarning!==null&&stripos($spawnWarning,'permission')!==false?'permission_denied':'process_creation_failed';
                throw new SafetyException('Test worker startup failed ['.$category.']; PHP Runtime path or process policy prevented startup.');
            }
            foreach($pipes as $pipe)stream_set_timeout($pipe,15);
            $processes[]=[$process,$pipes];
        }
        foreach($processes as [$process,$pipes]){
            $line=fgets($pipes[1]);$meta=stream_get_meta_data($pipes[1]);
            if(trim((string)$line)!=='READY'){
                $status=proc_get_status($process);
                $category=!empty($meta['timed_out'])?'ready_timeout':(!$status['running']?'worker_exited_before_ready':'invalid_ready_response');
                throw new SafetyException('Test worker startup failed ['.$category.']; inspect Worker phase and runtime configuration.');
            }
        }
        foreach($processes as [$process,$pipes]){fwrite($pipes[0],"GO\n");fclose($pipes[0]);}
        $outcomes=[];
        foreach($processes as [$process,$pipes]){
            $line=fgets($pipes[1]);$meta=stream_get_meta_data($pipes[1]);
            $diagnostics=stream_get_contents($pipes[2]);
            $result=json_decode((string)$line,true);
            if(!isset($result['outcome'])||$diagnostics!==''){
                $status=proc_get_status($process);
                $category=!empty($meta['timed_out'])?'result_timeout':(!$status['running']?'worker_exited_during_assignment':'invalid_worker_result');
                throw new SafetyException('Concurrent worker failed ['.$category.']; test run stopped; raw diagnostics withheld.');
            }
            $outcomes[]=$result['outcome'];
        }
        sort($outcomes);return $outcomes;
    }finally{
        foreach($processes as [$process,$pipes]){foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);if(is_resource($process)){ $status=proc_get_status($process);if($status['running'])proc_terminate($process);proc_close($process);}}
    }
}
require __DIR__.'/workflow-cases.php';
$runRows=[];
$actor=null;$vehicles=[];$drivers=[];$bookings=[];$runUsers=[];$runBookings=[];$tag=bin2hex(random_bytes(12));$checks=0;
$lockName='up_van_booking_test:stage_d_integration';
$locked=false;
echo 'RUN: ', $tag, PHP_EOL;
try{
    $locked=(int)q($db,'SELECT GET_LOCK(:name,0)',['name'=>$lockName])->fetchColumn()===1;
    if(!$locked)throw new SafetyException('Another integration harness holds the test-target lock.');
    $state=(new MigrationRunner($db,'up_van_booking_test'))->status();
    if(count($state['migrations'])!==3)throw new SafetyException('A fully migrated dedicated test database is required.');
    foreach($state['migrations'] as $i=>$m){$p=MigrationPlan::load()[$i];if($m['status']!=='applied'||!hash_equals($p['sha256'],$m['checksum']))throw new SafetyException('Test migration state mismatch.');}
    foreach(['users','vehicles','drivers','booking_requests','audit_logs','vehicle_assignments','approval_decisions','booking_reviews','booking_notifications','booking_passengers','booking_status_history','user_roles'] as $table){
        if((int)$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn()!==0)throw new SafetyException('Dedicated test data tables must be empty; existing records will not be changed.');
    }
    $db->beginTransaction();
    q($db,"INSERT INTO users (email,display_name,password_hash) VALUES (:email,'Synthetic DB test only',NULL)",['email'=>$tag.'@example.invalid']);$actor=(int)$db->lastInsertId();$runUsers[]=$actor;
    for($i=0;$i<5;$i++){
        q($db,"INSERT INTO vehicles (registration_number,registration_province,label,passenger_capacity,created_by,updated_by) VALUES (:r,'synthetic','Test only',12,:a,:u)",['r'=>$tag.$i,'a'=>$actor,'u'=>$actor]);$vehicles[]=(int)$db->lastInsertId();
        q($db,"INSERT INTO drivers (staff_code,display_name,created_by,updated_by) VALUES (:c,'Synthetic driver',:a,:u)",['c'=>$tag.$i,'a'=>$actor,'u'=>$actor]);$drivers[]=(int)$db->lastInsertId();
    }
    for($i=0;$i<9;$i++){
        q($db,"INSERT INTO booking_requests (requester_id,workflow_status,submitted_at,created_by,updated_by) VALUES (:r,'awaiting_assignment',CURRENT_TIMESTAMP(6),:a,:u)",['r'=>$actor,'a'=>$actor,'u'=>$actor]);$b=(int)$db->lastInsertId();$bookings[]=$b;
        q($db,"INSERT INTO approval_decisions (booking_request_id,decided_by,authority_kind,outcome,decided_at,returned_to_officer_at) VALUES (:b,:a,'director','approved','2098-01-01','2098-01-01')",['b'=>$b,'a'=>$actor]);
    }
    expectError($db,fn()=>q($db,"INSERT INTO booking_passengers (booking_request_id,display_name,created_by,updated_by) VALUES (18446744073709551614,'fake',:a,:u)",['a'=>$actor,'u'=>$actor]),1452);stageDAssert(true,'constraint.passenger_booking_fk');
    expectError($db,fn()=>q($db,"INSERT INTO users (email,display_name) VALUES (:e,'fake')",['e'=>$tag.'@example.invalid']),1062);stageDAssert(true,'constraint.unique_email');
    expectError($db,fn()=>q($db,'UPDATE vehicles SET passenger_capacity=0 WHERE id=:id',['id'=>$vehicles[0]]),3819);stageDAssert(true,'constraint.vehicle_capacity');
    expectError($db,fn()=>q($db,"UPDATE booking_requests SET workflow_status='invalid' WHERE id=:id",['id'=>$bookings[0]]),3819);stageDAssert(true,'constraint.workflow_status');
    expectError($db,fn()=>q($db,"UPDATE booking_requests SET expense_source='driver_personal_funds' WHERE id=:id",['id'=>$bookings[0]]),3819);stageDAssert(true,'constraint.invalid_expense');
    q($db,"UPDATE booking_requests SET expense_source='driver_extra_pay' WHERE id=:id",['id'=>$bookings[0]]);stageDAssert(q($db,'SELECT expense_source FROM booking_requests WHERE id=:id',['id'=>$bookings[0]])->fetchColumn()==='driver_extra_pay','constraint.valid_expense');
    $old=assign($db,$bookings[0],$vehicles[0],$drivers[0],$actor);
    expectError($db,fn()=>assign($db,$bookings[0],$vehicles[1],$drivers[1],$actor),1062);stageDAssert(true,'constraint.one_active_assignment');
    expectError($db,fn()=>q($db,'UPDATE vehicle_assignments SET ends_at=starts_at WHERE id=:id',['id'=>$old]),3819);stageDAssert(true,'constraint.assignment_window');
    cancel($db,$old,$actor);$replacement=assign($db,$bookings[0],$vehicles[1],$drivers[0],$actor,$old);
    cancel($db,$replacement,$actor);$last=assign($db,$bookings[0],$vehicles[1],$drivers[1],$actor,$replacement);
    $history=q($db,'SELECT vehicle_id,driver_id,status,replaces_assignment_id FROM vehicle_assignments WHERE booking_request_id=:b ORDER BY id',['b'=>$bookings[0]])->fetchAll();
    if(count($history)!==3||$history[0]['status']!=='cancelled'||(int)$history[1]['vehicle_id']!==$vehicles[1]||(int)$history[2]['driver_id']!==$drivers[1])throw new SafetyException('Synthetic replacement history check failed.');stageDAssert(true,'replacement.vehicle_and_driver');
    stageDAssert((int)$history[1]['replaces_assignment_id']===$old && (int)$history[2]['replaces_assignment_id']===$replacement,'replacement.exact_links');
    q($db,"UPDATE vehicle_assignments SET status='completed' WHERE id=:id",['id'=>$last]);
    if(q($db,"SELECT id FROM vehicle_assignments WHERE booking_request_id=:b AND status='active'",['b'=>$bookings[0]])->fetchColumn())throw new SafetyException('Completed synthetic assignment still reserves resources.');stageDAssert(true,'assignment.completed_release');
    stageDWorkflowCases($db,$tag,$actor,$vehicles[4],$drivers[4],$runUsers,$runBookings);
    $db->commit(); // Worker connections must see only these dedicated synthetic fixtures.
    $window=['2099-01-01 09:00:00','2099-01-01 10:00:00'];
    $cases=[
        [[$bookings[1],$vehicles[0],$drivers[0],$actor,...$window],[$bookings[2],$vehicles[0],$drivers[1],$actor,...$window],['assigned','conflict']],
        [[$bookings[3],$vehicles[1],$drivers[2],$actor,...$window],[$bookings[4],$vehicles[2],$drivers[2],$actor,...$window],['assigned','conflict']],
        [[$bookings[5],$vehicles[3],$drivers[3],$actor,...$window],[$bookings[6],$vehicles[3],$drivers[3],$actor,...$window],['assigned','conflict']],
        [[$bookings[7],$vehicles[4],$drivers[4],$actor,...$window],[$bookings[8],$vehicles[4],$drivers[4],$actor,'2099-01-01 10:00:00','2099-01-01 11:00:00'],['assigned','assigned']],
    ];
    foreach($cases as $index=>[$a,$b,$expected]){
        stageDAssert(pair($a,$b)===$expected,'concurrency.'.['vehicle','driver','both','boundary'][$index]);
        $assigned=0;$valid=true;
        foreach([$a[0],$b[0]] as $booking){
            $row=q($db,'SELECT workflow_status,version,updated_by FROM booking_requests WHERE id=:b',['b'=>$booking])->fetch();
            $rows=q($db,'SELECT assigned_by,status FROM vehicle_assignments WHERE booking_request_id=:b',['b'=>$booking])->fetchAll();
            $h=q($db,'SELECT from_status,to_status,changed_by,booking_version FROM booking_status_history WHERE booking_request_id=:b',['b'=>$booking])->fetchAll();
            $audit=q($db,"SELECT user_id,action FROM audit_logs WHERE entity_type='booking' AND entity_id=:b",['b'=>(string)$booking])->fetchAll();
            if($rows){
                $assigned++;
                $valid=$valid && count($rows)===1 && (int)$rows[0]['assigned_by']===$actor && $rows[0]['status']==='active' && $row['workflow_status']==='assigned' && (int)$row['version']===2 && (int)$row['updated_by']===$actor && count($h)===1 && $h[0]['from_status']==='awaiting_assignment' && $h[0]['to_status']==='assigned' && (int)$h[0]['changed_by']===$actor && (int)$h[0]['booking_version']===2 && count($audit)===1 && (int)$audit[0]['user_id']===$actor && $audit[0]['action']==='test.assign';
            }else{
                $valid=$valid && $row['workflow_status']==='awaiting_assignment' && (int)$row['version']===1 && !$h && !$audit;
            }
        }
        stageDAssert($valid && $assigned===($index===3?2:1),'concurrency.atomic_writes.'.($index+1));
    }
}finally{
    try {
        if($db->inTransaction())$db->rollBack();
        if($actor!==null){
            $db->beginTransaction();
            try{
                // All deletes intersect this run's recorded IDs with its synthetic owner.
                foreach($runUsers as $u){
                    $email=q($db,'SELECT email FROM users WHERE id=:id',['id'=>$u])->fetchColumn();
                    if($email!==false && !str_starts_with($email,$tag))throw new SafetyException('Synthetic ownership mismatch; cleanup stopped.');
                }
                $ownedBookings=array_merge($bookings,$runBookings);
                // Recover exact worker row IDs even if a worker committed but its response was lost.
                // The audit and both referenced rows commit atomically and carry this random run tag.
                $workerRows=q($db,"SELECT id,metadata FROM audit_logs WHERE user_id=:a AND action='test.assign' AND JSON_UNQUOTE(JSON_EXTRACT(metadata,'$.run'))=:tag",['a'=>$actor,'tag'=>$tag])->fetchAll();
                foreach($workerRows as $row){
                    $m=json_decode($row['metadata'],true,512,JSON_THROW_ON_ERROR);
                    foreach(['assignment_id','history_id'] as $key)if(!isset($m[$key]) || !is_int($m[$key]) || $m[$key]<1)throw new SafetyException('Worker cleanup inventory invalid.');
                    $runRows['vehicle_assignments'][]=$m['assignment_id'];
                    $runRows['booking_status_history'][]=$m['history_id'];
                    $runRows['audit_logs'][]=(int)$row['id'];
                }
                foreach(['audit_logs','booking_status_history','booking_notifications','booking_reviews','booking_passengers','vehicle_assignments','approval_decisions','booking_requests','drivers','vehicles'] as $table){
                    $ids=array_unique($runRows[$table]??[]);rsort($ids,SORT_NUMERIC);
                    foreach($ids as $id)q($db,'DELETE FROM '.$table.' WHERE id=:id',['id'=>$id]);
                }
                foreach($runUsers as $u){
                    // Only the one role fixture explicitly inserted for each synthetic user.
                    $role=$u===$actor?'vehicle_officer':($u===($runUsers[1]??null)?'requester':'approver');
                    q($db,'DELETE FROM user_roles WHERE user_id=:u AND role_id=(SELECT id FROM roles WHERE name=:r)',['u'=>$u,'r'=>$role]);
                }
                foreach($runUsers as $u)q($db,'DELETE FROM users WHERE id=:id AND email LIKE :tag',['id'=>$u,'tag'=>$tag.'%']);
                $db->commit();
                $residue=0;
                foreach($runRows as $table=>$ids)foreach(array_unique($ids) as $id)
                    $residue+=(int)q($db,'SELECT COUNT(*) FROM '.$table.' WHERE id=:id',['id'=>$id])->fetchColumn();
                foreach($ownedBookings as $b){
                    foreach(['booking_notifications','booking_reviews','booking_passengers','booking_status_history','vehicle_assignments','approval_decisions','calendar_events'] as $table)
                        $residue+=(int)q($db,'SELECT COUNT(*) FROM '.$table.' WHERE booking_request_id=:b',['b'=>$b])->fetchColumn();
                    $residue+=(int)q($db,'SELECT COUNT(*) FROM booking_requests WHERE id=:b',['b'=>$b])->fetchColumn();
                }
                foreach($runUsers as $u){
                    foreach(['audit_logs'=>'user_id','user_roles'=>'user_id','users'=>'id'] as $table=>$column)
                        $residue+=(int)q($db,'SELECT COUNT(*) FROM '.$table.' WHERE '.$column.'=:id',['id'=>$u])->fetchColumn();
                }
                foreach(['vehicles'=>$vehicles,'drivers'=>$drivers] as $table=>$ids)
                    foreach($ids as $id)$residue+=(int)q($db,'SELECT COUNT(*) FROM '.$table.' WHERE id=:id',['id'=>$id])->fetchColumn();
                stageDAssert($residue===0,'cleanup.no_run_residue');
            }catch(Throwable){
                if($db->inTransaction())$db->rollBack();
                throw new SafetyException('Synthetic cleanup incomplete for run '.$tag.'; inspect only this run, without resetting the database.');
            }
        }
    } finally {
        if($locked && (int)q($db,'SELECT RELEASE_LOCK(:name)',['name'=>$lockName])->fetchColumn()!==1)
            throw new SafetyException('Test harness lock release was not confirmed.');
    }
}
echo "PASS: $checks integration checks on dedicated test database; synthetic cleanup verified; harness only, not booking E2E.\n";
