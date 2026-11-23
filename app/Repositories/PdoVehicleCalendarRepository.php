<?php
declare(strict_types=1);
namespace App\Repositories;

use App\Database\GrantVerifier;
use App\Database\BookingSoftDelete;
use App\Database\VehicleCalendarGrantPlan;
use App\Modules\Booking\BookingCalendarWindow;
use App\Modules\Booking\VehicleCalendarRepository;
use PDO;

/** Test-only read path. Submitted requests and confirmed assignments block their own vehicle. */
final class PdoVehicleCalendarRepository implements VehicleCalendarRepository
{
    private bool $fuelEnabled=false;
    private bool $provinceEnabled=false;
    private bool $requestedVehicleEnabled=false;
    private bool $scheduleReadable=false;

    private readonly string $activeRequest;

    public function __construct(private readonly PDO $db)
    {
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql' || $db->getAttribute(PDO::ATTR_ERRMODE)!==PDO::ERRMODE_EXCEPTION || $db->getAttribute(PDO::ATTR_EMULATE_PREPARES)!==false) throw new \RuntimeException('VEHICLE_CALENDAR_PDO_REJECTED');
        $identity=$db->query('SELECT DATABASE() AS db_name, CURRENT_USER() AS account_name, @@GLOBAL.partial_revokes AS partial_revokes')->fetch(PDO::FETCH_ASSOC);
        if (($identity['db_name'] ?? '')!=='up_van_booking_test' || ($identity['account_name'] ?? '')!=='up_van_auth_test@127.0.0.1') throw new \RuntimeException('VEHICLE_CALENDAR_TARGET_REJECTED');
        $this->provinceEnabled=$this->hasColumn('vehicles','registration_province');
        $this->requestedVehicleEnabled=$this->hasColumn('booking_requests','requested_vehicle_id');
        if($this->provinceEnabled && $this->columnShape('vehicles','registration_province')!==['varchar(100)','YES'])throw new \RuntimeException('VEHICLE_PROVINCE_SCHEMA_DRIFT');
        if($this->requestedVehicleEnabled && $this->columnShape('booking_requests','requested_vehicle_id')!==['bigint unsigned','YES'])throw new \RuntimeException('REQUESTED_VEHICLE_SCHEMA_DRIFT');
        $this->fuelEnabled=$this->hasColumn('vehicles','fuel_type');
        if($this->fuelEnabled && $this->columnShape('vehicles','fuel_type')!==['varchar(100)','YES'])throw new \RuntimeException('VEHICLE_FUEL_SCHEMA_DRIFT');
        $this->activeRequest = BookingSoftDelete::available($db) ? ' AND deleted_at IS NULL' : '';
        $resolved=GrantVerifier::currentUserGrants($db);
        $rules=VehicleCalendarGrantPlan::rules($this->provinceEnabled,$this->requestedVehicleEnabled);
        $calendar=\App\Database\BookingCapabilityGrantGuard::evaluate($resolved['grants'],$rules,(bool)$identity['partial_revokes'],$resolved['roles_resolved'],$this->fuelEnabled);
        $this->scheduleReadable=$calendar['status']==='PASS';
        $runtime=\App\Database\ConnectionFactory::evaluateAuthRuntimeGrants($resolved['grants'],(bool)$identity['partial_revokes'],$resolved['roles_resolved'],$this->fuelEnabled);
        if (!$this->scheduleReadable && $runtime['status']!=='PASS') throw new \RuntimeException('VEHICLE_CALENDAR_GRANTS_REJECTED');
    }

    private function hasColumn(string $table,string $column): bool
    {
        $probe=\App\Database\RuntimeColumnProbe::inspect($this->db,$table,$column);
        if($probe['status']==='ERROR')throw new \RuntimeException('CALENDAR_COLUMN_PROBE_ERROR');
        return $probe['status']==='PRESENT' && $probe['readable']===true;
    }
    private function vehicleColumns(): string { return 'id, registration_number, '.($this->provinceEnabled?'registration_province':'NULL AS registration_province').', '.($this->fuelEnabled?'fuel_type':'NULL AS fuel_type').', label, passenger_capacity, is_active'; }
    private function columnShape(string $table,string $column): array
    {
        $q=$this->db->prepare('SELECT COLUMN_TYPE, IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=:schema AND TABLE_NAME=:table AND COLUMN_NAME=:column');
        $q->execute(['schema'=>'up_van_booking_test','table'=>$table,'column'=>$column]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if(!is_array($row))throw new \RuntimeException('VEHICLE_COLUMN_METADATA_UNAVAILABLE');
        return [(string)$row['COLUMN_TYPE'],(string)$row['IS_NULLABLE']];
    }

    public function activeVehicles(): array
    {
        $q=$this->db->prepare('SELECT '.$this->vehicleColumns().' FROM vehicles WHERE is_active=1 ORDER BY registration_number,id LIMIT 501');
        $q->execute();$rows=$q->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows)>500) throw new \RuntimeException('VEHICLE_RESULT_LIMIT');
        return $rows;
    }
    public function activeVehicle(int $id): ?array
    {
        $q=$this->db->prepare('SELECT '.$this->vehicleColumns().' FROM vehicles WHERE id=:id AND is_active=1');
        $q->execute(['id'=>$id]);$row=$q->fetch(PDO::FETCH_ASSOC);return is_array($row)?$row:null;
    }
    public function monthWindows(int $vehicleId,string $startUtc,string $endUtc): array
    {
        $this->requireSchedule();
        $confirmed=$this->intervals("SELECT starts_at AS start_at, ends_at AS end_at FROM vehicle_assignments WHERE vehicle_id=:vehicle_id AND status IN ('active','completed') AND starts_at<:window_end AND ends_at>:window_start ORDER BY starts_at LIMIT 5001",$vehicleId,$startUtc,$endUtc,'starts_at');
        $requests=$this->requestedVehicleEnabled ? $this->intervals("SELECT requested_start_at AS start_at, requested_end_at AS end_at, workflow_status FROM booking_requests WHERE requested_vehicle_id=:vehicle_id AND workflow_status IN ('submitted','under_review','pending_approval','awaiting_assignment','assigned'){$this->activeRequest} AND requested_start_at IS NOT NULL AND requested_end_at IS NOT NULL AND requested_start_at<:window_end AND requested_end_at>:window_start ORDER BY requested_start_at LIMIT 5001",$vehicleId,$startUtc,$endUtc,'requested_start_at') : [];
        // Display rows are separate from the unchanged reservation/availability windows.
        $displayRequests=$this->requestedVehicleEnabled ? $this->intervals("SELECT id AS booking_id, requested_start_at AS start_at, requested_end_at AS end_at, workflow_status FROM booking_requests WHERE requested_vehicle_id=:vehicle_id AND workflow_status IN ('submitted','under_review','pending_approval','awaiting_assignment','assigned','completed','awaiting_rejection_notice','closed_rejected'){$this->activeRequest} AND requested_start_at IS NOT NULL AND requested_end_at>requested_start_at AND requested_start_at<:window_end AND requested_end_at>:window_start ORDER BY requested_start_at,id LIMIT 5001",$vehicleId,$startUtc,$endUtc,'requested_start_at') : [];
        $pending=[];$unconfirmed=[];$complete=false;
        foreach($requests as $request) {
            if(in_array($request['workflow_status'],['submitted','under_review','pending_approval'],true))$pending[]=$request;
            else $unconfirmed[]=$request; // Approval alone is not a confirmed assignment.
        }
        if($this->requestedVehicleEnabled) {
            $q=$this->db->prepare("SELECT COUNT(*) FROM booking_requests WHERE requested_vehicle_id=:vehicle_id AND workflow_status IN ('submitted','under_review','pending_approval','awaiting_assignment','assigned'){$this->activeRequest} AND ((requested_start_at IS NULL AND requested_end_at IS NOT NULL) OR (requested_start_at IS NOT NULL AND requested_end_at IS NULL) OR requested_end_at<=requested_start_at)");
            $q->execute(['vehicle_id'=>$vehicleId]);$complete=(int)$q->fetchColumn()===0;
        }
        return ['requests'=>$displayRequests,'pending'=>$pending,'confirmed'=>$confirmed,'unconfirmed'=>$unconfirmed,'availability_confirmed'=>$complete];
    }
    private function intervals(string $sql,int $id,string $start,string $end,string $orderColumn): array
    {
        if (!in_array($orderColumn,['starts_at','requested_start_at'],true)) throw new \LogicException('CALENDAR_ORDER_COLUMN_REJECTED');
        $q=$this->db->prepare($sql);$q->execute(['vehicle_id'=>$id,'window_start'=>$start,'window_end'=>$end]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows)>5000) throw new \RuntimeException('VEHICLE_CALENDAR_RESULT_LIMIT');
        return $rows;
    }
    public function available(int $vehicleId,BookingCalendarWindow $window): bool
    {
        $this->requireSchedule();
        $capacity=$window->passengers===null?'':' AND v.passenger_capacity>=:passengers';
        if (!$this->requestedVehicleEnabled) throw new \RuntimeException('REQUESTED_VEHICLE_SCHEMA_REQUIRED');
        $q=$this->db->prepare("SELECT COUNT(*) FROM vehicles v WHERE v.id=:vehicle_id AND v.is_active=1{$capacity} AND NOT EXISTS (SELECT 1 FROM vehicle_assignments a WHERE a.vehicle_id=v.id AND a.status IN ('active','completed') AND a.starts_at<:assignment_end AND a.ends_at>:assignment_start) AND NOT EXISTS (SELECT 1 FROM booking_requests b WHERE b.requested_vehicle_id=v.id AND b.workflow_status IN ('submitted','under_review','pending_approval','awaiting_assignment','assigned'){$this->activeRequest} AND b.requested_start_at<:window_end AND b.requested_end_at>:window_start)");
        // Native PDO prepares require a unique placeholder for every occurrence.
        $params=['vehicle_id'=>$vehicleId,'assignment_start'=>$window->startUtc,'assignment_end'=>$window->endUtc,'window_start'=>$window->startUtc,'window_end'=>$window->endUtc];
        if ($window->passengers!==null) $params['passengers']=$window->passengers;
        $q->execute($params);
        return (int)$q->fetchColumn()===1;
    }
    public function pending(int $vehicleId,BookingCalendarWindow $window): int
    {
        $this->requireSchedule();
        if (!$this->requestedVehicleEnabled) throw new \RuntimeException('REQUESTED_VEHICLE_SCHEMA_REQUIRED');
        $q=$this->db->prepare("SELECT COUNT(*) FROM booking_requests WHERE requested_vehicle_id=:vehicle_id AND workflow_status IN ('submitted','under_review','pending_approval','awaiting_assignment','assigned'){$this->activeRequest} AND requested_start_at IS NOT NULL AND requested_end_at IS NOT NULL AND requested_start_at<:window_end AND requested_end_at>:window_start");
        $q->execute(['vehicle_id'=>$vehicleId,'window_start'=>$window->startUtc,'window_end'=>$window->endUtc]);return (int)$q->fetchColumn();
    }

    private function requireSchedule(): void
    {
        if(!$this->scheduleReadable)throw new \RuntimeException('CALENDAR_SCHEDULE_GRANTS_REQUIRED');
    }
}
