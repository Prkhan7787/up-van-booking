<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') {http_response_code(404);exit(1);}
require dirname(__DIR__).'/bootstrap/cli.php';
use App\Database\ConnectionFactory;
use App\Database\SafeDatabaseException;
use App\Database\SystemLogGrantPlan;
use App\Database\GrantVerifier;
use App\Repositories\PdoSystemLogRepository;
$step='CONFIG';
try {
    $config=ConnectionFactory::config('auth_test');
    if ($config['database']!=='up_van_booking_test' || $config['username']!=='up_van_auth_test') throw new \RuntimeException();
    $step='CONNECT'; $db=ConnectionFactory::connect('auth_test');
    $step='GRANTS';
    $resolved=GrantVerifier::currentUserGrants($db);
    $partialRevokes=(bool)$db->query('SELECT @@GLOBAL.partial_revokes')->fetchColumn();
    $evaluation=GrantVerifier::evaluate($resolved['grants'],'up_van_booking_test',SystemLogGrantPlan::rules(),$partialRevokes,$resolved['roles_resolved']);
    if ($evaluation['status']!=='PASS') {
        echo json_encode(['status'=>$evaluation['status'],'step'=>'GRANTS','code'=>'SYSTEM_LOG_GRANTS_NOT_READY','missing_privileges'=>$evaluation['missing_privileges'],'unexpected_privileges'=>$evaluation['unexpected_privileges'],'roles_resolved'=>$resolved['roles_resolved']]),PHP_EOL;
        exit(1);
    }
    new PdoSystemLogRepository($db);
    $step='READ_PERMISSION';
    $query=$db->prepare('SELECT '.implode(', ',SystemLogGrantPlan::COLUMNS).' FROM audit_logs WHERE 1=0'); $query->execute();
    echo json_encode(['status'=>'PASS','target'=>'up_van_booking_test','system_logs_capability'=>'PASS','data_returned'=>false]),PHP_EOL;
} catch (Throwable $error) {
    $report=['status'=>'FAIL','step'=>$step,'code'=>'SYSTEM_LOG_CAPABILITY_UNVERIFIED'];
    if ($error instanceof PDOException) $error=SafeDatabaseException::fromPdo($error);
    if ($error instanceof SafeDatabaseException) {$report['sqlstate']=$error->sqlState;$report['native_code']=$error->nativeCode;}
    echo json_encode($report),PHP_EOL; exit(1);
}
