<?php
declare(strict_types=1);
namespace App\Database;

use PDO;

/** Hard pins the controlled 007 workflow to the approved local test instance. */
final class Migration007TargetIdentity
{
    public const UUID='77218fbd-d66d-11f1-9a0a-5c2886f057d5';
    public const DATADIR='C:/Users/User/mysql-data';

    public static function assertConfig(array $config): void
    {
        if(($config['host']??null)!=='127.0.0.1' || (string)($config['port']??'')!=='3306'
            || ($config['database']??null)!==MigrationTarget::TEST || ($config['username']??null)!=='up_van_test_migrator') {
            throw new SafetyException('MIGRATION_007_CONFIG_REJECTED');
        }
    }

    public static function inspect(PDO $pdo): array
    {
        $row=$pdo->query('SELECT @@port AS port,@@server_uuid AS uuid,@@datadir AS datadir,VERSION() AS version,DATABASE() AS database_name,CURRENT_USER() AS account_name')->fetch(PDO::FETCH_ASSOC);
        if(!is_array($row))throw new SafetyException('MIGRATION_007_IDENTITY_REJECTED');
        $data=rtrim(str_replace('\\','/',(string)($row['datadir']??'')),'/');
        if((int)($row['port']??0)!==3306 || ($row['uuid']??null)!==self::UUID
            || strcasecmp($data,self::DATADIR)!==0 || ($row['database_name']??null)!==MigrationTarget::TEST
            || ($row['account_name']??null)!=='up_van_test_migrator@127.0.0.1'
            || !preg_match('/^8\.4\.\d+(?:[-.]|$)/D',(string)($row['version']??''))) {
            throw new SafetyException('MIGRATION_007_IDENTITY_REJECTED');
        }
        return $row;
    }
}
