<?php
declare(strict_types=1);
use App\Modules\Booking\OfficerBookingDisplay;
?>
<section class="rounded-2xl border border-up-200 bg-white p-3 sm:p-7">
    <h1 class="text-2xl font-black">คำร้องรออนุมัติ</h1>
    <?php if ($rows === []): ?>
        <p class="mt-6 rounded-xl border border-slate-200 bg-slate-50 p-6 text-center">ยังไม่มีคำร้องรออนุมัติ</p>
    <?php else: ?>
        <div class="mt-6 overflow-x-auto rounded-xl border border-slate-200" role="region" aria-label="รายการคำร้องรออนุมัติ" tabindex="0">
            <table class="w-full min-w-[610px] border-collapse text-left text-sm">
                <thead class="bg-up-50 text-slate-800"><tr>
                    <th scope="col" class="p-3">สถานะคำร้อง</th>
                    <th scope="col" class="p-3">ผู้ขอ</th>
                    <th scope="col" class="p-3">ใช้รถไปที่ไหน</th>
                    <th scope="col" class="p-3">วันที่ส่งคำร้อง</th>
                    <th scope="col" class="p-3 text-right">ดูรายละเอียด</th>
                </tr></thead>
                <tbody class="divide-y divide-slate-200">
                <?php foreach (array_slice($rows, 0, 25) as $row): ?>
                    <?php [$status, $dot] = OfficerBookingDisplay::status((string)$row['workflow_status']); ?>
                    <tr>
                        <td class="p-3">
                            <span aria-hidden="true" class="inline-block size-2 rounded-full <?= e($dot) ?>"></span>
                            <?= e($status) ?>
                        </td>
                        <td class="break-words p-3"><?= e(($row['requester_name'] ?? '') ?: $row['requester_username']) ?></td>
                        <td class="max-w-52 break-words p-3"><?= e(trim((string)$row['destination']) ?: '—') ?></td>
                        <td class="p-3"><?= e(OfficerBookingDisplay::date($row['submitted_at'])) ?></td>
                        <td class="p-3 text-right">
                            <a class="inline-flex min-h-11 items-center rounded-lg bg-up px-3 font-bold text-white hover:bg-up-900" href="/director/approval-requests/detail?id=<?= (int)$row['id'] ?>">ดูรายละเอียด</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    <?php if ($page > 1): ?><a class="mt-4 inline-flex min-h-11 items-center rounded-lg border border-up px-4 font-bold text-up" href="?page=<?= $page - 1 ?>">ก่อนหน้า</a><?php endif; ?>
    <?php if (count($rows) > 25): ?><a class="mt-4 inline-flex min-h-11 items-center rounded-lg border border-up px-4 font-bold text-up" href="?page=<?= $page + 1 ?>">ถัดไป</a><?php endif; ?>
</section>
