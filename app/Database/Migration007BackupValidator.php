<?php
declare(strict_types=1);
namespace App\Database;

final class Migration007BackupValidator
{
    public static function inspect(string $path,string $expectedSha256): array
    {
        if(!preg_match('/\A[a-f0-9]{64}\z/iD',$expectedSha256) || !is_file($path) || is_link($path))throw new SafetyException('MIGRATION_007_BACKUP_REJECTED');
        $size=filesize($path);$hash=hash_file('sha256',$path);
        if(!is_int($size) || $size<1 || !is_string($hash) || !hash_equals(strtolower($expectedSha256),strtolower($hash)))throw new SafetyException('MIGRATION_007_BACKUP_REJECTED');
        return ['bytes'=>$size,'sha256'=>strtolower($hash)];
    }
}
