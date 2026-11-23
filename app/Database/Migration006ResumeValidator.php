<?php
declare(strict_types=1);
namespace App\Database;

/** The one approved recovery shape; not a general dirty-ledger adoption mechanism. */
final class Migration006ResumeValidator
{
    public const VEHICLE_HASH='8a8561bcf8ae8d774d24e0a1960a30699bfc6d712b77c130f5490b61b9337f7d';
    public const ORIGINAL_BACKUP_HASH='26d5b374af8809eedd12a45892435fbebb1117ab8a359e3b307c4607c4fae766';

    public static function assertLedger(array $ledger): void
    {
        if(count($ledger)!==6)throw new SafetyException('MIGRATION_006_RESUME_LEDGER_REJECTED');
        Migration006GrantPlan::phaseFromLedger(array_slice($ledger,0,5));
        $row=$ledger[5];
        if(($row['version']??null)!==MigrationPlan::VEHICLE006['version']
            || ($row['checksum']??null)!==MigrationPlan::VEHICLE006['sha256']
            || ($row['status']??null)!=='failed' || (int)($row['completed_statements']??-1)!==1
            || (int)($row['total_statements']??-1)!==2 || ($row['failure_code']??null)!=='statement_failed') {
            throw new SafetyException('MIGRATION_006_RESUME_LEDGER_REJECTED');
        }
    }

    public static function assertVehicleBaseline(array $rows,array $counts): string
    {
        $hash=hash('sha256',json_encode($rows,JSON_THROW_ON_ERROR));
        self::assertFingerprint(count($rows),$hash,$counts);
        return $hash;
    }

    public static function assertFingerprint(int $vehicles,string $hash,array $counts): void
    {
        if($vehicles!==2 || !hash_equals(self::VEHICLE_HASH,$hash)
            || (int)($counts['bookings']??-1)!==0 || (int)($counts['assignments']??-1)!==0) {
            throw new SafetyException('MIGRATION_006_RESUME_DATA_CHANGED');
        }
    }

    public static function assertBackups(string $original,string $partial,string $partialHash): void
    {
        if(!is_file($original) || !is_file($partial) || realpath($original)===realpath($partial)
            || filesize($original)!==44529 || filesize($partial)<1
            || !preg_match('/^[a-f0-9]{64}$/D',$partialHash)
            || !hash_equals(self::ORIGINAL_BACKUP_HASH,hash_file('sha256',$original))
            || !hash_equals($partialHash,hash_file('sha256',$partial))) {
            throw new SafetyException('MIGRATION_006_RESUME_BACKUP_REJECTED');
        }
    }
}
