<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\AuthorizationException;
use App\Core\Csrf;
use App\Models\AuthenticatedPrincipal;
use App\Modules\Booking\BookingSubmissionInput;
use App\Modules\Booking\BookingSubmissionService;
use App\Services\AuthorizationPolicy;

final class BookingSubmissionController
{
    /** @param null|callable():\DateTimeImmutable $clock Test-only clock injection keeps date validation deterministic. */
    public function __construct(private readonly ?BookingSubmissionService $service=null, ?callable $clock=null)
    {
        $this->clock = $clock === null
            ? static fn(): \DateTimeImmutable => new \DateTimeImmutable('now', new \DateTimeZone('Asia/Bangkok'))
            : \Closure::fromCallable($clock);
    }
    private readonly \Closure $clock;
    public function submit(?AuthenticatedPrincipal $actor=null): void
    {
        header('Content-Type: application/json; charset=UTF-8');
        if($actor===null||!(new AuthorizationPolicy())->allows($actor->roles,'booking.submit_own')){$this->json(403,['error'=>'FORBIDDEN']);return;}
        if(!Csrf::valid($_POST['_token']??null)){$this->json(419,['error'=>'CSRF_INVALID']);return;}
        if($this->service===null){$this->json(503,['error'=>'SUBMISSION_GATE_CLOSED','message'=>'ระบบส่งคำร้องยังไม่เปิดใช้งาน']);return;}
        $key=$_POST['submission_key']??null;
        if(!is_string($key)||!preg_match('/^[a-f0-9]{64}$/D',$key)){$this->json(422,['error'=>'SUBMISSION_KEY_INVALID']);return;}
        $payload=$_POST;unset($payload['_token'],$payload['submission_key']);ksort($payload);try{$digest=hash('sha256',json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));}catch(\JsonException){$this->json(422,['error'=>'BOOKING_PAYLOAD_INVALID']);return;}
        $receipts=$_SESSION['booking_submission_receipts']??[];
        if(isset($receipts[$key])){
            if(!hash_equals((string)$receipts[$key]['digest'],$digest)){$this->json(409,['error'=>'DUPLICATE_PAYLOAD_MISMATCH']);return;}
            $this->json(200,['status'=>'submitted','request_number'=>$receipts[$key]['request_number'],'duplicate'=>true]);return;
        }
        $createdAt=$_SESSION['booking_submission_keys'][$key]??null;
        if(!is_int($createdAt)||$createdAt<time()-7200||$createdAt>time()){$this->json(419,['error'=>'SUBMISSION_KEY_EXPIRED','message'=>'แบบฟอร์มหมดอายุ กรุณาโหลดหน้าใหม่']);return;}
        try{
            $input=BookingSubmissionInput::fromA4($_POST, ($this->clock)());
            $result=$this->service->submit($actor,$input);
            $receipts[$key]=['digest'=>$digest,'request_number'=>$result['request_number']];
            if(count($receipts)>20)$receipts=array_slice($receipts,-20,null,true);
            $_SESSION['booking_submission_receipts']=$receipts;
            unset($_SESSION['booking_submission_keys'][$key]);
            $this->json(201,$result);
        }catch(AuthorizationException){$this->json(403,['error'=>'FORBIDDEN']);}
        catch(\InvalidArgumentException $error){$this->json(422,['error'=>$error->getMessage(),'message'=>'กรุณาตรวจข้อมูลรถ วันเวลา และข้อมูลคำขอให้ครบถ้วน']);}
        catch(\DomainException $error){$message=match($error->getMessage()){
            'BOOKING_VEHICLE_UNAVAILABLE'=>'รถที่เลือกไม่พร้อมใช้งาน กรุณาเลือกข้อมูลใหม่',
            'BOOKING_VEHICLE_CAPACITY'=>'จำนวนผู้เดินทางเกินความจุของรถที่เลือก',
            'BOOKING_WINDOW_CONFLICT'=>'ช่วงวันเวลาไม่ว่างแล้ว กรุณาเลือกช่วงเวลาใหม่',
            default=>'ไม่สามารถส่งคำร้องได้ กรุณาตรวจข้อมูลแล้วลองอีกครั้ง',
        };$this->json(409,['error'=>$error->getMessage(),'message'=>$message]);}
        catch(\Throwable){$this->json(500,['error'=>'SUBMISSION_UNAVAILABLE','message'=>'ระบบยังไม่สามารถรับคำร้องได้ในขณะนี้']);}
    }
    private function json(int $status,array $body): void {http_response_code($status);echo json_encode($body,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);}
}
