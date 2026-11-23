<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/Database/SafetyException.php';
require dirname(__DIR__) . '/app/Database/SafeDatabaseException.php';
require dirname(__DIR__) . '/app/Database/AuthCredentialReadOnlyCheck.php';

use App\Database\AuthCredentialReadOnlyCheck;
use App\Database\SafetyException;

function authCheck(bool $condition, string $name): void
{
    if (!$condition) throw new RuntimeException('Auth credential offline test failed: ' . $name);
    echo 'PASS: ' . $name . PHP_EOL;
}

$good = static fn (): array => [
    'database'=>'up_van_booking_test',
    'identity'=>'up_van_auth_test@127.0.0.1',
    'grants_verified'=>true,
];
$pass = AuthCredentialReadOnlyCheck::run('up_van_booking_test','up_van_auth_test@127.0.0.1',$good);
authCheck($pass['status']==='PASS' && $pass['grants']==='PASS','expected identity/database and verified grants pass');

$credentialFailure = new PDOException('synthetic password or credential detail');
$credentialFailure->errorInfo = ['28000',1045,'synthetic secret detail'];
$badCredential = AuthCredentialReadOnlyCheck::run('up_van_booking_test','up_van_auth_test@127.0.0.1',static function () use ($credentialFailure): never { throw $credentialFailure; });
$credentialJson = json_encode($badCredential, JSON_THROW_ON_ERROR);
authCheck($badCredential['step']==='PDO_CONNECT_OR_VERIFY' && $badCredential['sqlstate']==='28000' && $badCredential['native_code']===1045,'credential mismatch reports only SQLSTATE and native code');
authCheck(!str_contains($credentialJson,'synthetic') && !str_contains($credentialJson,'password'),'PDO diagnostics redact raw driver details');

$connectionFailure = new PDOException('synthetic host or DSN detail');
$connectionFailure->errorInfo = ['HY000',2002,'synthetic connection detail'];
$connectFailed = AuthCredentialReadOnlyCheck::run('up_van_booking_test','up_van_auth_test@127.0.0.1',static function () use ($connectionFailure): never { throw $connectionFailure; });
authCheck($connectFailed['sqlstate']==='HY000' && $connectFailed['native_code']===2002,'connection failure reports safe PDO error codes');

$wrongDatabase = AuthCredentialReadOnlyCheck::run('up_van_booking_test','up_van_auth_test@127.0.0.1',static fn (): array => [
    'database'=>'up_van_booking','identity'=>'up_van_auth_test@127.0.0.1','grants_verified'=>true,
]);
authCheck($wrongDatabase['step']==='IDENTITY' && $wrongDatabase['code']==='WRONG_DATABASE','wrong database is denied');

$wrongAccount = AuthCredentialReadOnlyCheck::run('up_van_booking_test','up_van_auth_test@127.0.0.1',static fn (): array => [
    'database'=>'up_van_booking_test','identity'=>'other@127.0.0.1','grants_verified'=>true,
]);
authCheck($wrongAccount['code']==='WRONG_ACCOUNT','credential identity mismatch is denied');

$unverifiedGrants = AuthCredentialReadOnlyCheck::run('up_van_booking_test','up_van_auth_test@127.0.0.1',static fn (): array => [
    'database'=>'up_van_booking_test','identity'=>'up_van_auth_test@127.0.0.1','grants_verified'=>false,
]);
authCheck($unverifiedGrants['step']==='GRANTS' && $unverifiedGrants['code']==='GRANTS_NOT_VERIFIED','unverified grants never pass');

$grantRejected = AuthCredentialReadOnlyCheck::run('up_van_booking_test','up_van_auth_test@127.0.0.1',static function (): never {
    throw new SafetyException('synthetic grant detail must not escape');
});
authCheck($grantRejected['step']==='CONNECTION_OR_GRANTS' && $grantRejected['code']==='SAFE_CONNECTION_REJECTED','ConnectionFactory policy rejection is reported without internal details');

echo "PASS: 8 offline credential verification checks; no database contacted.\n";
