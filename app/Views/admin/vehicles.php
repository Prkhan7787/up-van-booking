<?php declare(strict_types=1); ?>
<div class="mb-6" data-admin-notice="<?= $notice!==null ? 'success' : '' ?>" data-admin-notice-text="<?= e($notice ?? '') ?>">
    <p class="text-sm font-bold tracking-wide text-up">ADMINISTRATION</p>
    <h1 class="mt-2 text-2xl font-black text-up sm:text-3xl">จัดการรถตู้</h1>
    <p class="mt-2 text-sm leading-6 text-slate-700">รายการรถจากฐานทดสอบ · เฉพาะ Admin<?= !$actionsEnabled ? ' · การเขียนข้อมูลยังปิดใช้งาน' : '' ?></p>
</div>
<?php if ($error!==null): ?><p role="alert" data-vehicle-load-error class="mb-5 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-900"><?= e($error) ?></p><?php endif; ?>
<?php if ($saveError!==null): ?><p role="alert" data-vehicle-save-error class="mb-5 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-900"><?= e($saveError) ?></p><?php endif; ?>
<?php if ($notice!==null): ?><p role="status" class="mb-5 rounded-xl border border-up-200 bg-up-50 p-4 text-sm text-up"><?= e($notice) ?></p><?php endif; ?>
<section class="rounded-2xl border border-slate-200 bg-white p-5 sm:p-7" aria-labelledby="vehicle-create-title">
    <h2 id="vehicle-create-title" class="text-xl font-bold">เพิ่มรถตู้</h2>
    <form action="/admin/vehicles" method="post" data-admin-confirm="บันทึกรถตู้" data-vehicle-action="create">
    <input type="hidden" name="_token" value="<?= e(App\Core\Csrf::token()) ?>"><input type="hidden" name="action" value="create">
    <fieldset <?= !$actionsEnabled ? 'disabled' : '' ?> class="mt-5 grid gap-4 sm:grid-cols-2">
        <legend class="sr-only">ข้อมูลรถตู้</legend>
        <label class="text-sm font-semibold">ชื่อรถ<input name="label" required maxlength="150" class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 bg-white p-2"></label>
        <label class="text-sm font-semibold">หมายเลขทะเบียน<input name="registration_number" required maxlength="30" class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 bg-white p-2"></label>
        <?php if ($provinceEnabled): ?><label class="text-sm font-semibold">จังหวัดทะเบียน<input name="registration_province" required maxlength="100" class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 bg-white p-2"></label><?php endif; ?>
        <?php if (!$provinceEnabled): ?><label class="text-sm font-semibold">จังหวัดทะเบียน<input disabled aria-describedby="province-pending" class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 bg-slate-50 p-2"><span id="province-pending" class="mt-1 block text-xs text-slate-600">เพิ่มได้หลังตรวจ Migration 006 และสิทธิ์แล้ว</span></label><?php endif; ?>
        <label class="text-sm font-semibold">จำนวนที่นั่ง<input name="passenger_capacity" required type="number" min="1" max="65535" class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 bg-white p-2"></label>
        <label class="text-sm font-semibold">ชนิดน้ำมัน<select <?= $fuelEnabled ? 'name="fuel_type"' : 'disabled' ?> class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 bg-white p-2 disabled:bg-slate-50"><option value="">ยังไม่ระบุ</option><?php foreach ($fuelTypes as $fuelType): ?><option value="<?= e($fuelType) ?>"><?= e($fuelType) ?></option><?php endforeach; ?></select><?php if (!$fuelEnabled): ?><span class="mt-1 block text-xs font-normal text-slate-600">รอ Migration 007 และสิทธิ์ข้อมูลน้ำมัน</span><?php endif; ?></label>
    </fieldset>
    <button type="submit" <?= !$actionsEnabled ? 'disabled' : '' ?> class="mt-5 min-h-11 rounded-xl bg-up px-5 py-2 font-bold text-white disabled:cursor-not-allowed disabled:bg-slate-300">บันทึกรถตู้</button>
    </form>
    <?php if (!$actionsEnabled): ?><p class="mt-4 text-xs leading-5 text-slate-600">รอ Migration, Grants และการอนุมัติ Test Vehicle Write Gate ก่อนบันทึกข้อมูลจริง</p><?php endif; ?>
</section>
<section class="mt-6 overflow-hidden rounded-2xl border border-slate-200 bg-white" aria-labelledby="vehicle-list-title">
    <div class="border-b border-slate-200 p-5 sm:p-6"><h2 id="vehicle-list-title" class="text-xl font-bold">รถตู้ในระบบ</h2></div>
    <div data-vehicle-cards class="grid gap-4 p-4 md:grid-cols-2 sm:p-6">
        <?php foreach ($vehicles as $vehicle): ?>
        <article class="min-w-0 rounded-xl border border-slate-200 bg-white p-4">
            <h3 class="break-words font-bold text-up"><?= e((string)$vehicle['label']) ?></h3>
            <p class="mt-1 break-words text-sm text-slate-500">ทะเบียน <?= e((string)$vehicle['registration_number']) ?> · <?= e((string)$vehicle['passenger_capacity']) ?> ที่นั่ง</p>
            <form action="/admin/vehicles" method="post" data-admin-confirm="บันทึกการแก้ไขรถตู้" data-vehicle-action="update" class="mt-4 grid gap-3">
                <input type="hidden" name="_token" value="<?= e(App\Core\Csrf::token()) ?>"><input type="hidden" name="action" value="update"><input type="hidden" name="vehicle_id" value="<?= e((string)$vehicle['id']) ?>">
                <label class="text-xs font-semibold">ชื่อรถ<input name="label" required maxlength="150" value="<?= e((string)$vehicle['label']) ?>" <?= !$actionsEnabled ? 'disabled' : '' ?> class="mt-1 min-h-11 w-full rounded-lg border border-slate-300 p-2 text-sm"></label>
                <label class="text-xs font-semibold">หมายเลขทะเบียน<input name="registration_number" required maxlength="30" value="<?= e((string)$vehicle['registration_number']) ?>" <?= !$actionsEnabled ? 'disabled' : '' ?> class="mt-1 min-h-11 w-full rounded-lg border border-slate-300 p-2 text-sm"></label>
                <label class="text-xs font-semibold">จังหวัดทะเบียน<input <?= $provinceEnabled ? 'name="registration_province" required' : 'disabled' ?> maxlength="100" value="<?= $provinceEnabled ? e((string)($vehicle['registration_province'] ?? '')) : '' ?>" <?= $provinceEnabled && !$actionsEnabled ? 'disabled' : '' ?> <?= !$provinceEnabled ? 'aria-describedby="vehicle-province-pending-'.e((string)$vehicle['id']).'"' : '' ?> class="mt-1 min-h-11 w-full rounded-lg border border-slate-300 p-2 text-sm disabled:bg-slate-50"><?php if (!$provinceEnabled): ?><span id="vehicle-province-pending-<?= e((string)$vehicle['id']) ?>" class="mt-1 block font-normal text-slate-500">รอ Migration 006 และสิทธิ์จังหวัดทะเบียน</span><?php endif; ?></label>
                <label class="text-xs font-semibold">จำนวนที่นั่ง<input name="passenger_capacity" required type="number" min="1" max="65535" value="<?= e((string)$vehicle['passenger_capacity']) ?>" <?= !$actionsEnabled ? 'disabled' : '' ?> class="mt-1 min-h-11 w-full rounded-lg border border-slate-300 p-2 text-sm"></label>
                <label class="text-xs font-semibold">ชนิดน้ำมัน<select <?= $fuelEnabled ? 'name="fuel_type"' : 'disabled' ?> <?= !$actionsEnabled ? 'disabled' : '' ?> class="mt-1 min-h-11 w-full rounded-lg border border-slate-300 p-2 text-sm disabled:bg-slate-50"><option value="">ยังไม่ระบุ</option><?php foreach ($fuelTypes as $fuelType): ?><option value="<?= e($fuelType) ?>" <?= ($vehicle['fuel_type']??null)===$fuelType?'selected':'' ?>><?= e($fuelType) ?></option><?php endforeach; ?></select><?php if (!$fuelEnabled): ?><span class="mt-1 block font-normal text-slate-500">รอ Migration 007 และสิทธิ์ข้อมูลน้ำมัน</span><?php endif; ?></label>
                <button type="submit" <?= !$actionsEnabled ? 'disabled' : '' ?> class="min-h-11 w-full rounded-lg bg-slate-100 px-3 py-2 text-center text-sm font-bold text-slate-800 hover:bg-slate-200 disabled:opacity-50">บันทึกการแก้ไข</button>
            </form>
            <form action="/admin/vehicles" method="post" data-admin-confirm="ลบรถตู้: ซ่อนจากรถที่ใช้จองใหม่ โดยรักษาประวัติเดิม" data-vehicle-action="delete" class="mt-2 flex justify-end">
                <input type="hidden" name="_token" value="<?= e(App\Core\Csrf::token()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="vehicle_id" value="<?= e((string)$vehicle['id']) ?>">
                <button type="submit" <?= !$actionsEnabled ? 'disabled' : '' ?> class="inline-flex min-h-11 items-center justify-center gap-2 rounded-lg px-3 py-2 text-sm font-semibold text-red-700 hover:bg-red-50 disabled:opacity-50"><svg aria-hidden="true" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 6h18M9 6V4h6v2M5 6l1 14h12l1-14M10 10v6M14 10v6" stroke-linecap="round" stroke-linejoin="round"/></svg>ลบรถตู้</button>
            </form>
        </article>
        <?php endforeach; ?>
        <?php if ($vehicles===[]): ?><p class="text-sm text-slate-600">ยังไม่มีรายการรถที่ตรวจสอบได้</p><?php endif; ?>
    </div>
</section>
