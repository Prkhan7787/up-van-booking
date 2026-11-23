<section class="mx-auto max-w-5xl rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
    <p class="text-sm font-bold text-up">UP VAN BOOKING</p>
    <h1 class="mt-2 text-2xl font-black text-slate-900">ยินดีต้อนรับ</h1>
    <p class="mt-2 leading-7 text-slate-600">การเข้าสู่ระบบสำเร็จ สิทธิ์ของคุณตรวจสอบจากฐานข้อมูลในทุกคำขอ</p>
    <div class="mt-6">
        <h2 class="text-sm font-bold text-slate-700">บทบาทที่ได้รับ</h2>
        <?php if ($roles === []): ?>
            <p class="mt-2 text-sm text-slate-600">บัญชีนี้ยังไม่มีบทบาท จึงไม่สามารถใช้ฟังก์ชันอื่นได้</p>
        <?php else: ?>
            <ul class="mt-2 flex flex-wrap gap-2" aria-label="บทบาทผู้ใช้">
                <?php foreach ($roles as $role): ?><li class="rounded-full border border-up-200 bg-up-50 px-3 py-1.5 text-sm font-semibold text-up"><?= e(App\Services\RoleLabels::label($role)) ?></li><?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
    <?php if (in_array('driver', $roles, true)): ?>
    <div class="mt-6 rounded-xl border border-up-200 bg-up-50 p-4">
        <h2 class="font-bold text-up">พื้นที่พนักงานขับรถ</h2>
        <p class="mt-1 text-sm text-slate-700">ดูเฉพาะงานที่ผูกกับบัญชีนี้</p>
        <a href="/driver/schedule" class="mt-3 inline-flex min-h-11 items-center rounded-lg bg-up px-4 py-2 font-semibold text-white">เปิดตารางงานของฉัน</a>
    </div>
    <?php endif; ?>
</section>
