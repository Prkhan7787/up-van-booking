<?php
declare(strict_types=1);
namespace App\Database;

/** Narrow, deterministic checks for the test-only 005 vehicles migration. */
final class Migration005VehicleValidator
{
    public const VERSION = '005_vehicle_registration_only';
    public const SHA256 = '4a6cf9274da4b60543b67004b35b4a46cd816195c9dfb6f5aa361b503d578ae6';

    /** SELECT-only grants for preflight, scoped to the existing test migrator. */
    public static function readOnlyPreflightGrants(): array
    {
        return [
            "GRANT SELECT (\`id\`, \`registration_number\`, \`registration_province\`, \`label\`, \`passenger_capacity\`, \`is_active\`, \`created_by\`, \`updated_by\`, \`created_at\`, \`updated_at\`) ON \`up_van_booking_test\`.\`vehicles\` TO 'up_van_test_migrator'@'127.0.0.1'",
            "GRANT SELECT (\`id\`) ON \`up_van_booking_test\`.\`booking_requests\` TO 'up_van_test_migrator'@'127.0.0.1'",
            "GRANT SELECT (\`id\`) ON \`up_van_booking_test\`.\`vehicle_assignments\` TO 'up_van_test_migrator'@'127.0.0.1'",
        ];
    }

    public static function expectedVehicleColumns(): array
    {
        return ['id','registration_number','registration_province','label','passenger_capacity','is_active','created_by','updated_by','created_at','updated_at'];
    }

    /** Returns metadata-only differences; never reads application rows. */
    public static function vehicleColumnDiff(array $actual): array
    {
        $expected=self::expectedVehicleColumns();
        $names=array_values(array_filter($actual,'is_string'));
        return [
            'missing'=>array_values(array_diff($expected,$names)),
            'unexpected'=>array_values(array_diff($names,$expected)),
            'order_matches'=>$names===$expected,
        ];
    }

    public static function assertTarget(string $target): void
    {
        if ($target !== MigrationTarget::TEST) throw new SafetyException('Migration 005 is restricted to up_van_booking_test.');
    }

    public static function assertLedgerApplied(array $row): void
    {
        if (($row['version'] ?? null)!==self::VERSION || ($row['checksum'] ?? null)!==self::SHA256
            || ($row['status'] ?? null)!=='applied' || (int)($row['completed_statements'] ?? 0)!==1
            || (int)($row['total_statements'] ?? 0)!==1) throw new SafetyException('Migration 005 ledger is partial or inconsistent; manual inspection required.');
    }

    /** Null means 001–004 are sound and 005 is absent/pending. */
    public static function baselineLedgerError(array $plan, array $rows): ?string
    {
        $baseline=array_slice($plan,0,4);
        if (count($baseline)!==4 || count($rows)!==4) return 'LEDGER_ROWS_NOT_BASELINE';
        foreach ($baseline as $i=>$entry) {
            $row=$rows[$i]??[];
            if (($row['version']??null)!==$entry['version']) return 'LEDGER_ORDER_MISMATCH';
            if (!hash_equals((string)$entry['sha256'],(string)($row['checksum']??''))) return 'LEDGER_CHECKSUM_MISMATCH';
            if (($row['status']??null)!=='applied') return 'LEDGER_PARTIAL_OR_UNAPPLIED';
            if ((int)($row['completed_statements']??-1)!==count($entry['statements'])
                || (int)($row['total_statements']??-1)!==count($entry['statements'])) return 'LEDGER_STATEMENT_COUNT_MISMATCH';
        }
        return null;
    }

    public static function safetyCode(string $message): string
    {
        return match ($message) {
            'Migration connection mismatch.'=>'DATABASE_TARGET_MISMATCH',
            'Migration ledger is absent.'=>'LEDGER_MISSING',
            'Migration 005 duplicate registration precondition failed.'=>'DUPLICATE_REGISTRATION',
            'Migration 005 schema drift: vehicles columns mismatch.'=>'VEHICLE_COLUMNS_DRIFT',
            'Migration 005 vehicle column metadata is incomplete or differs from expected schema.'=>'VEHICLE_METADATA_INCOMPLETE',
            'Migration 005 schema drift: old composite unique index mismatch.'=>'OLD_UNIQUE_INDEX_DRIFT',
            'Migration 005 schema drift detected.'=>'VEHICLE_SCHEMA_DRIFT',
            'Migration 005 ledger is partial or inconsistent; manual inspection required.'=>'MIGRATION_005_PARTIAL_OR_INVALID',
            default=>'PREFLIGHT_SAFETY_CHECK_FAILED',
        };
    }

    public static function assertPreconditions(array $columns, array $indexes, array $duplicates): void
    {
        if ($duplicates !== []) throw new SafetyException('Migration 005 duplicate registration precondition failed.');
        $expected=self::expectedVehicleColumns();
        if ($columns !== $expected) throw new SafetyException('Migration 005 schema drift: vehicles columns mismatch.');
        if ($indexes !== [['uq_vehicle_registration', 'registration_number', 1, 0], ['uq_vehicle_registration', 'registration_province', 2, 0]]) {
            throw new SafetyException('Migration 005 schema drift: old composite unique index mismatch.');
        }
    }

    public static function assertPostconditions(array $columns, array $indexes): void
    {
        if (in_array('registration_province', $columns, true)) throw new SafetyException('Migration 005 postcondition failed: province column remains.');
        if ($columns === [] || $indexes !== [['uq_vehicle_registration_number', 'registration_number', 1, 0]]) {
            throw new SafetyException('Migration 005 postcondition failed: registration unique index mismatch.');
        }
    }
}
