<?php declare(strict_types=1); ?>
<section class="rounded-2xl border border-slate-200 bg-white p-5 sm:p-7">
<h1 class="text-2xl font-black"><?= e($listTitle ?? 'คำขอของฉัน') ?></h1>
<?php if ($rows===[]): ?><p class="mt-5 text-slate-600">ยังไม่มีคำขอ</p><?php endif; ?>
<ul class="mt-5 grid gap-3">
<?php foreach ($rows as $row): ?><li class="rounded-xl border border-slate-200 p-4"><a class="font-bold text-up underline" href="<?= e('/bookings/detail?'.http_build_query(['id'=>$row['id']])) ?>"><?= e($row['request_number'] ?: 'Draft #'.$row['id']) ?></a><p class="mt-1 break-words text-sm text-slate-700"><?= e($row['destination'] ?: 'ยังไม่ระบุจุดหมาย') ?> · <?= e($row['workflow_status']) ?></p></li><?php endforeach; ?>
</ul>
<nav class="mt-5 flex gap-4" aria-label="หน้าคำขอ"><?php if ($page>1): ?><a href="<?= e(($listPath ?? '/bookings').'?page='.($page-1)) ?>">ก่อนหน้า</a><?php endif; ?><?php if ($hasNext): ?><a href="<?= e(($listPath ?? '/bookings').'?page='.($page+1)) ?>">ถัดไป</a><?php endif; ?></nav>
</section>
