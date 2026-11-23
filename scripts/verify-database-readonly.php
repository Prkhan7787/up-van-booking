<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap/cli.php';

use App\Database\ConnectionFactory;
use App\Database\GrantVerifier;
use App\Database\Migration004GrantPlan;
use App\Database\MigrationPlan;
use App\Database\MigrationRunner;
use App\Database\SafetyException;

/**
 * Read-only Task 03 Stage B verification. Never prints passwords, DSNs, or exception messages.
 * This script does not apply migrations or modify database state.
 */
function stageBErrorCode(PDOException $error, string $phase): array
{
    $info = $error->errorInfo ?? [];
    return [
        'phase' => $phase,
        'sqlstate' => $info[0] ?? $error->getCode(),
        'native_code' => isset($info[1]) && is_numeric($info[1]) ? (int) $info[1] : null,
    ];
}

function stageBGrantAudit(array $grants, string $database, array $grantRules, bool $partialRevokes, bool $rolesResolved): array
{
    return GrantVerifier::evaluate($grants, $database, $grantRules, $partialRevokes, $rolesResolved);
}

$accountPlan = [
    'runtime' => ['database' => 'up_van_booking', 'username' => 'up_van_app', 'prefix' => 'DB_', 'ddl' => false, 'grant_rules' => ['database'=>['SELECT'],'tables'=>[]]],
    'preflight' => ['database' => 'up_van_booking', 'username' => 'up_van_preflight', 'prefix' => 'PREFLIGHT_DB_', 'ddl' => false, 'grant_rules' => ['database'=>['SELECT'],'tables'=>[]]],
    'migration' => ['database' => 'up_van_booking', 'username' => 'up_van_migrator', 'prefix' => 'MIGRATION_DB_', 'ddl' => true, 'grant_rules' => ['database'=>['CREATE','CREATE VIEW','INDEX','REFERENCES','SELECT','INSERT','UPDATE'],'tables'=>[]]],
    'test' => ['database' => 'up_van_booking_test', 'username' => 'up_van_test', 'prefix' => 'TEST_DB_', 'ddl' => false, 'grant_rules' => ['database'=>['SELECT','INSERT','UPDATE','DELETE'],'tables'=>[]]],
    'migration_test' => ['database' => 'up_van_booking_test', 'username' => 'up_van_test_migrator', 'prefix' => 'MIGRATION_TEST_DB_', 'ddl' => true, 'grant_rules' => Migration004GrantPlan::rules()],
];

$plan = MigrationPlan::load();
$createdTables = [];
$seenTables = [];
$foreignKeyCount = 0;
$namedKeyCount = 0;
$uniqueCount = 0;
$checkCount = 0;
$creationOrderValid = true;

foreach ($plan as $migration) {
    foreach ($migration['statements'] as $statement) {
        if (preg_match('/^CREATE TABLE ([a-z_]+)\\s*\\(/i', $statement, $tableMatch)) {
            $table = strtolower($tableMatch[1]);
            preg_match_all('/\\bREFERENCES\\s+([a-z_]+)\\s*\\(/i', $statement, $references);
            foreach ($references[1] as $parent) {
                if (!isset($seenTables[strtolower($parent)])) {
                    $creationOrderValid = false;
                }
            }
            $createdTables[] = $table;
            $seenTables[$table] = true;
        }
        $foreignKeyCount += preg_match_all('/\\bFOREIGN KEY\\s*\\(/i', $statement);
        $namedKeyCount += preg_match_all('/^\\s*(?:UNIQUE\\s+)?KEY\\s+[a-z_]+\\s*\\(/mi', $statement);
        $uniqueCount += preg_match_all('/\\bUNIQUE(?:\\s+KEY|\\s+INDEX)?\\b/i', $statement);
        $checkCount += preg_match_all('/\\bCHECK\\s*\\(/i', $statement);
    }
}

$report = [
    'mode' => 'read_only',
    'php_version' => PHP_VERSION,
    'pdo_mysql_loaded' => extension_loaded('pdo_mysql'),
    'execution_gates_false' => \App\Core\Env::get('DB_PHASE_B_APPROVED') === 'false'
        && \App\Core\Env::get('TEST_DB_EXECUTION_APPROVED') === 'false',
    'manifest' => [
        'checksum_valid' => true,
        'migrations' => array_map(static fn (array $item): array => [
            'version' => $item['version'],
            'sha256' => $item['sha256'],
            'statements' => count($item['statements']),
            'objects' => $item['objects'],
        ], $plan),
        'static_schema_review' => [
            'table_creation_order' => $createdTables,
            'foreign_key_declarations' => $foreignKeyCount,
            'named_key_declarations' => $namedKeyCount,
            'unique_declarations' => $uniqueCount,
            'check_declarations' => $checkCount,
            'foreign_key_parents_precede_children' => $creationOrderValid,
        ],
    ],
    'accounts' => [],
];

foreach ($accountPlan as $purpose => $expected) {
    $phase = 'configuration';
    try {
        $config = ConnectionFactory::config($purpose);
        $host = $config['host'] === '::1' ? '[::1]' : $config['host'];
        $dsn = "mysql:host={$host};port={$config['port']};dbname={$config['database']};charset=utf8mb4";

        $phase = 'pdo_connect';
        $pdo = new PDO($dsn, $config['username'], $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 5,
            PDO::MYSQL_ATTR_MULTI_STATEMENTS => false,
        ]);

        $phase = 'session_metadata';
        $session = $pdo->query(
            'SELECT VERSION() AS server_version,
                    DATABASE() AS database_name,
                    CURRENT_USER() AS account_name,
                    @@character_set_client AS client_charset,
                    @@character_set_connection AS connection_charset,
                    @@collation_connection AS connection_collation'
        )->fetch();

        $schemaQuery = $pdo->prepare(
            'SELECT DEFAULT_CHARACTER_SET_NAME AS database_charset,
                    DEFAULT_COLLATION_NAME AS database_collation
             FROM INFORMATION_SCHEMA.SCHEMATA
             WHERE SCHEMA_NAME = :database_name'
        );
        $schemaQuery->execute(['database_name' => $expected['database']]);
        $schema = $schemaQuery->fetch();

        $phase = 'grants';
        $grantState = GrantVerifier::currentUserGrants($pdo);
        $grants = $grantState['grants'];
        $partialRevokes = (bool) $pdo->query('SELECT @@GLOBAL.partial_revokes')->fetchColumn();
        $directGrants = $pdo->query('SHOW GRANTS FOR CURRENT_USER')->fetchAll(PDO::FETCH_COLUMN);
        ConnectionFactory::validateGrants($directGrants, $expected['database'], $expected['ddl'], $partialRevokes);
        $grantAudit = stageBGrantAudit($grants, $expected['database'], $expected['grant_rules'], $partialRevokes, $grantState['roles_resolved']);

        $phase = 'migration_inventory';
        $state = (new MigrationRunner($pdo, $expected['database']))->status();
        $tablesQuery = $pdo->prepare(
            'SELECT TABLE_NAME, TABLE_TYPE, ENGINE, TABLE_COLLATION
             FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = :database_name
             ORDER BY TABLE_NAME'
        );
        $tablesQuery->execute(['database_name' => $expected['database']]);
        $objects = $tablesQuery->fetchAll();

        $constraintsQuery = $pdo->prepare(
            'SELECT CONSTRAINT_TYPE, COUNT(*) AS item_count
             FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
             WHERE TABLE_SCHEMA = :database_name
             GROUP BY CONSTRAINT_TYPE ORDER BY CONSTRAINT_TYPE'
        );
        $constraintsQuery->execute(['database_name' => $expected['database']]);
        $constraints = $constraintsQuery->fetchAll();

        $indexesQuery = $pdo->prepare(
            'SELECT COUNT(*) AS index_count,
                    SUM(CASE WHEN NON_UNIQUE = 0 THEN 1 ELSE 0 END) AS unique_index_count
             FROM (
                 SELECT TABLE_NAME, INDEX_NAME, MAX(NON_UNIQUE) AS NON_UNIQUE
                 FROM INFORMATION_SCHEMA.STATISTICS
                 WHERE TABLE_SCHEMA = :database_name
                 GROUP BY TABLE_NAME, INDEX_NAME
             ) AS indexes_for_schema'
        );
        $indexesQuery->execute(['database_name' => $expected['database']]);
        $indexes = $indexesQuery->fetch();

        $rowsByVersion = array_column($state['migrations'], null, 'version');
        $recordedVersions = array_column($state['migrations'], 'version');
        $expectedVersions = array_column($plan, 'version');
        $ledgerOrderValid = $recordedVersions === array_slice($expectedVersions, 0, count($recordedVersions));
        $ledgerRowsValid = $ledgerOrderValid;
        foreach ($state['migrations'] as $row) {
            $match = null;
            foreach ($plan as $entry) {
                if ($entry['version'] === $row['version']) {
                    $match = $entry;
                    break;
                }
            }
            if ($match === null
                || $row['status'] !== 'applied'
                || !hash_equals($match['sha256'], $row['checksum'])
                || (int) $row['completed_statements'] !== count($match['statements'])
                || (int) $row['total_statements'] !== count($match['statements'])) {
                $ledgerRowsValid = false;
            }
        }

        $expectedObjects = ['schema_migrations'];
        foreach ($plan as $entry) {
            if (isset($rowsByVersion[$entry['version']])
                && $rowsByVersion[$entry['version']]['status'] === 'applied'
                && hash_equals($entry['sha256'], $rowsByVersion[$entry['version']]['checksum'])) {
                $expectedObjects = array_merge($expectedObjects, $entry['objects']);
            }
        }
        $actualNames = array_column($objects, 'TABLE_NAME');
        $expectedNames = $state['ledger'] === 'absent' ? [] : $expectedObjects;
        sort($actualNames);
        sort($expectedNames);
        $inventoryMatchesLedger = $state['ledger'] === 'absent'
            ? $objects === []
            : $actualNames === $expectedNames;

        $storageMatches = true;
        foreach ($objects as $object) {
            if ($object['TABLE_TYPE'] === 'VIEW') {
                if ($object['TABLE_NAME'] !== 'calendar_events') {
                    $storageMatches = false;
                }
                continue;
            }
            if ($object['ENGINE'] !== 'InnoDB' || $object['TABLE_COLLATION'] !== 'utf8mb4_unicode_ci') {
                $storageMatches = false;
            }
        }

        $report['accounts'][$purpose] = [
            'connection_status' => 'connected',
            'account_name' => $session['account_name'],
            'database_name' => $session['database_name'],
            'server_version' => $session['server_version'],
            'database_charset' => $schema['database_charset'] ?? null,
            'database_collation' => $schema['database_collation'] ?? null,
            'connection_charset' => $session['connection_charset'],
            'connection_collation' => $session['connection_collation'],
            'grant_audit' => $grantAudit,
            'object_inventory' => $objects,
            'constraints' => $constraints,
            'indexes' => $indexes,
            'migration_state' => [
                'ledger' => $state['ledger'],
                'rows' => $state['migrations'],
                'ledger_order_valid' => $ledgerOrderValid,
                'ledger_checksums_and_counts_valid' => $ledgerRowsValid,
                'inventory_matches_ledger' => $inventoryMatchesLedger,
                'storage_matches_plan' => $storageMatches,
            ],
        ];
    } catch (PDOException $error) {
        $report['accounts'][$purpose] = [
            'connection_status' => 'failed',
            'error_code' => stageBErrorCode($error, $phase),
        ];
    } catch (SafetyException) {
        $report['accounts'][$purpose] = [
            'connection_status' => 'failed_safety_validation',
            'error_code' => ['phase' => $phase, 'category' => 'scope_or_configuration_check'],
        ];
    } catch (Throwable) {
        $report['accounts'][$purpose] = [
            'connection_status' => 'failed',
            'error_code' => ['phase' => $phase, 'category' => 'verification_failed_details_withheld'],
        ];
    }
}

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
