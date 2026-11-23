<?php
declare(strict_types=1);
use App\Modules\Booking\BookingApprovalService;
use App\Modules\Booking\OfficerBookingDisplay;
?>
<section class="rounded-2xl border border-up-200 bg-white p-3 sm:p-7">
    <h1 class="text-2xl font-black">คำร้องของฉัน</h1>
    <?php if (!$rows): ?>
        <p class="mt-6 text-slate-600">ยังไม่มีคำร้อง</p>
    <?php else: ?>
    <div class="mt-6 overflow-x-auto rounded-xl border border-slate-200" role="region" aria-label="คำร้องของฉัน" tabindex="0">
        <table class="w-full min-w-[610px] border-collapse text-left text-sm">
            <thead class="bg-up-50"><tr>
                <?php foreach (['สถานะคำร้อง','ใช้รถไปที่ไหน','วันที่ส่งคำร้อง','ผลการแจ้ง','ดูรายละเอียด'] as $heading): ?>
                    <th scope="col" class="p-3"><?= e($heading) ?></th>
                <?php endforeach; ?>
            </tr></thead>
            <tbody class="divide-y divide-slate-200">
            <?php foreach (array_slice($rows,0,25) as $row): ?>
                <?php
                $notified = !empty($row['notified_outcome']);
                [$label,$color] = OfficerBookingDisplay::status($row['workflow_status']);
                if (!$notified && in_array($row['workflow_status'],BookingApprovalService::RESULT_STATUSES,true)) {
                    [$label,$color] = ['อยู่ระหว่างดำเนินการ','bg-yellow-500'];
                }
                ?>
                <tr>
                    <td class="p-3"><span aria-hidden="true" class="inline-block size-2 rounded-full <?= e($color) ?>"></span> <?= e($label) ?></td>
                    <td class="max-w-52 break-words p-3"><?= e(trim((string)($row['destination'] ?? '')) ?: '—') ?></td>
                    <td class="p-3"><?= e(OfficerBookingDisplay::date($row['submitted_at'])) ?></td>
                    <td class="p-3"><?= $notified ? 'แจ้งผู้ขอแล้ว' : 'ยังไม่แจ้งผู้ขอ' ?></td>
                    <td class="p-3"><a class="inline-flex min-h-11 items-center rounded-lg bg-up px-3 font-bold text-white" href="/my/booking-requests/detail?id=<?= (int)$row['id'] ?>">ดูรายละเอียด</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
    <?php if ($page>1): ?><a class="mt-4 inline-flex min-h-11 items-center px-3 font-bold text-up" href="?page=<?= $page-1 ?>">ก่อนหน้า</a><?php endif; ?>
    <?php if (count($rows)>25): ?><a class="mt-4 inline-flex min-h-11 items-center px-3 font-bold text-up" href="?page=<?= $page+1 ?>">ถัดไป</a><?php endif; ?>
</section>
