<?php
declare(strict_types=1);
namespace App\Database;

final class Migration011ApplyBackup
{
    public const MAXIMUM_AGE_SECONDS=3600;
    public static function verify(string $directory,string $pin): array
    {
        // Reuse the existing logical backup format, file hash, identity and inventory checks.
        $result=Migration010ApplyBackup::verify($directory,$pin);
        $bytes=(string)file_get_contents($directory.'/backup-manifest.json');
        if(!hash_equals($pin,hash('sha256',$bytes)))throw new SafetyException('MIGRATION_011_BACKUP_CHANGED');
        $manifest=json_decode($bytes,true,32,JSON_THROW_ON_ERROR);
        $start=$manifest['started_utc']??null;$end=$manifest['finished_utc']??null;
        if (!is_string($start)||!is_string($end)||!preg_match('/\A\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:\.\d+)?Z\z/D',$start)
            ||!preg_match('/\A\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:\.\d+)?Z\z/D',$end)) throw new SafetyException('MIGRATION_011_BACKUP_TIMESTAMP_REQUIRED');
        $began=strtotime($start);$finished=strtotime($end);
        if ($began===false||$finished===false||$began>$finished||$finished>time()||time()-$began>self::MAXIMUM_AGE_SECONDS
            || in_array('microsoft_identities',$manifest['tables'],true)) throw new SafetyException('MIGRATION_011_BACKUP_STALE_OR_STATE_REJECTED');
        return $result+['verified_at_utc'=>gmdate('c'),'backup_finished_utc'=>$end,'maximum_age_seconds'=>self::MAXIMUM_AGE_SECONDS];
    }
}
