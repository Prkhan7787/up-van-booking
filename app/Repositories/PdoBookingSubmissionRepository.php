<?php
declare(strict_types=1);
namespace App\Repositories;

use App\Database\BookingSubmissionGrantPlan;
use App\Database\BookingApprovalGrantPlan;
use App\Modules\Booking\BookingApprovalRepository;
use App\Modules\Booking\BookingSubmissionRepository;
use App\Modules\Booking\BookingState;
use App\Database\BookingOfficerReadGrantPlan;
use App\Database\BookingOfficerEditGrantPlan;
use App\Database\BookingSoftDelete;
use App\Database\BookingCapabilityGrantGuard;
use App\Database\GrantVerifier;
use App\Modules\Booking\OfficerBookingDeletionRepository;
use App\Services\AuditLogger;
use PDO;

/** Candidate test runtime adapter; it is instantiated only after explicit execution and write gates. */
final class PdoBookingSubmissionRepository implements BookingSubmissionRepository, BookingApprovalRepository, OfficerBookingDeletionRepository
{
    private bool $snapshotEnabled=false;
    private readonly bool $deliveryReadable;
    private readonly string $activeRequest;
    private const FIELDS=[
        'application_date','travel_type','requester_name','requester_position','requester_affiliation','requester_phone',
        'purpose','destination','additional_details','expense_source','expense_source_details','pickup_person_name',
        'pickup_person_phone','pickup_location','pickup_at','dropoff_location','dropoff_at','coordinator_name',
        'coordinator_phone','requested_start_at','requested_end_at','passenger_count','a4_request_data',
    ];
    private const READ='b.id, b.requester_id, b.requested_vehicle_id, b.request_number, b.workflow_status, b.version, b.created_at, b.submitted_at, b.application_date, b.travel_type, b.requester_name, b.requester_position, b.requester_affiliation, b.requester_phone, b.purpose, b.destination, b.additional_details, b.expense_source, b.expense_source_details, b.pickup_person_name, b.pickup_person_phone, b.pickup_location, b.pickup_at, b.dropoff_location, b.dropoff_at, b.coordinator_name, b.coordinator_phone, b.requested_start_at, b.requested_end_at, b.passenger_count';

    public function __construct(private readonly PDO $db,string $capability='read')
    {
        if($db->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql'||$db->getAttribute(PDO::ATTR_ERRMODE)!==PDO::ERRMODE_EXCEPTION||$db->getAttribute(PDO::ATTR_EMULATE_PREPARES)!==false)throw new \RuntimeException('BOOKING_PDO_REJECTED');
        $identity=$db->query('SELECT DATABASE() AS db_name, CURRENT_USER() AS account_name, @@GLOBAL.partial_revokes AS partial_revokes')->fetch(PDO::FETCH_ASSOC);
        if(($identity['db_name']??'')!=='up_van_booking_test'||($identity['account_name']??'')!=='up_van_auth_test@127.0.0.1')throw new \RuntimeException('BOOKING_TARGET_REJECTED');
        $deletionSchema = BookingSoftDelete::available($db);
        $this->activeRequest = $deletionSchema ? ' AND deleted_at IS NULL' : '';
        if ($capability === 'officer_delete' && !$deletionSchema) throw new \RuntimeException('BOOKING_SOFT_DELETE_SCHEMA_REQUIRED');
        $snapshot=\App\Database\RuntimeColumnProbe::inspect($db,'booking_requests','a4_request_data');
        if($snapshot['status']==='ERROR')throw new \RuntimeException('BOOKING_SNAPSHOT_SCHEMA_UNVERIFIED');
        $this->snapshotEnabled=$snapshot['status']==='PRESENT'&&$snapshot['readable']===true;
        if($capability!=='read'&&!$this->snapshotEnabled)throw new \RuntimeException('BOOKING_PARTIAL_A4_SCHEMA_REQUIRED');
        $resolved=GrantVerifier::currentUserGrants($db);
        $plan=match($capability){
            'read'=>BookingOfficerReadGrantPlan::rules($this->snapshotEnabled),
            'submit'=>BookingSubmissionGrantPlan::rules(true),
            'forward','decision','section3_draft','notify'=>BookingApprovalGrantPlan::rules($capability),
            'officer_edit'=>BookingOfficerEditGrantPlan::rules(true),
            'officer_delete'=>BookingCapabilityGrantGuard::merge(BookingOfficerReadGrantPlan::rules(true), BookingSubmissionGrantPlan::rules(true), BookingSoftDelete::READ_GRANTS, BookingSoftDelete::WRITE_GRANTS),
            default=>throw new \RuntimeException('BOOKING_CAPABILITY_REJECTED'),
        };
        $columns=$db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='up_van_booking_test' AND TABLE_NAME='booking_requests' AND COLUMN_NAME='submitted_at'")->fetchAll(PDO::FETCH_COLUMN);
        if($columns!==['submitted_at'])throw new \RuntimeException('BOOKING_SUBMITTED_AT_SCHEMA_UNVERIFIED');
        $status=\App\Database\BookingCapabilityGrantGuard::evaluate($resolved['grants'],$plan,(bool)$identity['partial_revokes'],$resolved['roles_resolved'],\App\Database\RuntimeColumnProbe::inspect($db,'vehicles','fuel_type')['status']==='PRESENT');
        if($status['status']!=='PASS')throw new \RuntimeException('BOOKING_RUNTIME_GRANTS_REJECTED');
        $delivery = GrantVerifier::evaluate($resolved['grants'],'up_van_booking_test',BookingApprovalGrantPlan::DELIVERY_READ,
            (bool)$identity['partial_revokes'],$resolved['roles_resolved']);
        $this->deliveryReadable = $delivery['missing_privileges']===[];
    }
    public function transaction(callable $operation): mixed
    {
        if($this->db->inTransaction())throw new \RuntimeException('BOOKING_NESTED_TRANSACTION');
        // Each statement reads latest committed conflicts after acquiring the vehicle mutex.
        $this->db->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $this->db->beginTransaction();try{$value=$operation();$this->db->commit();return $value;}catch(\Throwable $error){if($this->db->inTransaction())$this->db->rollBack();throw $error;}
    }
    public function lockVehicle(int $vehicleId): ?array
    {
        $this->requireTransaction();$q=$this->db->prepare('SELECT id,is_active,passenger_capacity FROM vehicles WHERE id=:id FOR UPDATE');$q->execute(['id'=>$vehicleId]);$row=$q->fetch(PDO::FETCH_ASSOC);return $row?:null;
    }
    public function hasRequestConflict(int $vehicleId,string $startUtc,string $endUtc,?int $excludeBookingId=null): bool
    {
        // The exclusive vehicle row lock serializes every submission/assignment writer;
        // use a plain read here so no UPDATE grant is needed on request rows.
        $this->requireTransaction();$sql="SELECT id FROM booking_requests WHERE requested_vehicle_id=:vehicle AND workflow_status IN ('submitted','under_review','pending_approval','awaiting_assignment','assigned') AND requested_start_at<:ends AND requested_end_at>:starts".$this->activeRequest;if($excludeBookingId!==null)$sql.=' AND id<>:exclude_id';$sql.=' LIMIT 1';$q=$this->db->prepare($sql);$params=['vehicle'=>$vehicleId,'ends'=>$endUtc,'starts'=>$startUtc];if($excludeBookingId!==null)$params['exclude_id']=$excludeBookingId;$q->execute($params);return $q->fetchColumn()!==false;
    }
    public function hasAssignmentConflict(int $vehicleId,string $startUtc,string $endUtc): bool
    {
        // Assignment writers must take the same vehicle lock first, as the existing
        // assignment harness does; this check itself remains read-only.
        $this->requireTransaction();$q=$this->db->prepare("SELECT id FROM vehicle_assignments WHERE vehicle_id=:vehicle AND status IN ('active','completed') AND starts_at<:ends AND ends_at>:starts LIMIT 1");$q->execute(['vehicle'=>$vehicleId,'ends'=>$endUtc,'starts'=>$startUtc]);return $q->fetchColumn()!==false;
    }
    public function insertDraft(int $ownerId,?int $vehicleId,string $requestNumber,array $fields): int
    {
        if(array_diff(self::FIELDS,array_keys($fields))!==[]||array_diff(array_keys($fields),self::FIELDS)!==[])throw new \InvalidArgumentException('BOOKING_FIELD_SET_INVALID');
        $ordered=[];foreach(self::FIELDS as $field)$ordered[$field]=$fields[$field];
        $values=$ordered+['requester_id'=>$ownerId,'requested_vehicle_id'=>$vehicleId,'request_number'=>$requestNumber,'workflow_status'=>'draft','created_by'=>$ownerId,'updated_by'=>$ownerId];
        $columns=array_keys($values);$params=$values;$sql='INSERT INTO booking_requests ('.implode(',',$columns).') VALUES (:'.implode(',:',array_keys($values)).')';
        $q=$this->db->prepare($sql);$q->execute($params);return (int)$this->db->lastInsertId();
    }
    public function appendDraftHistory(int $bookingId,int $actorId): void
    {
        $q=$this->db->prepare("INSERT INTO booking_status_history (booking_request_id,from_status,to_status,changed_by,booking_version) VALUES (:id,NULL,'draft',:actor,1)");$q->execute(['id'=>$bookingId,'actor'=>$actorId]);
    }
    public function auditDraftCreated(int $bookingId,int $actorId): void
    {
        (new AuditLogger($this->db))->record($actorId,'booking.draft.created','booking_request',(string)$bookingId,['status'=>'draft','version'=>1]);
    }
    public function lock(int $id): ?\App\Modules\Booking\BookingState
    {
        $this->requireTransaction();$q=$this->db->prepare('SELECT id,requester_id,workflow_status,version FROM booking_requests WHERE id=:id'.$this->activeRequest.' FOR UPDATE');$q->execute(['id'=>$id]);$r=$q->fetch(PDO::FETCH_ASSOC);return $r?new \App\Modules\Booking\BookingState((int)$r['id'],(int)$r['requester_id'],(string)$r['workflow_status'],(int)$r['version']):null;
    }
    public function save(\App\Modules\Booking\BookingState $before,\App\Modules\Booking\BookingState $after): bool
    {
        $this->requireTransaction();$q=$this->db->prepare('UPDATE booking_requests SET workflow_status=:to_status,version=:next_version,updated_by=:actor,submitted_at=CASE WHEN :submitted=1 THEN CURRENT_TIMESTAMP(6) ELSE submitted_at END WHERE id=:id AND requester_id=:owner AND workflow_status=:from_status AND version=:version'.$this->activeRequest);
        $q->execute(['to_status'=>$after->status,'next_version'=>$after->version,'actor'=>$before->ownerId,'submitted'=>$after->status==='submitted'?1:0,'id'=>$before->id,'owner'=>$before->ownerId,'from_status'=>$before->status,'version'=>$before->version]);return $q->rowCount()===1;
    }
    public function appendHistory(int $actorId,\App\Modules\Booking\BookingState $before,\App\Modules\Booking\BookingState $after): void
    {
        $q=$this->db->prepare('INSERT INTO booking_status_history (booking_request_id,from_status,to_status,changed_by,booking_version) VALUES (:id,:from_status,:to_status,:actor,:version)');$q->execute(['id'=>$before->id,'from_status'=>$before->status,'to_status'=>$after->status,'actor'=>$actorId,'version'=>$after->version]);
    }
    public function audit(string $event,int $actorId,\App\Modules\Booking\BookingState $before,\App\Modules\Booking\BookingState $after): void
    {
        $metadata = ['from'=>$before->status,'to'=>$after->status,'version'=>$after->version];
        if ($event === 'booking.forward_recalled' && $before->status === 'pending_approval' && $after->status === 'under_review') {
            $metadata['reason'] = 'officer_requested_revision_before_approval';
        } elseif ($event === 'booking.requester_notified' && $before->status === $after->status) {
            $metadata['channel'] = 'in_app';
        } elseif ($event !== 'booking.status_changed') {
            throw new \InvalidArgumentException('BOOKING_AUDIT_ACTION_INVALID');
        }
        (new AuditLogger($this->db))->record($actorId, $event, 'booking_request', (string)$before->id, $metadata);
    }
    public function officerVehicles():array
    {
        return $this->db->query('SELECT id,label,registration_number,registration_province FROM vehicles WHERE is_active=1 ORDER BY registration_number,id LIMIT 501')->fetchAll(PDO::FETCH_ASSOC);
    }
    public function officerList(int $limit,int $offset): array
    {
        $sql="SELECT b.id,b.version,b.request_number,b.requester_name,u.username AS requester_username,b.requested_start_at,b.requested_end_at,b.destination,b.requested_vehicle_id,b.requester_phone,b.purpose,b.travel_type,b.expense_source,b.passenger_count,b.workflow_status,b.submitted_at,v.label AS vehicle_label,v.registration_number,v.registration_province, ".$this->deliveryColumn()." FROM booking_requests b LEFT JOIN vehicles v ON v.id=b.requested_vehicle_id JOIN users u ON u.id=b.requester_id WHERE b.workflow_status NOT IN ('draft')".$this->activeRequest." ORDER BY b.submitted_at ASC,b.id ASC LIMIT :limit OFFSET :offset";
        $q=$this->db->prepare($sql);$q->bindValue(':limit',$limit,PDO::PARAM_INT);$q->bindValue(':offset',$offset,PDO::PARAM_INT);$q->execute();return $q->fetchAll(PDO::FETCH_ASSOC);
    }
    public function officerDetail(int $id): ?array
    {
        return $this->requestDetail($id, null);
    }

    public function ownedDetail(int $id, int $owner): ?array
    {
        return $this->requestDetail($id, $owner);
    }

    private function requestDetail(int $id, ?int $owner): ?array
    {
        $sql = 'SELECT '.$this->readColumns().',v.label AS requested_vehicle_label,
            v.registration_number AS requested_vehicle_registration,v.registration_province AS requested_vehicle_province,
            '.$this->deliveryColumn()." FROM booking_requests b LEFT JOIN vehicles v ON v.id=b.requested_vehicle_id
            WHERE b.id=:id AND b.workflow_status<>'draft'".$this->activeRequest;
        $params = ['id'=>$id];
        if ($owner !== null) {
            $sql .= ' AND b.requester_id=:owner';
            $params['owner'] = $owner;
        }
        $q=$this->db->prepare($sql);
        $q->execute($params);
        return $q->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function ownedList(int $owner, int $limit, int $offset): array
    {
        $q=$this->db->prepare("SELECT b.id,b.destination,b.workflow_status,b.submitted_at,".$this->deliveryColumn()."
            FROM booking_requests b WHERE b.requester_id=:owner AND b.workflow_status<>'draft'".$this->activeRequest."
            ORDER BY b.submitted_at DESC,b.id DESC LIMIT :limit OFFSET :offset");
        $q->bindValue('owner',$owner,PDO::PARAM_INT);
        $q->bindValue('limit',$limit,PDO::PARAM_INT);
        $q->bindValue('offset',$offset,PDO::PARAM_INT);
        $q->execute();
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    private function deliveryColumn(): string
    {
        return $this->deliveryReadable
            ? "(SELECT MAX(n.outcome) FROM booking_notifications n WHERE n.booking_request_id=b.id AND n.channel='in_app') AS notified_outcome"
            : 'NULL AS notified_outcome';
    }

    public function decisionOutcome(int $id): ?string
    {
        $this->requireTransaction();
        $q=$this->db->prepare('SELECT outcome FROM approval_decisions WHERE booking_request_id=:id');
        $q->execute(['id'=>$id]);
        return $q->fetchColumn() ?: null;
    }

    public function publishNotification(int $id, int $actorId, string $outcome): bool
    {
        $this->requireTransaction();
        // The caller holds the booking row lock, serializing all in-app delivery attempts.
        $check=$this->db->prepare("SELECT 1 FROM booking_notifications WHERE booking_request_id=:id AND channel='in_app' LIMIT 1");
        $check->execute(['id'=>$id]);
        if ($check->fetchColumn() !== false) return false;
        $q=$this->db->prepare("INSERT INTO booking_notifications (booking_request_id,outcome,channel,notified_at,notified_by)
            VALUES (:id,:outcome,'in_app',UTC_TIMESTAMP(6),:actor)");
        $q->execute(['id'=>$id,'outcome'=>$outcome,'actor'=>$actorId]);
        return true;
    }

    public function officerEditSnapshot(int $id):?array
    {
        $q=$this->db->prepare('SELECT id,requested_vehicle_id,workflow_status,version,requested_start_at,requested_end_at FROM booking_requests WHERE id=:id'.$this->activeRequest);$q->execute(['id'=>$id]);$row=$q->fetch(PDO::FETCH_ASSOC);return $row?:null;
    }
    public function lockOfficerEditableRequest(int $id):?array
    {
        $columns=str_replace('b.','',$this->readColumns());$q=$this->db->prepare('SELECT '.$columns.' FROM booking_requests WHERE id=:id'.$this->activeRequest.' FOR UPDATE');$q->execute(['id'=>$id]);$row=$q->fetch(PDO::FETCH_ASSOC);return $row?:null;
    }
    public function saveOfficerEdit(int $id,int $expectedVersion,int $actorId,?int $vehicleId,array $fields):bool
    {
        $allowed=array_values(array_diff([...BookingOfficerEditGrantPlan::UPDATE_COLUMNS,'a4_request_data'],['requested_vehicle_id','version','updated_by']));if(array_diff(array_keys($fields),$allowed)!==[])throw new \InvalidArgumentException('BOOKING_EDIT_FIELD_INVALID');
        $set=[];$params=['id'=>$id,'expected_version'=>$expectedVersion,'actor_id'=>$actorId,'vehicle_id'=>$vehicleId];foreach($fields as $key=>$value){$set[]='`'.$key.'`=:f_'.$key;$params['f_'.$key]=$value;}$set[]='requested_vehicle_id=:vehicle_id';$set[]='version=version+1';$set[]='updated_by=:actor_id';
        if($set===[])throw new \InvalidArgumentException('BOOKING_EDIT_FIELD_INVALID');
        $sql='UPDATE booking_requests SET '.implode(',',$set).' WHERE id=:id AND version=:expected_version AND workflow_status IN (\'submitted\',\'under_review\')'.$this->activeRequest;$q=$this->db->prepare($sql);$q->execute($params);return $q->rowCount()===1;
    }
    public function auditOfficerEdit(int $id,int $actorId,array $changedFields,int $fromVersion,int $toVersion):void
    {
        if($changedFields===[])return;sort($changedFields);(new AuditLogger($this->db))->record($actorId,'booking.officer_request.updated','booking_request',(string)$id,['changed_fields'=>$changedFields,'from_version'=>$fromVersion,'to_version'=>$toVersion,'status_scope'=>['submitted','under_review']]);
    }
    private function readColumns():string {return self::READ.($this->snapshotEnabled?', b.a4_request_data':'');}
    public function hasBlockingBookingAssignment(int $id): bool
    {
        $this->requireTransaction();
        $statement = $this->db->prepare("SELECT 1 FROM vehicle_assignments WHERE booking_request_id=:id AND status IN ('active','completed') LIMIT 1");
        $statement->execute(['id'=>$id]);
        return $statement->fetchColumn() !== false;
    }

    public function markOfficerDeleted(int $id, int $version, int $actorId): bool
    {
        $this->requireTransaction();
        $statement = $this->db->prepare("UPDATE booking_requests
            SET deleted_at=UTC_TIMESTAMP(6), deleted_by=:actor, deletion_reason=:reason,
                updated_by=:editor, version=version+1
            WHERE id=:id AND version=:version AND deleted_at IS NULL AND workflow_status IN ('submitted','under_review')");
        $statement->execute([
            'actor'=>$actorId,'editor'=>$actorId,'reason'=>BookingSoftDelete::OFFICER_QUEUE_REASON,
            'id'=>$id,'version'=>$version,
        ]);
        return $statement->rowCount() === 1;
    }

    public function auditOfficerDeleted(int $id, int $actorId, string $status, int $version): void
    {
        $this->requireTransaction();
        (new AuditLogger($this->db))->record($actorId,'booking.officer_request.deleted','booking_request',(string)$id,
            ['reason'=>BookingSoftDelete::OFFICER_QUEUE_REASON,'workflow_status'=>$status,
                'from_version'=>$version,'to_version'=>$version+1]);
    }
    public function approvalList(int $limit, int $offset): array
    {
        $q = $this->db->prepare("SELECT b.id, b.requester_name, u.username AS requester_username, b.destination,
            b.workflow_status, b.submitted_at FROM booking_requests b JOIN users u ON u.id=b.requester_id
            WHERE b.workflow_status='pending_approval'".$this->activeRequest.' ORDER BY b.submitted_at,b.id LIMIT :limit OFFSET :offset');
        $q->bindValue('limit', $limit, PDO::PARAM_INT);
        $q->bindValue('offset', $offset, PDO::PARAM_INT);
        $q->execute();
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    public function recordProposal(int $id, int $actorId): void
    {
        $this->requireTransaction();
        $q = $this->db->prepare("INSERT INTO booking_reviews
            (booking_request_id, reviewed_by, reviewed_at, receipt_result, proposed_at, proposed_by, created_by, updated_by)
            VALUES (:id, :reviewer, UTC_TIMESTAMP(6), 'ready_for_approval', UTC_TIMESTAMP(6), :proposer, :creator, :editor)
            ON DUPLICATE KEY UPDATE receipt_result='ready_for_approval', proposed_at=UTC_TIMESTAMP(6),
                proposed_by=:update_proposer, updated_by=:update_editor");
        $q->execute(['id'=>$id, 'reviewer'=>$actorId, 'proposer'=>$actorId, 'creator'=>$actorId,
            'editor'=>$actorId, 'update_proposer'=>$actorId, 'update_editor'=>$actorId]);
    }

    public function recordDecision(int $id, int $actorId, array $authority, string $outcome, ?string $reason): void
    {
        $this->requireTransaction();
        $q = $this->db->prepare('INSERT INTO approval_decisions
            (booking_request_id, decided_by, authority_kind, authority_reference, outcome, reason, decided_at, returned_to_officer_at)
            VALUES (:id, :actor, :kind, :reference, :outcome, :reason, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))');
        $q->execute(['id'=>$id, 'actor'=>$actorId, 'kind'=>$authority['kind'], 'reference'=>$authority['reference'],
            'outcome'=>$outcome, 'reason'=>$reason]);
    }

    public function saveApprovalState(BookingState $before, BookingState $after, int $actorId, ?string $snapshot = null): bool
    {
        $this->requireTransaction();
        $set = $snapshot === null ? '' : ', a4_request_data=:snapshot';
        $q = $this->db->prepare('UPDATE booking_requests SET workflow_status=:status, version=version+1,
            updated_by=:actor'.$set.' WHERE id=:id AND version=:version AND workflow_status=:previous'.$this->activeRequest);
        $params = ['status'=>$after->status, 'actor'=>$actorId, 'id'=>$before->id, 'version'=>$before->version, 'previous'=>$before->status];
        if ($snapshot !== null) $params['snapshot'] = $snapshot;
        $q->execute($params);
        return $q->rowCount() === 1;
    }

    public function saveSection3(int $id, int $version, int $actorId, string $snapshot): bool
    {
        $this->requireTransaction();
        $q = $this->db->prepare("UPDATE booking_requests SET a4_request_data=:snapshot, version=version+1, updated_by=:actor
            WHERE id=:id AND version=:version AND workflow_status IN ('submitted','under_review','pending_approval')".$this->activeRequest);
        $q->execute(['snapshot'=>$snapshot, 'actor'=>$actorId, 'id'=>$id, 'version'=>$version]);
        return $q->rowCount() === 1;
    }

    public function auditSection3(int $id, int $actorId, int $version): void
    {
        $this->requireTransaction();
        (new AuditLogger($this->db))->record($actorId, 'booking.section3_draft.updated', 'booking_request', (string)$id,
            ['from_version'=>$version,'to_version'=>$version+1]);
    }

    private function requireTransaction(): void {if(!$this->db->inTransaction())throw new \RuntimeException('BOOKING_LOCK_REQUIRES_TRANSACTION');}
}
