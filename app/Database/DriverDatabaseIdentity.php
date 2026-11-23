<?php
declare(strict_types=1);
namespace App\Database;

use PDO;

final class DriverDatabaseIdentity
{
    public const UUID = '77218fbd-d66d-11f1-9a0a-5c2886f057d5';

    public static function assertConfig(array $config, string $account = 'up_van_auth_test'): void
    {
        if (($config['host'] ?? null) !== '127.0.0.1' || (string)($config['port'] ?? '') !== '3306'
            || ($config['database'] ?? null) !== MigrationTarget::TEST || ($config['username'] ?? null) !== $account) {
            throw new SafetyException('DRIVER_DATABASE_CONFIG_REJECTED');
        }
    }

    public static function inspect(PDO $db, string $account = 'up_van_auth_test'): void
    {
        $row = $db->query('SELECT @@port AS port, @@server_uuid AS uuid, DATABASE() AS database_name, CURRENT_USER() AS account_name')->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || (string)($row['port'] ?? '') !== '3306' || ($row['uuid'] ?? null) !== self::UUID
            || ($row['database_name'] ?? null) !== MigrationTarget::TEST || ($row['account_name'] ?? null) !== $account.'@127.0.0.1') {
            throw new SafetyException('DRIVER_DATABASE_IDENTITY_REJECTED');
        }
    }
}
