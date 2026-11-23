<?php
declare(strict_types=1);
namespace App\Database;

/** Explicit 006 profile for the existing test migrator; never used by runtime. */
final class Migration006GrantPlan
{
    /** Compatibility representation of the reviewed post-006 legacy column set. */
    public static function rules(): array
    {
        return self::postRules(true);
    }

    public static function preRules(): array
    {
        // Retain the account's reviewed 001–005 privileges; do not expand that profile.
        $rules=MigrationTestGrantPlan::rules();
        $rules['tables']['vehicles']['SELECT']=array_values(array_diff(
            $rules['tables']['vehicles']['SELECT'], ['registration_province']
        ));
        $rules['tables']['booking_requests']['ALTER']=null;
        $rules['tables']['booking_requests']['CREATE']=null;
        $rules['tables']['booking_requests']['INSERT']=null;
        // MySQL 8.4 ADD FOREIGN KEY checks REFERENCES at parent TABLE scope.
        $rules['tables']['vehicles']['REFERENCES']=null;
        // SHOW GRANTS can retain the historical column grant alongside table
        // REFERENCES. Accept only that exact redundant id grant, never alone.
        $rules['optional_redundant_columns']=['vehicles'=>['REFERENCES'=>['id']]];
        return $rules;
    }

    /** DDL/postflight read metadata, not province/requested-vehicle row values. */
    public static function postRules(bool $legacyProvinceRead=false): array
    {
        $rules=self::preRules();
        if($legacyProvinceRead) $rules['tables']['vehicles']['SELECT'][]='registration_province';
        return $rules;
    }

    public static function profiles(string $phase): array
    {
        return match($phase) {
            'pre'=>['schema_005'=>self::preRules()],
            'post'=>['schema_006'=>self::postRules(), 'schema_006_legacy_province_read'=>self::postRules(true)],
            default=>throw new SafetyException('MIGRATION_006_GRANT_PHASE_REJECTED'),
        };
    }

    /** A phase is evidence from a clean pinned ledger, never from failed probes. */
    public static function phaseFromLedger(array $ledger): string
    {
        $count=count($ledger);
        if(!in_array($count,[5,6],true)) throw new SafetyException('MIGRATION_006_GRANT_LEDGER_REJECTED');
        $plan=MigrationPlan::load(true);
        foreach(array_values($ledger) as $i=>$row) {
            $entry=$plan[$i];
            $field=$entry['version'];
            if(($row['version']??null)!==$entry['version']) throw new MigrationSchemaException('MIGRATION_006_LEDGER_VERSION_MISMATCH','LEDGER',$field.'.version');
            if(($row['status']??null)!=='applied') throw new MigrationSchemaException('MIGRATION_006_LEDGER_NOT_APPLIED','LEDGER',$field.'.status');
            if(!is_string($row['checksum']??null) || !hash_equals($entry['sha256'],$row['checksum'])) throw new MigrationSchemaException('MIGRATION_006_LEDGER_CHECKSUM_MISMATCH','LEDGER',$field.'.checksum');
            if((int)($row['completed_statements']??-1)!==count($entry['statements'])
                || (int)($row['total_statements']??-1)!==count($entry['statements'])) throw new MigrationSchemaException('MIGRATION_006_LEDGER_STATEMENT_COUNT_MISMATCH','LEDGER',$field.'.statement_count');
        }
        return $count===5?'pre':'post';
    }

    public static function schemaPhase(\PDO $pdo): string
    {
        // Reuse the existing read-only Runner/Validators: no alternate schema guard.
        $runner=new MigrationRunner($pdo,MigrationTarget::TEST);
        try {
            $state=$runner->status();
            $phase=self::phaseFromLedger($state['migrations']);
            if($phase==='pre') $runner->vehicle006Preflight();
        } catch(\Throwable $error) {
            if($error instanceof \PDOException) throw $error;
            if($error instanceof MigrationSchemaException) throw $error;
            throw MigrationSchemaException::fromFailure($error,$runner);
        }
        // status() already validates 006's nullable column types, index/FK and UNIQUE
        // when its ledger is applied. Partial or invisible metadata never falls back.
        return $phase;
    }
}
