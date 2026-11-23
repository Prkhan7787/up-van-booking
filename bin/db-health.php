<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap/cli.php';
use App\Database\ConnectionFactory;
use App\Database\MigrationPlan;
use App\Database\MigrationRunner;
use App\Database\SafetyException;

$result = ['php'=>PHP_VERSION, 'pdo_drivers'=>PDO::getAvailableDrivers(), 'mode'=>'offline','migration_plan'=>MigrationPlan::summary()];
$flags = array_slice($argv,1);
if ($flags === ['--connect']) {
    $db = ConnectionFactory::connect();
    $result['mode'] = 'read-only-health';
    $result['server_version'] = $db->query('SELECT VERSION()')->fetchColumn();
    $result['database'] = $db->query('SELECT DATABASE()')->fetchColumn();
    $result['migration_status'] = (new MigrationRunner($db, 'up_van_booking'))->status();
    $query = $db->prepare('SELECT TABLE_NAME, ENGINE, TABLE_COLLATION FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = :db ORDER BY TABLE_NAME');
    $query->execute(['db'=>'up_van_booking']); $result['tables'] = $query->fetchAll();
    $expected = ['schema_migrations'];
    foreach (MigrationPlan::load() as $entry) $expected = array_merge($expected,$entry['objects']);
    $result['missing_objects'] = array_values(array_diff($expected,array_column($result['tables'],'TABLE_NAME')));
    $result['migration_issues'] = [];
    $recorded = array_column($result['migration_status']['migrations'],null,'version');
    foreach (MigrationPlan::load() as $entry) {
        $row = $recorded[$entry['version']] ?? null;
        if (!$row || $row['status'] !== 'applied' || !hash_equals($entry['sha256'],$row['checksum'])
            || (int)$row['completed_statements'] !== count($entry['statements'])) $result['migration_issues'][] = $entry['version'] . ': pending or dirty';
    }
    if (count($recorded) !== count(MigrationPlan::load())) $result['migration_issues'][] = 'unexpected ledger inventory';
    $result['storage_issues'] = [];
    foreach($result['tables'] as $table) {
        if($table['TABLE_NAME']==='calendar_events') continue;
        if($table['ENGINE']!=='InnoDB' || $table['TABLE_COLLATION']!=='utf8mb4_unicode_ci') $result['storage_issues'][] = 'table engine/collation mismatch';
    }
} elseif ($flags !== [] && $flags !== ['--offline']) throw new SafetyException('Usage: --offline OR --connect (read-only).');
echo json_encode($result, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE), PHP_EOL;
if (!empty($result['missing_objects']) || !empty($result['migration_issues']) || !empty($result['storage_issues'])) exit(2);
