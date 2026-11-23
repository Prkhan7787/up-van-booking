<div class="mb-6">
    <p class="text-sm font-bold tracking-wide text-up">ROLE-BASED WORKSPACE</p>
    <h1 class="mt-2 text-2xl font-black sm:text-3xl">ตัวอย่าง Dashboard ตามบทบาท</h1>
    <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">แสดงแนวทางเนื้อหาและงานของแต่ละบทบาท โดยไม่จำลองสถานะเข้าสู่ระบบหรือแสดงข้อมูลธุรกรรม</p>
</div>
<section class="grid gap-4 xl:grid-cols-2" aria-label="ตัวอย่าง Dashboard สี่บทบาท">
<?php foreach ($dashboards as $dashboard): ?>
    <article class="rounded-2xl border border-slate-200 bg-white p-5 sm:p-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-3">
                <span class="grid size-11 place-items-center rounded-xl bg-up-50 text-up" aria-hidden="true">
                    <?php if ($dashboard['role'] === 'requester'): ?>
                    <svg width="25" height="25" viewBox="0 0 24 24" fill="none"><path d="M4 4h10l5 5v11H4V4Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M14 4v6h5M8 13h7M8 17h5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                    <?php elseif ($dashboard['role'] === 'vehicle_officer'): ?>
                    <svg width="25" height="25" viewBox="0 0 24 24" fill="none"><path d="M3 14h18l-2-6H6l-3 6ZM5 14v4h14v-4M7 18v2m10-2v2" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><circle cx="7" cy="15" r="1" fill="#D4AF37"/><circle cx="17" cy="15" r="1" fill="#D4AF37"/></svg>
                    <?php elseif ($dashboard['role'] === 'approver'): ?>
                    <svg width="25" height="25" viewBox="0 0 24 24" fill="none"><rect x="5" y="4" width="14" height="17" rx="2" stroke="currentColor" stroke-width="1.8"/><path d="M9 4V3h6v1M8 11l2.5 2.5L16 8" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    <?php else: ?>
                    <svg width="25" height="25" viewBox="0 0 24 24" fill="none"><path d="m12 3 8 3v5c0 5-3.4 8.3-8 10-4.6-1.7-8-5-8-10V6l8-3Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="m9 12 2 2 4-4" stroke="#D4AF37" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    <?php endif; ?>
                </span>
                <span class="rounded-full bg-up-50 px-3 py-1.5 text-xs font-bold text-up"><?= e($dashboard['role']) ?></span>
            </div>
            <span class="text-xs font-semibold text-slate-500">ตัวอย่าง · ไม่มีข้อมูลจริง</span>
        </div>
        <h2 class="mt-4 text-xl font-bold"><?= e($dashboard['label']) ?></h2>
        <p class="mt-2 text-sm leading-6 text-slate-600"><?= e($dashboard['summary']) ?></p>
        <ul class="mt-5 grid gap-2 sm:grid-cols-2">
            <?php foreach ($dashboard['items'] as $item): ?>
            <li class="flex min-h-11 items-start gap-2 rounded-xl bg-slate-50 px-3 py-2.5 text-sm leading-5 text-slate-700"><span class="mt-1 font-black text-up" aria-hidden="true">•</span><span><?= e($item) ?></span></li>
            <?php endforeach; ?>
        </ul>
        <p class="mt-5 border-t border-slate-100 pt-4 text-xs text-slate-500">การเปิดใช้งานต้องตรวจสิทธิ์บทบาทฝั่ง Server ทุก Request</p>
    </article>
<?php endforeach; ?>
</section>
