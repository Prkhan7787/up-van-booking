<?php
declare(strict_types=1);
namespace App\Database;

final class Migration010ApplyBackup
{
    public static function verify(string $directory, string $manifestSha256): array
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
        if (!is_array($manifest)
            || ($manifest['format'] ?? null) !== 'upvan-pre010-logical-backup-v1'
            || ($manifest['host'] ?? null) !== '127.0.0.1' || ($manifest['port'] ?? null) !== 3306
            || ($manifest['database'] ?? null) !== MigrationTarget::TEST
            || ($manifest['uuid'] ?? null) !== DriverDatabaseIdentity::UUID
            || ($manifest['version'] ?? null) !== Migration010ApplyGuard::MYSQL_VERSION
            || ($manifest['ledger'] ?? null) !== '001-009 applied; 010 absent'
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
        return ['manifest_sha256'=>$manifestSha256,'base_table_count'=>count($tables)];
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
