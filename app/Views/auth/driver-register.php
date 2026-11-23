<!doctype html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>สมัครสมาชิกพนักงานขับรถ | UP Van Booking</title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body class="min-h-screen bg-slate-50 text-slate-900">
<main class="mx-auto max-w-xl px-4 py-8 sm:px-6 sm:py-12">
    <a href="/login" class="inline-flex min-h-11 items-center font-semibold text-up hover:underline">← กลับหน้าเข้าสู่ระบบ</a>
    <section class="mt-5 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-8" aria-labelledby="driver-register-title">
        <p class="text-sm font-bold tracking-wide text-up">DRIVER ACCOUNT</p>
        <h1 id="driver-register-title" class="mt-2 text-2xl font-black">สมัครสมาชิกพนักงานขับรถ</h1>
        <p class="mt-2 text-sm leading-6 text-slate-600">บัญชีที่สมัครจะได้รับสิทธิ์พนักงานขับรถเท่านั้น การสมัครไม่ผูกกับข้อมูลคนขับเดิมโดยอัตโนมัติ</p>
        <?php if (!empty($success)): ?><div role="status" class="mt-5 rounded-xl border border-up-200 bg-up-50 p-4 text-sm leading-6 text-up">ได้รับข้อมูลแล้ว ลองเข้าสู่ระบบด้วยเบอร์โทรศัพท์ได้ทันที หากยังเข้าไม่ได้ กรุณาติดต่อผู้ดูแล</div>
        <?php elseif (!empty($unavailable) || empty($enabled)): ?><div role="status" class="mt-5 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm leading-6 text-slate-800">การสมัครสมาชิกบนฐานทดสอบยังไม่พร้อมใช้งาน<?= !empty($unavailable) ? ' กรุณาติดต่อผู้ดูแล' : '' ?></div><?php endif; ?>
        <?php if (empty($success)): ?>
        <form action="/driver/register" method="post" class="mt-6 space-y-4" novalidate>
            <input type="hidden" name="_token" value="<?= e(App\Core\Csrf::token()) ?>">
            <label class="block text-sm font-semibold" for="real-name">ชื่อจริง
                <input id="real-name" name="real_name" required maxlength="150" autocomplete="given-name" value="<?= e((string)($old['real_name'] ?? '')) ?>" <?= empty($enabled) ? 'disabled' : '' ?> class="mt-2 min-h-12 w-full rounded-xl border <?= isset($errors['real_name']) ? 'border-red-500 bg-red-50' : 'border-slate-300 bg-white' ?> px-4 focus:border-up focus:outline-none focus:ring-2 focus:ring-up-200 disabled:bg-slate-100">
                <?php if (isset($errors['real_name'])): ?><span class="mt-1 block text-sm text-red-700" role="alert"><?= e($errors['real_name']) ?></span><?php endif; ?>
            </label>
            <label class="block text-sm font-semibold" for="nickname">ชื่อเล่น
                <input id="nickname" name="nickname" required maxlength="80" autocomplete="nickname" value="<?= e((string)($old['nickname'] ?? '')) ?>" <?= empty($enabled) ? 'disabled' : '' ?> class="mt-2 min-h-12 w-full rounded-xl border <?= isset($errors['nickname']) ? 'border-red-500 bg-red-50' : 'border-slate-300 bg-white' ?> px-4 focus:border-up focus:outline-none focus:ring-2 focus:ring-up-200 disabled:bg-slate-100">
                <?php if (isset($errors['nickname'])): ?><span class="mt-1 block text-sm text-red-700" role="alert"><?= e($errors['nickname']) ?></span><?php endif; ?>
            </label>
            <label class="block text-sm font-semibold" for="phone">เบอร์โทรศัพท์ (ใช้เข้าสู่ระบบ)
                <input id="phone" name="phone" type="tel" required maxlength="20" autocomplete="tel" inputmode="tel" value="<?= e((string)($old['phone'] ?? '')) ?>" <?= empty($enabled) ? 'disabled' : '' ?> class="mt-2 min-h-12 w-full rounded-xl border <?= isset($errors['phone']) ? 'border-red-500 bg-red-50' : 'border-slate-300 bg-white' ?> px-4 focus:border-up focus:outline-none focus:ring-2 focus:ring-up-200 disabled:bg-slate-100">
                <?php if (isset($errors['phone'])): ?><span class="mt-1 block text-sm text-red-700" role="alert"><?= e($errors['phone']) ?></span><?php endif; ?>
            </label>
            <label class="block text-sm font-semibold" for="password">รหัสผ่าน
                <input id="password" name="password" type="password" required minlength="6" maxlength="1024" autocomplete="new-password" <?= empty($enabled) ? 'disabled' : '' ?> class="mt-2 min-h-12 w-full rounded-xl border <?= isset($errors['password']) ? 'border-red-500 bg-red-50' : 'border-slate-300 bg-white' ?> px-4 focus:border-up focus:outline-none focus:ring-2 focus:ring-up-200 disabled:bg-slate-100">
                <?php if (isset($errors['password'])): ?><span class="mt-1 block text-sm text-red-700" role="alert"><?= e($errors['password']) ?></span><?php endif; ?>
            </label>
            <label class="block text-sm font-semibold" for="password-confirmation">ยืนยันรหัสผ่าน
                <input id="password-confirmation" name="password_confirmation" type="password" required minlength="6" maxlength="1024" autocomplete="new-password" <?= empty($enabled) ? 'disabled' : '' ?> class="mt-2 min-h-12 w-full rounded-xl border <?= isset($errors['password_confirmation']) ? 'border-red-500 bg-red-50' : 'border-slate-300 bg-white' ?> px-4 focus:border-up focus:outline-none focus:ring-2 focus:ring-up-200 disabled:bg-slate-100">
                <?php if (isset($errors['password_confirmation'])): ?><span class="mt-1 block text-sm text-red-700" role="alert"><?= e($errors['password_confirmation']) ?></span><?php endif; ?>
            </label>
            <button type="submit" <?= empty($enabled) ? 'disabled' : '' ?> class="min-h-12 w-full rounded-xl bg-up px-5 py-3 font-bold text-white hover:bg-up-900 focus:outline-none focus:ring-2 focus:ring-gold disabled:cursor-not-allowed disabled:bg-slate-300">สมัครสมาชิก</button>
        </form>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
