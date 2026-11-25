<?php
declare(strict_types=1);
namespace App\Database;

/** Current-state recovery snapshot. A pre-011 backup is never accepted here. */
final class Migration011ReconciliationBackup
{
    public const MAXIMUM_AGE_SECONDS=3600;
    public const LEDGER_STATE='001-009 applied; 011 failed 1/1; 010 absent';

    public static function verify(string $directory,string $manifestSha256): array
    {
        $result=Migration010ApplyBackup::verify($directory,$manifestSha256,true);
        $bytes=(string)file_get_contents($directory.'/backup-manifest.json');
        $manifest=json_decode($bytes,true,32,JSON_THROW_ON_ERROR);
        if(($manifest['ledger']??null)!==self::LEDGER_STATE)throw new SafetyException('MIGRATION_011_RECONCILIATION_BACKUP_STATE_REJECTED');
        $expected=['approval_decisions','audit_logs','auth_login_buckets','booking_notifications','booking_passengers',
            'booking_requests','booking_reviews','booking_status_history','drivers','microsoft_identities','roles',
            'schema_migrations','user_roles','users','vehicle_assignments','vehicles'];
        $tables=$manifest['tables']??null;
        if(!is_array($tables)||!array_is_list($tables))throw new SafetyException('MIGRATION_011_RECONCILIATION_BACKUP_INVENTORY_REJECTED');
        sort($tables);sort($expected);
        if($tables!==$expected)throw new SafetyException('MIGRATION_011_RECONCILIATION_BACKUP_INVENTORY_REJECTED');
        $start=$manifest['started_utc']??null;$end=$manifest['finished_utc']??null;
        if(!is_string($start)||!is_string($end)||!preg_match('/\A\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:\.\d+)?Z\z/D',$start)
            ||!preg_match('/\A\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:\.\d+)?Z\z/D',$end))throw new SafetyException('MIGRATION_011_RECONCILIATION_BACKUP_TIMESTAMP_REQUIRED');
        $began=strtotime($start);$finished=strtotime($end);$now=time();
        if($began===false||$finished===false||$began>$finished||$finished>$now||$now-$began>self::MAXIMUM_AGE_SECONDS)
            throw new SafetyException('MIGRATION_011_RECONCILIATION_BACKUP_STALE_OR_STATE_REJECTED');
        return $result+['base_table_count'=>16,'verified_at_utc'=>gmdate('c'),'backup_finished_utc'=>$end,
            'maximum_age_seconds'=>self::MAXIMUM_AGE_SECONDS,'ledger_state'=>self::LEDGER_STATE];
    }
}
