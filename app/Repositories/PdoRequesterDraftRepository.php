<?php
declare(strict_types=1);
namespace App\Repositories;

use App\Modules\Booking\RequesterDraftRepository;
use App\Services\AuditLogger;
use App\Database\GrantVerifier;
use App\Database\BookingSoftDelete;
use App\Database\RequesterBookingReadGrantPlan;
use PDO;

/** Prepared for an approved test-only booking runtime; deliberately not wired to HTTP yet. */
final class PdoRequesterDraftRepository implements RequesterDraftRepository
{
    private const FIELDS=['requester_name','requester_position','requester_affiliation','requester_phone','purpose','destination','additional_details','expense_source_details','pickup_person_name','pickup_person_phone','pickup_location','dropoff_location','coordinator_name','coordinator_phone','travel_type','expense_source','passenger_count','application_date','requested_start_at','requested_end_at','pickup_at','dropoff_at'];
    private const READ='id, requester_id, request_number, workflow_status, version, created_at, updated_at, application_date, travel_type, requester_name, requester_position, requester_affiliation, requester_phone, purpose, destination, additional_details, expense_source, expense_source_details, pickup_person_name, pickup_person_phone, pickup_location, pickup_at, dropoff_location, dropoff_at, coordinator_name, coordinator_phone, requested_start_at, requested_end_at, passenger_count';
    private readonly string $activeRequest;

    public function __construct(private readonly PDO $db)
    {
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql' || $db->getAttribute(PDO::ATTR_ERRMODE)!==PDO::ERRMODE_EXCEPTION || $db->getAttribute(PDO::ATTR_EMULATE_PREPARES)!==false) throw new \RuntimeException('BOOKING_PDO_REJECTED');
        $identity=$db->query('SELECT DATABASE() AS db_name, CURRENT_USER() AS account_name, @@GLOBAL.partial_revokes AS partial_revokes')->fetch(PDO::FETCH_ASSOC);
        if (($identity['db_name'] ?? '')!=='up_van_booking_test' || ($identity['account_name'] ?? '')!=='up_van_auth_test@127.0.0.1') throw new \RuntimeException('BOOKING_TARGET_REJECTED');
        $this->activeRequest = BookingSoftDelete::available($db) ? ' AND deleted_at IS NULL' : '';
        $resolved=GrantVerifier::currentUserGrants($db);
        $evaluation=GrantVerifier::evaluate($resolved['grants'],'up_van_booking_test',RequesterBookingReadGrantPlan::rules(),(bool)$identity['partial_revokes'],$resolved['roles_resolved']);
        if ($evaluation['status']!=='PASS') $evaluation=GrantVerifier::evaluate($resolved['grants'],'up_van_booking_test',RequesterBookingReadGrantPlan::rules(true),(bool)$identity['partial_revokes'],$resolved['roles_resolved']);
        if ($evaluation['status']!=='PASS') $evaluation=GrantVerifier::evaluate($resolved['grants'],'up_van_booking_test',\App\Database\BookingCalendarGrantPlan::rules(),(bool)$identity['partial_revokes'],$resolved['roles_resolved']);
        if ($evaluation['status']!=='PASS') $evaluation=GrantVerifier::evaluate($resolved['grants'],'up_van_booking_test',\App\Database\BookingCalendarGrantPlan::rules(true),(bool)$identity['partial_revokes'],$resolved['roles_resolved']);
        if ($evaluation['status']!=='PASS') $evaluation=GrantVerifier::evaluate($resolved['grants'],'up_van_booking_test',\App\Database\VehicleCalendarGrantPlan::rules(),(bool)$identity['partial_revokes'],$resolved['roles_resolved']);
        if ($evaluation['status']!=='PASS') $evaluation=GrantVerifier::evaluate($resolved['grants'],'up_van_booking_test',\App\Database\VehicleAdminGrantPlan::rules(true),(bool)$identity['partial_revokes'],$resolved['roles_resolved']);
        if ($evaluation['status']!=='PASS') $evaluation=GrantVerifier::evaluate($resolved['grants'],'up_van_booking_test',\App\Database\VehicleAdminGrantPlan::rules(true,true),(bool)$identity['partial_revokes'],$resolved['roles_resolved']);
        if ($this->activeRequest !== '') $evaluation=\App\Database\BookingCapabilityGrantGuard::evaluate($resolved['grants'],RequesterBookingReadGrantPlan::rules(),(bool)$identity['partial_revokes'],$resolved['roles_resolved'],\App\Database\RuntimeColumnProbe::inspect($db,'vehicles','fuel_type')['status']==='PRESENT');
        if ($evaluation['status']!=='PASS') throw new \RuntimeException('BOOKING_READ_GRANTS_REJECTED');
    }
    public function transaction(callable $operation): mixed
    {
        if ($this->db->inTransaction()) throw new \RuntimeException('BOOKING_NESTED_TRANSACTION');
        // Each statement reads latest committed conflicts after acquiring the vehicle mutex.
        $this->db->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $this->db->beginTransaction();
        try {$result=$operation();$this->db->commit();return $result;}
        catch (\Throwable $error) {if ($this->db->inTransaction()) $this->db->rollBack();throw $error;}
    }
    public function listOwn(int $ownerId,int $limit,int $offset): array
    {
        $q=$this->db->prepare('SELECT id, request_number, destination, requested_start_at, requested_end_at, workflow_status, version, created_at FROM booking_requests WHERE requester_id=:owner'.$this->activeRequest.' ORDER BY id DESC LIMIT :limit OFFSET :offset');
        $q->bindValue(':owner',$ownerId,PDO::PARAM_INT);$q->bindValue(':limit',$limit,PDO::PARAM_INT);$q->bindValue(':offset',$offset,PDO::PARAM_INT);$q->execute();
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }
    public function listAll(int $limit,int $offset): array
    {
        $q=$this->db->prepare('SELECT id, request_number, destination, requested_start_at, requested_end_at, workflow_status, version, created_at FROM booking_requests WHERE 1=1'.$this->activeRequest.' ORDER BY id DESC LIMIT :limit OFFSET :offset');
        $q->bindValue(':limit',$limit,PDO::PARAM_INT);$q->bindValue(':offset',$offset,PDO::PARAM_INT);$q->execute();
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }
    public function find(int $id): ?array
    {
        $q=$this->db->prepare('SELECT '.self::READ.' FROM booking_requests WHERE id=:id'.$this->activeRequest);$q->execute(['id'=>$id]);
        return $q->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    public function findOwned(int $id,int $ownerId): ?array
    {
        $q=$this->db->prepare('SELECT '.self::READ.' FROM booking_requests WHERE id=:id AND requester_id=:owner'.$this->activeRequest);
        $q->execute(['id'=>$id,'owner'=>$ownerId]);
        return $q->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    public function lock(int $id): ?array
    {
        if (!$this->db->inTransaction()) throw new \RuntimeException('BOOKING_LOCK_REQUIRES_TRANSACTION');
        $q=$this->db->prepare('SELECT '.self::READ.' FROM booking_requests WHERE id=:id'.$this->activeRequest.' FOR UPDATE');$q->execute(['id'=>$id]);
        return $q->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    public function insert(int $ownerId,array $fields): int
    {
        $values=$this->validatedFields($fields);
        $columns=array_keys($values);$columns[]='requester_id';$columns[]='created_by';$columns[]='updated_by';
        $params=$values+['requester_id'=>$ownerId,'created_by'=>$ownerId,'updated_by'=>$ownerId];
        $sql='INSERT INTO booking_requests ('.implode(',',$columns).') VALUES (:'.implode(',:',$columns).')';
        $q=$this->db->prepare($sql);$q->execute($params);return (int)$this->db->lastInsertId();
    }
    public function update(int $id,int $ownerId,int $expectedVersion,array $fields): bool
    {
        $values=$this->validatedFields($fields);
        $set=array_map(static fn(string $key):string=>$key.'=:'.$key,array_keys($values));
        $set[]='updated_by=:editor';$set[]='version=version+1';
        $q=$this->db->prepare('UPDATE booking_requests SET '.implode(',',$set).' WHERE id=:id AND requester_id=:owner AND workflow_status=\'draft\' AND version=:expected'.$this->activeRequest);
        $q->execute($values+['editor'=>$ownerId,'id'=>$id,'owner'=>$ownerId,'expected'=>$expectedVersion]);
        return $q->rowCount()===1;
    }
    public function appendDraftHistory(int $id,int $actorId,int $version): void
    {
        $q=$this->db->prepare("INSERT INTO booking_status_history (booking_request_id,from_status,to_status,changed_by,booking_version) VALUES (:id,NULL,'draft',:actor,:version)");
        $q->execute(['id'=>$id,'actor'=>$actorId,'version'=>$version]);
    }
    public function auditDraft(string $action,int $id,int $actorId,int $version): void
    {
        if (!in_array($action,['booking.draft.created','booking.draft.updated'],true)) throw new \InvalidArgumentException('BOOKING_AUDIT_ACTION_INVALID');
        (new AuditLogger($this->db))->record($actorId,$action,'booking_request',(string)$id,['version'=>$version]);
    }
    private function validatedFields(array $fields): array
    {
        if (array_keys($fields)!==self::FIELDS) throw new \InvalidArgumentException('BOOKING_FIELD_SET_INVALID');
        return $fields;
    }
}
