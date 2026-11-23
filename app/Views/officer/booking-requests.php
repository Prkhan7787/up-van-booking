<?php
declare(strict_types=1);
use App\Modules\Booking\OfficerBookingDisplay;
?>
<section class="rounded-2xl border border-up-200 bg-white p-3 sm:p-7">
    <div>
        <p class="text-sm font-bold text-up">งานยานพาหนะ</p>
        <h1 class="mt-1 text-2xl font-black">คำร้องที่ส่งแล้ว</h1>
    </div>
    <?php if ($rows===[]): ?>
        <div class="mt-6 rounded-xl border border-slate-200 bg-slate-50 p-6 text-center">
            <p class="font-bold text-slate-800">ยังไม่มีคำร้องที่ส่งเข้ามา</p>
            <p class="mt-1 text-sm text-slate-600">เมื่อมีผู้ใช้ส่งคำร้องแล้ว รายการจะแสดงที่หน้านี้</p>
        </div>
    <?php else: ?>
        <div class="mt-6 overflow-x-auto rounded-xl border border-slate-200" role="region" aria-label="รายการคำร้อง" tabindex="0">
            <table class="w-full min-w-[610px] border-collapse text-left text-sm">
                <thead class="bg-up-50 text-slate-800"><tr>
                    <th scope="col" class="p-2 sm:p-3">สถานะคำร้อง</th>
                    <th scope="col" class="p-2 sm:p-3">ผู้ขอ</th>
                    <th scope="col" class="p-2 sm:p-3">ใช้รถไปที่ไหน</th>
                    <th scope="col" class="p-2 sm:p-3">วันที่ส่งคำร้อง</th>
                    <th scope="col" class="p-2 sm:p-3">ผลการแจ้ง</th>
                    <th scope="col" class="p-2 text-right sm:p-3">การดำเนินการ</th>
                    <th scope="col" class="p-2 text-right sm:p-3">ลบคำร้อง</th>
                </tr></thead>
                <tbody class="divide-y divide-slate-200">
                <?php foreach(array_slice($rows, 0, 25) as $row): ?>
                    <?php [$status, $dot] = OfficerBookingDisplay::status((string)$row['workflow_status']); ?>
                    <tr>
                        <td class="p-2 sm:p-3">
                            <span class="flex items-baseline gap-1.5 font-semibold">
                                <span aria-hidden="true" class="inline-block size-2 shrink-0 rounded-full <?= e($dot) ?>"></span>
                                <span class="min-w-0 break-words"><?= e($status) ?></span>
                            </span>
                        </td>
                        <td class="break-words p-2 sm:p-3"><?= e(($row['requester_name'] ?? '') ?: ($row['requester_username'] ?? '—')) ?></td>
                        <?php $destination = trim((string)($row['destination'] ?? '')); ?>
                        <td class="max-w-52 break-words p-2 sm:p-3"><?= e($destination !== '' ? $destination : '—') ?></td>
                        <td class="break-words p-2 sm:p-3"><?= e(OfficerBookingDisplay::date($row['submitted_at'] ?? null)) ?></td>
                        <td class="p-2 sm:p-3"><?= e(!empty($row['notified_outcome']) ? 'แจ้งผู้ขอแล้ว'
                            : (in_array($row['workflow_status'], \App\Modules\Booking\BookingApprovalService::RESULT_STATUSES, true) ? 'ยังไม่แจ้งผู้ขอ' : '—')) ?></td>
                        <td class="p-2 text-right sm:p-3">
                            <div class="flex flex-wrap items-center justify-end gap-2">
                                <a class="inline-flex min-h-11 max-w-full items-center justify-center rounded-lg bg-up px-2 text-center font-bold text-white hover:bg-up-900" href="<?= e('/officer/booking-requests/detail?'.http_build_query(['id'=>$row['id']])) ?>">ดูรายละเอียด</a>
                                <?php if (!empty($canRecall) && $row['workflow_status'] === 'pending_approval'): ?>
                                    <form method="post" action="/officer/booking-requests/recall" data-booking-recall>
                                        <input type="hidden" name="_token" value="<?= e($recallCsrf ?? '') ?>">
                                        <input type="hidden" name="booking_id" value="<?= (int)$row['id'] ?>">
                                        <input type="hidden" name="booking_version" value="<?= (int)($row['version'] ?? 0) ?>">
                                        <button type="button" data-booking-recall-button
                                            class="inline-flex min-h-11 items-center justify-center rounded-lg border border-up px-2 font-bold text-up disabled:cursor-not-allowed disabled:opacity-60"
                                            <?= !empty($recallEnabled) && !empty($row['version']) ? '' : 'disabled' ?>>ยกเลิกการส่งต่อ</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td class="p-2 text-right sm:p-3">
                            <?php if (!empty($canDelete)): ?>
                                <?php $rowCanDelete = !empty($deleteEnabled) && !empty($row['version']) && in_array($row['workflow_status'], ['submitted','under_review'], true); ?>
                                <form method="post" action="/officer/booking-requests/delete" data-booking-delete>
                                    <input type="hidden" name="_token" value="<?= e($deleteCsrf ?? '') ?>">
                                    <input type="hidden" name="booking_id" value="<?= (int)$row['id'] ?>">
                                    <input type="hidden" name="booking_version" value="<?= (int)($row['version'] ?? 0) ?>">
                                    <button type="button" data-booking-delete-button class="min-h-11 min-w-11 rounded-lg bg-red-600 px-3 font-bold text-white hover:bg-red-700 disabled:cursor-not-allowed disabled:opacity-60"
                                        <?= $rowCanDelete ? '' : 'disabled' ?>>ลบ</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if (!empty($canDelete) && empty($deleteEnabled)): ?>
            <p class="mt-3 text-sm text-slate-600">ระบบยังไม่เปิดให้ลบคำร้อง</p>
        <?php endif; ?>
        <?php if (count($rows)>25): ?><a class="mt-4 inline-flex min-h-11 items-center rounded-lg border border-up px-4 font-bold text-up" href="?page=<?= (int)$page+1 ?>">ถัดไป</a><?php endif; ?>
    <?php endif; ?>
</section>
