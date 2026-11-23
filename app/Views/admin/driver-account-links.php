<section aria-labelledby="driver-links-title">
    <div class="mb-5"><p class="text-sm font-bold tracking-wide text-up">DRIVER ACCOUNT CONTROL</p><h1 id="driver-links-title" class="mt-2 text-2xl font-black">ยืนยันการผูกบัญชีพนักงานขับรถ</h1>
        <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">เลือกบัญชี Driver และข้อมูลพนักงานขับรถที่ตรวจสอบตัวบุคคลแล้ว การผูกต้องทำโดย Admin หรือเจ้าหน้าที่รถ และบันทึกผู้ดำเนินการใน Audit Log</p></div>
    <?php if ($notice): ?><p role="status" class="mb-4 rounded-xl border border-up-200 bg-up-50 p-4 text-sm text-up">บันทึกการผูกบัญชีแล้ว</p><?php endif; ?>
    <?php if ($error !== null): ?><p role="alert" class="mb-4 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-900"><?= e($error) ?></p><?php endif; ?>
    <div class="grid gap-5 lg:grid-cols-2">
        <section class="rounded-2xl border border-slate-200 bg-white p-5" aria-labelledby="unlinked-drivers-title"><h2 id="unlinked-drivers-title" class="font-bold">ข้อมูลพนักงานขับรถที่ยังไม่ผูก</h2>
            <?php if ($drivers === []): ?><p class="mt-3 text-sm text-slate-600">ไม่มีรายการที่พร้อมให้ผูก</p><?php else: ?><ul class="mt-3 space-y-2 text-sm"><?php foreach ($drivers as $driver): ?><li class="rounded-lg bg-slate-50 p-3"><strong><?= e((string)$driver['display_name']) ?></strong><span class="ml-2 text-slate-600">รหัส <?= e((string)$driver['staff_code']) ?></span><span class="ml-2 text-xs text-slate-500">ID <?= e((string)$driver['id']) ?></span></li><?php endforeach; ?></ul><?php endif; ?>
        </section>
        <section class="rounded-2xl border border-slate-200 bg-white p-5" aria-labelledby="unlinked-accounts-title"><h2 id="unlinked-accounts-title" class="font-bold">บัญชี Driver ที่ยังไม่ผูก</h2>
            <?php if ($accounts === []): ?><p class="mt-3 text-sm text-slate-600">ไม่มีรายการที่พร้อมให้ผูก</p><?php else: ?><ul class="mt-3 space-y-2 text-sm"><?php foreach ($accounts as $account): ?><li class="rounded-lg bg-slate-50 p-3"><strong><?= e((string)$account['display_name']) ?></strong> (<?= e((string)$account['nickname']) ?>)<span class="ml-2 text-slate-600">เบอร์ <?= e((string)$account['phone_masked']) ?></span><span class="ml-2 text-xs text-slate-500">ID <?= e((string)$account['id']) ?></span></li><?php endforeach; ?></ul><?php endif; ?>
        </section>
    </div>
    <form action="/admin/driver-account-links" method="post" class="mt-5 rounded-2xl border border-slate-200 bg-white p-5 sm:p-6">
        <input type="hidden" name="_token" value="<?= e(App\Core\Csrf::token()) ?>">
        <h2 class="font-bold">ยืนยันรายการที่เลือก</h2>
        <div class="mt-4 grid gap-4 sm:grid-cols-2">
            <label class="text-sm font-semibold">บัญชี Driver
                <select name="user_id" required class="mt-2 min-h-12 w-full rounded-xl border border-slate-300 bg-white px-3"><option value="">เลือกบัญชี</option><?php foreach ($accounts as $account): ?><option value="<?= e((string)$account['id']) ?>"><?= e((string)$account['display_name']) ?> · <?= e((string)$account['phone_masked']) ?> · ID <?= e((string)$account['id']) ?></option><?php endforeach; ?></select>
            </label>
            <label class="text-sm font-semibold">ข้อมูลพนักงานขับรถ
                <select name="driver_id" required class="mt-2 min-h-12 w-full rounded-xl border border-slate-300 bg-white px-3"><option value="">เลือกข้อมูล</option><?php foreach ($drivers as $driver): ?><option value="<?= e((string)$driver['id']) ?>"><?= e((string)$driver['display_name']) ?> · รหัส <?= e((string)$driver['staff_code']) ?> · ID <?= e((string)$driver['id']) ?></option><?php endforeach; ?></select>
            </label>
        </div>
        <label class="mt-4 flex items-start gap-3 text-sm leading-6"><input name="staff_verified" value="1" type="checkbox" required class="mt-1 size-4 accent-up"><span>ยืนยันว่าได้ตรวจสอบตัวบุคคลกับหลักฐาน/ช่องทางของหน่วยงานแล้ว การจับคู่ไม่ได้อาศัยเพียงชื่อหรือเบอร์โทรศัพท์</span></label>
        <button type="submit" class="mt-5 min-h-11 rounded-xl bg-up px-5 py-3 font-bold text-white hover:bg-up-900 focus:outline-none focus:ring-2 focus:ring-gold">บันทึกการผูกบัญชี</button>
    </form>
</section>
