<?php
declare(strict_types=1);

interface UpVanProvisionReadOnlyConnection
{
    public function currentUser(): string;

    /** @return array{DEFAULT_CHARACTER_SET_NAME:string,DEFAULT_COLLATION_NAME:string}|null */
    public function targetSchema(): ?array;

    public function targetAccountsExist(): bool;
}

final class UpVanPdoProvisionReadOnlyConnection implements UpVanProvisionReadOnlyConnection
{
    public function __construct(private PDO $pdo)
    {
    }

    public function currentUser(): string
    {
        return (string) $this->pdo->query('SELECT CURRENT_USER()')->fetchColumn();
    }

    public function targetSchema(): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT DEFAULT_CHARACTER_SET_NAME, DEFAULT_COLLATION_NAME '
            . 'FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = :schema'
        );
        $statement->execute(['schema' => 'up_van_booking_test']);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public function targetAccountsExist(): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM mysql.user '
            . 'WHERE (User = :auth_user AND Host = :auth_host) '
            . 'OR (User = :provisioner_user AND Host = :provisioner_host)'
        );
        $statement->execute([
            'auth_user' => 'up_van_auth_test',
            'auth_host' => '127.0.0.1',
            'provisioner_user' => 'up_van_test_provisioner',
            'provisioner_host' => '127.0.0.1',
        ]);
        return (int) $statement->fetchColumn() !== 0;
    }
}

final class UpVanProvisionPreflight
{
    private const TARGET = 'up_van_booking_test';
    private const GATES = [
        'DB_PHASE_B_APPROVED',
        'TEST_DB_EXECUTION_APPROVED',
        'TEST_AUTH_EXECUTION_APPROVED',
        'TEST_ADMIN_PROVISION_APPROVED',
    ];

    /** @return array{status:string,step:string,code:string,sqlstate?:string,native_code?:int|null} */
    public static function run(mixed $target, array $gates, mixed $password, callable $connect): array
    {
        $step = 'CONFIG';
        try {
            if ($target !== self::TARGET) {
                return self::failure($step, 'TARGET_MISMATCH');
            }
            if (!extension_loaded('pdo_mysql')) {
                return self::failure($step, 'PDO_MYSQL_NOT_LOADED');
            }

            $step = 'GATES';
            foreach (self::GATES as $gate) {
                if (($gates[$gate] ?? null) !== 'false') {
                    return self::failure($step, 'EXECUTION_GATE_NOT_CLOSED');
                }
            }

            $step = 'ROOT_PASSWORD_PRESENT';
            if (!is_string($password) || $password === '') {
                return self::failure($step, 'ROOT_PASSWORD_MISSING');
            }

            $step = 'PDO_CONNECT';
            $connection = $connect();
            if (!$connection instanceof UpVanProvisionReadOnlyConnection) {
                return self::failure($step, 'CONNECTION_PROVIDER_INVALID');
            }

            $step = 'ROOT_IDENTITY';
            if (!str_starts_with($connection->currentUser(), 'root@')) {
                return self::failure($step, 'ROOT_IDENTITY_MISMATCH');
            }

            $step = 'TARGET_SCHEMA';
            $schema = $connection->targetSchema();
            if (!is_array($schema)
                || ($schema['DEFAULT_CHARACTER_SET_NAME'] ?? null) !== 'utf8mb4'
                || ($schema['DEFAULT_COLLATION_NAME'] ?? null) !== 'utf8mb4_unicode_ci') {
                return self::failure($step, 'TARGET_SCHEMA_MISMATCH');
            }

            $step = 'TARGET_ACCOUNTS';
            if ($connection->targetAccountsExist()) {
                return self::failure($step, 'TARGET_ACCOUNT_ALREADY_EXISTS');
            }

            return ['status' => 'PASS', 'step' => 'COMPLETE', 'code' => 'READ_ONLY_PREFLIGHT_PASS'];
        } catch (PDOException $error) {
            $info = $error->errorInfo ?? [];
            $sqlState = isset($info[0]) && is_string($info[0]) ? $info[0] : (string) $error->getCode();
            if (!preg_match('/\A[A-Z0-9]{5}\z/i', $sqlState)) {
                $sqlState = 'UNKNOWN';
            }
            $native = isset($info[1]) && is_numeric($info[1]) ? (int) $info[1] : null;
            return [
                'status' => 'FAIL',
                'step' => $step,
                'code' => 'PDO_ERROR',
                'sqlstate' => $sqlState,
                'native_code' => $native,
            ];
        } catch (Throwable) {
            return self::failure($step, 'SAFE_CHECK_FAILED');
        }
    }

    private static function failure(string $step, string $code): array
    {
        return ['status' => 'FAIL', 'step' => $step, 'code' => $code];
    }
}
