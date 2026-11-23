<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap/cli.php';

use App\Core\Env;
use App\Database\ConnectionFactory;
use App\Database\MigrationPlan;
use App\Database\MigrationRunner;

$phase = 'gates';
try {
    if (Env::get('DB_PHASE_B_APPROVED') !== 'false' || Env::get('TEST_DB_EXECUTION_APPROVED') !== 'false') {
        throw new RuntimeException('Execution gates must both be false for preflight.');
    }
    $phase = 'manifest';
    $plan = MigrationPlan::load();
    $phase = 'pdo_connect';
    $db = ConnectionFactory::connect('test');
    $phase = 'identity';
    $identity = $db->query('SELECT DATABASE() AS database_name, CURRENT_USER() AS account_name')->fetch();
    if ($identity !== ['database_name'=>'up_van_booking_test', 'account_name'=>'up_van_test@127.0.0.1']) throw new RuntimeException('Identity mismatch.');
    $phase = 'least_privilege';
    $grants = $db->query('SHOW GRANTS FOR CURRENT_USER')->fetchAll(PDO::FETCH_COLUMN);
    $privileges = [];
    foreach ($grants as $grant) {
        if (str_starts_with($grant, 'GRANT USAGE ON *.* TO ')) continue;
        if (!preg_match('/^GRANT (.+) ON `[^`]+`\.\* TO /', $grant, $m)) throw new RuntimeException('Unexpected grant scope.');
        $privileges = array_merge($privileges, array_map('trim', explode(',', $m[1])));
    }
    sort($privileges);
    if ($privileges !== ['DELETE','INSERT','SELECT','UPDATE']) throw new RuntimeException('Least privilege mismatch.');
    $phase = 'ledger';
    $state = (new MigrationRunner($db, 'up_van_booking_test'))->status();
    if (count($state['migrations']) !== 3) throw new RuntimeException('Ledger incomplete.');
    $expected = ['schema_migrations'];
    foreach ($plan as $i=>$entry) {
        $row = $state['migrations'][$i];
        if ($row['version'] !== $entry['version'] || $row['status'] !== 'applied' || !hash_equals($entry['sha256'], $row['checksum']) || (int)$row['completed_statements'] !== count($entry['statements']) || (int)$row['total_statements'] !== count($entry['statements'])) throw new RuntimeException('Ledger mismatch.');
        $expected = array_merge($expected, $entry['objects']);
    }
    $phase = 'inventory';
    $actual = $state['objects']; sort($actual); sort($expected);
    if ($actual !== $expected) throw new RuntimeException('Object inventory mismatch.');
    $counts = [];
    foreach ([
        'tables'=>"SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE'",
        'views'=>"SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='VIEW'",
        'foreign_keys'=>"SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND CONSTRAINT_TYPE='FOREIGN KEY'",
        'checks'=>"SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND CONSTRAINT_TYPE='CHECK'",
        'unique'=>"SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND CONSTRAINT_TYPE='UNIQUE'",
        'indexes'=>"SELECT COUNT(*) FROM (SELECT TABLE_NAME,INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() GROUP BY TABLE_NAME,INDEX_NAME) AS i",
    ] as $key=>$sql) $counts[$key] = (int)$db->query($sql)->fetchColumn();
    if ($counts !== ['tables'=>14,'views'=>1,'foreign_keys'=>35,'checks'=>27,'unique'=>10,'indexes'=>58]) throw new RuntimeException('Inventory counts mismatch.');
    $db->query('SELECT * FROM calendar_events LIMIT 0');
    echo json_encode(['status'=>'PASS','identity'=>$identity,'least_privilege'=>$privileges,'ledger'=>'001–003 applied; checksums and statement counts match manifest','inventory'=>$counts,'production_gate'=>false,'test_gate'=>false], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE), PHP_EOL;
} catch (Throwable $error) {
    echo json_encode(['status'=>'FAIL','phase'=>$phase,'category'=>$error instanceof PDOException ? 'pdo_error' : 'connection_or_safety_validation_failed','details'=>'Withheld to protect credentials; no test execution authorized by this preflight.']), PHP_EOL;
    exit(1);
}
