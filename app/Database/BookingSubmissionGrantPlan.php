<?php
declare(strict_types=1);
namespace App\Database;

/** Exact review-only runtime union for gated submission and officer intake. Never executes GRANT. */
final class BookingSubmissionGrantPlan
{
    public const INSERT_COLUMNS=[
        'requested_vehicle_id','requester_id','request_number','application_date','travel_type',
        'requester_name','requester_position','requester_affiliation','requester_phone','purpose','destination',
        'additional_details','expense_source','expense_source_details','pickup_person_name','pickup_person_phone',
        'pickup_location','pickup_at','dropoff_location','dropoff_at','coordinator_name','coordinator_phone',
        'requested_start_at','requested_end_at','passenger_count','workflow_status','created_by','updated_by',
    ];
    public static function rules(bool $snapshotEnabled=false): array
    {
        $rules=AuthRuntimeGrantPlan::rules();
        // InnoDB locking reads require a narrowly scoped UPDATE privilege.
        $rules['tables']['vehicles']=['SELECT'=>['id','is_active','passenger_capacity'],'UPDATE'=>['updated_by']];
        $rules['tables']['vehicle_assignments']=['SELECT'=>['id','vehicle_id','status','starts_at','ends_at']];
        $rules['tables']['booking_requests']=['SELECT'=>['id','requester_id','requested_vehicle_id','workflow_status','version','requested_start_at','requested_end_at','submitted_at']];
        $rules['tables']['booking_requests']['INSERT']=self::INSERT_COLUMNS;
        $rules['tables']['booking_requests']['UPDATE']=['workflow_status','version','updated_by','submitted_at'];
        $rules['tables']['booking_status_history']['INSERT']=['booking_request_id','from_status','to_status','changed_by','booking_version'];
        $rules['tables']['audit_logs']['INSERT']=['user_id','action','entity_type','entity_id','metadata'];
        if($snapshotEnabled){$rules['tables']['booking_requests']['INSERT'][]='a4_request_data';$rules['tables']['booking_requests']['SELECT'][]='a4_request_data';}
        return $rules;
    }
    public static function proposedSql(bool $snapshotEnabled=false): array
    {
        $cols=static fn(array $items):string=>implode(', ',array_map(static fn(string $v):string=>'`'.$v.'`',$items));
        $account="'up_van_auth_test'@'127.0.0.1'";
        $q=chr(96);
        $rules=self::rules($snapshotEnabled);
        return [
            'GRANT SELECT ('.$cols($rules['tables']['booking_requests']['SELECT']).') ON `up_van_booking_test`.`booking_requests` TO '.$account,
            'GRANT SELECT (`id`, `is_active`, `passenger_capacity`) ON `up_van_booking_test`.`vehicles` TO '.$account,
            'GRANT SELECT (`id`, `vehicle_id`, `status`, `starts_at`, `ends_at`) ON `up_van_booking_test`.`vehicle_assignments` TO '.$account,
            'GRANT INSERT ('.$cols($rules['tables']['booking_requests']['INSERT']).') ON `up_van_booking_test`.`booking_requests` TO '.$account,
            'GRANT UPDATE (`updated_by`) ON `up_van_booking_test`.`vehicles` TO '.$account,
            'GRANT UPDATE ('.$q.'workflow_status'.$q.', '.$q.'version'.$q.', '.$q.'updated_by'.$q.', '.$q.'submitted_at'.$q.') ON '.$q.'up_van_booking_test'.$q.'.'.$q.'booking_requests'.$q.' TO '.$account,
            "GRANT INSERT (`booking_request_id`, `from_status`, `to_status`, `changed_by`, `booking_version`) ON `up_van_booking_test`.`booking_status_history` TO {$account}",
            "GRANT INSERT (`user_id`, `action`, `entity_type`, `entity_id`, `metadata`) ON `up_van_booking_test`.`audit_logs` TO {$account}",
        ];
    }
}
