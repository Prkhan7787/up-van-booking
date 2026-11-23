<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap/cli.php';

use App\Database\ConnectionFactory;
use App\Database\MigrationPlan;
use App\Database\MigrationRunner;
use App\Database\Migration004GrantPlan;
use App\Database\GrantVerifier;
use App\Database\MigrationTarget;
use App\Database\SafetyException;

$report = ['mode'=>'read_only_preflight','target'=>MigrationTarget::TEST,'database_accessed'=>false,'status'=>'BLOCKED','gates'=>['production'=>'unknown','test'=>'unknown']];
$pdo = null;
$stage = 'gate_check';
$queryStep = null;
try {
    foreach ([MigrationTarget::PRODUCTION, MigrationTarget::TEST] as $gateTarget) {
        try {
            ConnectionFactory::requireMigrationApproval($gateTarget);
            throw new SafetyException('A database migration gate is open; preflight stopped.');
        } catch (SafetyException $error) {
            if (str_contains($error->getMessage(), 'not enabled')) {
                $report['gates'][$gateTarget===MigrationTarget::PRODUCTION?'production':'test']='closed';
                continue;
            }
            throw $error;
        }
    }
    $stage = 'migration_manifest';
    $plan = MigrationPlan::load();
    $stage = 'test_account_precheck';
    $testPlan = ConnectionFactory::config('test');
    if ($testPlan['database'] !== MigrationTarget::TEST || $testPlan['username'] !== 'up_van_test') throw new SafetyException('Test connection identity precheck failed.');

    $stage = 'pdo_connection';
    $dsn = "mysql:host={$testPlan['host']};port={$testPlan['port']};dbname={$testPlan['database']};charset=utf8mb4";
    $pdo = new PDO($dsn, $testPlan['username'], $testPlan['password'], [
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES=>false,
        PDO::ATTR_TIMEOUT=>5,
        PDO::MYSQL_ATTR_MULTI_STATEMENTS=>false,
    ]);
    $report['database_accessed'] = true;
    $stage = 'read_only_queries';
    $queryStep = 'identity_version_query';
    // CURRENT_USER is reserved in MySQL 8.4; use a non-keyword result alias.
    $identity = $pdo->query('SELECT DATABASE() AS database_name, CURRENT_USER() AS account_identity, VERSION() AS server_version')->fetch(PDO::FETCH_ASSOC);
    if (!is_array($identity) || $identity['database_name'] !== MigrationTarget::TEST || $identity['account_identity'] !== 'up_van_test@127.0.0.1') throw new SafetyException('Connected test database identity mismatch.');
    if (!preg_match('/^8\.4\.\d+(?:[-.]|$)/D', (string)$identity['server_version'])) throw new SafetyException('MySQL 8.4 server is required.');
    $stage = 'test_account_grant_check';
    $queryStep = 'current_account_show_grants';
    $grants=$pdo->query('SHOW GRANTS FOR CURRENT_USER')->fetchAll(PDO::FETCH_COLUMN);
    $queryStep = 'partial_revokes_query';
    $partialRevokes=(bool)$pdo->query('SELECT @@GLOBAL.partial_revokes')->fetchColumn();
    ConnectionFactory::validateGrants($grants, MigrationTarget::TEST, false, $partialRevokes);
    $stage = 'read_only_transaction';
    $queryStep = 'start_read_only_transaction';
    $pdo->exec('SET TRANSACTION READ ONLY');
    $pdo->beginTransaction();
    $stage = 'read_only_queries';
    $queryStep = 'test_schema_charset_collation_query';
    $schema = $pdo->prepare('SELECT DEFAULT_CHARACTER_SET_NAME, DEFAULT_COLLATION_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = :db');
    $schema->execute(['db'=>MigrationTarget::TEST]);
    $schemaRow = $schema->fetch(PDO::FETCH_ASSOC);
    $report['identity'] = ['database'=>$identity['database_name'],'current_user'=>$identity['account_identity'],'mysql_version'=>$identity['server_version'],'charset'=>$schemaRow['DEFAULT_CHARACTER_SET_NAME'] ?? null,'collation'=>$schemaRow['DEFAULT_COLLATION_NAME'] ?? null];

    $report['current_account_grants'] = [];
    foreach ($grants as $grant) {
        if (preg_match('/^GRANT (.+) ON `([^`]+)`\.(\*|`[^`]+`) TO /', (string)$grant, $match)) {
            $report['current_account_grants'][] = ['privileges'=>array_map('trim',explode(',',$match[1])),'scope'=>'database/table-scoped'];
        }
    }
    try {
        $queryStep = 'test_migrator_show_grants';
        $migratorGrants = $pdo->query("SHOW GRANTS FOR 'up_van_test_migrator'@'127.0.0.1'")->fetchAll(PDO::FETCH_COLUMN);
        $queryStep = null;
        $report['test_migrator_grants'] = GrantVerifier::evaluate($migratorGrants, MigrationTarget::TEST, Migration004GrantPlan::rules(), $partialRevokes);
    } catch (PDOException $error) {
        $report['test_migrator_grants'] = ['status'=>'UNVERIFIED','query_step'=>'test_migrator_show_grants','sqlstate'=>(string)$error->getCode(),'native_code'=>isset($error->errorInfo[1]) ? (int)$error->errorInfo[1] : null];
    }

    $queryStep = 'migration_ledger_and_object_inventory_queries';
    $runnerStatus = (new MigrationRunner($pdo, MigrationTarget::TEST))->status();
    $report['ledger'] = $runnerStatus;
    $report['plan'] = array_map(static fn(array $entry): array => ['version'=>$entry['version'],'checksum'=>$entry['sha256'],'statement_count'=>count($entry['statements']),'objects'=>$entry['objects']], $plan);

    $objectNames = $runnerStatus['objects'];
    $applied = [];
    $ledgerValid = $runnerStatus['ledger'] === 'present';
    foreach ($runnerStatus['migrations'] as $index=>$row) {
        $entry = $plan[$index] ?? null;
        $valid = $entry !== null && $row['version'] === $entry['version'] && $row['status'] === 'applied'
            && hash_equals($entry['sha256'], (string)$row['checksum'])
            && (int)$row['completed_statements'] === count($entry['statements'])
            && (int)$row['total_statements'] === count($entry['statements']);
        if (!$valid) $ledgerValid = false;
        if ($valid) $applied[] = $row['version'];
    }
    $report['ledger_check'] = ['valid'=>$ledgerValid,'applied_versions'=>$applied,'expected_baseline'=>['001_identity','002_booking_design','003_roles'],'fourth_already_applied'=>in_array('004_auth_security',$applied,true)];

    $expectedObjects = ['schema_migrations'];
    foreach ($plan as $entry) if (in_array($entry['version'],$applied,true)) $expectedObjects = array_merge($expectedObjects,$entry['objects']);
    sort($expectedObjects); $actualObjects=$objectNames; sort($actualObjects);
    $report['schema_inventory'] = ['objects'=>$objectNames,'matches_ledger'=>$expectedObjects===$actualObjects];
    $queryStep = 'schema_constraints_inventory_query';
    $constraints = $pdo->prepare("SELECT TABLE_NAME, CONSTRAINT_NAME, CONSTRAINT_TYPE FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=:db ORDER BY TABLE_NAME, CONSTRAINT_TYPE, CONSTRAINT_NAME");
    $constraints->execute(['db'=>MigrationTarget::TEST]);
    $report['constraints'] = $constraints->fetchAll(PDO::FETCH_ASSOC);

    $queryStep = 'users_table_precondition_query';
    $userTable = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=:db AND TABLE_NAME='users'");
    $userTable->execute(['db'=>MigrationTarget::TEST]);
    $queryStep = 'username_column_precondition_query';
    $usernameColumn = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=:db AND TABLE_NAME='users' AND COLUMN_NAME='username'");
    $usernameColumn->execute(['db'=>MigrationTarget::TEST]);
    $queryStep = 'auth_version_column_precondition_query';
    $authVersionColumn = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=:db AND TABLE_NAME='users' AND COLUMN_NAME='auth_version'");
    $authVersionColumn->execute(['db'=>MigrationTarget::TEST]);
    $queryStep = 'rate_bucket_table_precondition_query';
    $bucketTable = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=:db AND TABLE_NAME='auth_login_buckets'");
    $bucketTable->execute(['db'=>MigrationTarget::TEST]);
    $usersTableExists=(int)$userTable->fetchColumn()===1;
    $usernameColumnExists=(int)$usernameColumn->fetchColumn()===1;
    $authVersionColumnExists=(int)$authVersionColumn->fetchColumn()===1;
    $bucketTableExists=(int)$bucketTable->fetchColumn()===1;
    $report['migration_004_preconditions'] = ['users_table_exists'=>$usersTableExists,'username_column_exists'=>$usernameColumnExists,'auth_version_column_exists'=>$authVersionColumnExists,'rate_bucket_table_exists'=>$bucketTableExists];

    if ($usersTableExists && $usernameColumnExists) {
        $report['username_readiness'] = [];
        $queryStep = 'users_missing_username_count_query';
        $report['username_readiness']['users_missing_username'] = (int)$pdo->query('SELECT COUNT(*) FROM users WHERE username IS NULL')->fetchColumn();
        $queryStep = 'invalid_username_count_query';
        $report['username_readiness']['invalid_username_count'] = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE username IS NOT NULL AND NOT REGEXP_LIKE(username, '^[A-Za-z0-9_.-]{3,50}$', 'c')")->fetchColumn();
        $queryStep = 'case_insensitive_duplicate_groups_count_query';
        $report['username_readiness']['case_insensitive_duplicate_groups'] = (int)$pdo->query('SELECT COUNT(*) FROM (SELECT LOWER(username) AS normalized_name FROM users WHERE username IS NOT NULL GROUP BY LOWER(username) HAVING COUNT(*) > 1) AS duplicate_groups')->fetchColumn();
    } elseif ($usersTableExists) {
        $queryStep = 'users_requiring_manual_username_mapping_count_query';
        $report['username_readiness'] = ['users_without_username_column'=>(int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(),'invalid_username_count'=>'not_applicable','case_insensitive_duplicate_groups'=>'not_applicable'];
    } else {
        $report['username_readiness'] = ['status'=>'users table absent'];
    }

    $report['status'] = 'PASS';
    $queryStep = null;
    if (!$ledgerValid || !in_array('003_roles',$applied,true) || count($applied)!==3 || !$report['schema_inventory']['matches_ledger']) $report['status']='FAIL';
    if ($report['status']==='PASS' && (in_array(true, array_slice($report['migration_004_preconditions'],1), true) || ($report['test_migrator_grants']['status'] ?? null)!=='PASS')) $report['status']='WARNING';
    if (($report['identity']['charset'] ?? null)!=='utf8mb4' || ($report['identity']['collation'] ?? null)!=='utf8mb4_unicode_ci') $report['status']='FAIL';
} catch (Throwable $error) {
    $report['status'] = 'BLOCKED';
    $report['error_type'] = $stage;
    if ($queryStep !== null) $report['failed_query_step'] = $queryStep;
    if ($error instanceof PDOException) {
        $report['sqlstate']=(string)$error->getCode();
        $report['native_code']=isset($error->errorInfo[1]) ? (int)$error->errorInfo[1] : null;
    }
} finally {
    if ($pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
}

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), PHP_EOL;
exit($report['status']==='BLOCKED' || $report['status']==='FAIL' ? 1 : 0);
