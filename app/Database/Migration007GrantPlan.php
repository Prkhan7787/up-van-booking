<?php
declare(strict_types=1);
namespace App\Database;

/** Keeps the already-reviewed 006 migrator scope unchanged across 007. */
final class Migration007GrantPlan
{
    public static function profiles(string $phase): array
    {
        if(!in_array($phase,['pre','post'],true))throw new SafetyException('MIGRATION_007_GRANT_PHASE_REJECTED');
        // 007 executes one ALTER on vehicles and reads/writes only the existing migration ledger.
        // No REFERENCES, CREATE, global privilege, or runtime fuel-column privilege is added.
        return [$phase==='pre'?'schema_006_before_007':'schema_007_after_apply'=>Migration006GrantPlan::postRules(true)];
    }

    public static function phaseFromLedger(array $ledger): string
    {
        $rows=array_values($ledger);
        $count=count($rows);
        if(!array_is_list($ledger) || !in_array($count,[6,7,8,9],true))throw new SafetyException('MIGRATION_007_LEDGER_STATE_REJECTED');
        // Validate the full pinned prefix rather than inferring phase from row count.
        // Migration 008 retains the reviewed post-007 least-privilege grant set.
        $plan=MigrationPlan::load(true,true,true,$count===9);
        foreach(array_slice($plan,0,$count) as $index=>$entry)self::assertLedgerRow($rows[$index]??null,$entry);
        return $count===6?'pre':'post';
    }

    private static function assertLedgerRow(mixed $row,array $entry): void
    {
        if(!is_array($row) || ($row['version']??null)!==$entry['version']
            || !is_string($row['checksum']??null) || !hash_equals($entry['sha256'],$row['checksum'])
            || ($row['status']??null)!=='applied'
            || (int)($row['completed_statements']??-1)!==count($entry['statements']??[])
            || (int)($row['total_statements']??-1)!==count($entry['statements']??[])) {
            throw new SafetyException('MIGRATION_007_LEDGER_STATE_REJECTED');
        }
    }
}
