<section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8" aria-labelledby="driver-dashboard-title">
    <p class="text-sm font-bold tracking-wide text-up">DRIVER WORKSPACE</p>
    <h1 id="driver-dashboard-title" class="mt-2 text-2xl font-black">ภาพรวมพนักงานขับรถ</h1>
    <?php if (!$linked): ?>
        <p role="status" class="mt-5 rounded-xl border border-up-200 bg-up-50 p-4 text-sm leading-6">บัญชีนี้ยังไม่ถูกผูกกับข้อมูลพนักงานขับรถ จึงยังไม่มีตารางงานแสดง</p>
    <?php else: ?>
        <p class="mt-4 text-slate-700">งานที่ได้รับมอบหมายและเปิดให้ดู: <strong><?= e((string)$assignmentCount) ?></strong> รายการ</p>
    <?php endif; ?>
    <a href="/driver/schedule" class="mt-6 inline-flex min-h-11 items-center rounded-xl bg-up px-5 py-3 font-bold text-white hover:bg-up-900">ดูตารางงานของฉัน</a>
</section>
