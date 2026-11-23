<?php declare(strict_types=1); ?>
<h1 class="text-2xl font-black text-up">System Log</h1>
<p class="mt-2 text-sm leading-6 text-slate-600">อ่านเหตุการณ์ที่ระบบบันทึกไว้ · ปัจจุบันรองรับ Authentication และการจัดการบัญชี ไม่ใช่ประวัติ Workflow ที่ยังไม่เปิดใช้งาน · รายละเอียดแสดงเฉพาะข้อมูลที่ปลอดภัย</p>
<?php if ($error!==null): ?><p role="alert" class="mt-4 rounded-xl border border-red-200 bg-red-50 p-4 text-red-900"><?= e($error) ?></p><?php endif; ?>
<form method="get" action="/admin/system-logs" class="mt-6 grid min-w-0 gap-4 rounded-2xl border border-slate-200 bg-white p-5 sm:grid-cols-2">
    <label class="min-w-0 text-sm font-semibold">ประเภทเหตุการณ์ (รหัสตรงกัน)<input name="action" maxlength="100" value="<?= e($filter->action) ?>" placeholder="เช่น auth.login.success" class="mt-2 min-h-11 w-full min-w-0 rounded-lg border border-slate-300 px-3"></label>
    <label class="min-w-0 text-sm font-semibold">รหัสผู้ดำเนินการ<input name="actor" inputmode="numeric" value="<?= e((string)($filter->actor ?? '')) ?>" class="mt-2 min-h-11 w-full min-w-0 rounded-lg border border-slate-300 px-3"></label>
    <label class="min-w-0 text-sm font-semibold">วันที่เริ่มต้น (เวลาประเทศไทย)<input type="date" name="from" value="<?= e($filter->from) ?>" class="mt-2 min-h-11 w-full min-w-0 rounded-lg border border-slate-300 px-3"></label>
    <label class="min-w-0 text-sm font-semibold">วันที่สิ้นสุด<input type="date" name="to" value="<?= e($filter->to) ?>" class="mt-2 min-h-11 w-full min-w-0 rounded-lg border border-slate-300 px-3"></label>
    <button class="min-h-11 rounded-lg bg-up px-4 font-bold text-white" type="submit">ค้นหา Log</button><a href="/admin/system-logs" class="inline-flex min-h-11 items-center justify-center rounded-lg border border-up px-4 font-semibold text-up">ล้างตัวกรอง</a>
</form>
<section class="mt-6 min-w-0 overflow-hidden rounded-2xl border border-slate-200 bg-white" aria-label="รายการเหตุการณ์">
    <div class="overflow-x-auto"><table class="w-full min-w-[700px] text-left text-sm">
    <thead class="bg-slate-50"><tr><?php foreach (['เวลา','ผู้ดำเนินการ','เหตุการณ์','ข้อมูลอ้างอิง','รายละเอียด'] as $heading): ?><th class="px-4 py-3" scope="col"><?= e($heading) ?></th><?php endforeach; ?></tr></thead>
    <tbody><?php foreach ($rows as $row): ?><tr class="border-t border-slate-200 align-top">
        <td class="whitespace-nowrap px-4 py-4"><?= e(format_system_timestamp((string)$row['created_at'])) ?></td>
        <td class="px-4 py-4"><?= $row['user_id']===null ? 'ระบบ / ไม่ระบุ' : e((string)(int)$row['user_id']) ?></td>
        <td class="max-w-xs break-all px-4 py-4"><?= e((string)$row['action']) ?></td>
        <td class="max-w-xs break-all px-4 py-4"><?= e((string)$row['entity_type']) ?> · <?= e((string)($row['entity_id'] ?? '—')) ?></td>
        <td class="min-w-[260px] px-4 py-4"><details><summary class="cursor-pointer py-2 font-semibold text-up">รายละเอียดที่แสดงได้</summary>
            <?php if ($row['details']===[]): ?><p class="mt-2 text-xs text-slate-600">ไม่มีรายละเอียดที่แสดงได้</p><?php else: ?><dl class="mt-2 space-y-3 text-sm">
            <?php foreach ($row['details'] as $detailKey=>$detailValue): $snapshot=$detailKey==='roles' ? ['roles'=>$detailValue] : $detailValue; ?>
                <div><dt class="font-semibold"><?= e(match($detailKey){'before'=>'ก่อนเปลี่ยนแปลง','after'=>'หลังเปลี่ยนแปลง',default=>'บทบาทที่กำหนด'}) ?></dt><dd class="mt-1 break-words text-slate-600"><?= e(implode(', ',array_map(App\Services\RoleLabels::label(...),$snapshot['roles']))) ?><?php if (array_key_exists('active',$snapshot)): ?> · <?= $snapshot['active'] ? 'เปิดใช้งาน' : 'ระงับบัญชี' ?><?php endif; ?></dd></div>
            <?php endforeach; ?></dl><?php endif; ?>
        </details></td>
    </tr><?php endforeach; ?><?php if ($rows===[]): ?><tr><td colspan="5" class="p-6 text-slate-600">ไม่พบรายการที่แสดงได้</td></tr><?php endif; ?></tbody></table></div>
    <nav aria-label="หน้ารายการ Log" class="flex flex-wrap items-center gap-4 border-t border-slate-200 p-4">
        <?php if ($filter->page>1): ?><a class="inline-flex min-h-11 items-center font-semibold text-up" href="<?= e('/admin/system-logs?'.$filter->query($filter->page-1)) ?>">ก่อนหน้า</a><?php endif; ?><span>หน้า <?= e((string)$filter->page) ?></span>
        <?php if ($hasNext): ?><a class="inline-flex min-h-11 items-center font-semibold text-up" href="<?= e('/admin/system-logs?'.$filter->query($filter->page+1)) ?>">ถัดไป</a><?php endif; ?>
    </nav>
</section>
