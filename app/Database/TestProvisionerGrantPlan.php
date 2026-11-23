<?php
declare(strict_types=1);
namespace App\Database;

final class TestProvisionerGrantPlan
{
    public static function rules(): array
    {
        return ['database'=>[], 'tables'=>[
            'users'=>[
                'SELECT'=>['id','email','username'],
                'INSERT'=>['email','username','display_name','password_hash','is_active'],
            ],
            'roles'=>['SELECT'=>['id','name']],
            'user_roles'=>[
                'SELECT'=>['user_id','role_id'],
                'INSERT'=>['user_id','role_id'],
            ],
            'audit_logs'=>['INSERT'=>['user_id','action','entity_type','entity_id','metadata']],
        ]];
    }

    public static function grantSql(bool $partialRevokes): array
    {
        // These are table/column scope grants, where the schema qualifier is literal.
        $schema = 'up_van_booking_test';
        $account = "'up_van_test_provisioner'@'127.0.0.1'";
        return [
            "GRANT SELECT (`id`, `email`, `username`), INSERT (`email`, `username`, `display_name`, `password_hash`, `is_active`) ON `{$schema}`.`users` TO {$account}",
            "GRANT SELECT (`id`, `name`) ON `{$schema}`.`roles` TO {$account}",
            "GRANT SELECT (`user_id`, `role_id`), INSERT (`user_id`, `role_id`) ON `{$schema}`.`user_roles` TO {$account}",
            "GRANT INSERT (`user_id`, `action`, `entity_type`, `entity_id`, `metadata`) ON `{$schema}`.`audit_logs` TO {$account}",
        ];
    }
}
