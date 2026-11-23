<?php
declare(strict_types=1);
require dirname(__DIR__) . '/scripts/recover-test-auth-credentials-execute.php';

final class RecoverySqlStatement extends PDOStatement
{
    public function __construct(private array $rows) {}
    public function execute(?array $params = null): bool { return true; }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed { return $this->rows[0] ?? false; }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return $this->rows; }
    public function fetchColumn(int $column = 0): mixed { return 0; }
}
final class RecoverySqlFakePdo extends PDO
{
    public array $sql = [];
    public function __construct(private int $failAt = 0) {}
    private function capture(string $sql): void {
        $this->sql[] = $sql;
        if (count($this->sql) === $this->failAt) {
            $error = new PDOException('synthetic sensitive message');
            $error->errorInfo = ['42000',1064,'synthetic sensitive SQL'];
            throw $error;
        }
    }
    public function prepare(string $query, array $options = []): PDOStatement|false {
        $this->capture($query);
        return new RecoverySqlStatement(str_contains($query,'SCHEMATA')
            ? [['DEFAULT_CHARACTER_SET_NAME'=>'utf8mb4','DEFAULT_COLLATION_NAME'=>'utf8mb4_unicode_ci']]
            : [['User'=>'up_van_auth_test','Host'=>'127.0.0.1'],['User'=>'up_van_test_provisioner','Host'=>'127.0.0.1']]);
    }
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
        $this->capture($query);
        return new RecoverySqlStatement(str_starts_with($query,'SHOW GRANTS')
            ? (str_contains($query,"'up_van_auth_test'") ? App\Database\AuthRuntimeGrantPlan::grantSql(false) : App\Database\TestProvisionerGrantPlan::grantSql(false)) : []);
    }
}
$steps=['TARGET_SCHEMA','TARGET_ACCOUNTS','PARTIAL_REVOKES','AUTH_ACCOUNT_GRANTS','PROVISIONER_ACCOUNT_GRANTS'];
foreach ($steps as $index=>$expected) {
    $step='INITIAL';
    try { assertRecoveryTargetAndGrants(new RecoverySqlFakePdo($index+1),$step); throw new RuntimeException('Expected synthetic error'); }
    catch (PDOException $error) {
        if ($step!==$expected || $error->errorInfo[1]!==1064) throw new RuntimeException('Incorrect SQL diagnostic stage: '.$step.' expected '.$expected);
        echo 'PASS: simulated 1064 identifies ', $expected, PHP_EOL;
    }
}
$pdo=new RecoverySqlFakePdo(); $step='INITIAL';
assertRecoveryTargetAndGrants($pdo,$step);
foreach ($pdo->sql as $sql) {
    if (!preg_match('/\A(?:SELECT|SHOW GRANTS)\b/', $sql) || str_contains($sql,';')) throw new RuntimeException('Preflight must use one read-only statement');
    if (str_starts_with($sql,'SHOW') && str_contains($sql,':')) throw new RuntimeException('Account names must not be bound as values');
}
if (count($pdo->sql)!==5) throw new RuntimeException('Unexpected preflight SQL path');
if ($pdo->sql[3] !== "SHOW GRANTS FOR 'up_van_auth_test'@'127.0.0.1'"
    || $pdo->sql[4] !== "SHOW GRANTS FOR 'up_van_test_provisioner'@'127.0.0.1'") {
    throw new RuntimeException('Fixed account or host quoting is incorrect');
}
echo 'PASS: fixed account SHOW GRANTS, value-only bindings and single read-only statements', PHP_EOL;
echo 'PASS: 6 SQL preflight offline checks; no MySQL contacted. Syntax on a real server remains unverified.', PHP_EOL;
