<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/cli.php';

use App\Database\ConnectionFactory;
use App\Database\GrantVerifier;
use App\Database\RequesterBookingReadGrantPlan;
use App\Database\BookingCalendarGrantPlan;

$result=['mode'=>'read_only','target'=>'up_van_booking_test','status'=>'NOT_VERIFIED','step'=>'CONFIG'];
try {
    $config=ConnectionFactory::config('auth_test');
    if ($config['host']!=='127.0.0.1' || $config['port']!=='3306' || $config['database']!=='up_van_booking_test' || $config['username']!=='up_van_auth_test') throw new RuntimeException('TARGET_REJECTED');
    $result['step']='PDO_CONNECT';
    $pdo=new PDO('mysql:host=127.0.0.1;port=3306;dbname=up_van_booking_test;charset=utf8mb4',$config['username'],$config['password'],[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES=>false,
        PDO::ATTR_TIMEOUT=>5,
        PDO::MYSQL_ATTR_MULTI_STATEMENTS=>false,
    ]);
    $result['step']='IDENTITY';
    $identity=$pdo->query('SELECT DATABASE() AS database_name, CURRENT_USER() AS account_name, VERSION() AS server_version, @@GLOBAL.partial_revokes AS partial_revokes')->fetch(PDO::FETCH_ASSOC);
    if (!is_array($identity) || $identity['database_name']!=='up_van_booking_test' || $identity['account_name']!=='up_van_auth_test@127.0.0.1' || !preg_match('/^8\.4\.\d+(?:[-.]|$)/D',(string)$identity['server_version'])) throw new RuntimeException('IDENTITY_REJECTED');
    $result['step']='GRANTS';
    $resolved=GrantVerifier::currentUserGrants($pdo);
    // System Log SELECT is already approved for this runtime account. Use the
    // full module union even when Booking grants are still missing, so the
    // diagnostic never mislabels approved audit_logs columns as unexpected.
    $evaluation=GrantVerifier::evaluate($resolved['grants'],'up_van_booking_test',BookingCalendarGrantPlan::rules(true),(bool)$identity['partial_revokes'],$resolved['roles_resolved']);
    $result['calendar_capability']=$evaluation['status'];
    $result['grant_status']=$evaluation['status'];
    $result['missing_privileges']=$evaluation['missing_privileges'];
    $result['unexpected_privileges']=$evaluation['unexpected_privileges'];
    $result['role_privileges_resolved']=$resolved['roles_resolved'];
    if ($evaluation['status']!=='PASS' || $result['calendar_capability']!=='PASS') { $result['status']='FAIL'; }
    else {
        $result['step']='SCHEMA';
        $q=$pdo->prepare('SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=:schema AND TABLE_NAME=:table_name');
        $q->execute(['schema'=>'up_van_booking_test','table_name'=>'booking_requests']);
        $names=array_map('strtolower',$q->fetchAll(PDO::FETCH_COLUMN));
        $missing=array_values(array_diff(RequesterBookingReadGrantPlan::COLUMNS,$names));
        $result['missing_columns']=$missing;
        if ($missing!==[]) {$result['status']='FAIL';}
        else {
            // Parse and authorize the exact read projection without returning a booking row.
            $pdo->query('SELECT '.implode(', ',RequesterBookingReadGrantPlan::COLUMNS).' FROM booking_requests WHERE 1=0')->closeCursor();
            $pdo->query('SELECT id, is_active, passenger_capacity FROM vehicles WHERE 1=0')->closeCursor();
            $pdo->query('SELECT vehicle_id, status, starts_at, ends_at FROM vehicle_assignments WHERE 1=0')->closeCursor();
            $result['status']='PASS';$result['step']='BOOKING_READ_CAPABILITY';
        }
    }
} catch (PDOException $error) {
    $result['status']='FAIL';$result['code']='PDO_ERROR';$result['sqlstate']=(string)$error->getCode();$result['native_code']=isset($error->errorInfo[1])?(int)$error->errorInfo[1]:null;
} catch (Throwable $error) {
    $result['status']='FAIL';$result['code']=$error instanceof RuntimeException && in_array($error->getMessage(),['TARGET_REJECTED','IDENTITY_REJECTED'],true)?$error->getMessage():'SAFE_VERIFICATION_FAILED';
}
echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR),PHP_EOL;
exit($result['status']==='PASS'?0:1);
