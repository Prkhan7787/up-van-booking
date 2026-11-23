<?php
declare(strict_types=1);
namespace App\Modules\Booking;

use DateTimeImmutable;
use DateTimeZone;

/** Returns no enforceable date until the holiday calendar and exceptions are confirmed. */
final class BookingAdvancePolicy
{
    public static function earliest(?array $holidayDates,bool $exceptionsConfirmed,?DateTimeImmutable $today=null): ?string
    {
        if ($holidayDates===null || !$exceptionsConfirmed) return null;
        $day=($today ?? new DateTimeImmutable('now',new DateTimeZone('Asia/Bangkok')))->setTimezone(new DateTimeZone('Asia/Bangkok'))->setTime(0,0);
        foreach ($holidayDates as $holiday) {
            if (!is_string($holiday) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D',$holiday)) throw new \InvalidArgumentException('HOLIDAY_POLICY_INVALID');
        }
        $count=0;
        while ($count<5) {
            $day=$day->modify('+1 day');
            if ((int)$day->format('N')<=5 && !in_array($day->format('Y-m-d'),$holidayDates,true)) $count++;
        }
        return $day->format('Y-m-d');
    }
}
