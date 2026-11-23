<?php
declare(strict_types=1);

require dirname(__DIR__) . '/scripts/lib/ProvisionPreflight.php';

final class FakeUpVanPreflightConnection implements UpVanProvisionReadOnlyConnection
{
    public function __construct(
        private string $identity = 'root@localhost',
        private ?array $schema = [
            'DEFAULT_CHARACTER_SET_NAME' => 'utf8mb4',
            'DEFAULT_COLLATION_NAME' => 'utf8mb4_unicode_ci',
        ],
        private bool $accountsExist = false,
    ) {
    }

    public function currentUser(): string { return $this->identity; }
    public function targetSchema(): ?array { return $this->schema; }
    public function targetAccountsExist(): bool { return $this->accountsExist; }
}

function assertPreflight(bool $condition, string $name): void
{
    if (!$condition) {
        throw new RuntimeException('Preflight offline test failed: ' . $name);
    }
    echo 'PASS: ' . $name . PHP_EOL;
}

$gates = array_fill_keys([
    'DB_PHASE_B_APPROVED',
    'TEST_DB_EXECUTION_APPROVED',
    'TEST_AUTH_EXECUTION_APPROVED',
    'TEST_ADMIN_PROVISION_APPROVED',
], 'false');
$passedConnect = static fn (): UpVanProvisionReadOnlyConnection => new FakeUpVanPreflightConnection();

$success = UpVanProvisionPreflight::run('up_van_booking_test', $gates, 'synthetic-secret', $passedConnect);
assertPreflight($success['status'] === 'PASS' && $success['step'] === 'COMPLETE', 'all checks pass using an offline fake connection');

$connectCalled = false;
$gateFailure = $gates;
$gateFailure['TEST_AUTH_EXECUTION_APPROVED'] = 'true';
$gateResult = UpVanProvisionPreflight::run('up_van_booking_test', $gateFailure, 'synthetic-secret', static function () use (&$connectCalled): never {
    $connectCalled = true;
    throw new RuntimeException('must not connect');
});
assertPreflight($gateResult['step'] === 'GATES' && $gateResult['code'] === 'EXECUTION_GATE_NOT_CLOSED' && !$connectCalled, 'gate failure blocks before PDO connect');

$pdoFailure = new PDOException('synthetic sensitive PDO message');
$pdoFailure->errorInfo = ['HY000', 2002, 'synthetic secret-like driver text'];
$pdoResult = UpVanProvisionPreflight::run('up_van_booking_test', $gates, 'synthetic-secret', static function () use ($pdoFailure): never {
    throw $pdoFailure;
});
$pdoJson = json_encode($pdoResult, JSON_THROW_ON_ERROR);
assertPreflight($pdoResult['step'] === 'PDO_CONNECT' && $pdoResult['sqlstate'] === 'HY000' && $pdoResult['native_code'] === 2002, 'PDO failure reports only stage, SQLSTATE, and native code');
assertPreflight(!str_contains($pdoJson, 'synthetic sensitive') && !str_contains($pdoJson, 'synthetic secret-like') && !str_contains($pdoJson, 'synthetic-secret'), 'PDO diagnostic redacts exception text and password');

$schemaMismatch = UpVanProvisionPreflight::run(
    'up_van_booking_test',
    $gates,
    'synthetic-secret',
    static fn (): UpVanProvisionReadOnlyConnection => new FakeUpVanPreflightConnection(
        schema: ['DEFAULT_CHARACTER_SET_NAME' => 'utf8mb4', 'DEFAULT_COLLATION_NAME' => 'utf8mb4_general_ci']
    )
);
assertPreflight($schemaMismatch['step'] === 'TARGET_SCHEMA' && $schemaMismatch['code'] === 'TARGET_SCHEMA_MISMATCH', 'wrong target schema is reported at TARGET_SCHEMA');

$accountExists = UpVanProvisionPreflight::run(
    'up_van_booking_test',
    $gates,
    'synthetic-secret',
    static fn (): UpVanProvisionReadOnlyConnection => new FakeUpVanPreflightConnection(accountsExist: true)
);
assertPreflight($accountExists['step'] === 'TARGET_ACCOUNTS' && $accountExists['code'] === 'TARGET_ACCOUNT_ALREADY_EXISTS', 'existing target account blocks at TARGET_ACCOUNTS');

$targetFailure = UpVanProvisionPreflight::run('up_van_booking', $gates, 'synthetic-secret', static function () use (&$connectCalled): never {
    $connectCalled = true;
    throw new RuntimeException('must not connect');
});
assertPreflight($targetFailure['step'] === 'CONFIG' && $targetFailure['code'] === 'TARGET_MISMATCH', 'wrong database target blocks before connect');

$missingPassword = UpVanProvisionPreflight::run('up_van_booking_test', $gates, '', static function () use (&$connectCalled): never {
    $connectCalled = true;
    throw new RuntimeException('must not connect');
});
assertPreflight($missingPassword['step'] === 'ROOT_PASSWORD_PRESENT' && $missingPassword['code'] === 'ROOT_PASSWORD_MISSING', 'missing root password blocks before connect');

echo "PASS: 8 offline preflight diagnostics checks; no database was contacted.\n";
