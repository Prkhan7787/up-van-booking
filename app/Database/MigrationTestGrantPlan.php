<?php
declare(strict_types=1);
namespace App\Database;

/** Least-privilege effective grants required by the test-only migration runner, 001–005. */
final class MigrationTestGrantPlan
{
    public static function rules(): array
    {
        return [
            'database' => ['CREATE'],
            'tables' => [
                'schema_migrations' => ['SELECT', 'INSERT', 'UPDATE'],
                'users' => ['ALTER', 'CREATE', 'INSERT'],
                // MigrationRunner::status() verifies Migration 004 table metadata before 005.
                'auth_login_buckets' => ['SELECT'],
                'vehicles' => [
                    'ALTER' => null,
                    'CREATE' => null,
                    'INSERT' => null,
                    'SELECT' => ['id', 'registration_number', 'registration_province', 'label', 'passenger_capacity', 'is_active', 'created_by', 'updated_by', 'created_at', 'updated_at'],
                ],
                'booking_requests' => ['SELECT' => ['id']],
                'vehicle_assignments' => ['SELECT' => ['id']],
            ],
        ];
    }

    /** Only non-secret reason codes and object/privilege labels are returned. */
    public static function safeDiagnostics(array $evaluation): array
    {
        $reasons=$evaluation['reason_codes']??[];
        $scopeReasons=array_values(array_intersect($reasons,['global_privilege','other_or_wildcard_database','other_database_table_scope','grant_option']));
        $mismatchType=$scopeReasons!==[]?'rejected_scope':(in_array('unexpected_privilege',$reasons,true)?'rejected_privilege':($evaluation['status']==='UNVERIFIED'?'unverified':(in_array('missing_privilege',$reasons,true)?'missing_privilege':'unknown')));
        return [
            'mismatch_type'=>$mismatchType,
            'rejected_privileges'=>array_values(array_filter($evaluation['unexpected_privileges']??[],static fn($v):bool=>is_string($v))),
            'rejected_scope'=>$scopeReasons,
            'missing_privileges'=>array_values(array_filter($evaluation['missing_privileges']??[],static fn($v):bool=>is_string($v))),
            'role_privileges_resolved'=>(bool)($evaluation['role_privileges_resolved']??false),
        ];
    }
}
