<?php
declare(strict_types=1);
namespace App\Database;

/** Exact extra runtime capability required by Driver registration, linking and own-schedule reads. */
final class DriverAuthGrantPlan
{
    public static function additions(): array
    {
        return ['database'=>[], 'tables'=>[
            'users'=>[
                'SELECT'=>['display_name','nickname'],
                'INSERT'=>['username','email','display_name','nickname','password_hash','is_active','auth_version'],
            ],
            'user_roles'=>['INSERT'=>['user_id','role_id']],
            'drivers'=>[
                'SELECT'=>['id','staff_code','display_name','is_active','user_id'],
                'UPDATE'=>['user_id'],
            ],
            'vehicle_assignments'=>['SELECT'=>['id','booking_request_id','vehicle_id','driver_id','starts_at','ends_at','status']],
            'booking_requests'=>['SELECT'=>['id','workflow_status','pickup_location','dropoff_location']],
            'vehicles'=>['SELECT'=>['id','label','registration_number']],
            'booking_passengers'=>['SELECT'=>['id','booking_request_id','display_name','affiliation']],
        ]];
    }

    public static function rules(): array
    {
        return BookingCapabilityGrantGuard::merge(AuthRuntimeGrantPlan::rules(), self::additions());
    }

    /** Review artifact only. These grants are never executed by application code. */
    public static function grantSql(): array
    {
        $base = AuthRuntimeGrantPlan::grantSql(false);
        $schema = 'up_van_booking_test';
        $account = "'up_van_auth_test'@'127.0.0.1'";
        return [...$base,
            "GRANT SELECT (`display_name`, `nickname`), INSERT (`username`, `email`, `display_name`, `nickname`, `password_hash`, `is_active`, `auth_version`) ON `{$schema}`.`users` TO {$account}",
            "GRANT INSERT (`user_id`, `role_id`) ON `{$schema}`.`user_roles` TO {$account}",
            "GRANT SELECT (`id`, `staff_code`, `display_name`, `is_active`, `user_id`), UPDATE (`user_id`) ON `{$schema}`.`drivers` TO {$account}",
            "GRANT SELECT (`id`, `booking_request_id`, `vehicle_id`, `driver_id`, `starts_at`, `ends_at`, `status`) ON `{$schema}`.`vehicle_assignments` TO {$account}",
            "GRANT SELECT (`id`, `workflow_status`, `pickup_location`, `dropoff_location`) ON `{$schema}`.`booking_requests` TO {$account}",
            "GRANT SELECT (`id`, `label`, `registration_number`) ON `{$schema}`.`vehicles` TO {$account}",
            "GRANT SELECT (`id`, `booking_request_id`, `display_name`, `affiliation`) ON `{$schema}`.`booking_passengers` TO {$account}",
        ];
    }
}
