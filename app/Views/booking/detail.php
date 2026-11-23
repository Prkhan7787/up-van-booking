<?php declare(strict_types=1); ?>
<section class="rounded-2xl border border-slate-200 bg-white p-5 sm:p-7">
<a href="<?= e($backPath) ?>" class="text-sm font-semibold text-up underline"><?= $backPath==='/bookings' ? 'คำขอของฉัน' : 'กลับภาพรวม' ?></a>
<h1 class="mt-3 text-2xl font-black">รายละเอียดคำขอ <?= e($booking['request_number'] ?: '#'.$booking['id']) ?></h1>
<dl class="mt-5 grid gap-4 sm:grid-cols-2">
<?php foreach (['workflow_status'=>'สถานะ','destination'=>'จุดหมาย','purpose'=>'วัตถุประสงค์','passenger_count'=>'จำนวนผู้เดินทาง','created_at'=>'สร้างเมื่อ'] as $key=>$label): ?><div class="rounded-xl bg-slate-50 p-4"><dt class="text-sm font-semibold text-slate-600"><?= e($label) ?></dt><dd class="mt-1 break-words"><?= e((string)($booking[$key] ?? '—')) ?></dd></div><?php endforeach; ?>
</dl>
<a href="<?= e('/bookings/document?'.http_build_query(['id'=>$booking['id']])) ?>" class="mt-6 inline-flex min-h-11 items-center rounded-xl bg-up px-4 py-2 font-bold text-white">ดูเอกสาร A4</a>
<p class="mt-4 text-sm text-slate-600">แสดงข้อมูลแบบอ่านอย่างเดียว การแก้ไข Draft ยังไม่เปิดใช้</p>
</section>
