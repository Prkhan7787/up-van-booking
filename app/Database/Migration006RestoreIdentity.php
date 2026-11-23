<?php
declare(strict_types=1);
namespace App\Database;

/** Restore rehearsal identity only; never changes the migrator account/grant policy. */
final class Migration006RestoreIdentity
{
    public static function assertConfig(array $config): void
    {
        if (($config['host'] ?? null)!=='127.0.0.1' || (string)($config['port'] ?? '')!=='3307'
            || ($config['database'] ?? null)!==MigrationTarget::TEST
            || ($config['username'] ?? null)!=='up_van_test_migrator') {
            throw new SafetyException('MIGRATION_006_RESTORE_CONFIG_REJECTED');
        }
    }

    public static function assertIdentity(array $identity): void
    {
        $data=str_replace('\\','/',(string)($identity['datadir'] ?? ''));
        if ((int)($identity['port'] ?? 0)!==3307
            || ($identity['uuid'] ?? null)!=='37e4d222-dda2-11f1-b503-5c2886f057d5'
            || rtrim($data,'/')!=='C:/Users/User/mysql-restore-006-data'
            || ($identity['database_name'] ?? null)!==MigrationTarget::TEST
            || ($identity['account'] ?? null)!=='up_van_test_migrator@127.0.0.1') {
            throw new SafetyException('MIGRATION_006_RESTORE_IDENTITY_REJECTED');
        }
    }

    public static function inspect(\PDO $db): array
    {
        $identity=$db->query('SELECT @@port AS port, @@server_uuid AS uuid, @@datadir AS datadir, DATABASE() AS database_name, CURRENT_USER() AS account')->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($identity)) throw new SafetyException('MIGRATION_006_RESTORE_IDENTITY_REJECTED');
        self::assertIdentity($identity);
        return $identity;
    }
}
