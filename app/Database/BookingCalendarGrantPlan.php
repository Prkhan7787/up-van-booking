<?php
declare(strict_types=1);
namespace App\Database;

/** Read-only additive grant plan for calendar aggregates and availability. Not executed by the app. */
final class BookingCalendarGrantPlan
{
    public static function rules(bool $systemLogGranted=false): array
    {
        $rules=RequesterBookingReadGrantPlan::rules($systemLogGranted);
        $rules['tables']['vehicles']=['SELECT'=>['id','is_active','passenger_capacity']];
        $rules['tables']['vehicle_assignments']=['SELECT'=>['vehicle_id','status','starts_at','ends_at']];
        return $rules;
    }
    public static function supplementalSql(): array
    {
        $account="'up_van_auth_test'@'127.0.0.1'";
        return [
            RequesterBookingReadGrantPlan::supplementalSql(),
            "GRANT SELECT (`id`, `is_active`, `passenger_capacity`) ON `up_van_booking_test`.`vehicles` TO {$account}",
            "GRANT SELECT (`vehicle_id`, `status`, `starts_at`, `ends_at`) ON `up_van_booking_test`.`vehicle_assignments` TO {$account}",
        ];
    }
}
