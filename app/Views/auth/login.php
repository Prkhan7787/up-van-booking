<!doctype html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>เข้าสู่ระบบ | UP Van Booking</title>
    <link rel="stylesheet" href="/assets/app.css">
    <link rel="stylesheet" href="/assets/sweetalert2.min.css">
    <script src="/assets/sweetalert2.min.js" defer></script>
    <script src="/assets/app.js" defer></script>
</head>
<body class="min-h-screen bg-slate-50 text-slate-900">
<main class="mx-auto flex min-h-screen max-w-6xl items-center justify-center px-4 py-10 sm:px-6">
    <div class="grid w-full overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-xl shadow-slate-900/5 lg:grid-cols-2">
        <section class="bg-up p-7 text-white sm:p-10 lg:flex lg:flex-col lg:justify-between">
            <a href="/" class="inline-flex min-h-12 items-center gap-3" aria-label="กลับหน้าแรก">
                <span class="grid size-11 place-items-center rounded-xl bg-white text-lg font-black text-up">UP</span>
                <span><strong class="block">UP Van Booking</strong><span class="text-sm text-up-100">มหาวิทยาลัยพะเยา</span></span>
            </a>
            <div class="mt-12 lg:my-16">
                <h1 class="text-3xl font-black leading-tight sm:text-4xl">ระบบจองรถตู้</h1>
            </div>
            <figure class="mx-auto mt-8 w-full max-w-xs lg:mt-0">
                <img src="/assets/images/university-van-illustration.webp" width="1555" height="687" alt="ภาพประกอบรถตู้สีขาว อ้างอิงจากภาพรถที่ผู้ดูแลให้ ไม่ใช่ภาพถ่ายรถจริง" class="h-auto w-full object-contain" loading="lazy" decoding="async">
                <figcaption class="mt-2 text-center text-xs text-up-100">ภาพประกอบจากภาพอ้างอิง ไม่ใช่ภาพถ่ายรถจริง</figcaption>
            </figure>
        </section>
        <section class="p-6 sm:p-10 lg:p-12" aria-labelledby="login-title">
            <div class="mx-auto max-w-md">
                <h2 id="login-title" class="text-2xl font-black">เข้าสู่ระบบ</h2>
                <p class="mt-2 text-sm text-slate-600">ใช้บัญชีภายในระบบชั่วคราว ระหว่างรอเชื่อม Microsoft 365</p>
                <div id="microsoft-login-status" role="status" class="mt-6 rounded-xl border border-up-200 border-l-4 border-l-gold bg-up-50 p-4 text-sm leading-6 text-slate-800">
                    <strong class="block text-up">ขณะนี้ระบบยังไม่ได้เชื่อม Microsoft 365</strong>
                    <span>กรุณาเข้าสู่ระบบด้วยบัญชีภายในระบบ</span>
                </div>
                <?php if (empty($testAuthEnabled)): ?>
                <p id="local-login-status" role="status" class="mt-4 text-sm leading-6 text-slate-600">คุณยังกรอกข้อมูลได้ แต่ขณะนี้ระบบยังไม่เปิดให้เข้าสู่ระบบด้วยบัญชีภายใน</p>
                <?php endif; ?>
                <?php if (!empty($error)): ?><div data-login-error role="alert" class="mt-6 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-900"><?= e($error) ?></div><?php endif; ?>
                <form class="mt-5 space-y-5" action="/login" method="post" aria-labelledby="login-title" <?= empty($testAuthEnabled) ? 'aria-describedby="local-login-status"' : '' ?>>
                    <input type="hidden" name="_token" value="<?= e(App\Core\Csrf::token()) ?>">
                    <div>
                        <label for="username" class="mb-2 block text-sm font-semibold">ชื่อผู้ใช้ หรือ เบอร์โทรศัพท์</label>
                        <input id="username" name="username" type="text" autocomplete="username" required class="min-h-12 w-full rounded-xl border border-up-200 bg-white px-4 text-slate-700 focus:border-up focus:outline-none focus:ring-2 focus:ring-up-200" placeholder="กรอกชื่อผู้ใช้หรือเบอร์โทรศัพท์">
                    </div>
                    <div>
                        <label for="password" class="mb-2 block text-sm font-semibold">รหัสผ่าน</label>
                        <input id="password" name="password" type="password" autocomplete="current-password" required class="min-h-12 w-full rounded-xl border border-up-200 bg-white px-4 text-slate-700 focus:border-up focus:outline-none focus:ring-2 focus:ring-up-200" placeholder="รหัสผ่าน">
                    </div>
                    <button type="submit" class="min-h-12 w-full rounded-xl bg-up px-5 py-3 font-bold text-white hover:bg-up-900 focus:outline-none focus:ring-2 focus:ring-gold focus:ring-offset-2">เข้าสู่ระบบ</button>
                </form>
                <button type="button" disabled aria-describedby="microsoft-button-status" class="mt-4 flex min-h-12 w-full cursor-not-allowed items-center justify-center rounded-xl border border-up-200 bg-up-50 px-5 py-3 font-semibold text-slate-600">
                    เข้าสู่ระบบด้วย Microsoft 365
                </button>
                <p id="microsoft-button-status" class="mt-2 text-center text-sm text-slate-600">ยังไม่เปิดใช้งาน</p>
                <a href="/" class="mt-6 inline-flex min-h-11 items-center font-semibold text-up hover:underline">กลับหน้าแรก</a>
            </div>
        </section>
    </div>
</main>
</body>
</html>
