<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/cli.php';

use App\Database\{GrantVerifier, Migration010ApplyBackup, Migration010ApplyGuard, Migration010DriverValidator, MigrationPlan, MigrationRunner, MigrationTarget, SafetyException};

/** Only read plans and in-memory checks. This CLI never connects to MySQL. */
function migration010JsonRows(string $select, array $columns): string
{
    $pairs = array_map(static fn(string $c): string => "'$c', q.".'`'.$c.'`', $columns);
    return 'SELECT COALESCE(JSON_ARRAYAGG(JSON_OBJECT('.implode(',',$pairs).')),JSON_ARRAY()) FROM ('.$select.') q';
}

try {
    $args = array_slice($argv, 1);
    if ($args === ['--queries']) {
        $db = 'TABLE_SCHEMA=DATABASE()';
        $usersAndDrivers = "$db AND TABLE_NAME IN ('users','drivers')";
        $apply = "User='up_van_migration010_apply' AND Host='127.0.0.1'";
        $meta = [
            'columns'=>["SELECT TABLE_NAME,COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,ORDINAL_POSITION FROM information_schema.COLUMNS WHERE $usersAndDrivers", ['TABLE_NAME','COLUMN_NAME','COLUMN_TYPE','IS_NULLABLE','ORDINAL_POSITION']],
            'engines'=>["SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE $usersAndDrivers", ['TABLE_NAME','ENGINE']],
            'indexes'=>["SELECT TABLE_NAME,INDEX_NAME,COLUMN_NAME,NON_UNIQUE,SEQ_IN_INDEX FROM information_schema.STATISTICS WHERE $usersAndDrivers", ['TABLE_NAME','INDEX_NAME','COLUMN_NAME','NON_UNIQUE','SEQ_IN_INDEX']],
            'foreign_keys'=>["SELECT k.CONSTRAINT_NAME,k.COLUMN_NAME,k.REFERENCED_TABLE_SCHEMA,k.REFERENCED_TABLE_NAME,k.REFERENCED_COLUMN_NAME,r.DELETE_RULE,r.UPDATE_RULE FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME AND r.TABLE_NAME=k.TABLE_NAME WHERE k.TABLE_SCHEMA=DATABASE() AND k.TABLE_NAME='drivers' AND (k.COLUMN_NAME='user_id' OR k.CONSTRAINT_NAME='fk_drivers_user')", ['CONSTRAINT_NAME','COLUMN_NAME','REFERENCED_TABLE_SCHEMA','REFERENCED_TABLE_NAME','REFERENCED_COLUMN_NAME','DELETE_RULE','UPDATE_RULE']],
            'roles'=>['SELECT name,HEX(description) AS description_hex FROM roles', ['name','description_hex']],
            'ledger'=>['SELECT version,checksum,status,completed_statements,total_statements FROM schema_migrations', ['version','checksum','status','completed_statements','total_statements']],
            'account'=>["SELECT Host,plugin,account_locked,password_expired FROM mysql.user WHERE $apply", ['Host','plugin','account_locked','password_expired']],
            'baseline'=>[MigrationRunner::driver010BaselineSchemaQuery(), ['ready']],
            'flags'=>["SELECT (SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE USER='up_van_migration010_apply') AS active_sessions, IF(@@GLOBAL.mandatory_roles='',1,0) AS mandatory_roles_empty, @@GLOBAL.partial_revokes AS partial_revokes, (SELECT COUNT(*) FROM mysql.role_edges WHERE TO_USER='up_van_migration010_apply' AND TO_HOST='127.0.0.1') AS assigned_roles, IS_FREE_LOCK('up_van_booking_test:migrations') AS migration_lock_free", ['active_sessions','mandatory_roles_empty','partial_revokes','assigned_roles','migration_lock_free']],
        ];
        $queries = [];
        foreach ($meta as $name=>[$select,$columns]) $queries[$name] = migration010JsonRows($select,$columns);
        echo json_encode($queries, JSON_THROW_ON_ERROR), PHP_EOL;
    } elseif (in_array($args, [['--preflight'], ['--postflight'], ['--verify-backup']], true)) {
        $raw = stream_get_contents(STDIN, 262145);
        if ($raw === false || strlen($raw)>262144) throw new SafetyException('MIGRATION_010_READINESS_INPUT_REJECTED');
        $packet = json_decode($raw, true, 24, JSON_THROW_ON_ERROR);
        if ($args === ['--verify-backup']) {
            $verified = Migration010ApplyBackup::verify($packet['directory'], $packet['manifest_sha256'], requireFresh: true);
            echo json_encode(['status'=>'PASS']+$verified, JSON_THROW_ON_ERROR), PHP_EOL;
            exit;
        }
        $postflight = $args === ['--postflight'];
        $account = $packet['account'] ?? [];
        if (count($account)!==1 || ($account[0]['Host']??null)!=='127.0.0.1' || ($account[0]['plugin']??null)!=='caching_sha2_password'
            || ($account[0]['account_locked']??null)!=='Y' || ($account[0]['password_expired']??null)!=='N') {
            throw new SafetyException('MIGRATION_010_ACCOUNT_STATE_REJECTED');
        }
        $flags = $packet['flags'][0] ?? [];
        if (count($packet['flags'] ?? [])!==1 || (string)($flags['active_sessions']??'')!=='0' || (string)($flags['assigned_roles']??'')!=='0'
            || (string)($flags['mandatory_roles_empty']??'')!=='1' || (string)($flags['migration_lock_free']??'')!=='1'
            || !in_array((string)($flags['partial_revokes']??''),['0','1'],true)) {
            throw new SafetyException('MIGRATION_010_ACCOUNT_OR_LOCK_GUARD_REJECTED');
        }
        $grants = $packet['grants'] ?? [];
        foreach ($grants as $grant) {
            if (!is_string($grant) || !preg_match('/\AGRANT [A-Z]/D',$grant) || stripos($grant,'WITH ADMIN OPTION')!==false) {
                throw new SafetyException('MIGRATION_010_APPLY_GRANTS_REJECTED');
            }
        }
        if (GrantVerifier::evaluate($grants, MigrationTarget::TEST, Migration010ApplyGuard::rules(), (bool)$flags['partial_revokes'], true)['status']!=='PASS') {
            throw new SafetyException('MIGRATION_010_APPLY_GRANTS_REJECTED');
        }
        if (count($packet['baseline']??[])!==1 || (string)($packet['baseline'][0]['ready']??'')!=='1') {
            throw new SafetyException('MIGRATION_010_BASELINE_SCHEMA_REJECTED');
        }
        $metadata = ['columns'=>[], 'engines'=>array_column($packet['engines'],'ENGINE','TABLE_NAME'),
            'indexes'=>$packet['indexes'], 'link_foreign_keys'=>[], 'roles'=>[]];
        foreach (['users','drivers'] as $table) {
            $columns = array_values(array_filter($packet['columns'], static fn(array $r): bool=>$r['TABLE_NAME']===$table));
            usort($columns, static fn(array $a,array $b): int=>$a['ORDINAL_POSITION']<=>$b['ORDINAL_POSITION']);
            $metadata['columns'][$table] = $columns;
        }
        foreach ($packet['foreign_keys'] as $row) {
            $ordered = [];
            foreach (['CONSTRAINT_NAME','COLUMN_NAME','REFERENCED_TABLE_SCHEMA','REFERENCED_TABLE_NAME','REFERENCED_COLUMN_NAME','DELETE_RULE','UPDATE_RULE'] as $key) {
                $ordered[$key] = $row[$key] ?? null;
            }
            $metadata['link_foreign_keys'][] = $ordered;
        }
        foreach ($packet['roles'] as $role) {
            $hex = $role['description_hex'] ?? null;
            $metadata['roles'][] = ['name'=>$role['name'],'description'=>is_string($hex) && strlen($hex)%2===0 && preg_match('/\A[0-9A-F]*\z/D',$hex) ? hex2bin($hex) : null];
        }
        Migration010DriverValidator::assertLedger($packet['ledger'], $postflight);
        Migration010DriverValidator::assertSchema($metadata, $postflight);
        $has011 = in_array(MigrationPlan::MICROSOFT011['version'],array_column($packet['ledger'],'version'),true);
        echo json_encode(['status'=>'PASS','grants'=>'PASS','account_lock'=>'Y','active_apply_sessions'=>0,
            'ledger_010'=>$postflight?'APPLIED_3_OF_3':'ABSENT','ledger_011'=>$has011?'APPLIED_CHECKSUM_PASS':'ABSENT',
            'schema'=>'PASS','backup_ledger_state'=>Migration010ApplyGuard::backupLedgerState($packet['ledger'])], JSON_THROW_ON_ERROR), PHP_EOL;
    } else {
        throw new SafetyException('MIGRATION_010_READINESS_ARGUMENTS_REJECTED');
    }
} catch (Throwable $error) {
    $code = $error instanceof SafetyException && preg_match('/\AMIGRATION_010_[A-Z0-9_]+\z/D',$error->getMessage())
        ? $error->getMessage() : 'MIGRATION_010_READINESS_REJECTED';
    echo json_encode(['status'=>'FAIL','code'=>$code], JSON_THROW_ON_ERROR), PHP_EOL;
    exit(1);
}
