<?php
declare(strict_types=1);
namespace App\Modules\Booking;

use App\Core\AuthorizationException;
use App\Models\AuthenticatedPrincipal;
use App\Services\AuthorizationPolicy;
use DateTimeImmutable;
use DateTimeZone;

final class BookingCalendarService
{
    public function __construct(private readonly BookingCalendarRepository $repo) {}
    public function month(AuthenticatedPrincipal $actor,string $month): array
    {
        $this->allow($actor);
        if (!preg_match('/^\d{4}-\d{2}$/D',$month)) throw new \InvalidArgumentException('CALENDAR_MONTH_INVALID');
        $local=DateTimeImmutable::createFromFormat('!Y-m-d',$month.'-01',new DateTimeZone('Asia/Bangkok'));
        if ($local===false || $local->format('Y-m')!==$month) throw new \InvalidArgumentException('CALENDAR_MONTH_INVALID');
        $end=$local->modify('first day of next month');
        $utc=static fn(DateTimeImmutable $date):string=>$date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $windows=$this->repo->monthWindows($utc($local),$utc($end));
        self::assertWindows($windows);
        $fleet=$this->repo->eligibleVehicles(null);
        $days=[];
        for ($day=$local;$day<$end;$day=$day->modify('+1 day')) {
            $dayStart=$utc($day);$dayEnd=$utc($day->modify('+1 day'));
            $pending=self::overlaps($windows['pending'] ?? [],$dayStart,$dayEnd);
            $confirmed=self::overlaps($windows['confirmed'] ?? [],$dayStart,$dayEnd);
            $unconfirmed=self::overlaps($windows['unconfirmed'] ?? [],$dayStart,$dayEnd);
            // A verified per-vehicle read can prove no overlap in the whole local day.
            // Missing metadata/reads never become an empty, supposedly free schedule.
            $days[]=['date'=>$day->format('Y-m-d'),'status'=>$confirmed>0?'assigned':($pending>0?'pending':($fleet<1?'no_fleet':($unconfirmed>0 || ($windows['availability_confirmed']??false)!==true?'unchecked':'free'))),
                'requests'=>self::displayRequests($windows['requests'] ?? [],$dayStart,$dayEnd),
                'pending'=>$pending,'confirmed'=>$confirmed];
        }
        return ['month'=>$month,'days'=>$days,'showCounts'=>!in_array('requester',$actor->roles,true) || count($actor->roles)>1];
    }
    public function quote(AuthenticatedPrincipal $actor,BookingCalendarWindow $window): array
    {
        $this->allow($actor);
        $windows=$this->repo->monthWindows($window->startUtc,$window->endUtc);
        self::assertWindows($windows);
        return ['eligible'=>$this->repo->eligibleVehicles($window->passengers),
            'available'=>$this->repo->availableVehicles($window),
            'pending'=>self::overlaps($windows['pending'],$window->startUtc,$window->endUtc),
            'confirmed'=>self::overlaps($windows['confirmed'] ?? [],$window->startUtc,$window->endUtc),
            'unconfirmed'=>self::overlaps($windows['unconfirmed'] ?? [],$window->startUtc,$window->endUtc),
            'availability_confirmed'=>($windows['availability_confirmed']??false)===true,
            'canPrepareForm'=>(new AuthorizationPolicy())->allows($actor->roles,'booking.draft.create')];
    }
    private static function displayRequests(array $rows,string $start,string $end): array
    {
        $result=[];
        foreach ($rows as $row) {
            if (!isset($row['booking_id'],$row['workflow_status'],$row['start_at'],$row['end_at'])) continue;
            try { $overlap=self::overlaps([$row],$start,$end); }
            catch (\RuntimeException) { continue; }
            if ($overlap) $result[(int)$row['booking_id']]=['booking_id'=>(int)$row['booking_id'],'workflow_status'=>(string)$row['workflow_status']];
        }
        return array_values($result);
    }
    private function allow(AuthenticatedPrincipal $actor): void
    {
        if (!(new AuthorizationPolicy())->allows($actor->roles,'calendar.view')) throw new AuthorizationException();
    }
    private static function overlaps(array $rows,string $start,string $end): int
    {
        $startTime=self::timestamp($start);$endTime=self::timestamp($end);
        $count=0;
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['start_at'],$row['end_at']) || !is_string($row['start_at']) || !is_string($row['end_at'])) throw new \RuntimeException('CALENDAR_DATA_INVALID');
            $a=self::timestamp($row['start_at']);$b=self::timestamp($row['end_at']);
            if($a >= $b)throw new \RuntimeException('CALENDAR_DATA_INVALID');
            if ($a<$endTime && $b>$startTime) $count++;
        }
        return $count;
    }
    private static function assertWindows(array $windows): void
    {
        foreach(['pending','confirmed'] as $key)if(!isset($windows[$key]) || !is_array($windows[$key]))throw new \RuntimeException('CALENDAR_DATA_INVALID');
        if(isset($windows['unconfirmed']) && !is_array($windows['unconfirmed']))throw new \RuntimeException('CALENDAR_DATA_INVALID');
    }
    private static function timestamp(string $value): DateTimeImmutable
    {
        if(!preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})(?:\.(\d{1,6}))?$/D',$value,$m))throw new \RuntimeException('CALENDAR_DATA_INVALID');
        $normalized=$m[1].'.'.str_pad($m[2]??'',6,'0');
        $date=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u',$normalized,new DateTimeZone('UTC'));
        if($date===false || $date->format('Y-m-d H:i:s.u')!==$normalized)throw new \RuntimeException('CALENDAR_DATA_INVALID');
        return $date;
    }
}
