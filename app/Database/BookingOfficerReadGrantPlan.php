<?php
declare(strict_types=1);
namespace App\Database;

/** Read-only requirements for the vehicle-officer queue and stored A4 detail. */
final class BookingOfficerReadGrantPlan
{
    public static function rules(bool $snapshotEnabled=false): array
    {
        $rules=AuthRuntimeGrantPlan::rules();
        $rules['tables']['booking_requests']=['SELECT'=>array_values(array_unique([...array_diff(RequesterDraftGrantPlan::READ_COLUMNS,['updated_at']),'requested_vehicle_id','submitted_at']))];
        $rules['tables']['vehicles']['SELECT']=['id','registration_number','registration_province','label'];
        if($snapshotEnabled)$rules['tables']['booking_requests']['SELECT'][]='a4_request_data';
        return $rules;
    }
    public static function proposedSql(bool $snapshotEnabled=false): array
    {
        $cols=static fn(array $items):string=>implode(', ',array_map(static fn(string $v):string=>'`'.$v.'`',$items));
        $q=chr(96);
        return [
            "GRANT SELECT (".$cols(self::rules($snapshotEnabled)['tables']['booking_requests']['SELECT']).") ON `up_van_booking_test`.`booking_requests` TO 'up_van_auth_test'@'127.0.0.1'",
            "GRANT SELECT (`id`, `registration_number`, `registration_province`, `label`) ON `up_van_booking_test`.`vehicles` TO 'up_van_auth_test'@'127.0.0.1'",
        ];
    }
}
