<?php
declare(strict_types=1);
namespace App\Modules\Booking;

use App\Core\AuthorizationException;
use App\Models\AuthenticatedPrincipal;
use App\Services\AuthorizationPolicy;

/** Transactional submit and read-only officer intake. HTTP/database gates stay outside this service. */
final class BookingSubmissionService
{
    private readonly BookingWorkflowService $workflow;
    public function __construct(private readonly BookingSubmissionRepository $repo)
    {
        $this->workflow=new BookingWorkflowService($repo,new BookingTransitionPolicy(new DenyBookingScope(),new VerifiedSubmissionRequirements()));
    }

    public function submit(AuthenticatedPrincipal $actor,BookingSubmissionInput $input): array
    {
        if(!(new AuthorizationPolicy())->allows($actor->roles,'booking.submit_own'))throw new AuthorizationException();
        return $this->repo->transaction(function()use($actor,$input):array{
            if($input->vehicleId!==null){
                $vehicle=$this->repo->lockVehicle($input->vehicleId);
                if($vehicle===null||empty($vehicle['is_active']))throw new \DomainException('BOOKING_VEHICLE_UNAVAILABLE');
                if($input->fields['passenger_count']!==null&&(int)$vehicle['passenger_capacity']<(int)$input->fields['passenger_count'])throw new \DomainException('BOOKING_VEHICLE_CAPACITY');
                if(BookingRequestCompleteness::hasSchedule($input->fields+['requested_vehicle_id'=>$input->vehicleId])&&($this->repo->hasRequestConflict($input->vehicleId,$input->fields['requested_start_at'],$input->fields['requested_end_at'])||$this->repo->hasAssignmentConflict($input->vehicleId,$input->fields['requested_start_at'],$input->fields['requested_end_at'])))throw new \DomainException('BOOKING_WINDOW_CONFLICT');
            }
            $number='UPV-'.date('Ymd').'-'.strtoupper(bin2hex(random_bytes(4)));
            $id=$this->repo->insertDraft($actor->userId,$input->vehicleId,$number,$input->fields);
            $this->repo->appendDraftHistory($id,$actor->userId);
            $this->repo->auditDraftCreated($id,$actor->userId);
            $state=$this->workflow->transitionInTransaction($actor,$id,1,'submitted');
            return ['id'=>$id,'request_number'=>$number,'status'=>$state->status,'information_complete'=>BookingRequestCompleteness::missing($input->fields+['requested_vehicle_id'=>$input->vehicleId])===[],'schedule_reserved'=>BookingRequestCompleteness::hasSchedule($input->fields+['requested_vehicle_id'=>$input->vehicleId])];
        });
    }

    public function officerVehicles(AuthenticatedPrincipal $actor):array
    {
        if(!(new AuthorizationPolicy())->allows($actor->roles,'booking.officer_request.edit'))throw new AuthorizationException();
        return $this->repo->officerVehicles();
    }
    public function officerList(AuthenticatedPrincipal $actor,int $page=1): array
    {
        if(!(new AuthorizationPolicy())->allows($actor->roles,'booking.officer_queue.view')||$page<1||$page>10000)throw new AuthorizationException();
        return array_map(static function(array $row):array{$row['information_incomplete']=BookingRequestCompleteness::missing($row)!==[];$row['schedule_reserved']=BookingRequestCompleteness::hasSchedule($row);return $row;},$this->repo->officerList(26,($page-1)*25));
    }

    public function officerDetail(AuthenticatedPrincipal $actor,int $id): array
    {
        if($id<1||!(new AuthorizationPolicy())->allows($actor->roles,'booking.officer_queue.view'))throw new AuthorizationException();
        $row=$this->repo->officerDetail($id);
        if($row===null||!in_array($row['workflow_status'],BookingApprovalService::OFFICER_VISIBLE,true))throw new AuthorizationException();
        return $row;
    }

    public function updateOfficerRequest(AuthenticatedPrincipal $actor,int $id,int $expectedVersion,array $post):array
    {
        return $this->repo->transaction(fn() => self::editInTransaction($this->repo, $actor, $id, $expectedVersion, $post));
    }

    public static function editInTransaction(BookingSubmissionRepository|BookingApprovalRepository $repo, AuthenticatedPrincipal $actor,
        int $id, int $expectedVersion, array $post, bool $forward = false): array
    {
        if ($id < 1 || $expectedVersion < 1 || !(new AuthorizationPolicy())->allows($actor->roles, 'booking.officer_request.edit')) {
            throw new AuthorizationException();
        }
        $snapshot=$repo->officerEditSnapshot($id);
        if($snapshot===null)throw new AuthorizationException();
        if((int)$snapshot['version']!==$expectedVersion)throw new \DomainException('BOOKING_VERSION_CONFLICT');
        if(!in_array($snapshot['workflow_status'],['submitted','under_review'],true))throw new AuthorizationException();
        $oldVehicleId=isset($snapshot['requested_vehicle_id'])?(int)$snapshot['requested_vehicle_id']:null;
        if ($forward || array_key_exists('vehicle_registration',$post)) $post['vehicle_id'] = (string)$oldVehicleId;
        $input = $forward ? BookingSubmissionInput::fromForwardA4($post) : BookingSubmissionInput::fromA4($post, null, true);
        $fields = $input->fields;
        $vehicleIds=array_values(array_unique(array_filter([$oldVehicleId,$input->vehicleId],static fn($value)=>$value!==null&&$value>0)));
        sort($vehicleIds,SORT_NUMERIC);
        $lockedVehicles=[];foreach($vehicleIds as $vehicleId){$row=$repo->lockVehicle($vehicleId);if($row===null&&!$forward)throw new \DomainException('BOOKING_VEHICLE_UNAVAILABLE');$lockedVehicles[$vehicleId]=$row;}
        $vehicle=$input->vehicleId===null?null:($lockedVehicles[$input->vehicleId]??null);
        if ($input->vehicleId!==null && empty($vehicle['is_active'])) {
            if (!$forward) throw new \DomainException('BOOKING_VEHICLE_UNAVAILABLE');
            $fields['requested_start_at']=$fields['requested_end_at']=null;
        }
        if(!$forward&&$vehicle!==null&&$fields['passenger_count']!==null&&(int)$vehicle['passenger_capacity']<(int)$input->fields['passenger_count'])throw new \DomainException('BOOKING_VEHICLE_CAPACITY');
        $current=$repo->lockOfficerEditableRequest($id);
        if($current===null||!in_array($current['workflow_status'],['submitted','under_review'],true))throw new AuthorizationException();
        if((int)$current['version']!==$expectedVersion||(int)($current['requested_vehicle_id']??0)!==(int)($snapshot['requested_vehicle_id']??0))throw new \DomainException('BOOKING_VERSION_CONFLICT');
        $updates=[];$changed=[];
        $editable=['travel_type','requester_name','requester_position','requester_affiliation','requester_phone','purpose','destination','additional_details','expense_source','expense_source_details','pickup_person_name','pickup_person_phone','pickup_location','pickup_at','dropoff_location','dropoff_at','coordinator_name','coordinator_phone','requested_start_at','requested_end_at','passenger_count','a4_request_data'];
        $draft = A4RequestSnapshot::section3($current['a4_request_data'] ?? null, 'section3_draft');
        if ($forward && (new AuthorizationPolicy())->allows($actor->roles, 'booking.section3_draft.edit')) {
            $submittedDraft = BookingApprovalService::section3($post, true);
            $draft = array_filter($submittedDraft, static fn(string $value): bool => $value !== '') ? $submittedDraft : [];
        }
        $updatedSnapshot = $fields['a4_request_data'];
        if ($draft !== []) $updatedSnapshot = A4RequestSnapshot::withSection3($updatedSnapshot, 'section3_draft', $draft);
        $oldRegistration = A4RequestSnapshot::registrationText($current['a4_request_data'] ?? null);
        $registration = $oldRegistration;
        if (array_key_exists('vehicle_registration',$post)) {
            $text = $post['vehicle_registration'];
            if ($forward ? !A4RequestSnapshot::validDocumentText($text) : !A4RequestSnapshot::validRegistrationText($text)) throw new \InvalidArgumentException('BOOKING_REGISTRATION_TEXT_INVALID');
            $display = $oldRegistration;
            if ($display === null) {
                $detail = $repo->officerDetail($id);
                $display = VehicleRegistrationDisplay::format($detail['requested_vehicle_registration'] ?? null,$detail['requested_vehicle_province'] ?? null);
            }
            if ($text !== $display) $registration = $text;
        }
        $updatedSnapshot = A4RequestSnapshot::mergeOfficerSnapshot($current['a4_request_data'] ?? null,$updatedSnapshot,$registration);
        foreach ($editable as $field) {
            $new = $field === 'a4_request_data' ? $updatedSnapshot : ($fields[$field] ?? null);
            $old = $current[$field] ?? null;
            if ($field === 'passenger_count' && $old !== null) $old = (int)$old;
            if (in_array($field, ['pickup_at','dropoff_at','requested_start_at','requested_end_at'], true) && is_string($old)) {
                $old = substr($old, 0, 19);
            }
            if ($field === 'a4_request_data') {
                $originalFields = $old === null
                    ? A4RequestSnapshot::values(RequesterA4Presenter::formData($current))
                    : A4RequestSnapshot::restore($old);
                $oldDraft = A4RequestSnapshot::section3($old, 'section3_draft');
                ksort($oldDraft);
                ksort($draft);
                if ($originalFields === A4RequestSnapshot::restore($new) && $oldDraft === $draft && $registration === $oldRegistration) continue;
            } elseif ($new === $old) {
                continue;
            }
            $updates[$field] = $new;
            $changed[] = $field;
        }
        if($input->vehicleId!==$oldVehicleId){$changed[]='requested_vehicle_id';}
        $routeChanged=$input->vehicleId!==$oldVehicleId||array_key_exists('requested_start_at',$updates)||array_key_exists('requested_end_at',$updates);
        if(($routeChanged || $forward)&&BookingRequestCompleteness::hasSchedule($fields+['requested_vehicle_id'=>$input->vehicleId])&&($repo->hasRequestConflict($input->vehicleId,$fields['requested_start_at'],$fields['requested_end_at'],$id)||$repo->hasAssignmentConflict($input->vehicleId,$fields['requested_start_at'],$fields['requested_end_at'])))throw new \DomainException('BOOKING_WINDOW_CONFLICT');
        if($changed===[])return ['id'=>$id,'version'=>$expectedVersion,'changed_fields'=>[]];
        if(!$repo->saveOfficerEdit($id,$expectedVersion,$actor->userId,$input->vehicleId,$updates))throw new \DomainException('BOOKING_VERSION_CONFLICT');
        $repo->auditOfficerEdit($id,$actor->userId,$changed,$expectedVersion,$expectedVersion+1);
        return ['id'=>$id,'version'=>$expectedVersion+1,'changed_fields'=>$changed];
    }
}
