<?php
declare(strict_types=1);
namespace App\Database;

/** Proposed privileges only. Never executes SQL. */
final class SystemLogGrantPlan
{
    public const COLUMNS = ['id','user_id','action','entity_type','entity_id','metadata','created_at'];
    public static function capabilityRules(): array
    {
        return ['tables'=>['audit_logs'=>['SELECT'=>self::COLUMNS]]];
    }
    public static function rules(): array
    {
        $rules=AdminUserGrantPlan::rules();
        $rules['tables']['audit_logs']['SELECT']=self::COLUMNS;
        return $rules;
    }
    public static function supplementalSql(): string
    {
        return "GRANT SELECT (`id`, `user_id`, `action`, `entity_type`, `entity_id`, `metadata`, `created_at`) ON `up_van_booking_test`.`audit_logs` TO 'up_van_auth_test'@'127.0.0.1'";
    }
}
