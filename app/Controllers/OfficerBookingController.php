<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\AuthorizationException;
use App\Core\BookingRuntimeFeature;
use App\Modules\Booking\A4RequestSnapshot;
use App\Modules\Booking\BookingApprovalService;
use App\Core\Csrf;
use App\Core\View;
use App\Models\AuthenticatedPrincipal;
use App\Modules\Booking\BookingSubmissionService;
use App\Modules\Booking\OfficerBookingDeletionService;
use App\Modules\Booking\RequesterA4Presenter;
use App\Services\AuthorizationPolicy;

final class OfficerBookingController
{
    public function __construct(
        private readonly ?BookingSubmissionService $service=null,
        private readonly ?BookingSubmissionService $editService=null,
        private readonly ?OfficerBookingDeletionService $deleteService=null,
    ) {}
    public function index(?AuthenticatedPrincipal $actor=null): void
    {
        $this->authorize($actor);
        if($this->service===null){http_response_code(503);View::renderWithWorkspaceLayout('booking/unavailable',$this->layout($actor,'คำร้องงานยานพาหนะ')+['message'=>'หน้ายังไม่พร้อมใช้งาน ต้องอนุมัติ Read Capability และตรวจ Runtime Grants ก่อน']);return;}
        $page=$this->pageNumber($_GET['page']??'1');
        $policy = new AuthorizationPolicy();
        $canDelete = $policy->allows($actor->roles, 'booking.officer_request.delete');
        $canRecall = $policy->allows($actor->roles, 'booking.officer_request.recall');
        $recallEnabled = $canRecall && BookingRuntimeFeature::officerReadEnabled() && BookingRuntimeFeature::officerEditEnabled();
        View::renderWithWorkspaceLayout('officer/booking-requests',$this->layout($actor,'คำร้องงานยานพาหนะ')+[
            'rows'=>$this->service->officerList($actor,$page),'page'=>$page,'canDelete'=>$canDelete,
            'canRecall'=>$canRecall,'recallEnabled'=>$recallEnabled,'recallCsrf'=>$recallEnabled ? Csrf::token() : '',
            'deleteEnabled'=>$canDelete && $this->deleteService!==null,
            'deleteCsrf'=>$canDelete && $this->deleteService!==null ? Csrf::token() : '',
        ]);
    }
    public function detail(?AuthenticatedPrincipal $actor=null): void
    {
        $this->authorize($actor);
        if($this->service===null){http_response_code(503);View::renderWithWorkspaceLayout('booking/unavailable',$this->layout($actor,'รายละเอียดคำร้อง')+['message'=>'หน้ายังไม่พร้อมใช้งาน ต้องอนุมัติ Read Capability และตรวจ Runtime Grants ก่อน']);return;}
        $id=filter_var($_GET['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if(!is_int($id))throw new AuthorizationException();
        $row=$this->service->officerDetail($actor,$id);
        $editable = in_array($row['workflow_status'], BookingApprovalService::EDITABLE, true);
        $canEdit = $editable && $this->editService !== null;
        $canAdminDraft = $this->editService !== null && (new AuthorizationPolicy())->allows($actor->roles, 'booking.section3_draft.edit')
            && $editable;
        $showNotify = in_array($row['workflow_status'], BookingApprovalService::RESULT_STATUSES, true)
            && empty($row['notified_outcome'])
            && (new AuthorizationPolicy())->allows($actor->roles,'booking.officer_request.notify');
        $notifyEnabled = false;
        if ($showNotify) {
            try {
                $notifyEnabled = BookingRuntimeFeature::approvalService('notify') !== null;
            } catch (\RuntimeException $error) {
                if ($error->getMessage() !== 'BOOKING_RUNTIME_GRANTS_REJECTED') throw $error;
            }
        }
        $formData = RequesterA4Presenter::formData($row);
        if ($canAdminDraft) $formData += A4RequestSnapshot::section3($row['a4_request_data'] ?? null, 'section3_draft');
        View::render('form-preview', [
            'formData'=>$formData, 'documentMode'=>!$canEdit && !$canAdminDraft, 'officerEditMode'=>$canEdit,
            'adminDraftMode'=>$canAdminDraft, 'showForward'=>$editable,
            'showNotify'=>$showNotify, 'notifyEnabled'=>$notifyEnabled,
            'forwardEnabled'=>$canEdit && BookingRuntimeFeature::officerReadEnabled(),
            'formPermissions'=>['requestFields'=>$canEdit,'vehicleFields'=>$canEdit,'section3'=>$canAdminDraft],
            'documentBack'=>'/officer/booking-requests', 'officerRequestMeta'=>self::metadata($row),
            'csrfToken'=>Csrf::token(), 'bookingId'=>(int)$row['id'], 'bookingVersion'=>(int)$row['version'],
        ]);
    }

    public static function metadata(array $row): array
    {
        $status = $row['workflow_status'];
        return [
            'request_number'=>$row['request_number'], 'vehicle_label'=>$row['requested_vehicle_label'] ?? '',
            'vehicle_registration'=>$row['requested_vehicle_registration'] ?? '', 'vehicle_province'=>$row['requested_vehicle_province'] ?? '',
            'submitted_at'=>$row['submitted_at'], 'status'=>$status,
            'information_incomplete'=>\App\Modules\Booking\BookingRequestCompleteness::missing($row) !== [],
            'schedule_reserved'=>\App\Modules\Booking\BookingRequestCompleteness::hasSchedule($row)
                && in_array($status, ['submitted','under_review','pending_approval','awaiting_assignment','assigned'], true),
            'requested_start_at'=>$row['requested_start_at'] ?? null, 'requested_end_at'=>$row['requested_end_at'] ?? null,
            'destination'=>$row['destination'] ?? '',
        ];
    }
    public function update(?AuthenticatedPrincipal $actor=null):void
    {
        header('Content-Type: application/json; charset=UTF-8');
        if($actor===null||!(new AuthorizationPolicy())->allows($actor->roles,'booking.officer_request.edit')){$this->json(403,['error'=>'FORBIDDEN']);return;}
        if(!Csrf::valid($_POST['_token']??null)){$this->json(419,['error'=>'CSRF_INVALID']);return;}
        if($this->editService===null){$this->json(503,['error'=>'OFFICER_EDIT_GATE_CLOSED','message'=>'การบันทึกการแก้ไขยังไม่เปิดใช้งาน']);return;}
        $id=filter_var($_POST['booking_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        $version=filter_var($_POST['booking_version']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if(!is_int($id)||!is_int($version)){$this->json(422,['error'=>'BOOKING_VERSION_INVALID']);return;}
        try{$result=$this->editService->updateOfficerRequest($actor,$id,$version,$_POST);$this->json(200,['status'=>'updated']+$result);}
        catch(AuthorizationException){$this->json(403,['error'=>'FORBIDDEN']);}
        catch(\InvalidArgumentException $error){$this->json(422,['error'=>$error->getMessage(),'message'=>'กรุณาตรวจข้อมูลคำร้องและวันเวลา']);}
        catch(\DomainException $error){$message=match($error->getMessage()){'BOOKING_VEHICLE_UNAVAILABLE'=>'รถที่เลือกไม่พร้อมใช้งาน','BOOKING_VEHICLE_CAPACITY'=>'จำนวนผู้เดินทางเกินความจุรถ','BOOKING_WINDOW_CONFLICT'=>'ช่วงเวลาทับซ้อนกับคำร้องหรือการจัดรถอื่น','BOOKING_VERSION_CONFLICT'=>'คำร้องถูกแก้ไขแล้ว กรุณาโหลดข้อมูลใหม่',default=>'ไม่สามารถบันทึกการแก้ไขได้'};$this->json(409,['error'=>$error->getMessage(),'message'=>$message]);}
        catch(\Throwable){$this->json(500,['error'=>'OFFICER_EDIT_UNAVAILABLE','message'=>'ระบบยังไม่สามารถบันทึกการแก้ไขได้']);}
    }
    private function authorize(?AuthenticatedPrincipal $actor): void {if($actor===null||!(new AuthorizationPolicy())->allows($actor->roles,'booking.officer_queue.view'))throw new AuthorizationException();}
    public function delete(?AuthenticatedPrincipal $actor=null): void
    {
        if ($actor===null || !(new AuthorizationPolicy())->allows($actor->roles,'booking.officer_request.delete')) {
            $this->json(403,['error'=>'FORBIDDEN']); return;
        }
        if (!Csrf::valid($_POST['_token'] ?? null)) {
            $this->json(419,['error'=>'CSRF_INVALID']); return;
        }
        if ($this->deleteService===null) {
            $this->json(503,['error'=>'OFFICER_DELETE_GATE_CLOSED','message'=>'ระบบยังไม่เปิดให้ลบคำร้อง']); return;
        }
        $id=filter_var($_POST['booking_id'] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        $version=filter_var($_POST['booking_version'] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if (!is_int($id) || !is_int($version)) {
            $this->json(422,['error'=>'BOOKING_VERSION_INVALID','message'=>'ข้อมูลคำร้องไม่ถูกต้อง']); return;
        }
        try {
            $this->deleteService->delete($actor,$id,$version);
            $this->json(200,['status'=>'deleted']);
        } catch (AuthorizationException) {
            $this->json(403,['error'=>'FORBIDDEN']);
        } catch (\DomainException) {
            $this->json(409,['error'=>'BOOKING_DELETE_CONFLICT','message'=>'คำร้องเปลี่ยนสถานะหรือมีการจัดรถแล้ว กรุณาโหลดรายการใหม่']);
        } catch (\Throwable) {
            $this->json(500,['error'=>'BOOKING_DELETE_UNAVAILABLE','message'=>'ลบคำร้องไม่สำเร็จ กรุณาตรวจรายการก่อนลองใหม่']);
        }
    }
    private function layout(AuthenticatedPrincipal $actor,string $title): array {return ['pageTitle'=>$title,'workspaceAuthenticated'=>true,'workspaceRoles'=>$actor->roles,'activeNav'=>'booking-officer'];}
    private function pageNumber(mixed $value): int {if(!is_string($value)||!ctype_digit($value)||strlen($value)>5)return 1;return max(1,min(10000,(int)$value));}
    private function json(int $status,array $body):void{http_response_code($status);echo json_encode($body,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);}
}
