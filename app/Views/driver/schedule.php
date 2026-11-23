<?php
$formatDriverDate = static function (?string $value): string {
    if ($value === null || $value === '') return '—';
    try { return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Asia/Bangkok'))->format('d/m/Y H:i'); }
    catch (Throwable) { return '—'; }
};
?>
<section aria-labelledby="driver-schedule-title">
    <div class="mb-5"><p class="text-sm font-bold tracking-wide text-up">MY ASSIGNMENTS</p><h1 id="driver-schedule-title" class="mt-2 text-2xl font-black">ตารางงานขับรถของฉัน</h1></div>
    <?php if (!$linked): ?><p role="status" class="rounded-xl border border-up-200 bg-up-50 p-4 text-sm leading-6">บัญชีนี้ยังไม่ถูกผูกกับข้อมูลพนักงานขับรถ จึงยังไม่มีงานให้แสดง</p><?php endif; ?>
    <?php if ($detail !== null): ?>
        <article class="mb-5 rounded-2xl border border-up-200 bg-white p-5 sm:p-7" aria-labelledby="assignment-detail-title">
            <a href="/driver/schedule" class="text-sm font-semibold text-up underline">← กลับตารางงาน</a>
            <h2 id="assignment-detail-title" class="mt-4 text-xl font-bold">รายละเอียดการเดินทาง</h2>
            <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2">
                <div><dt class="font-semibold text-slate-600">เริ่มเดินทาง</dt><dd class="mt-1"><?= e($formatDriverDate($detail['starts_at'] ?? null)) ?></dd></div>
                <div><dt class="font-semibold text-slate-600">สิ้นสุด</dt><dd class="mt-1"><?= e($formatDriverDate($detail['ends_at'] ?? null)) ?></dd></div>
                <div><dt class="font-semibold text-slate-600">รถ</dt><dd class="mt-1"><?= e((string)$detail['vehicle_label']) ?> · <?= e((string)$detail['registration_number']) ?></dd></div>
                <div><dt class="font-semibold text-slate-600">สถานะงาน</dt><dd class="mt-1"><?= e((string)$detail['assignment_status']) ?></dd></div>
                <div><dt class="font-semibold text-slate-600">ต้นทาง</dt><dd class="mt-1 break-words"><?= e((string)($detail['pickup_location'] ?? '—')) ?></dd></div>
                <div><dt class="font-semibold text-slate-600">ปลายทาง</dt><dd class="mt-1 break-words"><?= e((string)($detail['dropoff_location'] ?? '—')) ?></dd></div>
            </dl>
            <h3 class="mt-6 font-bold">ผู้โดยสารในงานนี้</h3>
            <?php if ($detail['passengers'] === []): ?><p class="mt-2 text-sm text-slate-600">ไม่มีรายชื่อผู้โดยสารในรายการ</p><?php else: ?><ul class="mt-2 list-inside list-disc space-y-1 text-sm"><?php foreach ($detail['passengers'] as $passenger): ?><li><?= e((string)$passenger['display_name']) ?><?= !empty($passenger['affiliation']) ? ' · '.e((string)$passenger['affiliation']) : '' ?></li><?php endforeach; ?></ul><?php endif; ?>
        </article>
    <?php endif; ?>
    <div class="grid gap-4 md:grid-cols-2">
        <?php foreach ($assignments as $assignment): ?>
        <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <h2 class="font-bold text-up"><?= e((string)$assignment['vehicle_label']) ?></h2>
            <p class="mt-2 text-sm text-slate-700"><?= e($formatDriverDate($assignment['starts_at'] ?? null)) ?> — <?= e($formatDriverDate($assignment['ends_at'] ?? null)) ?></p>
            <p class="mt-2 break-words text-sm"><strong>ต้นทาง:</strong> <?= e((string)($assignment['pickup_location'] ?? '—')) ?></p>
            <p class="mt-1 break-words text-sm"><strong>ปลายทาง:</strong> <?= e((string)($assignment['dropoff_location'] ?? '—')) ?></p>
            <a href="/driver/schedule/detail?id=<?= e((string)$assignment['assignment_id']) ?>" class="mt-4 inline-flex min-h-11 items-center font-semibold text-up underline">ดูรายละเอียดงาน</a>
        </article>
        <?php endforeach; ?>
        <?php if ($linked && $assignments === []): ?><p class="rounded-xl border border-slate-200 bg-white p-4 text-sm text-slate-600">ยังไม่มีงานที่เปิดให้แสดง</p><?php endif; ?>
    </div>
</section>
