<?php
declare(strict_types=1);
namespace App\Modules\Booking;

/** Request document only. Section 2/driver text is advisory, never operational evidence. */
final class A4RequestSnapshot
{
    private const FIELDS=['travel_type','application_day','application_month','application_year',
        'requester_name','requester_position','requester_affiliation','requester_affiliation_continued',
        'requester_signature','requester_printed_name','requester_phone','destination','purpose','passenger_count',
        'departure_date','departure_time','return_date','return_time','expense_source','project_expense_details','expense_source_details',
        'document_number_prefix','request_number_suffix','driver_name','officer_signature','officer_printed_name','officer_day','officer_month','officer_year',
        'driver_signature','acknowledging_driver_name','actual_return_day','actual_return_month','actual_return_year','actual_return_time',
        'pickup_person_name','pickup_person_phone','pickup_location','pickup_day','pickup_month','pickup_year','pickup_time',
        'dropoff_location','dropoff_day','dropoff_month','dropoff_year','dropoff_time','coordinator_name','coordinator_phone'];
    public static function values(array $post):array
    {
        $out=[];
        foreach(self::FIELDS as $field){
            $value=$post[$field]??'';
            if(!self::validDocumentText($value))throw new \InvalidArgumentException('BOOKING_FIELD_INVALID');
            $out[$field]=$value;
        }
        return $out;
    }
    public static function encode(array $post):string
    {
        $json=json_encode(['version'=>1,'fields'=>self::values($post)],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
        if(strlen($json)>100000)throw new \InvalidArgumentException('BOOKING_PAYLOAD_TOO_LARGE');
        return $json;
    }
    public static function withSection3(?string $json, string $key, array $fields): string
    {
        if (!in_array($key, ['section3_draft', 'section3_decision'], true)) throw new \InvalidArgumentException('BOOKING_SNAPSHOT_KEY_INVALID');
        $data = $json === null ? ['version'=>1,'fields'=>[]] : json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        $data[$key] = $fields;
        $encoded = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        if (strlen($encoded) > 170000) throw new \InvalidArgumentException('BOOKING_PAYLOAD_TOO_LARGE');
        return $encoded;
    }

    public static function validDocumentText(mixed $text): bool
    {
        return is_string($text) && preg_match('/\A[^\x00-\x08\x0B\x0C\x0E-\x1F\x7F]{0,5000}\z/u',$text) === 1;
    }

    public static function validRegistrationText(mixed $text): bool
    {
        return is_string($text) && preg_match('/\A[^\p{Cc}\p{Zl}\p{Zp}]{0,255}\z/u',$text) === 1;
    }

    public static function registrationText(?string $json): ?string
    {
        if ($json === null) return null;
        return json_decode($json,true,32,JSON_THROW_ON_ERROR)['officer_registration_text'] ?? null;
    }

    public static function mergeOfficerSnapshot(?string $old, string $updated, ?string $registration): string
    {
        $original = $old === null ? [] : json_decode($old,true,32,JSON_THROW_ON_ERROR);
        $changes = json_decode($updated,true,32,JSON_THROW_ON_ERROR);
        $changes['fields'] = array_replace($original['fields'] ?? [], $changes['fields']);
        $merged = array_replace($original,$changes);
        if (!isset($changes['section3_draft'])) unset($merged['section3_draft']);
        if ($registration !== null) $merged['officer_registration_text'] = $registration;
        $encoded = json_encode($merged,JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        if (strlen($encoded)>170000) throw new \InvalidArgumentException('BOOKING_PAYLOAD_TOO_LARGE');
        return $encoded;
    }

    public static function withoutDecision(?string $json): ?string
    {
        if ($json === null) return null;
        $snapshot = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        if (!array_key_exists('section3_decision', $snapshot)) return $json;
        unset($snapshot['section3_decision']);
        return json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    public static function section3(?string $json, string $key): array
    {
        if ($json === null) return [];
        $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        return $data[$key] ?? [];
    }

    public static function restore(mixed $json):array
    {
        if($json===null)return [];
        if(!is_string($json)||strlen($json)>170000)throw new \RuntimeException('BOOKING_SNAPSHOT_INVALID');
        $data=json_decode($json,true,32,JSON_THROW_ON_ERROR);
        if(($data['version']??null)!==1||!is_array($data['fields']??null))throw new \RuntimeException('BOOKING_SNAPSHOT_INVALID');
        return self::values($data['fields']);
    }
}
