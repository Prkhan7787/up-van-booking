<?php
declare(strict_types=1);
namespace App\Services;

final class UiFoundationService
{
    /** @return list<array{role:string,label:string,summary:string,items:list<string>}> */
    public function dashboardPreviews(): array
    {
        return [
            ['role'=>'requester','label'=>'ผู้ขอใช้รถ','summary'=>'ติดตามคำขอของตนเองตั้งแต่แบบร่างจนจบการเดินทาง','items'=>['สร้างและแก้ไขแบบร่าง','ส่งคำขอและติดตามสถานะ','ตรวจผลพิจารณา']],
            ['role'=>'vehicle_officer','label'=>'เจ้าหน้าที่รถ','summary'=>'ตรวจคำขอ ประสานผลพิจารณา และจัดรถหลังได้รับอนุมัติ','items'=>['คิวตรวจสอบคำขอ','ผลพิจารณาที่ส่งกลับ','การจัดรถและปฏิทิน']],
            ['role'=>'approver','label'=>'ผู้อำนวยการ','summary'=>'พิจารณาเฉพาะคำขอที่เจ้าหน้าที่เสนอ','items'=>['รายการรอพิจารณา','บันทึกอนุมัติหรือไม่อนุมัติ','ส่งผลกลับเจ้าหน้าที่']],
            ['role'=>'admin','label'=>'ผู้ดูแลระบบ','summary'=>'จัดการบัญชีและตรวจสอบเหตุการณ์ตามสิทธิ์ที่ได้รับ','items'=>['บัญชีผู้ใช้และบทบาท','ค่าระบบ','รายงานและประวัติระบบ']],
        ];
    }

    /** @return array{roles:list<array{value:string,label:string}>} */
    public function adminUserManagementOptions(): array
    {
        return [
            'roles'=>array_map(static fn(string $key): array=>['value'=>$key,'label'=>$key.' — '.RoleLabels::label($key)],AdminUserManagementService::ROLES),
        ];
    }

    /** Pure server-side policy helper for the future create-account endpoint. */
    public function validatePasswordPair(string $password, string $confirmation): array
    {
        $length = preg_match_all('/./us', $password);
        $errors = [];
        if ($length === false || $length < 6) $errors['password'] = 'รหัสผ่านต้องมีอย่างน้อย 6 ตัวอักษร';
        if (!hash_equals($password, $confirmation)) $errors['confirmation'] = 'รหัสผ่านและการยืนยันไม่ตรงกัน';
        return $errors;
    }
}
