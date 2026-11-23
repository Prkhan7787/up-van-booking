<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap/cli.php';

use App\Database\ConnectionFactory;
use App\Database\GrantVerifier;
use App\Database\Migration004GrantPlan;
use App\Database\MigrationTarget;
use App\Database\SafetyException;

$result=['mode'=>'read_only_test_migrator_grant_verification','target'=>MigrationTarget::TEST,'status'=>'BLOCKED','database_accessed'=>false];
$pdo=null;
try {
    foreach ([MigrationTarget::PRODUCTION,MigrationTarget::TEST] as $target) {
        try { ConnectionFactory::requireMigrationApproval($target); throw new SafetyException('Migration gate must remain closed for grant verification.'); }
        catch (SafetyException $error) {
            if (str_contains($error->getMessage(),'not enabled')) continue;
            throw $error;
        }
    }
    $config=ConnectionFactory::config('migration_test');
    if ($config['database']!==MigrationTarget::TEST || $config['username']!=='up_van_test_migrator') throw new SafetyException('Test migrator identity precheck failed.');
    $dsn="mysql:host={$config['host']};port={$config['port']};dbname={$config['database']};charset=utf8mb4";
    $pdo=new PDO($dsn,$config['username'],$config['password'],[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES=>false,
        PDO::ATTR_TIMEOUT=>5,
        PDO::MYSQL_ATTR_MULTI_STATEMENTS=>false,
    ]);
    $result['database_accessed']=true;
    $pdo->exec('SET TRANSACTION READ ONLY');
    $pdo->beginTransaction();
    $identity=$pdo->query('SELECT DATABASE() AS database_name, CURRENT_USER() AS account_identity, VERSION() AS server_version')->fetch(PDO::FETCH_ASSOC);
    if (!is_array($identity) || $identity['database_name']!==MigrationTarget::TEST || $identity['account_identity']!=='up_van_test_migrator@127.0.0.1') throw new SafetyException('Test migrator connection identity mismatch.');
    $grantState=GrantVerifier::currentUserGrants($pdo);
    $grants=$grantState['grants'];
    $partialRevokes=(bool)$pdo->query('SELECT @@GLOBAL.partial_revokes')->fetchColumn();
    $result['identity']=['database'=>$identity['database_name'],'account'=>$identity['account_identity'],'server_version'=>$identity['server_version']];
    $result['grant_evaluation']=GrantVerifier::evaluate($grants,MigrationTarget::TEST,Migration004GrantPlan::rules(),$partialRevokes,$grantState['roles_resolved']);
    $result['grant_sql_review_only']=Migration004GrantPlan::sql($partialRevokes);
    $result['status']=$result['grant_evaluation']['status'];
} catch (Throwable $error) {
    $result['status']='BLOCKED';
    $result['error_type']=$error instanceof SafetyException?'safety_precondition':($error instanceof PDOException?'pdo_error':'verification_error');
    if ($error instanceof PDOException) {
        $result['sqlstate']=(string)$error->getCode();
        $result['native_code']=isset($error->errorInfo[1])?(int)$error->errorInfo[1]:null;
    }
} finally {
    if ($pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
}
echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE),PHP_EOL;
exit($result['status']==='PASS'?0:1);
