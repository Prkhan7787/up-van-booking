<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap/cli.php';

use App\Core\Env;
use App\Database\AuthRuntimeGrantPlan;
use App\Database\ConnectionFactory;
use App\Database\GrantVerifier;
use App\Database\MigrationTarget;
use App\Database\SafeDatabaseException;
use App\Database\TestProvisionerGrantPlan;
final class AuthCredentialRecoveryStateMachine
{
    public static function targetAllowed(string $target): bool
    {
        return $target === 'up_van_booking_test';
    }

    private const NEXT = [
        'PREPARED'=>['ENV_SYNCED'], 'ENV_SYNCED'=>['AUTH_UPDATED'],
        'AUTH_UPDATED'=>['PROVISIONER_UPDATED'], 'PROVISIONER_UPDATED'=>['VERIFIED'],
        'VERIFIED'=>['COMPLETE'], 'COMPLETE'=>[],
    ];

    public static function next(string $state, string $next): bool
    {
        return in_array($next, self::NEXT[$state] ?? [], true);
    }

    public static function decision(?string $state, bool $credentialsPresent, bool $accountsExist): string
    {
        if (!$accountsExist) return 'BLOCK_ACCOUNTS_NOT_COMPLETE';
        if ($state === null) return $credentialsPresent ? 'BLOCK_CREDENTIALS_WITHOUT_STATE' : 'START_NEW';
        if (!array_key_exists($state, self::NEXT)) return 'BLOCK_INVALID_STATE';
        if ($state === 'COMPLETE') return 'VERIFY_AND_STOP';
        return 'RESUME_SAME_CREDENTIAL_SET';
    }

    public static function accountDecision(bool $accountExists, bool $credentialMatches, ?int $nativePdoCode): string
    {
        if (!$accountExists) return 'BLOCK_ACCOUNT_MISSING';
        if ($credentialMatches) return 'KEEP_PASSWORD_UNCHANGED';
        if ($nativePdoCode === 1045) return 'SET_STORED_PASSWORD';
        return 'BLOCK_NO_PASSWORD_CHANGE';
    }

    public static function actionAllowed(string $state, string $action): bool
    {
        return match ($action) {
            'reconcile-auth' => $state === 'ENV_SYNCED',
            'reconcile-provisioner' => $state === 'AUTH_UPDATED',
            'verify' => in_array($state,['PROVISIONER_UPDATED','VERIFIED','COMPLETE'],true),
            'preflight', 'root-check' => true,
            default => false,
        };
    }

    public static function authenticationFailure(string $sqlState, ?int $nativeCode, bool $duringConnect): bool
    {
        return $duringConnect && $nativeCode === 1045 && in_array($sqlState, ['28000','HY000'], true);
    }
}

function recoverOutput(string $status, string $step, string $code, array $extra = []): never
{
    echo json_encode(['status'=>$status,'step'=>$step,'code'=>$code] + $extra + ['account'=>null,'sqlstate'=>null,'native_code'=>null], JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit($status === 'PASS' ? 0 : 1);
}

function recoverRootConnection(string &$sqlStep): PDO
{
    $password = getenv('UP_VAN_RECOVERY_ROOT_PASSWORD');
    if (!is_string($password) || $password === '') throw new RuntimeException('ROOT_PASSWORD_MISSING');
    $sqlStep = 'PDO_CONNECT';
    $pdo = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', $password, [
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES=>false,
        PDO::ATTR_TIMEOUT=>5,
        PDO::MYSQL_ATTR_MULTI_STATEMENTS=>false,
    ]);
    $sqlStep = 'ROOT_IDENTITY';
    $identity = (string)$pdo->query('SELECT CURRENT_USER()')->fetchColumn();
    if (!str_starts_with($identity, 'root@')) throw new RuntimeException('ROOT_IDENTITY_MISMATCH');
    $sqlStep = 'MYSQL_VERSION';
    $version = (string)$pdo->query('SELECT VERSION()')->fetchColumn();
    if (!preg_match('/^8\.4\.\d+(?:[-.]|$)/D', $version)) throw new RuntimeException('MYSQL_VERSION_UNSUPPORTED');
    return $pdo;
}

function assertRecoveryGatesClosed(): void
{
    foreach (['DB_PHASE_B_APPROVED','TEST_DB_EXECUTION_APPROVED','TEST_AUTH_EXECUTION_APPROVED','TEST_ADMIN_PROVISION_APPROVED'] as $gate) {
        if (Env::get($gate) !== 'false') throw new RuntimeException('EXECUTION_GATE_NOT_CLOSED');
    }
}

/** @return array<string,mixed> */
function assertRecoveryTargetAndGrants(PDO $root, string &$sqlStep): array
{
    $sqlStep = 'TARGET_SCHEMA';
    $schema = $root->prepare('SELECT DEFAULT_CHARACTER_SET_NAME, DEFAULT_COLLATION_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = :schema');
    $schema->execute(['schema'=>'up_van_booking_test']);
    $schemaRow = $schema->fetch();
    if (!is_array($schemaRow) || $schemaRow['DEFAULT_CHARACTER_SET_NAME'] !== 'utf8mb4' || $schemaRow['DEFAULT_COLLATION_NAME'] !== 'utf8mb4_unicode_ci') {
        throw new RuntimeException('TARGET_SCHEMA_INVALID');
    }
    $sqlStep = 'TARGET_ACCOUNTS';
    $accounts = $root->prepare('SELECT User, Host FROM mysql.user WHERE (User = :auth_user AND Host = :auth_host) OR (User = :provisioner_user AND Host = :provisioner_host)');
    $accounts->execute(['auth_user'=>'up_van_auth_test','auth_host'=>'127.0.0.1','provisioner_user'=>'up_van_test_provisioner','provisioner_host'=>'127.0.0.1']);
    $rows = $accounts->fetchAll();
    if (count($rows) !== 2) throw new RuntimeException('EXISTING_TEST_ACCOUNTS_REQUIRED');
    $sqlStep = 'PARTIAL_REVOKES';
    $partialRevokes = (bool)$root->query('SELECT @@GLOBAL.partial_revokes')->fetchColumn();
    $plans = [
        ['account'=>"'up_van_auth_test'@'127.0.0.1'",'rules'=>AuthRuntimeGrantPlan::rules()],
        ['account'=>"'up_van_test_provisioner'@'127.0.0.1'",'rules'=>TestProvisionerGrantPlan::rules()],
    ];
    foreach ($plans as $plan) {
        $sqlStep = $plan['account'] === "'up_van_auth_test'@'127.0.0.1'" ? 'AUTH_ACCOUNT_GRANTS' : 'PROVISIONER_ACCOUNT_GRANTS';
        $grants = $root->query('SHOW GRANTS FOR '.$plan['account'])->fetchAll(PDO::FETCH_COLUMN);
        $hasRole = false;
        foreach ($grants as $grant) {
            if (preg_match('/^GRANT\s+(?:`[^`]+`|\'[^\']+\')@(?:`[^`]+`|\'[^\']+\')(?:\s*,\s*(?:`[^`]+`|\'[^\']+\')@(?:`[^`]+`|\'[^\']+\'))*\s+TO\s+/i', (string)$grant)) $hasRole = true;
        }
        $evaluation = GrantVerifier::evaluate($grants, MigrationTarget::TEST, $plan['rules'], $partialRevokes, !$hasRole);
        if ($evaluation['status'] !== 'PASS') throw new RuntimeException('EXISTING_GRANTS_NOT_EXACT');
    }
    return ['accounts'=>'BOTH_PRESENT','grants'=>'PASS','target'=>'up_van_booking_test'];
}

function verifyRecoveryAccount(string $purpose): void
{
    $pdo = ConnectionFactory::connect($purpose);
    $identity = $pdo->query('SELECT CURRENT_USER() AS account_identity, DATABASE() AS database_name')->fetch(PDO::FETCH_ASSOC);
    $expectedUser = $purpose === 'auth_test' ? 'up_van_auth_test@127.0.0.1' : 'up_van_test_provisioner@127.0.0.1';
    if (($identity['database_name'] ?? null) !== 'up_van_booking_test' || ($identity['account_identity'] ?? null) !== $expectedUser) {
        throw new RuntimeException('POSTCONDITION_IDENTITY_MISMATCH');
    }
    // ConnectionFactory::connect() runs GrantVerifier and denies any grant drift.
}

function runRecoveryAction(string $action, string $target, string $state, string $approval): never
{
    if (!AuthCredentialRecoveryStateMachine::targetAllowed($target)) recoverOutput('FAIL','TARGET','WRONG_TARGET');
    if (!AuthCredentialRecoveryStateMachine::actionAllowed($state,$action)) recoverOutput('FAIL','STATE','RECOVERY_STATE_ACTION_MISMATCH');
    if (str_starts_with($action,'reconcile-') && $approval !== 'CONFIRM_UP_VAN_BOOKING_TEST_CREDENTIAL_RECOVERY') {
        recoverOutput('FAIL','APPROVAL','EXPLICIT_EXECUTE_CONFIRMATION_REQUIRED');
    }
    $sqlStep = 'GATES';
    try {
        assertRecoveryGatesClosed();
        $root = recoverRootConnection($sqlStep);
        if ($action === 'root-check') recoverOutput('PASS','ROOT_CONNECTION','ROOT_CONNECTION_VERIFIED');
        $preflight = assertRecoveryTargetAndGrants($root, $sqlStep);
        if ($action === 'preflight') recoverOutput('PASS','PREFLIGHT','TARGETS_AND_GRANTS_VALID',$preflight);

        $account = match ($action) {
            'reconcile-auth'=>['purpose'=>'auth_test','user'=>'up_van_auth_test','env'=>'AUTH_TEST_DB_PASSWORD'],
            'reconcile-provisioner'=>['purpose'=>'test_provisioner','user'=>'up_van_test_provisioner','env'=>'TEST_PROVISIONER_DB_PASSWORD'],
            'verify'=>null,
            default=>throw new RuntimeException('UNKNOWN_ACTION'),
        };
        if ($action === 'verify') {
            $sqlStep = 'VERIFY_AUTH_ACCOUNT';
            verifyRecoveryAccount('auth_test');
            $sqlStep = 'VERIFY_PROVISIONER_ACCOUNT';
            verifyRecoveryAccount('test_provisioner');
            assertRecoveryTargetAndGrants($root, $sqlStep);
            recoverOutput('PASS','VERIFY','BOTH_CREDENTIALS_AND_GRANTS_VALID');
        }
        $newPassword = getenv($account['env']);
        if (!is_string($newPassword) || !preg_match('/\A[a-f0-9]{64}\z/', $newPassword)) throw new RuntimeException('RECOVERY_CREDENTIAL_INVALID');
        try {
            $sqlStep = 'VERIFY_ACCOUNT';
            verifyRecoveryAccount($account['purpose']);
            if (AuthCredentialRecoveryStateMachine::accountDecision(true,true,null) === 'KEEP_PASSWORD_UNCHANGED') {
                recoverOutput('PASS','ACCOUNT','CREDENTIAL_ALREADY_MATCHES',['account'=>$account['user']]);
            }
        } catch (SafeDatabaseException $error) {
            if (AuthCredentialRecoveryStateMachine::accountDecision(true,false,$error->nativeCode) !== 'SET_STORED_PASSWORD'
                || !AuthCredentialRecoveryStateMachine::authenticationFailure($error->sqlState, $error->nativeCode, $error->duringConnect)) throw $error;
        }
        $sqlStep = 'RECOVER_ACCOUNT_PASSWORD';
        $root->exec("ALTER USER '{$account['user']}'@'127.0.0.1' IDENTIFIED BY '{$newPassword}'");
        $sqlStep = 'VERIFY_ACCOUNT';
        verifyRecoveryAccount($account['purpose']);
        assertRecoveryTargetAndGrants($root, $sqlStep);
        recoverOutput('PASS','ACCOUNT','CREDENTIAL_RECOVERED',['account'=>$account['user'],'grants'=>'UNCHANGED_AND_VALID']);
    } catch (SafeDatabaseException $error) {
        recoverOutput('FAIL',$sqlStep,'PDO_ERROR',['sqlstate'=>$error->sqlState,'native_code'=>$error->nativeCode]);
    } catch (PDOException $error) {
        $info = $error->errorInfo ?? [];
        $sqlState = isset($info[0]) && is_string($info[0]) && preg_match('/\A[A-Z0-9]{5}\z/i',$info[0]) ? $info[0] : 'UNKNOWN';
        $native = isset($info[1]) && is_numeric($info[1]) ? (int)$info[1] : null;
        recoverOutput('FAIL',$sqlStep,'PDO_ERROR',['sqlstate'=>$sqlState,'native_code'=>$native]);
    } catch (Throwable $error) {
        $known = [
            'ROOT_PASSWORD_MISSING','ROOT_IDENTITY_MISMATCH','MYSQL_VERSION_UNSUPPORTED','EXECUTION_GATE_NOT_CLOSED',
            'TARGET_SCHEMA_INVALID','EXISTING_TEST_ACCOUNTS_REQUIRED','EXISTING_GRANTS_NOT_EXACT',
            'POSTCONDITION_IDENTITY_MISMATCH','RECOVERY_CREDENTIAL_INVALID','UNKNOWN_ACTION',
        ];
        $code = in_array($error->getMessage(),$known,true) ? $error->getMessage() : 'SAFE_RECOVERY_FAILURE';
        recoverOutput('FAIL','RECOVERY',$code);
    }
}

if (PHP_SAPI === 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    runRecoveryAction($argv[1] ?? '', $argv[2] ?? '', $argv[3] ?? '', $argv[4] ?? '');
}
