<?php
declare(strict_types=1);
namespace App\Database;

/** Review-only scoped write plan for editing submitted requests; never issues GRANT. */
final class BookingOfficerEditGrantPlan
{
    public const UPDATE_COLUMNS=[
        'requested_vehicle_id','travel_type','requester_name','requester_position','requester_affiliation',
        'requester_phone','purpose','destination','additional_details','expense_source','expense_source_details',
        'pickup_person_name','pickup_person_phone','pickup_location','pickup_at','dropoff_location','dropoff_at',
        'coordinator_name','coordinator_phone','requested_start_at','requested_end_at','passenger_count','version','updated_by',
    ];
    public static function rules(bool $snapshotEnabled=false):array
    {
        $rules=BookingOfficerReadGrantPlan::rules($snapshotEnabled);
        $rules['tables']['vehicles']['SELECT']=['id','registration_number','registration_province','label','is_active','passenger_capacity'];
        $rules['tables']['vehicle_assignments']['SELECT']=['id','vehicle_id','status','starts_at','ends_at'];
        // Needed only so `SELECT ... FOR UPDATE` can serialize vehicle changes.
        $rules['tables']['vehicles']['UPDATE']=['updated_by'];
        $rules['tables']['booking_requests']['UPDATE']=self::UPDATE_COLUMNS;
        $rules['tables']['audit_logs']['INSERT']=['user_id','action','entity_type','entity_id','metadata'];
        if($snapshotEnabled)$rules['tables']['booking_requests']['UPDATE'][]='a4_request_data';
        return $rules;
    }
    public static function proposedSql(bool $snapshotEnabled=false):array
    {
        $cols=static fn(array $items):string=>implode(', ',array_map(static fn(string $v):string=>'`'.$v.'`',$items));
        $account="'up_van_auth_test'@'127.0.0.1'";
        return [
            ...BookingOfficerReadGrantPlan::proposedSql($snapshotEnabled),
            'GRANT SELECT (`is_active`, `passenger_capacity`) ON `up_van_booking_test`.`vehicles` TO '.$account,
            'GRANT SELECT (`id`, `vehicle_id`, `status`, `starts_at`, `ends_at`) ON `up_van_booking_test`.`vehicle_assignments` TO '.$account,
            'GRANT UPDATE (`updated_by`) ON `up_van_booking_test`.`vehicles` TO '.$account,
            'GRANT UPDATE ('.$cols(self::rules($snapshotEnabled)['tables']['booking_requests']['UPDATE']).') ON `up_van_booking_test`.`booking_requests` TO '.$account,
            'GRANT INSERT (`user_id`, `action`, `entity_type`, `entity_id`, `metadata`) ON `up_van_booking_test`.`audit_logs` TO '.$account,
        ];
    }
}
