<?php
declare(strict_types=1);
namespace App\Modules\Booking;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/** Validates the existing A4 fields. Route/fuel estimates are deliberately not persisted. */
final readonly class BookingSubmissionInput
{
    private const TEXT_LIMITS=[
        'requester_name'=>150,'requester_position'=>150,'requester_affiliation'=>255,
        'requester_phone'=>30,'purpose'=>5000,'destination'=>500,'additional_details'=>5000,
        'expense_source_details'=>5000,'pickup_person_name'=>150,'pickup_person_phone'=>30,
        'pickup_location'=>500,'dropoff_location'=>500,'coordinator_name'=>150,'coordinator_phone'=>30,
    ];
    private const MONTHS=['มกราคม'=>1,'กุมภาพันธ์'=>2,'มีนาคม'=>3,'เมษายน'=>4,'พฤษภาคม'=>5,'มิถุนายน'=>6,'กรกฎาคม'=>7,'สิงหาคม'=>8,'กันยายน'=>9,'ตุลาคม'=>10,'พฤศจิกายน'=>11,'ธันวาคม'=>12];

    private function __construct(public ?int $vehicleId, public array $fields) {}

    public static function fromA4(array $post, ?DateTimeImmutable $now=null, bool $allowPastWindow=false): self
    {
        $vehicle=$post['vehicle_id']??'';
        if(!is_string($vehicle)||($vehicle!==''&&(!ctype_digit($vehicle)||strlen($vehicle)>10||(int)$vehicle<1)))throw new InvalidArgumentException('BOOKING_VEHICLE_REQUIRED');
        $fields=[];
        foreach(self::TEXT_LIMITS as $key=>$limit){
            $fields[$key]=self::text($post[$key]??'', $limit, false);
        }
        $position=self::text($post['requester_position']??'',150,false);
        $affiliation=self::text($post['requester_affiliation']??'',255,false);
        $continued=self::text($post['requester_affiliation_continued']??'',255,false);
        $fields['requester_position']=$position;
        $fields['requester_affiliation']=trim(implode(' ',array_filter([$affiliation,$continued],static fn($v)=>$v!==null))) ?: null;
        $combined=$fields['requester_affiliation'];
        if($combined!==null&&(function_exists('mb_strlen')?mb_strlen($combined,'UTF-8'):preg_match_all('/./us',$combined))>255)throw new InvalidArgumentException('BOOKING_FIELD_INVALID');
        foreach(['travel_type'=>RequesterDraftInput::TRAVEL_TYPES,'expense_source'=>RequesterDraftInput::EXPENSE_SOURCES] as $key=>$allowed){
            $value=$post[$key]??'';
            if(!is_string($value)||($value!==''&&!in_array($value,$allowed,true)))throw new InvalidArgumentException('BOOKING_CHOICE_REQUIRED');
            $fields[$key]=$value===''?null:$value;
        }
        $count=$post['passenger_count']??'';
        if(!is_string($count)||($count!==''&&(!ctype_digit($count)||(int)$count<1||(int)$count>65535)))throw new InvalidArgumentException('BOOKING_COUNT_INVALID');
        $fields['passenger_count']=$count===''?null:(int)$count;
        $fields['application_date']=($now??new DateTimeImmutable('now',new DateTimeZone('Asia/Bangkok')))->setTimezone(new DateTimeZone('Asia/Bangkok'))->format('Y-m-d');
        $start=self::optionalTravelDateTime($post,'departure');
        $end=self::optionalTravelDateTime($post,'return');
        $localNow=($now??new DateTimeImmutable('now',new DateTimeZone('Asia/Bangkok')))->setTimezone(new DateTimeZone('Asia/Bangkok'));
        foreach(['departure','return'] as $prefix){$date=$post[$prefix.'_date']??'';if(!$allowPastWindow&&$date!==''&&self::dateTime($date,'00:00')->format('Y-m-d')<$localNow->format('Y-m-d'))throw new InvalidArgumentException('BOOKING_WINDOW_INVALID');}
        if(($start!==null&&!$allowPastWindow&&$start<=$localNow)||($start!==null&&$end!==null&&$end<=$start))throw new InvalidArgumentException('BOOKING_WINDOW_INVALID');
        // Existing DB check requires either two valid timestamps or two NULLs.
        // Incomplete individual date/time values remain verbatim in the A4 snapshot.
        $hasWindow=$vehicle!==''&&$start!==null&&$end!==null;
        $fields['requested_start_at']=$hasWindow?$start->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'):null;
        $fields['requested_end_at']=$hasWindow?$end->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'):null;
        $fields['pickup_at']=self::optionalA4DateTime($post,'pickup');
        $fields['dropoff_at']=self::optionalA4DateTime($post,'dropoff');
        if($fields['pickup_at']!==null && $fields['dropoff_at']!==null && $fields['pickup_at']>$fields['dropoff_at'])throw new InvalidArgumentException('BOOKING_PICKUP_WINDOW_INVALID');
        $genericDetails=self::text($post['expense_source_details']??'',5000,false);
        $projectDetails=self::text($post['project_expense_details']??'',5000,false);
        $fields['additional_details']=$genericDetails ?? $fields['additional_details'];
        $fields['expense_source_details']=$projectDetails ?? $fields['expense_source_details'];
        $fields['a4_request_data']=A4RequestSnapshot::encode($post);
        return new self($vehicle===''?null:(int)$vehicle,$fields);
    }

    public const FORWARD_REQUIRED_FIELDS = [
        'requester_name', 'requester_phone', 'purpose', 'destination', 'travel_type',
        'expense_source', 'passenger_count', 'departure_date', 'departure_time', 'return_date', 'return_time',
    ];

    /** Preserve the document verbatim and project only usable values into booking columns. */
    public static function fromForwardA4(array $post): self
    {
        $snapshot = A4RequestSnapshot::encode($post);
        foreach (self::FORWARD_REQUIRED_FIELDS as $field) {
            if (preg_match('/\A[\s\x{FEFF}]*\z/u', $post[$field] ?? '') === 1) {
                throw new \DomainException('BOOKING_FORWARD_FIELDS_BLANK');
            }
        }
        $normalized = $post;
        $limits = self::TEXT_LIMITS + ['requester_affiliation_continued'=>255,'project_expense_details'=>5000];
        foreach ($limits as $field=>$limit) {
            try { self::text($post[$field] ?? '', $limit, false); }
            catch (InvalidArgumentException) { unset($normalized[$field]); }
        }
        $affiliation = trim(trim($normalized['requester_affiliation'] ?? '').' '.trim($normalized['requester_affiliation_continued'] ?? ''));
        if (preg_match_all('/./us', $affiliation)>255) unset($normalized['requester_affiliation'],$normalized['requester_affiliation_continued']);
        foreach (['travel_type'=>RequesterDraftInput::TRAVEL_TYPES,'expense_source'=>RequesterDraftInput::EXPENSE_SOURCES] as $field=>$allowed) {
            if (!in_array($post[$field] ?? '', $allowed, true)) unset($normalized[$field]);
        }
        $count = $post['passenger_count'] ?? '';
        if (!ctype_digit($count) || (int)$count<1 || (int)$count>65535) unset($normalized['passenger_count']);

        try {
            $start = self::optionalTravelDateTime($post, 'departure');
            $end = self::optionalTravelDateTime($post, 'return');
            if ($start && $end && $start >= $end) throw new InvalidArgumentException();
        } catch (InvalidArgumentException) {
            unset($normalized['departure_date'],$normalized['departure_time'],$normalized['return_date'],$normalized['return_time']);
        }

        $stops = [];
        foreach (['pickup','dropoff'] as $stop) {
            try { $stops[$stop] = self::optionalA4DateTime($post, $stop); }
            catch (InvalidArgumentException) {
                unset($normalized[$stop.'_day'],$normalized[$stop.'_month'],$normalized[$stop.'_year'],$normalized[$stop.'_time']);
            }
        }
        if (isset($stops['pickup'],$stops['dropoff']) && $stops['pickup']>$stops['dropoff']) {
            unset($normalized['dropoff_day'],$normalized['dropoff_month'],$normalized['dropoff_year'],$normalized['dropoff_time']);
        }

        $input = self::fromA4($normalized, null, true);
        $fields = $input->fields;
        $fields['a4_request_data'] = $snapshot;
        return new self($input->vehicleId, $fields);
    }

    private static function text(mixed $value,int $limit,bool $required): ?string
    {
        if(!is_string($value)||preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',$value))throw new InvalidArgumentException('BOOKING_FIELD_INVALID');
        $value=trim($value);$length=function_exists('mb_strlen')?mb_strlen($value,'UTF-8'):preg_match_all('/./us',$value);
        if($length===false||$length>$limit||($required&&$value===''))throw new InvalidArgumentException('BOOKING_FIELD_REQUIRED');
        return $value===''?null:$value;
    }

    private static function dateTime(mixed $date,mixed $time): DateTimeImmutable
    {
        if(!is_string($date)||!is_string($time)||!preg_match('/^(?:([0-3]?\d) ([^\s]+) (\d{4})|(\d{4})-(\d{2})-(\d{2})|(\d{1,2})\/(\d{1,2})\/(\d{4}))$/uD',$date,$m)||!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D',$time))throw new InvalidArgumentException('BOOKING_DATE_INVALID');
        if(($m[1]??'')!==''){
            $month=self::MONTHS[$m[2]]??null;$year=(int)$m[3];if($year>2400)$year-=543;$value=sprintf('%04d-%02d-%02dT%s',$year,$month??0,(int)$m[1],$time);
        }elseif(($m[4]??'')!=='')$value=sprintf('%04d-%02d-%02dT%s',(int)$m[4],(int)$m[5],(int)$m[6],$time);
        else{$year=(int)$m[9];if($year>2400)$year-=543;$value=sprintf('%04d-%02d-%02dT%s',$year,(int)$m[8],(int)$m[7],$time);}
        $parsed=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$value,new DateTimeZone('Asia/Bangkok'));
        if($parsed===false||$parsed->format('Y-m-d\TH:i')!==$value||!preg_match('/^[1-9]\d{3}$/D',$parsed->setTimezone(new DateTimeZone('UTC'))->format('Y')))throw new InvalidArgumentException('BOOKING_DATE_INVALID');
        return $parsed;
    }

    private static function optionalTravelDateTime(array $post,string $prefix):?DateTimeImmutable
    {
        $date=$post[$prefix.'_date']??'';$time=$post[$prefix.'_time']??'';
        if(!is_string($date)||!is_string($time))throw new InvalidArgumentException('BOOKING_DATE_INVALID');
        if($date!=='')self::dateTime($date,'00:00');
        if($time!==''&&!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D',$time))throw new InvalidArgumentException('BOOKING_DATE_INVALID');
        return $date!==''&&$time!==''?self::dateTime($date,$time):null;
    }

    private static function optionalA4DateTime(array $post,string $prefix): ?string
    {
        $parts=[$post[$prefix.'_day']??'',$post[$prefix.'_month']??'',$post[$prefix.'_year']??'',$post[$prefix.'_time']??''];
        foreach($parts as $part)if(!is_string($part))throw new InvalidArgumentException('BOOKING_PICKUP_DATE_INVALID');
        if($parts[0]!==''&&(!ctype_digit($parts[0])||(int)$parts[0]<1||(int)$parts[0]>31))throw new InvalidArgumentException('BOOKING_PICKUP_DATE_INVALID');
        if($parts[1]!==''&&!isset(self::MONTHS[$parts[1]]))throw new InvalidArgumentException('BOOKING_PICKUP_DATE_INVALID');
        if($parts[2]!==''&&(!ctype_digit($parts[2])||strlen($parts[2])!==4))throw new InvalidArgumentException('BOOKING_PICKUP_DATE_INVALID');
        if($parts[3]!==''&&!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D',$parts[3]))throw new InvalidArgumentException('BOOKING_PICKUP_DATE_INVALID');
        if(in_array('',array_slice($parts,0,3),true))return null;
        $parsed=self::dateTime($parts[0].' '.$parts[1].' '.$parts[2],$parts[3]?:'00:00');
        return $parts[3]!==''?$parsed->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'):null;
    }
}
