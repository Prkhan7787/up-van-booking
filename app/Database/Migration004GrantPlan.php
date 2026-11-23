<?php
declare(strict_types=1);
namespace App\Database;

final class Migration004GrantPlan
{
    /** Exact privileges required by the current candidate Migration 004 and existing ledger. */
    public static function rules(): array
    {
        return [
            'database'=>['CREATE'],
            'tables'=>[
                'schema_migrations'=>['SELECT','INSERT','UPDATE'],
                // Database-level CREATE also satisfies ALTER TABLE's CREATE prerequisite.
                'users'=>['ALTER','CREATE','INSERT'],
            ],
        ];
    }

    /** Prepared SQL text for administrator review only; this method never executes it. */
    public static function sql(bool $partialRevokes): array
    {
        $schema='up_van_booking_test';
        $dbScope='`'.($partialRevokes ? $schema : str_replace('_','\\_',$schema)).'`';
        $account="'up_van_test_migrator'@'127.0.0.1'";
        return [
            "GRANT CREATE ON {$dbScope}.* TO {$account}",
            "GRANT SELECT, INSERT, UPDATE ON `{$schema}`.`schema_migrations` TO {$account}",
            "GRANT ALTER, INSERT ON `{$schema}`.`users` TO {$account}",
        ];
    }
}
