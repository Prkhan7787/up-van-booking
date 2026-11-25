<?php
declare(strict_types=1);
namespace App\Database;

/** Review artifact only. Nothing in this class executes GRANT or REVOKE. */
final class AdminUserGrantPlan
{
    public static function rules(): array
    {
        $rules = AuthRuntimeGrantPlan::rules();
        $rules['tables']['users']['SELECT'] = ['id','username','email','display_name','is_active','created_at','password_hash','auth_version'];
        $rules['tables']['users']['INSERT'] = ['username','email','display_name','password_hash','is_active'];
        $rules['tables']['users']['UPDATE'] = ['password_hash','is_active','auth_version'];
        $rules['tables']['user_roles']['INSERT'] = ['user_id','role_id'];
        // MySQL has no column-scoped DELETE. This is limited to this one table.
        $rules['tables']['user_roles']['DELETE'] = null;
        return $rules;
    }

    public static function supplementalSql(): array
    {
        return [
            "GRANT SELECT (`email`, `display_name`, `created_at`), INSERT (`username`, `email`, `display_name`, `password_hash`, `is_active`), UPDATE (`is_active`, `auth_version`) ON `up_van_booking_test`.`users` TO 'up_van_auth_test'@'127.0.0.1'",
            "GRANT INSERT (`user_id`, `role_id`), DELETE ON `up_van_booking_test`.`user_roles` TO 'up_van_auth_test'@'127.0.0.1'",
        ];
    }

    public static function driverCreationRules(): array
    {
        return BookingCapabilityGrantGuard::merge(self::rules(), ['tables'=>[
            'users'=>['INSERT'=>['nickname']],
        ]]);
    }

    public static function driverCreationSql(): string
    {
        return "GRANT INSERT (`nickname`) ON `up_van_booking_test`.`users` TO 'up_van_auth_test'@'127.0.0.1'";
    }
}
