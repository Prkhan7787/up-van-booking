<?php
declare(strict_types=1);
namespace App\Database;

/** Least-privilege account and effective-grant policy for Migration 010 inspection only. */
final class Migration010ReadOnlyGrantPlan
{
    public const ACCOUNT='up_van_migration_preflight';

    /** Exact metadata and ledger reads used by MigrationRunner::driver010Inspect() and status(). */
    public static function rules(): array
    {
        return [
            'database'=>[],
            'tables'=>[
                'schema_migrations'=>['SELECT'=>['version','checksum','status','completed_statements','total_statements']],
                'users'=>['SELECT'=>['id']],
                'drivers'=>['SELECT'=>['id']],
                'roles'=>['SELECT'=>['name','description']],
                // These columns provide object visibility for existing 004–008 schema postconditions.
                'auth_login_buckets'=>['SELECT'=>['bucket_scope']],
                'vehicles'=>['SELECT'=>['id']],
                'booking_requests'=>['SELECT'=>['id']],
            ],
        ];
    }

    /**
     * Accept only an exact read-only grant set. Role grants are rejected outright so
     * dormant, active, mandatory, and inherited write privileges cannot be overlooked.
     */
    public static function evaluate(array $grants, bool $partialRevokes, bool $rolesResolved, string $activeRoles, string $mandatoryRoles=''): array
    {
        if (!$rolesResolved || $activeRoles !== 'NONE' || trim($mandatoryRoles)!=='' || self::hasRoleAssignment($grants)) {
            return [
                'status'=>'FAIL','missing_privileges'=>[],'unexpected_privileges'=>[],
                'reason_codes'=>['role_privileges_not_permitted'],'role_privileges_resolved'=>$rolesResolved,
            ];
        }
        return GrantVerifier::evaluate($grants,MigrationTarget::TEST,self::rules(),$partialRevokes,$rolesResolved);
    }

    private static function hasRoleAssignment(array $grants): bool
    {
        $quoted="(?:`[^`]+`|'[^']+')";
        $account=$quoted.'@'.$quoted;
        foreach($grants as $grant) {
            if(!is_string($grant)) return true;
            if(preg_match('/^GRANT\s+'.$account.'(?:\s*,\s*'.$account.')*\s+TO\s+/i',$grant)) return true;
        }
        return false;
    }
}
