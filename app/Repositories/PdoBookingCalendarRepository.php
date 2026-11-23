<?php
declare(strict_types=1);
namespace App\Repositories;

use App\Database\BookingCalendarGrantPlan;
use App\Database\GrantVerifier;
use App\Database\BookingSoftDelete;
use App\Modules\Booking\BookingCalendarRepository;
use App\Modules\Booking\BookingCalendarWindow;
use PDO;

/** Reads workflow display and availability intervals separately; excludes requester identity and trip details. */
final class PdoBookingCalendarRepository implements BookingCalendarRepository
{
    private const PENDING="('submitted','under_review','pending_approval')";
    private readonly string $activeRequest;

    public function __construct(private readonly PDO $db)
    {
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql' || $db->getAttribute(PDO::ATTR_ERRMODE)!==PDO::ERRMODE_EXCEPTION || $db->getAttribute(PDO::ATTR_EMULATE_PREPARES)!==false) throw new \RuntimeException('CALENDAR_PDO_REJECTED');
        $identity=$db->query('SELECT DATABASE() AS db_name, CURRENT_USER() AS account_name, @@GLOBAL.partial_revokes AS partial_revokes')->fetch(PDO::FETCH_ASSOC);
        if (($identity['db_name'] ?? '')!=='up_van_booking_test' || ($identity['account_name'] ?? '')!=='up_van_auth_test@127.0.0.1') throw new \RuntimeException('CALENDAR_TARGET_REJECTED');
        $this->activeRequest = BookingSoftDelete::available($db) ? ' AND deleted_at IS NULL' : '';
        $resolved=GrantVerifier::currentUserGrants($db);
        $capability=\App\Database\BookingCapabilityGrantGuard::evaluate($resolved['grants'],BookingCalendarGrantPlan::rules(),(bool)$identity['partial_revokes'],$resolved['roles_resolved'],true);
        if ($capability['status']!=='PASS') throw new \RuntimeException('CALENDAR_READ_GRANTS_REJECTED');
    }
    public function monthWindows(string $startUtc,string $endUtc): array
    {
        $pending=$this->intervals('SELECT requested_start_at AS start_at, requested_end_at AS end_at FROM booking_requests WHERE workflow_status IN '.self::PENDING.$this->activeRequest.' AND requested_start_at<:month_end AND requested_end_at>:month_start ORDER BY requested_start_at LIMIT 5001',$startUtc,$endUtc);
        $confirmed=$this->intervals("SELECT starts_at AS start_at, ends_at AS end_at FROM vehicle_assignments WHERE status='active' AND starts_at<:month_end AND ends_at>:month_start ORDER BY starts_at LIMIT 5001",$startUtc,$endUtc);
        $requests=$this->intervals("SELECT id AS booking_id, requested_start_at AS start_at, requested_end_at AS end_at, workflow_status FROM booking_requests WHERE workflow_status IN ('submitted','under_review','pending_approval','awaiting_assignment','assigned','completed','awaiting_rejection_notice','closed_rejected'){$this->activeRequest} AND requested_start_at IS NOT NULL AND requested_end_at>requested_start_at AND requested_start_at<:month_end AND requested_end_at>:month_start ORDER BY requested_start_at,id LIMIT 5001",$startUtc,$endUtc);
        return ['requests'=>$requests,'pending'=>$pending,'confirmed'=>$confirmed];
    }
    private function intervals(string $sql,string $startUtc,string $endUtc): array
    {
        $q=$this->db->prepare($sql);$q->execute(['month_start'=>$startUtc,'month_end'=>$endUtc]);
        $rows=$q->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows)>5000) throw new \RuntimeException('CALENDAR_RESULT_LIMIT');
        return $rows;
    }
    public function eligibleVehicles(?int $passengers): int
    {
        if ($passengers===null) return (int)$this->db->query('SELECT COUNT(*) FROM vehicles WHERE is_active=1')->fetchColumn();
        $q=$this->db->prepare('SELECT COUNT(*) FROM vehicles WHERE is_active=1 AND passenger_capacity>=:passengers');
        $q->execute(['passengers'=>$passengers]);return (int)$q->fetchColumn();
    }
    public function availableVehicles(BookingCalendarWindow $window): int
    {
        $capacity=$window->passengers===null?'':' AND v.passenger_capacity>=:passengers';
        $q=$this->db->prepare("SELECT COUNT(*) FROM vehicles v WHERE v.is_active=1{$capacity} AND NOT EXISTS (SELECT 1 FROM vehicle_assignments a WHERE a.vehicle_id=v.id AND a.status='active' AND a.starts_at<:window_end AND a.ends_at>:window_start)");
        $params=['window_start'=>$window->startUtc,'window_end'=>$window->endUtc];
        if ($window->passengers!==null) $params['passengers']=$window->passengers;
        $q->execute($params);return (int)$q->fetchColumn();
    }
    public function pendingRequests(BookingCalendarWindow $window): int
    {
        $q=$this->db->prepare('SELECT COUNT(*) FROM booking_requests WHERE workflow_status IN '.self::PENDING.$this->activeRequest.' AND requested_start_at<:window_end AND requested_end_at>:window_start');
        $q->execute(['window_start'=>$window->startUtc,'window_end'=>$window->endUtc]);return (int)$q->fetchColumn();
    }
}
