<?php declare(strict_types=1); $search=$search ?? ''; $selectedRole=$selectedRole ?? ''; $driverCreationEnabled=$driverCreationEnabled ?? false; ?>
<div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between" data-admin-notice="<?= !empty($notice) ? 'success' : '' ?>">
    <div>
        <p class="text-sm font-bold tracking-wide text-up">ADMINISTRATION</p>
        <h1 class="mt-2 text-2xl font-black sm:text-3xl">จัดการบัญชีผู้ใช้</h1>
        <p class="mt-2 text-sm leading-6 text-slate-600">จัดการบัญชีและสิทธิ์บนฐานทดสอบ · เฉพาะ Admin</p>
    </div>
</div>
<?php if (!empty($error)): ?><p role="alert" class="mb-6 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-900"><?= e($error) ?></p><?php endif; ?>
<?php if (!empty($notice)): ?><p role="status" class="mb-6 rounded-xl border border-up-200 bg-up-50 p-4 text-sm text-up"><?= e($notice) ?></p><?php endif; ?>

<section class="rounded-2xl border border-slate-200 bg-white p-5 sm:p-7" aria-labelledby="create-user-title">
    <div class="mb-5">
        <h2 id="create-user-title" class="text-xl font-bold">สร้างบัญชีผู้ใช้</h2>
        
    </div>
    <form action="/admin/users" method="post" <?= $actionsEnabled ? 'data-admin-confirm="สร้างบัญชีผู้ใช้" data-account-create' : 'data-unavailable-form' ?>>
        <input type="hidden" name="_token" value="<?= e(App\Core\Csrf::token()) ?>">
        <input type="hidden" name="action" value="create">
        <fieldset <?= !$actionsEnabled ? 'disabled' : '' ?> class="grid min-w-0 grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
            <legend class="sr-only">ข้อมูลสำหรับสร้างบัญชี</legend>
            <div><label for="new-username" class="mb-2 block text-sm font-semibold">Username</label><input id="new-username" name="username" required minlength="3" maxlength="50" autocomplete="off" class="min-h-12 w-full rounded-xl border border-slate-300 bg-white px-4 disabled:bg-slate-100" placeholder="กรอกชื่อผู้ใช้"></div>
            <div><label for="new-email" class="mb-2 block text-sm font-semibold">Email</label><input id="new-email" name="email" type="email" required maxlength="254" autocomplete="off" class="min-h-12 w-full rounded-xl border border-slate-300 bg-white px-4 disabled:bg-slate-100" placeholder="name@example.ac.th"></div>
            <div><label for="new-display-name" class="mb-2 block text-sm font-semibold">Display Name</label><input id="new-display-name" name="display_name" required maxlength="150" autocomplete="off" class="min-h-12 w-full rounded-xl border border-slate-300 bg-white px-4 disabled:bg-slate-100" placeholder="ชื่อที่แสดง"></div>
            <div><label for="new-password" class="mb-2 block text-sm font-semibold">Password</label><input id="new-password" name="password" type="password" required minlength="6" autocomplete="new-password" class="min-h-12 w-full rounded-xl border border-slate-300 bg-white px-4 disabled:bg-slate-100" placeholder="อย่างน้อย 6 ตัวอักษร"><p class="mt-1 text-xs text-slate-600">กำหนดขั้นต่ำ 6 ตัวอักษร</p></div>
            <div><label for="confirm-password" class="mb-2 block text-sm font-semibold">Confirm Password</label><input id="confirm-password" name="password_confirmation" type="password" required minlength="6" autocomplete="new-password" class="min-h-12 w-full rounded-xl border border-slate-300 bg-white px-4 disabled:bg-slate-100" placeholder="กรอกรหัสผ่านอีกครั้ง"></div>
            <div><label for="new-role" class="mb-2 block text-sm font-semibold">บทบาท</label><select id="new-role" name="role" class="min-h-12 min-w-0 w-full max-w-full rounded-xl border border-slate-300 bg-white px-4 disabled:bg-slate-100"><?php foreach ($roles as $role): ?><option value="<?= e($role['value']) ?>" <?= $role['value']==='requester' ? 'selected' : '' ?>><?= e(App\Services\RoleLabels::label($role['value'])) ?></option><?php endforeach; ?></select></div>
        </fieldset>
        <div class="mt-5 flex flex-wrap items-center gap-3">
            <button type="submit" <?= !$actionsEnabled ? 'disabled' : '' ?> class="inline-flex min-h-12 items-center rounded-xl bg-up px-5 py-3 font-bold text-white disabled:cursor-not-allowed disabled:bg-slate-300 disabled:text-slate-600"><?= $actionsEnabled ? 'สร้างบัญชี' : 'สร้างบัญชี (ปิดใช้งาน)' ?></button>
            
        </div>
    </form>
</section>

<details class="mt-6 rounded-2xl border border-slate-200 bg-white p-5 sm:p-7" <?= !empty($driverFormError) ? 'open' : '' ?>>
    <summary class="min-h-11 cursor-pointer rounded text-xl font-bold text-up">สร้างบัญชีพนักงานขับรถ</summary>
    <p class="mt-2 text-sm text-slate-600">ใช้เบอร์โทรศัพท์เข้าสู่ระบบ ระบบกำหนดบทบาทพนักงานขับรถให้อัตโนมัติ</p>
    <?php if (!$driverCreationEnabled): ?>
        <p class="mt-2 text-sm text-slate-600">ยังไม่เปิดให้สร้างบัญชีพนักงานขับรถ สิทธิ์ฐานข้อมูลยังไม่พร้อม</p>
    <?php endif; ?>
    <form action="/admin/users" method="post" class="mt-4" <?= $driverCreationEnabled ? 'data-admin-confirm="สร้างบัญชีพนักงานขับรถ" data-account-create' : 'data-unavailable-form' ?>>
        <input type="hidden" name="_token" value="<?= e(App\Core\Csrf::token()) ?>">
        <input type="hidden" name="action" value="create_driver">
        <fieldset <?= !$driverCreationEnabled ? 'disabled' : '' ?> class="grid min-w-0 grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
            <legend class="sr-only">ข้อมูลบัญชีพนักงานขับรถ</legend>
            <?php foreach ([
                'real_name'=>['ชื่อจริง','text','name','maxlength="150"'],
                'nickname'=>['ชื่อเล่น','text','nickname','maxlength="80"'],
                'phone'=>['เบอร์โทรศัพท์มือถือ','tel','tel','maxlength="32"'],
                'password'=>['รหัสผ่าน (อย่างน้อย 6 ตัวอักษร)','password','new-password','minlength="6"'],
                'password_confirmation'=>['ยืนยันรหัสผ่าน','password','new-password','minlength="6"'],
            ] as $field=>[$label,$type,$autocomplete,$length]): ?>
                <div>
                    <label for="driver-<?= e($field) ?>" class="mb-2 block text-sm font-semibold"><?= e($label) ?></label>
                    <input id="driver-<?= e($field) ?>" name="<?= e($field) ?>" type="<?= e($type) ?>" autocomplete="<?= e($autocomplete) ?>" <?= $length ?> required class="min-h-12 w-full rounded-xl border border-slate-300 bg-white px-4 disabled:bg-slate-100">
                </div>
            <?php endforeach; ?>
        </fieldset>
        <button type="submit" <?= !$driverCreationEnabled ? 'disabled' : '' ?> class="mt-5 inline-flex min-h-12 items-center rounded-xl bg-up px-5 py-3 font-bold text-white disabled:cursor-not-allowed disabled:bg-slate-300 disabled:text-slate-600">สร้างบัญชีพนักงานขับรถ</button>
    </form>
</details>

<section class="mt-6 overflow-hidden rounded-2xl border border-slate-200 bg-white" aria-labelledby="accounts-title">
    <div class="flex flex-col gap-4 border-b border-slate-200 p-5 sm:p-6 xl:flex-row xl:items-center xl:justify-between">
        <div><h2 id="accounts-title" class="text-xl font-bold">บัญชีผู้ใช้</h2></div>
        <form class="grid min-w-0 grid-cols-1 gap-3 sm:grid-cols-[minmax(10rem,1fr)_10rem_auto]" action="/admin/users" method="get">
            <label class="sr-only" for="user-search">ค้นหาบัญชี</label><input id="user-search" type="search" name="q" <?= !$actionsEnabled ? 'disabled' : '' ?> maxlength="100" value="<?= e($search) ?>" placeholder="ค้นหา username, email หรือชื่อ" class="min-h-11 rounded-xl border border-slate-300 bg-slate-100 px-3 text-sm">
            <label class="sr-only" for="role-filter">กรองตามบทบาท</label><select id="role-filter" name="role" <?= !$actionsEnabled ? 'disabled' : '' ?> class="min-h-11 min-w-0 max-w-full rounded-xl border border-slate-300 bg-slate-100 px-3 text-sm"><option value="">ทุกบทบาท</option><?php foreach ([...array_column($roles,'value'),'driver'] as $role): ?><option value="<?= e($role) ?>" <?= $selectedRole===$role ? 'selected' : '' ?>><?= e(App\Services\RoleLabels::label($role)) ?></option><?php endforeach; ?></select>
            <button type="submit" <?= !$actionsEnabled ? 'disabled' : '' ?> class="min-h-11 rounded-xl bg-up px-4 py-2 font-bold text-white disabled:bg-slate-300">ค้นหา</button>
        </form>
    </div>
    <table class="admin-users-table w-full table-fixed text-left text-sm">
        <colgroup><col class="w-[33%]"><col class="w-[29%]"><col class="w-[14%]"><col class="w-[24%]"></colgroup>
        <thead class="bg-slate-50 text-xs font-semibold text-slate-600"><tr><th scope="col">บัญชีผู้ใช้</th><th scope="col">บทบาทหลัก</th><th scope="col">สถานะ</th><th scope="col">การจัดการ</th></tr></thead>
        <tbody>
        <?php foreach ($accountRows as $row): $protected=App\Services\UsernamePolicy::normalize((string)$row['username'])==='admin1'; $own=(int)$row['id']===($actorId ?? 0); ?>
            <tr>
                <td><strong class="block break-all text-slate-900"><?= e($row['username'] ?? '—') ?></strong><span class="mt-1 block break-words text-slate-700"><?= e($row['display_name']) ?></span><span class="mt-1 block break-all text-xs text-slate-500"><?= e($row['email']) ?></span></td>
                <td><span class="mb-2 block text-xs font-semibold text-slate-500 md:hidden">บทบาทหลัก</span><?php if ($protected || $own || in_array('driver',$row['roles'],true)): ?><span class="block py-2 text-up"><?= e(implode(', ',array_map(App\Services\RoleLabels::label(...),$row['roles']))) ?></span><?php else: ?><?php require __DIR__.'/role-editor.php'; ?><?php endif; ?></td>
                <td><span class="inline-flex rounded-full px-2 py-1 text-xs font-semibold <?= (bool)$row['is_active'] ? 'bg-up-50 text-up' : 'bg-slate-100 text-slate-600' ?>"><?= (bool)$row['is_active'] ? 'เปิดใช้งาน' : 'ระงับ' ?></span></td>
                <td><div class="flex flex-wrap items-start gap-2">
                    <a href="<?= e('/admin/users/microsoft?user_id='.$row['id']) ?>" class="inline-flex min-h-11 items-center rounded-lg bg-up-50 px-3 py-2 text-xs font-semibold text-up">Microsoft 365</a>
                    <a href="<?= e('/admin/users?'.http_build_query(['q'=>$search,'role'=>$selectedRole,'page'=>$page ?? 1,'history'=>$row['id']]).'#user-history') ?>" class="inline-flex min-h-11 items-center rounded-lg bg-up-50 px-3 py-2 text-xs font-semibold text-up" aria-label="<?= e('ดูประวัติการเปลี่ยนแปลงของ '.$row['username']) ?>">ดูประวัติ</a>
                    <?php if (!$protected && !$own): ?><details class="text-xs"><summary class="min-h-11 cursor-pointer rounded-lg border border-slate-300 px-3 py-3 font-semibold text-up">รีเซ็ตรหัสผ่าน</summary>
                        <form action="/admin/users" method="post" data-admin-confirm="ยืนยันรีเซ็ตรหัสผ่านบัญชีนี้" class="mt-2 grid gap-2">
                            <input type="hidden" name="_token" value="<?= e(App\Core\Csrf::token()) ?>"><input type="hidden" name="action" value="reset_password"><input type="hidden" name="user_id" value="<?= e((string)$row['id']) ?>"><input type="hidden" name="auth_version" value="<?= e((string)$row['auth_version']) ?>">
                            <label>รหัสผ่านใหม่<input name="password" type="password" required minlength="6" autocomplete="new-password" class="min-h-11 w-full rounded-lg border border-slate-300 p-2"></label>
                            <label>ยืนยันรหัสผ่าน<input name="password_confirmation" type="password" required minlength="6" autocomplete="new-password" class="min-h-11 w-full rounded-lg border border-slate-300 p-2"></label>
                            <button type="submit" <?= !$actionsEnabled ? 'disabled' : '' ?> class="min-h-11 rounded-lg bg-up px-3 text-white disabled:opacity-50">บันทึกรหัสผ่านใหม่</button>
                        </form></details><?php endif; ?>
                    <?php if (!$protected): ?><form action="/admin/users" method="post" data-admin-confirm="เปลี่ยนสถานะบัญชี">
                        <input type="hidden" name="_token" value="<?= e(App\Core\Csrf::token()) ?>"><input type="hidden" name="action" value="status"><input type="hidden" name="user_id" value="<?= e((string)$row['id']) ?>"><input type="hidden" name="auth_version" value="<?= e((string)$row['auth_version']) ?>"><input type="hidden" name="active" value="<?= (bool)$row['is_active'] ? '0' : '1' ?>">
                        <button type="submit" <?= !$actionsEnabled || $own ? 'disabled' : '' ?> class="min-h-11 rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-700 disabled:cursor-not-allowed disabled:opacity-50"><?= (bool)$row['is_active'] ? 'ระงับบัญชี' : 'เปิดใช้งานบัญชี' ?></button>
                    </form><?php endif; ?>
                </div></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($accountRows===[]): ?><tr><td colspan="4" class="py-8 text-center text-slate-600">ไม่พบบัญชีผู้ใช้</td></tr><?php endif; ?>
        </tbody>
    </table>
    <?php if ($actionsEnabled): ?><nav class="flex gap-4 border-t border-slate-200 p-5" aria-label="หน้ารายชื่อบัญชี">
        <?php if (($page ?? 1)>1): ?><a class="font-semibold text-up" href="<?= e('/admin/users?'.http_build_query(['q'=>$search,'role'=>$selectedRole,'page'=>$page-1])) ?>">ก่อนหน้า</a><?php endif; ?>
        <span>หน้า <?= e((string)$page) ?></span>
        <?php if (!empty($hasNext)): ?><a class="font-semibold text-up" href="<?= e('/admin/users?'.http_build_query(['q'=>$search,'role'=>$selectedRole,'page'=>$page+1])) ?>">ถัดไป</a><?php endif; ?>
    </nav><?php endif; ?>
</section>
<?php if (($historyUser ?? null)!==null || !empty($historyError)): require __DIR__.'/user-history.php'; endif; ?>
