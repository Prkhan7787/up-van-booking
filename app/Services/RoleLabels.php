<?php
declare(strict_types=1);
namespace App\Services;

final class RoleLabels
{
    public const ALL = ['requester'=>'ผู้ขอใช้รถ','vehicle_officer'=>'เจ้าหน้าที่รถ','approver'=>'ผู้อำนวยการ','admin'=>'ผู้ดูแลระบบ','driver'=>'พนักงานขับรถ'];
    public static function label(string $role): string { return self::ALL[$role] ?? 'บทบาทที่ไม่รู้จัก'; }
}
