<?php
declare(strict_types=1);
namespace App\Modules\Booking;

final class OfficerBookingDisplay
{
    public static function status(string $workflow): array
    {
        return match ($workflow) {
            'submitted', 'under_review' => ['รอตรวจสอบ', 'bg-blue-600'],
            'pending_approval' => ['รออนุมัติ', 'bg-yellow-500'],
            'awaiting_assignment', 'assigned', 'completed' => ['อนุมัติแล้ว', 'bg-green-600'],
            'awaiting_rejection_notice', 'closed_rejected' => ['ไม่อนุมัติ', 'bg-red-600'],
            'cancelled' => ['ยกเลิก', 'bg-slate-500'],
            'draft' => ['ฉบับร่าง', 'bg-slate-500'],
            default => ['ไม่ทราบสถานะ', 'bg-slate-500'],
        };
    }

    public static function date(mixed $value): string
    {
        if (!is_string($value) || $value === '') return '—';
        try {
            return (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))
                ->setTimezone(new \DateTimeZone('Asia/Bangkok'))->format('d/m/Y H:i');
        } catch (\Exception) {
            return '—';
        }
    }
}
