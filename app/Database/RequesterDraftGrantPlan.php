<?php
declare(strict_types=1);
namespace App\Database;

/** Review artifact only. Booking grants are not active and this class executes no SQL. */
final class RequesterDraftGrantPlan
{
    public const READ_COLUMNS=['id','requester_id','request_number','workflow_status','version','created_at','updated_at','application_date','travel_type','requester_name','requester_position','requester_affiliation','requester_phone','purpose','destination','additional_details','expense_source','expense_source_details','pickup_person_name','pickup_person_phone','pickup_location','pickup_at','dropoff_location','dropoff_at','coordinator_name','coordinator_phone','requested_start_at','requested_end_at','passenger_count'];
    public const EDIT_COLUMNS=['requester_name','requester_position','requester_affiliation','requester_phone','purpose','destination','additional_details','expense_source_details','pickup_person_name','pickup_person_phone','pickup_location','dropoff_location','coordinator_name','coordinator_phone','travel_type','expense_source','passenger_count','application_date','requested_start_at','requested_end_at','pickup_at','dropoff_at'];
    public static function rules(bool $systemLogGranted=false): array
    {
        $rules=$systemLogGranted ? SystemLogGrantPlan::rules() : AdminUserGrantPlan::rules();
        $rules['tables']['booking_requests']=[
            'SELECT'=>self::READ_COLUMNS,
            'INSERT'=>[...self::EDIT_COLUMNS,'requester_id','created_by','updated_by'],
            'UPDATE'=>[...self::EDIT_COLUMNS,'updated_by','version'],
        ];
        $rules['tables']['booking_status_history']['INSERT']=['booking_request_id','from_status','to_status','changed_by','booking_version'];
        return $rules;
    }
    public static function supplementalSql(): array
    {
        $columns=static fn(array $items):string=>implode(', ',array_map(static fn(string $item):string=>'`'.$item.'`',$items));
        $account="'up_van_auth_test'@'127.0.0.1'";
        return [
            'GRANT SELECT ('.$columns(self::READ_COLUMNS).'), INSERT ('.$columns([...self::EDIT_COLUMNS,'requester_id','created_by','updated_by']).'), UPDATE ('.$columns([...self::EDIT_COLUMNS,'updated_by','version']).') ON `up_van_booking_test`.`booking_requests` TO '.$account,
            'GRANT INSERT (`booking_request_id`, `from_status`, `to_status`, `changed_by`, `booking_version`) ON `up_van_booking_test`.`booking_status_history` TO '.$account,
        ];
    }
}
