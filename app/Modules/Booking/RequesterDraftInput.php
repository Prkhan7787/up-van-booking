<?php
declare(strict_types=1);
namespace App\Modules\Booking;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/** Draft fields only. Submission requirements and the five-working-day rule remain pending. */
final readonly class RequesterDraftInput
{
    private const LIMITS = [
        'requester_name'=>150,'requester_position'=>150,'requester_affiliation'=>255,
        'requester_phone'=>30,'purpose'=>5000,'destination'=>500,
        'additional_details'=>5000,'expense_source_details'=>5000,
        'pickup_person_name'=>150,'pickup_person_phone'=>30,'pickup_location'=>500,
        'dropoff_location'=>500,'coordinator_name'=>150,'coordinator_phone'=>30,
    ];
    public const EXPENSE_SOURCES=['institute_budget','project_budget','other_expenses','driver_extra_pay'];
    public const TRAVEL_TYPES=['within_province','outside_province'];
    private function __construct(public array $fields) {}

    public static function fromArray(array $input): self
    {
        $data=[];
        foreach (self::LIMITS as $key=>$limit) {
            $value=$input[$key] ?? '';
            if (!is_string($value) || mb_strlen($value,'UTF-8')>$limit) throw new InvalidArgumentException('BOOKING_FIELD_INVALID');
            $clean=trim($value);$data[$key]=$clean==='' ? null : $clean;
        }
        foreach (['travel_type'=>self::TRAVEL_TYPES,'expense_source'=>self::EXPENSE_SOURCES] as $key=>$allowed) {
            $value=$input[$key] ?? '';
            if (!is_string($value) || ($value!=='' && !in_array($value,$allowed,true))) throw new InvalidArgumentException('BOOKING_CHOICE_INVALID');
            $data[$key]=$value ?: null;
        }
        $count=$input['passenger_count'] ?? '';
        if (!is_string($count) || ($count!=='' && (!ctype_digit($count) || (int)$count<1 || (int)$count>65535))) throw new InvalidArgumentException('BOOKING_COUNT_INVALID');
        $data['passenger_count']=$count==='' ? null : (int)$count;
        $application=$input['application_date'] ?? '';
        if (!is_string($application)) throw new InvalidArgumentException('BOOKING_DATE_INVALID');
        $data['application_date']=self::date($application);
        foreach (['requested_start_at','requested_end_at','pickup_at','dropoff_at'] as $key) {
            $value=$input[$key] ?? '';
            if (!is_string($value)) throw new InvalidArgumentException('BOOKING_DATE_INVALID');
            $data[$key]=self::localDateTimeToUtc($value);
        }
        if (($data['requested_start_at']===null) !== ($data['requested_end_at']===null)) throw new InvalidArgumentException('BOOKING_WINDOW_INCOMPLETE');
        if ($data['requested_start_at']!==null && $data['requested_start_at'] >= $data['requested_end_at']) throw new InvalidArgumentException('BOOKING_WINDOW_INVALID');
        if ($data['pickup_at']!==null && $data['dropoff_at']!==null && $data['pickup_at']>$data['dropoff_at']) throw new InvalidArgumentException('BOOKING_PICKUP_WINDOW_INVALID');
        return new self($data);
    }

    private static function date(string $value): ?string
    {
        if ($value==='') return null;
        $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value,new DateTimeZone('Asia/Bangkok'));
        if ($date===false || $date->format('Y-m-d')!==$value) throw new InvalidArgumentException('BOOKING_DATE_INVALID');
        return $value;
    }

    private static function localDateTimeToUtc(string $value): ?string
    {
        if ($value==='') return null;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/D',$value)) throw new InvalidArgumentException('BOOKING_DATE_INVALID');
        $date=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$value,new DateTimeZone('Asia/Bangkok'));
        if ($date===false || $date->format('Y-m-d\TH:i')!==$value) throw new InvalidArgumentException('BOOKING_DATE_INVALID');
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
