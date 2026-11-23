<?php
declare(strict_types=1);
namespace App\Modules\Booking;

use DateTimeImmutable;
use DateTimeZone;

/** Maps persisted booking fields into the existing A4 renderer; never invents signatures or decisions. */
final class RequesterA4Presenter
{
    private const MONTHS=[1=>'มกราคม','กุมภาพันธ์','มีนาคม','เมษายน','พฤษภาคม','มิถุนายน','กรกฎาคม','สิงหาคม','กันยายน','ตุลาคม','พฤศจิกายน','ธันวาคม'];
    public static function formData(array $row, bool $ownerView = false): array
    {
        $data=[];
        foreach (['travel_type','requester_name','requester_position','requester_affiliation','destination','purpose','passenger_count','expense_source','expense_source_details','requester_phone','pickup_person_name','pickup_person_phone','pickup_location','dropoff_location','coordinator_name','coordinator_phone'] as $key) {
            $data[$key]=(string)($row[$key] ?? '');
        }
        $data['requester_printed_name']=$data['requester_name'];
        if ($data['expense_source']==='project_budget') $data['project_expense_details']=$data['expense_source_details'];
        self::dateParts($data,'application',$row['application_date'] ?? null,false);
        self::travelParts($data,'departure',$row['requested_start_at'] ?? null);
        self::travelParts($data,'return',$row['requested_end_at'] ?? null);
        self::dateParts($data,'pickup',$row['pickup_at'] ?? null,true);
        self::dateParts($data,'dropoff',$row['dropoff_at'] ?? null,true);
        $data['pickup_time']=self::time($row['pickup_at'] ?? null,true);
        $data['dropoff_time']=self::time($row['dropoff_at'] ?? null,true);
        $snapshot=A4RequestSnapshot::restore($row['a4_request_data']??null);
        $data=array_replace($data,$snapshot);
        // The optional officer annotation applies only to the printed document.
        $data['vehicle_registration']=A4RequestSnapshot::registrationText($row['a4_request_data'] ?? null) ?? (!empty($row['requested_vehicle_id'])
            ?VehicleRegistrationDisplay::format($row['requested_vehicle_registration']??null,$row['requested_vehicle_province']??null):'');
        $decision = A4RequestSnapshot::section3($row['a4_request_data'] ?? null, 'section3_decision');
        // Privileged views use the default; owner views pass the exact published outcome.
        if ($ownerView) {
            $visibleOutcome = $row['notified_outcome'] ?? null;
            if ($visibleOutcome === null) return $data;
            if (($decision['approval_outcome'] ?? $visibleOutcome) !== $visibleOutcome) return $data;
            $decision['approval_outcome'] = $visibleOutcome;
        }
        return $data + $decision;
    }
    private static function travelParts(array &$data,string $prefix,mixed $value): void
    {
        $date=self::parse($value,true);
        if ($date===null) return;
        $data[$prefix.'_date']=$date->format('j').' '.self::MONTHS[(int)$date->format('n')].' '.((int)$date->format('Y')+543);
        $data[$prefix.'_time']=$date->format('H:i');
    }
    private static function dateParts(array &$data,string $prefix,mixed $value,bool $utc): void
    {
        $date=self::parse($value,$utc);
        if ($date===null) return;
        $data[$prefix.'_day']=$date->format('j');
        $data[$prefix.'_month']=self::MONTHS[(int)$date->format('n')];
        $data[$prefix.'_year']=(string)((int)$date->format('Y')+543);
    }
    private static function time(mixed $value,bool $utc): string {return self::parse($value,$utc)?->format('H:i') ?? '';}
    private static function parse(mixed $value,bool $utc): ?DateTimeImmutable
    {
        if (!is_string($value) || $value==='') return null;
        $date=DateTimeImmutable::createFromFormat($utc?'!Y-m-d H:i:s':'!Y-m-d',$utc ? substr($value,0,19) : $value,new DateTimeZone($utc?'UTC':'Asia/Bangkok'));
        if ($date===false) return null;
        return $utc ? $date->setTimezone(new DateTimeZone('Asia/Bangkok')) : $date;
    }
}
