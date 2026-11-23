<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/bootstrap/cli.php';

use App\Database\MigrationTestConnectionDiagnostic as D;
use App\Database\ConnectionFactory;
use App\Database\SafetyException;

$n=0;
$ok=static function(bool $value,string $name)use(&$n):void{if(!$value)throw new RuntimeException('FAIL '.$name);$n++;};
$ok(D::configFailureCode('Database target rejected.')==='CONFIG_TARGET_MISMATCH','target config mismatch classified');
$ok(D::configFailureCode('MySQL account host must match 127.0.0.1.')==='CONFIG_HOST_MISMATCH','host policy failure classified');
$ok(D::configFailureCode('Dedicated project credentials are not configured.')==='CONFIG_PASSWORD_MISSING','missing password classified without showing value');
$ok(D::pdoFailureCode('28000',1045)==='AUTHENTICATION_FAILED','SQLSTATE 28000 native 1045 classified as auth failure');
$ok(D::pdoFailureCode('HY000',1045)==='AUTHENTICATION_FAILED','SQLSTATE HY000 native 1045 classified as auth failure');
$ok(D::pdoFailureCode('HY000',2002)==='PDO_CONNECT_FAILED','connection refusal is distinct from auth failure');
$ok(D::identityFailureCode('up_van_booking_test','up_van_test_migrator@127.0.0.1')===null,'expected test schema and account identity pass');
$ok(D::identityFailureCode('up_van_booking','up_van_test_migrator@127.0.0.1')==='DATABASE_IDENTITY_MISMATCH','wrong database classified');
$ok(D::identityFailureCode('up_van_booking_test','up_van_test_migrator@localhost')==='HOST_ACCOUNT_MISMATCH','matched MySQL host account mismatch classified');
$ok(D::grantFailureCode('Test migration account grants do not match the reviewed Migration 001–005 scope.')==='GRANT_GUARD_FAILED','privilege rejection is distinct from authentication');
$ok(D::grantFailureCode('untrusted detail')==='GRANT_CHECK_FAILED','unknown grant failure returns safe code');
$saved=[];
try {
    foreach (['MIGRATION_TEST_DB_HOST','MIGRATION_TEST_DB_PORT','MIGRATION_TEST_DB_DATABASE','MIGRATION_TEST_DB_USERNAME','MIGRATION_TEST_DB_PASSWORD'] as $key) $saved[$key]=getenv($key);
    putenv('MIGRATION_TEST_DB_HOST=127.0.0.1');
    putenv('MIGRATION_TEST_DB_PORT=3306');
    putenv('MIGRATION_TEST_DB_DATABASE=up_van_booking_test');
    putenv('MIGRATION_TEST_DB_USERNAME=up_van_test_migrator');
    putenv('MIGRATION_TEST_DB_PASSWORD=offline-placeholder-only');
    $config=ConnectionFactory::config('migration_test');
    $ok($config['host']==='127.0.0.1' && $config['port']==='3306' && $config['database']==='up_van_booking_test' && $config['username']==='up_van_test_migrator','ConnectionFactory resolves expected migration_test profile with process overrides');
    $ok($config['password']==='offline-placeholder-only','process password override is consumed in memory only');
    putenv('MIGRATION_TEST_DB_HOST=localhost');
    $hostRejected=false; try { ConnectionFactory::config('migration_test'); } catch (SafetyException $e) { $hostRejected=D::configFailureCode($e->getMessage())==='CONFIG_HOST_MISMATCH'; }
    $ok($hostRejected,'unsafe host override is rejected before PDO');
    $ok((new ReflectionMethod(ConnectionFactory::class,'openPdo'))->isPrivate(),'raw PDO opener remains private to ConnectionFactory');
} finally {
    foreach ($saved as $key=>$value) { if ($value===false) putenv($key); else putenv($key.'='.$value); }
}
$cli=file_get_contents(dirname(__DIR__,2).'/bin/diagnose-migration-test-connection.php');
$factory=file_get_contents(dirname(__DIR__,2).'/app/Database/ConnectionFactory.php');
$ok(str_contains($cli,'diagnoseMigrationTestConnection()') && str_contains($factory,"self::config('migration_test')") && str_contains($factory,'self::openPdo($config,true)'),'diagnostic uses pinned profile and shared PDO construction');
$ok(str_contains($factory,'self::assertScopedGrants($pdo,MigrationTarget::TEST,true)'),'diagnostic invokes existing strict grant guard before returning results');
$ok(str_contains($factory,"'password'=>'SET'") && !str_contains($factory,"'password'=>\$config['password']"),'diagnostic reports only password presence, never the value');
foreach(['INSERT ','UPDATE ','DELETE ','ALTER ','CREATE ','DROP ','GRANT ','REVOKE '] as $forbidden)$ok(!str_contains(strtoupper($cli),$forbidden),'diagnostic source excludes '.$forbidden);
$ok(!str_contains($cli,'fwrite(STDERR') && !str_contains($cli,"'exception'"),'diagnostic emits safe codes only and has no raw exception output path');
echo "PASS: $n migration test connection diagnostic offline checks; no database connected.",PHP_EOL;
