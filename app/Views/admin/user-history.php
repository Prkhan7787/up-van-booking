<?php
declare(strict_types=1);
$historyBase=['q'=>$search,'role'=>$selectedRole,'page'=>$page ?? 1];
$describeSnapshot=static function(mixed $snapshot): string {
    if (!is_array($snapshot)) return 'ไม่ได้บันทึกใน Log';
    $parts=[];
    if (isset($snapshot['roles']) && is_array($snapshot['roles']) && $snapshot['roles']!==[]) $parts[]=implode(', ',array_map(App\Services\RoleLabels::label(...),$snapshot['roles']));
    if (array_key_exists('active',$snapshot) && is_bool($snapshot['active'])) $parts[]=$snapshot['active'] ? 'เปิดใช้งาน' : 'ระงับบัญชี';
    return $parts===[] ? 'ไม่ได้บันทึกใน Log' : implode(' · ',$parts);
};
?>
<section id="user-history" tabindex="-1" class="mt-6 rounded-2xl border border-slate-200 bg-white p-5 sm:p-6" aria-labelledby="history-title">
    <div class="flex flex-wrap items-start justify-between gap-3"><div><h2 id="history-title" class="text-xl font-bold text-up">ประวัติการเปลี่ยนแปลง<?= $historyUser!==null ? ' · '.e($historyUser['username']) : '' ?></h2>
    <?php if ($historyUser!==null): ?><p class="mt-2 text-sm text-slate-600">วันที่สร้างบัญชี <time datetime="<?= e(str_replace(' ','T',(string)$historyUser['created_at']).'Z') ?>"><?= e(format_system_timestamp((string)$historyUser['created_at'])) ?></time></p><?php endif; ?></div>
    <a href="<?= e('/admin/users?'.http_build_query($historyBase)) ?>" class="inline-flex min-h-11 items-center rounded-lg px-3 text-sm font-semibold text-up">ปิดประวัติ</a></div>
    <?php if (!empty($historyError)): ?><p role="alert" class="mt-4 rounded-xl border border-up-200 bg-up-50 p-4 text-sm text-slate-700"><?= e($historyError) ?></p>
    <?php elseif ($historyRows===[]): ?><p class="mt-5 text-sm text-slate-600">ไม่พบรายการประวัติใน audit_logs</p>
    <?php else: ?><ol class="mt-5 divide-y divide-slate-200">
        <?php foreach ($historyRows as $entry): $details=$entry['details']; $after=$details['after'] ?? (isset($details['roles']) ? ['roles'=>$details['roles']] : null); ?>
        <li class="grid gap-3 py-5 md:grid-cols-[10rem_minmax(0,1fr)]">
            <div class="text-sm"><time class="font-semibold" datetime="<?= e(str_replace(' ','T',(string)$entry['created_at']).'Z') ?>"><?= e(format_system_timestamp((string)$entry['created_at'])) ?></time><p class="mt-1 text-xs text-slate-500"><?= $entry['user_id']===null ? 'ผู้ดำเนินการ: ระบบ / ไม่ระบุ' : 'ผู้ดำเนินการ: #'.e((string)(int)$entry['user_id']) ?></p></div>
            <div class="min-w-0"><h3 class="font-semibold"><?= e(match($entry['action']){'admin.user.created'=>'สร้างบัญชี','admin.user.roles_changed'=>'เปลี่ยนบทบาท','admin.user.status_changed'=>'เปลี่ยนสถานะ','auth.test_admin.provisioned'=>'สร้างผู้ดูแลเริ่มต้น',default=>'เหตุการณ์บัญชี'}) ?></h3>
            <dl class="mt-3 grid gap-3 text-sm sm:grid-cols-2"><div class="min-w-0 rounded-xl bg-slate-50 p-3"><dt class="text-xs font-semibold text-slate-500">ก่อนเปลี่ยนแปลง</dt><dd class="mt-1 break-words"><?= e($describeSnapshot($details['before'] ?? null)) ?></dd></div><div class="min-w-0 rounded-xl bg-up-50 p-3"><dt class="text-xs font-semibold text-up">หลังเปลี่ยนแปลง</dt><dd class="mt-1 break-words"><?= e($describeSnapshot($after)) ?></dd></div></dl></div>
        </li><?php endforeach; ?>
    </ol>
    <nav aria-label="หน้าประวัติบัญชี" class="mt-4 flex flex-wrap items-center gap-4 text-sm">
        <?php if ($historyPage>1): ?><a class="inline-flex min-h-11 items-center font-semibold text-up" href="<?= e('/admin/users?'.http_build_query($historyBase+['history'=>$historyUser['id'],'history_page'=>$historyPage-1]).'#user-history') ?>">ก่อนหน้า</a><?php endif; ?>
        <span>หน้าประวัติ <?= e((string)$historyPage) ?></span>
        <?php if ($historyHasNext): ?><a class="inline-flex min-h-11 items-center font-semibold text-up" href="<?= e('/admin/users?'.http_build_query($historyBase+['history'=>$historyUser['id'],'history_page'=>$historyPage+1]).'#user-history') ?>">ถัดไป</a><?php endif; ?>
    </nav><?php endif; ?>
</section>
