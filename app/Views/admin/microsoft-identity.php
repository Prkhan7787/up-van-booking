<?php declare(strict_types=1); ?>
<a href="/admin/users" class="font-semibold text-up underline">กลับหน้าจัดการบัญชีผู้ใช้</a>
<section class="mt-6 rounded-2xl border border-slate-200 bg-white p-6">
    <h1 class="text-2xl font-bold text-up">ผูกบัญชี Microsoft 365</h1>
    <?php if (!empty($error)): ?><p role="alert" class="mt-4 text-red-900"><?= e($error) ?></p><?php endif; ?>
    <?php if (is_array($user)): ?>
    <p class="mt-4"><?= e($user['display_name']) ?> · <?= e($user['username']) ?></p>
    <p role="status" class="mt-3 font-semibold"><?= $mapping===null ? 'ยังไม่ได้ผูกบัญชี Microsoft' : 'ผูกบัญชี Microsoft แล้ว' ?></p>
    <p class="mt-3 text-sm text-slate-600">ใช้ Tenant ID และ Object ID ที่ผู้ดูแลตรวจสอบแล้ว การผูกหรือยกเลิกจะสิ้นสุด Session เดิมของบัญชีนี้</p>
    <form method="post" action="/admin/users/microsoft" class="mt-5 space-y-4">
        <input type="hidden" name="_token" value="<?= e(App\Core\Csrf::token()) ?>">
        <input type="hidden" name="user_id" value="<?= e((string)$user['id']) ?>">
        <input type="hidden" name="auth_version" value="<?= e((string)$user['auth_version']) ?>">
        <input type="hidden" name="action" value="<?= $mapping===null?'link':'unlink' ?>">
        <label class="block">Tenant ID<input name="tenant_id" required maxlength="36" <?= $mapping!==null?'readonly':'' ?> value="<?= e($mapping['tenant_id']??'') ?>" class="mt-2 min-h-12 w-full rounded-xl border border-slate-300 px-4"></label>
        <label class="block">Object ID<input name="object_id" required maxlength="36" <?= $mapping!==null?'readonly':'' ?> value="<?= e($mapping['object_id']??'') ?>" class="mt-2 min-h-12 w-full rounded-xl border border-slate-300 px-4"></label>
        <button class="min-h-12 rounded-xl bg-up px-5 py-3 font-bold text-white focus:ring-2 focus:ring-gold"><?= $mapping===null?'ผูกบัญชี Microsoft':'ยกเลิกการผูกบัญชี Microsoft' ?></button>
    </form>
    <?php endif; ?>
</section>
