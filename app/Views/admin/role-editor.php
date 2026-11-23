<?php
declare(strict_types=1);
$knownRoles=array_column($roles,'value');
$roleEditorEnabled=$actionsEnabled && count($row['roles'])===1 && array_diff($row['roles'],$knownRoles)===[];
$orderedRoles=array_values(array_intersect($knownRoles,$row['roles']));
$primaryRole=$orderedRoles[0] ?? '';
$originalRoles=e(json_encode($row['roles'],JSON_THROW_ON_ERROR));
$roleLabelJson=e(json_encode(App\Services\RoleLabels::ALL,JSON_THROW_ON_ERROR));
?>
<form action="/admin/users" method="post" data-admin-confirm="เปลี่ยนบทบาทหลัก" data-primary-role-editor data-original-roles="<?= $originalRoles ?>" data-role-labels="<?= $roleLabelJson ?>">
    <input type="hidden" name="_token" value="<?= e(App\Core\Csrf::token()) ?>"><input type="hidden" name="action" value="primary_role"><input type="hidden" name="user_id" value="<?= e((string)$row['id']) ?>"><input type="hidden" name="auth_version" value="<?= e((string)$row['auth_version']) ?>"><input type="hidden" name="previous_role" value="<?= e($primaryRole) ?>">
    <fieldset <?= !$roleEditorEnabled ? 'disabled' : '' ?> class="flex min-w-0 gap-2">
        <legend class="sr-only"><?= e('บทบาทหลักของ '.$row['username']) ?></legend>
        <label class="min-w-0 flex-1"><span class="sr-only">บทบาทหลัก</span><select name="primary_role" required class="min-h-11 w-full min-w-0 rounded-lg border border-slate-300 bg-white px-2 text-sm"><?php if ($primaryRole===''): ?><option value="">เลือกบทบาท</option><?php endif; ?><?php foreach ($roles as $role): ?><option value="<?= e($role['value']) ?>" <?= $primaryRole===$role['value'] ? 'selected' : '' ?>><?= e(App\Services\RoleLabels::label($role['value'])) ?></option><?php endforeach; ?></select></label>
        <button type="submit" class="min-h-11 rounded-lg bg-up px-3 text-xs font-bold text-white">บันทึก</button>
    </fieldset>
</form>
<?php if (count($row['roles'])!==1): ?><p role="status" class="mt-1 text-xs text-amber-800">บัญชีนี้มีหลายบทบาทหรือไม่มีบทบาท กรุณาตรวจสอบก่อนเปลี่ยนสิทธิ์</p><?php endif; ?>
