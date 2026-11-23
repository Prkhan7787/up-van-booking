<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/cli.php';
use App\Database\{ConnectionFactory,GrantVerifier,BookingCapabilityGrantGuard,BookingSubmissionGrantPlan,BookingOfficerReadGrantPlan,BookingOfficerEditGrantPlan,BookingApprovalGrantPlan};
$step='CONFIG';
try {
    $schemaOnly=in_array('--schema-only',$argv,true);
    $c=ConnectionFactory::config($schemaOnly?'migration_test':'auth_test');
    if($c['host']!=='127.0.0.1'||(int)$c['port']!==3306)throw new RuntimeException('TARGET_REJECTED');
    $step='CONNECT';
    $p=new PDO('mysql:host=127.0.0.1;port=3306;dbname=up_van_booking_test;charset=utf8mb4',$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_TIMEOUT=>5,PDO::ATTR_EMULATE_PREPARES=>false]);
    $step='IDENTITY';
    $i=$p->query('SELECT @@port AS port,@@server_uuid AS uuid,@@datadir AS datadir,DATABASE() AS db,CURRENT_USER() AS account,@@global.partial_revokes AS partial_revokes')->fetch(PDO::FETCH_ASSOC);
    if((int)$i['port']!==3306||$i['uuid']!=='77218fbd-d66d-11f1-9a0a-5c2886f057d5'||$i['db']!=='up_van_booking_test'||$i['account']!==($schemaOnly?'up_van_test_migrator@127.0.0.1':'up_van_auth_test@127.0.0.1'))throw new RuntimeException('IDENTITY_REJECTED');
    $step='SCHEMA_AND_GRANTS';
    $columns=$p->query("SELECT TABLE_NAME,COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='up_van_booking_test' AND TABLE_NAME IN ('booking_requests','vehicles','vehicle_assignments','booking_status_history','audit_logs','booking_reviews','approval_decisions','booking_notifications') ORDER BY TABLE_NAME,ORDINAL_POSITION")->fetchAll(PDO::FETCH_ASSOC);
    if($schemaOnly){echo json_encode(['identity'=>$i,'columns'=>$columns],JSON_PRETTY_PRINT).PHP_EOL;exit(0);}
    $privileges=$p->query("SELECT TABLE_NAME,COLUMN_NAME,PRIVILEGE_TYPE FROM information_schema.COLUMN_PRIVILEGES WHERE TABLE_SCHEMA='up_van_booking_test' AND GRANTEE=CONCAT(CHAR(39),'up_van_auth_test',CHAR(39),'@',CHAR(39),'127.0.0.1',CHAR(39)) ORDER BY TABLE_NAME,COLUMN_NAME,PRIVILEGE_TYPE")->fetchAll(PDO::FETCH_ASSOC);
    $grants=GrantVerifier::currentUserGrants($p);$fuel=false;$submitted=false;
    foreach($columns as $column){if($column['TABLE_NAME']==='vehicles'&&$column['COLUMN_NAME']==='fuel_type')$fuel=true;if($column['TABLE_NAME']==='booking_requests'&&$column['COLUMN_NAME']==='submitted_at')$submitted=true;}
    $snapshot=App\Database\RuntimeColumnProbe::inspect($p,'booking_requests','a4_request_data');
    $snapshotReady=$snapshot['status']==='PRESENT'&&$snapshot['readable']===true;
    $capabilities=[];
    $plans=['legacy_submission_grants'=>BookingSubmissionGrantPlan::rules(),'submission'=>BookingSubmissionGrantPlan::rules(true),'officer_read'=>BookingOfficerReadGrantPlan::rules($snapshotReady),'officer_edit'=>BookingOfficerEditGrantPlan::rules(true),
        'forward'=>BookingApprovalGrantPlan::rules('forward'),'section3_draft'=>BookingApprovalGrantPlan::rules('section3_draft'),
        'decision'=>BookingApprovalGrantPlan::rules('decision'),
        'notify'=>BookingApprovalGrantPlan::rules('notify'),
        'calendar'=>\App\Database\BookingCalendarGrantPlan::rules(),
        'admin_users'=>\App\Database\AdminUserGrantPlan::rules(),
        'vehicle_management'=>\App\Database\VehicleAdminGrantPlan::rules(false,false,true,false,true)];
    foreach($plans as $name=>$plan){
        $capabilities[$name]=BookingCapabilityGrantGuard::evaluate($grants['grants'],$plan,(bool)$i['partial_revokes'],$grants['roles_resolved'],$fuel);
        if(!$submitted)$capabilities[$name]['status']='SCHEMA_UNVERIFIED';
        if(in_array($name,['submission','officer_edit','forward','section3_draft','decision','notify'],true)&&!$snapshotReady)$capabilities[$name]['status']='SCHEMA_OR_SNAPSHOT_GRANTS_REQUIRED';
    }
    $modelDelivery=in_array('--model-result-delivery-grant-delta',$argv,true);
    if($modelDelivery || in_array('--model-approval-grant-delta', $argv, true)) {
        $step=$modelDelivery?'MODEL_RESULT_DELIVERY_GRANT_DELTA':'MODEL_APPROVAL_GRANT_DELTA';
        $file=$modelDelivery?'booking-result-delivery-grant-delta.proposed.sql':'booking-officer-approval-grant-delta.proposed.sql';
        $proposal=file_get_contents(dirname(__DIR__).'/scripts/'.$file);
        $sql=preg_replace('/^--.*$/m', '', $proposal);
        $statements=array_values(array_filter(array_map('trim', explode(';', preg_replace('/\s+/', ' ', $sql)))));
        $missing=[];
        foreach(($modelDelivery?['notify']:['officer_edit','forward','section3_draft','decision']) as $name) {
            $missing=array_merge($missing, $capabilities[$name]['missing_privileges']);
        }
        $added=[];
        foreach($statements as $statement) {
            if(!preg_match("/^GRANT (SELECT|UPDATE|INSERT) \(([^)]+)\) ON `up_van_booking_test`\.`([a-z_]+)` TO 'up_van_auth_test'@'127\.0\.0\.1'$/D", $statement, $match)) {
                throw new RuntimeException('PROPOSAL_SQL_FORMAT_INVALID');
            }
            foreach(explode(',', $match[2]) as $column) {
                if(!preg_match('/^`([a-z_0-9]+)`$/D', trim($column), $name)) throw new RuntimeException('PROPOSAL_COLUMN_INVALID');
                $added[]='column.'.$match[3].'.'.$name[1].':'.$match[1];
            }
        }
        if(count($added)!==count(array_unique($added)) || array_diff($added,$missing) || array_diff($missing,$added)) {
            throw new RuntimeException('PROPOSAL_DELTA_MISMATCH');
        }
        unset($plans['legacy_submission_grants']);
        if (!$modelDelivery) unset($plans['notify']);
        $modeled=[];
        foreach($plans as $name=>$plan) {
            $modeled[$name]=BookingCapabilityGrantGuard::evaluate(array_merge($grants['grants'],$statements),$plan,(bool)$i['partial_revokes'],$grants['roles_resolved'],$fuel);
        }
        $pass=!in_array('FAIL',array_column($modeled,'status'),true);
        echo json_encode(['status'=>$pass?'PASS':'FAIL','modeled_columns'=>count($added),'capabilities'=>array_map(static fn($result)=>$result['status'],$modeled)],JSON_PRETTY_PRINT).PHP_EOL;
        exit($pass?0:1);
    }
    echo json_encode(['identity'=>$i,'submitted_at_visible'=>$submitted,'a4_snapshot_probe'=>$snapshot,'columns'=>$columns,'column_privileges'=>$privileges,'grants'=>$grants,'capabilities'=>$capabilities],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
}catch(Throwable $e){echo json_encode(['status'=>'FAIL','step'=>$step,'sqlstate'=>$e instanceof PDOException?($e->errorInfo[0]??null):null,'native_code'=>$e instanceof PDOException?($e->errorInfo[1]??null):null]).PHP_EOL;exit(1);}
