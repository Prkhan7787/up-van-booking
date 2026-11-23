<?php
declare(strict_types=1);
namespace App\Database;

/** Pure validation of SELECT-only metadata; a dirty/partial migration is never adopted. */
final class Migration010DriverValidator
{
    public static function assertLedger(array $rows, bool $postflight): void
    {
        $plan = MigrationPlan::driver010LedgerEntries();
        $expected = $postflight ? $plan : array_slice($plan, 0, 9);
        if (count($rows) !== count($expected)) throw new SafetyException('MIGRATION_010_LEDGER_COUNT_OR_PARTIAL');
        foreach ($expected as $i=>$entry) {
            $row = $rows[$i] ?? [];
            if (($row['version'] ?? null) !== $entry['version'] || ($row['status'] ?? null) !== 'applied'
                || !hash_equals($entry['sha256'], (string)($row['checksum'] ?? ''))
                || (int)($row['completed_statements'] ?? -1) !== $entry['statement_count']
                || (int)($row['total_statements'] ?? -1) !== $entry['statement_count']) {
                throw new SafetyException('MIGRATION_010_LEDGER_DIRTY_OR_CHECKSUM');
            }
        }
    }

    public static function assertSchema(array $metadata, bool $postflight): void
    {
        $users = ['id','email','username','display_name','password_hash','is_active','auth_version','created_at','updated_at'];
        $drivers = ['id','staff_code','display_name','contact_phone','is_active','created_by','updated_by','created_at','updated_at'];
        if ($postflight) {
            array_splice($users, 4, 0, ['nickname']);
            array_splice($drivers, 4, 0, ['user_id']);
        }
        foreach (['users'=>$users,'drivers'=>$drivers] as $table=>$names) {
            $columns = $metadata['columns'][$table] ?? [];
            if (array_column($columns, 'COLUMN_NAME') !== $names) throw new SafetyException('MIGRATION_010_COLUMNS_DRIFT_'.$table);
            $byName = array_column($columns, null, 'COLUMN_NAME');
            self::column($byName['id'], 'bigint unsigned', 'NO');
            if ($table === 'users') {
                self::column($byName['email'], 'varchar(254)', $postflight ? 'YES' : 'NO');
                self::column($byName['username'], 'varchar(50)', 'YES');
                self::column($byName['auth_version'], 'bigint unsigned', 'NO');
                if ($postflight) self::column($byName['nickname'], 'varchar(80)', 'YES');
            } elseif ($postflight) self::column($byName['user_id'], 'bigint unsigned', 'YES');
            if (($metadata['engines'][$table] ?? null) !== 'InnoDB') throw new SafetyException('MIGRATION_010_ENGINE_REJECTED');
        }
        $indexes = $metadata['indexes'] ?? [];
        foreach ([['users','PRIMARY','id'],['users','email','email'],['users','uq_users_username','username'],['drivers','PRIMARY','id']] as [$table,$index,$column]) {
            self::index($indexes, $table, $index, $column);
        }
        $linkIndexes = array_values(array_filter($indexes, static fn(array $r): bool=>$r['TABLE_NAME']==='drivers' && $r['INDEX_NAME']==='uq_drivers_user_id'));
        $linkForeignKeys = $metadata['link_foreign_keys'] ?? [];
        if ($postflight) {
            self::index($indexes, 'drivers', 'uq_drivers_user_id', 'user_id');
            if (count($linkForeignKeys) === 1 && ($linkForeignKeys[0]['UPDATE_RULE'] ?? null) === 'RESTRICT') $linkForeignKeys[0]['UPDATE_RULE'] = 'NO ACTION';
            if (count($linkForeignKeys) !== 1 || $linkForeignKeys[0] !== [
                'CONSTRAINT_NAME'=>'fk_drivers_user','COLUMN_NAME'=>'user_id','REFERENCED_TABLE_SCHEMA'=>MigrationTarget::TEST,
                'REFERENCED_TABLE_NAME'=>'users','REFERENCED_COLUMN_NAME'=>'id','DELETE_RULE'=>'RESTRICT','UPDATE_RULE'=>'NO ACTION',
            ]) throw new SafetyException('MIGRATION_010_FOREIGN_KEY_REJECTED');
        } elseif ($linkIndexes !== [] || $linkForeignKeys !== []) throw new SafetyException('MIGRATION_010_PARTIAL_LINK_SCHEMA');
        $roles = array_column($metadata['roles'] ?? [], 'description', 'name');
        $roleNames = array_keys($roles); sort($roleNames);
        $expectedRoles = ['admin','approver','requester','vehicle_officer'];
        if ($postflight) $expectedRoles[] = 'driver';
        sort($expectedRoles);
        if ($roleNames !== $expectedRoles || ($postflight && $roles['driver'] !== 'พนักงานขับรถ')) throw new SafetyException('MIGRATION_010_ROLES_DRIFT');
    }

    /** Diagnostic only: the result must never be used to resume DDL automatically. */
    public static function partialState(array $metadata): array
    {
        return [
            'nickname_present'=>in_array('nickname', array_column($metadata['columns']['users'] ?? [], 'COLUMN_NAME'), true),
            'email_nullable'=>array_column($metadata['columns']['users'] ?? [], 'IS_NULLABLE', 'COLUMN_NAME')['email'] ?? null,
            'driver_user_id_present'=>in_array('user_id', array_column($metadata['columns']['drivers'] ?? [], 'COLUMN_NAME'), true),
            'driver_role_present'=>in_array('driver', array_column($metadata['roles'] ?? [], 'name'), true),
            'automatic_retry_allowed'=>false,
        ];
    }

    private static function column(array $column, string $type, string $nullable): void
    {
        if (strtolower((string)$column['COLUMN_TYPE']) !== $type || $column['IS_NULLABLE'] !== $nullable) throw new SafetyException('MIGRATION_010_COLUMN_TYPE_OR_NULLABILITY');
    }

    private static function index(array $indexes, string $table, string $name, string $column): void
    {
        $found = array_values(array_filter($indexes, static fn(array $r): bool=>$r['TABLE_NAME']===$table && $r['INDEX_NAME']===$name));
        if (count($found)!==1 || $found[0]['COLUMN_NAME']!==$column || (int)$found[0]['NON_UNIQUE']!==0 || (int)$found[0]['SEQ_IN_INDEX']!==1) {
            throw new SafetyException('MIGRATION_010_UNIQUE_INDEX_REJECTED');
        }
    }
}
