<?php
declare(strict_types=1);
namespace App\Modules\Booking;

final class BookingRequestCompleteness
{
    public static function hasSchedule(array $row):bool
    {
        return (int)($row['requested_vehicle_id']??0)>0&&!empty($row['requested_start_at'])&&!empty($row['requested_end_at'])&&$row['requested_start_at']<$row['requested_end_at'];
    }
    public static function missing(array $row):array
    {
        $missing=[];
        foreach(['requester_name','requester_phone','purpose','destination','travel_type','expense_source','passenger_count'] as $key)if(!isset($row[$key])||trim((string)$row[$key])==='')$missing[]=$key;
        if(isset($row['passenger_count'])&&(int)$row['passenger_count']<1)$missing[]='passenger_count';
        if(!self::hasSchedule($row))$missing[]='vehicle_and_travel_window';
        return $missing;
    }
    /** Must run on the locked persisted row, before any future proposal transition. */
    public static function assertReadyForProposal(array $row):void
    {
        if(self::missing($row)!==[])throw new \DomainException('BOOKING_INFORMATION_INCOMPLETE');
    }
}
