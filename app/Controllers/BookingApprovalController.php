<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\AuthorizationException;
use App\Core\BookingRuntimeFeature;
use App\Core\Csrf;
use App\Core\View;
use App\Models\AuthenticatedPrincipal;
use App\Modules\Booking\A4RequestSnapshot;
use App\Modules\Booking\RequesterA4Presenter;
use App\Services\AuthorizationPolicy;

final class BookingApprovalController
{
    public function index(AuthenticatedPrincipal $actor): void
    {
        $page = filter_var($_GET['page'] ?? '1', FILTER_VALIDATE_INT, ['options'=>['min_range'=>1,'max_range'=>10000]]) ?: 1;
        $service = BookingRuntimeFeature::approvalService('read');
        $layout = ['pageTitle'=>'คำร้องรออนุมัติ','workspaceAuthenticated'=>true,'workspaceRoles'=>$actor->roles,'activeNav'=>'director-approval'];
        if ($service === null) {
            http_response_code(503);
            View::renderWithWorkspaceLayout('booking/unavailable', $layout + ['message'=>'ระบบยังไม่เปิดอ่านคำร้อง']);
            return;
        }
        View::renderWithWorkspaceLayout('director/approval-requests', $layout + ['rows'=>$service->pendingList($actor, $page),'page'=>$page]);
    }

    public function detail(AuthenticatedPrincipal $actor): void
    {
        $id = self::positiveInt($_GET['id'] ?? null);
        $service = BookingRuntimeFeature::approvalService('read');
        if ($service === null) {
            http_response_code(503);
            echo 'ระบบยังไม่เปิดอ่านคำร้อง';
            return;
        }
        if ($id === null) throw new AuthorizationException();
        $row = $service->pendingDetail($actor, $id);
        $canDecide = $service->canDecide($actor, $row);
        $formData = RequesterA4Presenter::formData($row);
        if ($canDecide) {
            $formData += A4RequestSnapshot::section3($row['a4_request_data'] ?? null, 'section3_draft');
        }
        // A draft can suggest text, but a decision always needs a fresh selection by the approver.
        if ($canDecide) unset($formData['approval_outcome']);
        View::render('form-preview', [
            'formData'=>$formData, 'documentMode'=>!$canDecide,
            'directorDecisionMode'=>$canDecide, 'decisionEnabled'=>BookingRuntimeFeature::approvalEnabled(),
            'formPermissions'=>['section3'=>$canDecide],
            'documentBack'=>'/director/approval-requests', 'officerRequestMeta'=>OfficerBookingController::metadata($row),
            'bookingId'=>$id, 'bookingVersion'=>(int)$row['version'], 'csrfToken'=>Csrf::token(),
        ]);
    }

    public function myRequests(AuthenticatedPrincipal $actor): void
    {
        $service = BookingRuntimeFeature::approvalService('read');
        $layout = ['pageTitle'=>'คำร้องของฉัน','workspaceAuthenticated'=>true,'workspaceRoles'=>$actor->roles,'activeNav'=>'my-requests'];
        if ($service === null) {
            http_response_code(503);
            View::renderWithWorkspaceLayout('booking/unavailable',$layout + ['message'=>'ระบบยังไม่เปิดอ่านคำร้อง']);
            return;
        }
        $page = filter_var($_GET['page'] ?? '1', FILTER_VALIDATE_INT, ['options'=>['min_range'=>1,'max_range'=>10000]]) ?: 1;
        View::renderWithWorkspaceLayout('booking/my-requests', $layout + ['rows'=>$service->ownedList($actor,$page),'page'=>$page]);
    }

    public function myDetail(AuthenticatedPrincipal $actor): void
    {
        $id = self::positiveInt($_GET['id'] ?? null);
        if ($id === null) throw new AuthorizationException();
        $service = BookingRuntimeFeature::approvalService('read');
        if ($service === null) {
            http_response_code(503);
            echo 'ระบบยังไม่เปิดอ่านคำร้อง';
            return;
        }
        $row = $service->ownedDetail($actor,$id);
        $publication = $row['notified_outcome'] ?? null;
        View::render('form-preview', [
            'formData'=>RequesterA4Presenter::formData($row, ownerView:true),
            'documentMode'=>true, 'documentBack'=>'/my/booking-requests',
            'ownerAwaitingResult'=>$publication===null,
        ]);
    }

    public function notifyRequester(AuthenticatedPrincipal $actor): void { $this->write($actor, 'notify'); }
    public function forward(AuthenticatedPrincipal $actor): void { $this->write($actor, 'forward'); }
    public function recall(AuthenticatedPrincipal $actor): void { $this->write($actor, 'recall'); }
    public function draft(AuthenticatedPrincipal $actor): void { $this->write($actor, 'section3_draft'); }
    public function decide(AuthenticatedPrincipal $actor): void { $this->write($actor, 'decision'); }

    private function write(AuthenticatedPrincipal $actor, string $capability): void
    {
        header('Content-Type: application/json; charset=UTF-8');
        $permission = match ($capability) {
            'forward'=>'booking.officer_request.forward',
            'notify'=>'booking.officer_request.notify',
            'recall'=>'booking.officer_request.recall',
            'section3_draft'=>'booking.section3_draft.edit',
            'decision'=>'booking.decision',
        };
        if (!(new AuthorizationPolicy())->allows($actor->roles, $permission)) {
            $this->json(403, 'FORBIDDEN', 'บัญชีนี้ไม่มีสิทธิ์ทำรายการ');
            return;
        }
        if (!Csrf::valid($_POST['_token'] ?? null)) {
            $this->json(419, 'CSRF_INVALID', 'คำขอหมดอายุ กรุณาโหลดหน้าใหม่');
            return;
        }
        $id = self::positiveInt($_POST['booking_id'] ?? null);
        $version = self::positiveInt($_POST['booking_version'] ?? null);
        if ($id === null || $version === null) {
            $this->json(422, 'BOOKING_VERSION_INVALID', 'ข้อมูลคำร้องไม่ถูกต้อง');
            return;
        }
        try {
            $service = BookingRuntimeFeature::approvalService($capability === 'recall' ? 'forward' : $capability);
            if ($service === null) {
                $this->json(503, 'BOOKING_GATE_CLOSED', 'ระบบยังไม่เปิดให้ทำรายการนี้');
                return;
            }
            if ($capability === 'decision' && ($_POST['publish_result'] ?? null) !== '1') {
                $this->json(422,'BOOKING_RESULT_NOT_CONFIRMED','กรุณาโหลดหน้าใหม่แล้วกดส่งผลให้เจ้าหน้าที่รถ');
                return;
            }
            match ($capability) {
                'forward'=>$service->forward($actor, $id, $version, $_POST),
                'recall'=>$service->recall($actor, $id, $version),
                'notify'=>$service->notifyRequester($actor,$id,$version),
                'section3_draft'=>$service->saveAdminDraft($actor, $id, $version, $_POST),
                'decision'=>$service->decide($actor, $id, $version, $_POST),
            };
            echo json_encode(['status'=>'saved'], JSON_THROW_ON_ERROR);
        } catch (AuthorizationException) {
            $this->json(403, 'FORBIDDEN', 'บัญชีนี้ไม่มีสิทธิ์ทำรายการ');
        } catch (\InvalidArgumentException $error) {
            $this->json(422, $error->getMessage(), 'บันทึกข้อมูลเอกสารไม่ได้');
        } catch (\DomainException $error) {
            $message = match ($error->getMessage()) {
                'BOOKING_NOTIFICATION_ALREADY_PUBLISHED'=>'คำร้องนี้แจ้งผลให้ผู้ขอแล้ว',
                'BOOKING_NOTIFICATION_NO_DECISION'=>'คำร้องนี้ยังไม่มีผลพิจารณาที่แจ้งได้',
                'BOOKING_FORWARD_FIELDS_BLANK'=>'กรุณากรอกข้อมูลในช่องที่มีกรอบสีแดง',
                'BOOKING_INFORMATION_INCOMPLETE'=>'ข้อมูลที่ต้องใช้พิจารณาอนุมัติยังไม่ครบ',
                'BOOKING_VEHICLE_UNAVAILABLE'=>'รถที่เลือกไม่พร้อมใช้งาน',
                'BOOKING_VEHICLE_CAPACITY'=>'จำนวนผู้เดินทางเกินความจุรถ',
                'BOOKING_WINDOW_CONFLICT'=>'ช่วงเวลาทับซ้อนกับคำร้องหรือการจัดรถอื่น',
                default=>'คำร้องเปลี่ยนสถานะหรือถูกแก้ไขแล้ว กรุณาโหลดข้อมูลใหม่',
            };
            $this->json(409, $error->getMessage(), $message);
        } catch (\Throwable $error) {
            \App\Core\ErrorReporter::report($error);
            $this->json(503, 'BOOKING_ACTION_UNAVAILABLE', 'ระบบยังไม่พร้อมทำรายการ กรุณาตรวจสถานะคำร้อง');
        }
    }

    private static function positiveInt(mixed $value): ?int
    {
        $parsed = filter_var($value, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
        return is_int($parsed) ? $parsed : null;
    }

    private function json(int $status, string $error, string $message, array $fields=[]): void
    {
        http_response_code($status);
        echo json_encode(['error'=>$error,'message'=>$message,'fieldErrors'=>(object)$fields], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
