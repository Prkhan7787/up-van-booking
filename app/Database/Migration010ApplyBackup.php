<?php
declare(strict_types=1);
namespace App\Database;

final class Migration010ApplyBackup
{
    public const MAXIMUM_AGE_SECONDS = 3600;

    public static function verify(string $directory, string $manifestSha256, bool $allowFailed011=false, bool $requireFresh=false): array
    {
        $root = realpath($directory);
        $project = realpath(dirname(__DIR__, 2));
        if ($root === false || $project === false || !is_dir($root) || is_link($directory)
            || str_starts_with(strtolower($root).DIRECTORY_SEPARATOR, strtolower($project).DIRECTORY_SEPARATOR)
            || !preg_match('/\A[a-f0-9]{64}\z/D', $manifestSha256)) {
            throw new SafetyException('MIGRATION_010_BACKUP_PATH_OR_PIN_REJECTED');
        }
        $path = self::file($root, 'backup-manifest.json');
        $source = @file_get_contents($path, false, null, 0, 16385);
        if ($source === false || strlen($source) > 16384 || !hash_equals($manifestSha256, hash('sha256', $source))) {
            throw new SafetyException('MIGRATION_010_BACKUP_MANIFEST_HASH_REJECTED');
        }
        $manifest = json_decode($source, true);
        $ledgerState = $manifest['ledger'] ?? null;
        $hasMicrosoftIdentityBranch = in_array($ledgerState, [
            '001-009 applied; 011 applied; 010 absent',
            '001-009 applied; 011 failed 1/1; 010 absent',
        ], true);
        if (!is_array($manifest)
            || ($manifest['format'] ?? null) !== 'upvan-pre010-logical-backup-v1'
            || ($manifest['host'] ?? null) !== '127.0.0.1' || ($manifest['port'] ?? null) !== 3306
            || ($manifest['database'] ?? null) !== MigrationTarget::TEST
            || ($manifest['uuid'] ?? null) !== DriverDatabaseIdentity::UUID
            || ($manifest['version'] ?? null) !== Migration010ApplyGuard::MYSQL_VERSION
            || !in_array($ledgerState, ['001-009 applied; 010 absent','001-009 applied; 011 applied; 010 absent','001-009 applied; 011 failed 1/1; 010 absent'], true)
            || (!$allowFailed011 && $ledgerState==='001-009 applied; 011 failed 1/1; 010 absent')
            || ($manifest['calendar_view'] ?? null) !== 'calendar_events'
            || ($manifest['calendar_dependencies'] ?? null) !== ['booking_requests','vehicle_assignments']
            || !in_array($manifest['calendar_security'] ?? null, ['INVOKER','DEFINER'], true)) {
            throw new SafetyException('MIGRATION_010_BACKUP_MANIFEST_REJECTED');
        }
        $tables = $manifest['tables'] ?? null;
        $required = ['schema_migrations'];
        foreach (MigrationPlan::driver010LedgerEntries() as $entry) {
            foreach ($entry['objects'] as $object) {
                if ($object !== 'calendar_events') $required[] = $object;
            }
        }
        if (!is_array($tables) || !array_is_list($tables)
            || !array_all($tables, static fn($name) => is_string($name) && preg_match('/\A[a-z_][a-z0-9_]*\z/D', $name))
            || count($tables) !== count(array_unique($tables))
            || array_diff($required, $tables) !== []
            || in_array('calendar_events', $tables, true)) {
            throw new SafetyException('MIGRATION_010_BACKUP_INVENTORY_REJECTED');
        }
        // A fresh 010 backup may be taken after the independent 011 branch. Require
        // the table and its explicit ledger state together; never infer either one.
        if ($hasMicrosoftIdentityBranch !== in_array('microsoft_identities', $tables, true)) {
            throw new SafetyException('MIGRATION_010_BACKUP_BRANCH_STATE_REJECTED');
        }
        if ($requireFresh) {
            $expectedTables = array_values(array_unique($required));
            if ($hasMicrosoftIdentityBranch) $expectedTables[] = 'microsoft_identities';
            sort($expectedTables, SORT_STRING);
            $actualTables = $tables;
            sort($actualTables, SORT_STRING);
            if ($actualTables !== $expectedTables) throw new SafetyException('MIGRATION_010_BACKUP_INVENTORY_REJECTED');
            $start = self::utcTimestamp($manifest['started_utc'] ?? null);
            $finish = self::utcTimestamp($manifest['finished_utc'] ?? null);
            $now = time();
            if ($start > $finish || $finish > $now || $now - $start >= self::MAXIMUM_AGE_SECONDS) {
                throw new SafetyException('MIGRATION_010_BACKUP_STALE_OR_STATE_REJECTED');
            }
        }
        foreach (['base_tables'=>'up_van_booking_test_base_tables.sql','calendar_view'=>'calendar_events_view_restore.sql'] as $key=>$name) {
            $file = self::file($root, $name);
            $expected = $manifest[$key.'_sha256'] ?? null;
            $bytes = $manifest[$key.'_bytes'] ?? null;
            $size = @filesize($file);
            $hash = @hash_file('sha256', $file);
            if (!is_int($bytes) || $bytes < 1 || $bytes !== $size || !is_string($expected)
                || !is_string($hash) || !hash_equals($expected, $hash)) {
                throw new SafetyException('MIGRATION_010_BACKUP_FILE_HASH_REJECTED');
            }
        }
        $result = ['manifest_sha256'=>$manifestSha256,'base_table_count'=>count($tables)];
        if ($requireFresh) {
            if (time() - $start >= self::MAXIMUM_AGE_SECONDS) throw new SafetyException('MIGRATION_010_BACKUP_STALE_OR_STATE_REJECTED');
            $result += ['ledger_state'=>$ledgerState,'expires_at_utc'=>gmdate('c', $start + self::MAXIMUM_AGE_SECONDS)];
        }
        return $result;
    }

    private static function utcTimestamp(mixed $value): int
    {
        if (!is_string($value) || !preg_match('/\A\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:\.\d{1,7})?Z\z/D', $value)) {
            throw new SafetyException('MIGRATION_010_BACKUP_TIMESTAMP_REQUIRED');
        }
        $timestamp = strtotime($value);
        if ($timestamp === false || gmdate('Y-m-d\TH:i:s', $timestamp) !== substr($value, 0, 19)) {
            throw new SafetyException('MIGRATION_010_BACKUP_TIMESTAMP_REQUIRED');
        }
        return $timestamp;
    }

    private static function file(string $root, string $name): string
    {
        $path = $root.DIRECTORY_SEPARATOR.$name;
        clearstatcache(true, $path);
        if (is_link($path) || !is_file($path) || realpath($path) !== $path) {
            throw new SafetyException('MIGRATION_010_BACKUP_FILE_REJECTED');
        }
        return $path;
    }
}
