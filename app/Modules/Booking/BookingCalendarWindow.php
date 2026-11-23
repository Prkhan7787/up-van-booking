<?php
declare(strict_types=1);
namespace App\Modules\Booking;

use DateTimeImmutable;
use DateTimeZone;

/** Local Bangkok interval, converted to a half-open UTC window for read-only availability checks. */
final readonly class BookingCalendarWindow
{
    private function __construct(public string $startLocal,public string $endLocal,public string $startUtc,public string $endUtc,public ?int $passengers) {}
    public static function fromQuery(array $input): self
    {
        $start=$input['start'] ?? null;$end=$input['end'] ?? null;$count=$input['passengers'] ?? null;
        if (!is_string($start) || !is_string($end)) throw new \InvalidArgumentException('CALENDAR_SELECTION_INVALID');
        if ($count!==null && (!is_string($count) || !ctype_digit($count) || (int)$count<1 || (int)$count>65535)) throw new \InvalidArgumentException('CALENDAR_SELECTION_INVALID');
        $a=self::parse($start);$b=self::parse($end);
        if ($a >= $b) throw new \InvalidArgumentException('CALENDAR_WINDOW_INVALID');
        return new self($start,$end,$a->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),$b->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),$count===null?null:(int)$count);
    }
    public static function fromTravelQuery(array $input): self
    {
        $fields=['start_date','start_time','end_date','end_time'];
        if(array_intersect($fields,array_keys($input))!==[]) {
            foreach($fields as $field)if(!isset($input[$field]) || !is_string($input[$field]))throw new \InvalidArgumentException('CALENDAR_SELECTION_INVALID');
            foreach(['start','end'] as $key) {
                $value=$input[$key.'_date'].'T'.$input[$key.'_time'];
                if(isset($input[$key]) && $input[$key]!==$value)throw new \InvalidArgumentException('CALENDAR_SELECTION_CONFLICT');
                $input[$key]=$value;
            }
        }
        return self::fromQuery($input);
    }
    private static function parse(string $value): DateTimeImmutable
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/D',$value)) throw new \InvalidArgumentException('CALENDAR_DATE_INVALID');
        $date=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$value,new DateTimeZone('Asia/Bangkok'));
        if ($date===false || $date->format('Y-m-d\TH:i')!==$value) throw new \InvalidArgumentException('CALENDAR_DATE_INVALID');
        return $date;
    }
}
