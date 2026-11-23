<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap/cli.php';

use App\Database\ConnectionFactory;
use App\Database\SafetyException;

function configCheck(bool $condition, string $label): void
{
    if (!$condition) throw new RuntimeException('Auth config offline regression failed: ' . $label);
    echo 'PASS: ' . $label . PHP_EOL;
}

function withProcessEnvironment(array $values, callable $callback): mixed
{
    $old = [];
    foreach ($values as $key=>$value) {
        $old[$key] = getenv($key);
        putenv($key . '=' . $value);
    }
    try { return $callback(); }
    finally {
        foreach ($old as $key=>$value) {
            if ($value === false) putenv($key);
            else putenv($key . '=' . $value);
        }
    }
}

function assertConfigRejected(string $purpose, array $values, string $expectedMessage, string $label): void
{
    $rejected = false;
    withProcessEnvironment($values, static function () use ($purpose, $expectedMessage, &$rejected): void {
        try { ConnectionFactory::config($purpose); }
        catch (SafetyException $error) { $rejected = $error->getMessage() === $expectedMessage; }
    });
    configCheck($rejected, $label);
}

$base = [
    'AUTH_TEST_DB_HOST'=>'127.0.0.1','AUTH_TEST_DB_PORT'=>'3306',
    'AUTH_TEST_DB_DATABASE'=>'up_van_booking_test','AUTH_TEST_DB_USERNAME'=>'up_van_auth_test',
    'AUTH_TEST_DB_PASSWORD'=>'synthetic-offline-only',
];
assertConfigRejected('auth_test', array_merge($base,['AUTH_TEST_DB_USERNAME'=>'']), 'MySQL account does not match connection purpose.', 'missing auth username is rejected before PDO');
assertConfigRejected('auth_test', array_merge($base,['AUTH_TEST_DB_HOST'=>'localhost']), 'MySQL account host must match 127.0.0.1.', 'invalid host is rejected before PDO');
assertConfigRejected('auth_test', array_merge($base,['AUTH_TEST_DB_PORT'=>'3306x']), 'Invalid database port.', 'invalid port is rejected before PDO');
assertConfigRejected('auth_test', array_merge($base,['AUTH_TEST_DB_DATABASE'=>'up_van_booking']), 'Database target rejected.', 'wrong database is rejected before PDO');

$override = withProcessEnvironment($base, static fn (): array => ConnectionFactory::config('auth_test'));
configCheck($override['username']==='up_van_auth_test' && $override['host']==='127.0.0.1' && $override['port']==='3306', 'process Environment override is applied to validated non-secret config');

$provisioner = [
    'TEST_PROVISIONER_DB_HOST'=>'127.0.0.1','TEST_PROVISIONER_DB_PORT'=>'3306',
    'TEST_PROVISIONER_DB_DATABASE'=>'up_van_booking_test','TEST_PROVISIONER_DB_USERNAME'=>'up_van_test_provisioner',
    'TEST_PROVISIONER_DB_PASSWORD'=>'synthetic-offline-only',
];
$provisionerConfig = withProcessEnvironment($provisioner, static fn (): array => ConnectionFactory::config('test_provisioner'));
configCheck($provisionerConfig['database']==='up_van_booking_test' && $provisionerConfig['username']==='up_van_test_provisioner', 'provisioner profile remains pinned to its test account and database');

echo "PASS: 6 auth configuration offline regression checks; no database contacted.\n";
