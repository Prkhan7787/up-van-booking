<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/cli.php';
use App\Database\{Restore006MigratorPreparation as Prepare,ConnectionFactory,Migration006GrantPlan};
$step='PHP_RUNTIME';
try {
    Prepare::assertRuntime();
    $step='ARGUMENTS';
    if(count($argv)!==3) throw new RuntimeException();
    $mode=$argv[1];$confirm=$argv[2];
    if(!in_array($mode,['preflight','execute','postflight'],true) || $confirm!==Prepare::CONFIRM) throw new RuntimeException();
    $grants=Prepare::plan(dirname(__DIR__).'/scripts/migration-006-schema005-migrator-grants.proposed.sql');
    $step='CONFIG';
    $config=ConnectionFactory::config('migration_test_006');
    $step='STDIN_INPUT';
    $input=json_decode(stream_get_contents(STDIN,65536),true,8,JSON_THROW_ON_ERROR);
    if(!is_array($input) || !is_string($input['root_password']??null) || $input['root_password']==='') throw new RuntimeException();
    $connect=static function(string $username,string $password):PDO {
        return new PDO('mysql:host=127.0.0.1;port=3307;dbname=up_van_booking_test;charset=utf8mb4',$username,$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_TIMEOUT=>5,PDO::MYSQL_ATTR_MULTI_STATEMENTS=>false]);
    };
    $service=new Prepare($connect,static fn(PDO $db):string=>Migration006GrantPlan::schemaPhase($db));
    $result=$service->run($mode,$confirm,$config,$input['root_password'],$grants);
    unset($input,$config);
    echo json_encode($result,JSON_THROW_ON_ERROR),PHP_EOL;
    exit($result['status']==='PASS'?0:1);
} catch(Throwable $e) {
    $safe=['PHP_VERSION_UNSUPPORTED','PHP_PDO_MYSQL_MISSING','PHP_MBSTRING_MISSING'];
    $code=in_array($e->getMessage(),$safe,true)?$e->getMessage():($e instanceof JsonException?'STDIN_JSON_INVALID':'LOCAL_PREPARATION_REJECTED');
    echo json_encode(['status'=>'FAIL','step'=>$step,'code'=>$code,'diagnostic_revision'=>Prepare::DIAGNOSTIC_REVISION,'automatic_retry'=>false]),PHP_EOL;exit(1);
}
