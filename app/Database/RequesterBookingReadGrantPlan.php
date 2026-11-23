<?php
declare(strict_types=1);
namespace App\Database;

/** Exact SELECT-only Booking extension for the existing test Auth Runtime account. No SQL is executed here. */
final class RequesterBookingReadGrantPlan
{
    public const COLUMNS=RequesterDraftGrantPlan::READ_COLUMNS;

    public static function rules(bool $systemLogGranted=false): array
    {
        $rules=$systemLogGranted ? SystemLogGrantPlan::rules() : AdminUserGrantPlan::rules();
        $rules['tables']['booking_requests']=['SELECT'=>self::COLUMNS];
        return $rules;
    }

    public static function supplementalSql(): string
    {
        $columns=implode(', ',array_map(static fn(string $column):string=>'`'.$column.'`',self::COLUMNS));
        return "GRANT SELECT ({$columns}) ON `up_van_booking_test`.`booking_requests` TO 'up_van_auth_test'@'127.0.0.1'";
    }
}
