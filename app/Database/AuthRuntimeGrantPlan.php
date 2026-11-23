<?php
declare(strict_types=1);
namespace App\Database;

final class AuthRuntimeGrantPlan
{
    public static function rules(): array
    {
        return ['database'=>[], 'tables'=>[
            'users'=>['SELECT'=>['id','username','is_active','password_hash','auth_version'],'UPDATE'=>['password_hash']],
            'roles'=>['SELECT'=>['id','name']],
            'user_roles'=>['SELECT'=>['user_id','role_id']],
            // SELECT ... FOR UPDATE requires SELECT plus UPDATE, DELETE, or LOCK TABLES.
            // The application also updates only window_started_at and attempt_count.
            'auth_login_buckets'=>[
                'SELECT'=>['bucket_scope','bucket_digest','window_started_at','attempt_count'],
                'INSERT'=>['bucket_scope','bucket_digest','window_started_at','attempt_count'],
                'UPDATE'=>['window_started_at','attempt_count'],
            ],
            'audit_logs'=>['INSERT'=>['user_id','action','entity_type','entity_id','metadata']],
        ]];
    }

    /** SQL is a review artifact only; no statement is executed here. */
    public static function grantSql(bool $partialRevokes): array
    {
        // These are table/column scope grants, where the schema qualifier is literal.
        $schema = 'up_van_booking_test';
        $account = "'up_van_auth_test'@'127.0.0.1'";
        return [
            "GRANT SELECT (`id`, `username`, `is_active`, `password_hash`, `auth_version`) ON `{$schema}`.`users` TO {$account}",
            "GRANT UPDATE (`password_hash`) ON `{$schema}`.`users` TO {$account}",
            "GRANT SELECT (`id`, `name`) ON `{$schema}`.`roles` TO {$account}",
            "GRANT SELECT (`user_id`, `role_id`) ON `{$schema}`.`user_roles` TO {$account}",
            "GRANT SELECT (`bucket_scope`, `bucket_digest`, `window_started_at`, `attempt_count`), INSERT (`bucket_scope`, `bucket_digest`, `window_started_at`, `attempt_count`), UPDATE (`window_started_at`, `attempt_count`) ON `{$schema}`.`auth_login_buckets` TO {$account}",
            "GRANT INSERT (`user_id`, `action`, `entity_type`, `entity_id`, `metadata`) ON `{$schema}`.`audit_logs` TO {$account}",
        ];
    }
}
