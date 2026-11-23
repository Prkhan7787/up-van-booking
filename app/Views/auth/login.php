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
    <script src="/assets/login.js" defer></script>
</head>
<body class="min-h-screen bg-slate-50 text-slate-900" data-auth-error="<?= !empty($error) ? 'invalid' : '' ?>">
<main class="mx-auto flex min-h-screen max-w-6xl items-center justify-center px-4 py-10 sm:px-6">
    <div class="grid w-full overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-xl shadow-slate-900/5 lg:grid-cols-2">
        <section class="bg-up p-7 text-white sm:p-10 lg:flex lg:flex-col lg:justify-between">
            <a href="/" class="inline-flex min-h-12 items-center gap-3" aria-label="กลับหน้าแรก">
                <span class="grid size-11 place-items-center rounded-xl bg-white text-lg font-black text-up">UP</span>
                <span><strong class="block">UP Van Booking</strong><span class="text-sm text-up-100">มหาวิทยาลัยพะเยา</span></span>
            </a>
            <div class="mt-12 lg:my-16">
                <p class="text-sm font-bold tracking-[.18em] text-gold">SECURE ACCESS</p>
                <h1 class="mt-4 text-3xl font-black leading-tight sm:text-4xl">เข้าสู่ระบบ<br>ขอใช้รถตู้</h1>
                <p class="mt-4 max-w-md leading-7 text-up-100">ลงชื่อเข้าใช้เพื่อเข้าถึงบริการตามบทบาทที่ได้รับมอบหมาย</p>
            </div>
            <figure class="mx-auto mt-8 w-full max-w-xs lg:mt-0">
                <img src="/assets/images/university-van-illustration.webp" width="1555" height="687" alt="ภาพประกอบรถตู้สีขาว อ้างอิงจากภาพรถที่ผู้ดูแลให้ ไม่ใช่ภาพถ่ายรถจริง" class="h-auto w-full object-contain" loading="lazy" decoding="async">
                <figcaption class="mt-2 text-center text-xs text-up-100">ภาพประกอบจากภาพอ้างอิง ไม่ใช่ภาพถ่ายรถจริง</figcaption>
            </figure>
            <p class="hidden text-sm text-up-100 lg:block">ระบบสำหรับบุคลากรมหาวิทยาลัยพะเยา</p>
        </section>
        <section class="p-6 sm:p-10 lg:p-12" aria-labelledby="login-title">
            <div class="mx-auto max-w-md">
                <h2 id="login-title" class="text-2xl font-black">เข้าสู่ระบบ</h2>
                <p class="mt-2 text-sm text-slate-600">เลือกวิธีเข้าสู่ระบบตามประเภทบัญชี</p>
                <div class="mt-6">
                    <button type="button" data-login-notice="microsoft" class="min-h-12 w-full rounded-xl border border-slate-300 bg-white px-5 py-3 font-bold text-slate-700 hover:border-up hover:text-up focus:outline-none focus:ring-2 focus:ring-up-200">
                        เข้าสู่ระบบด้วย Microsoft 365
                    </button>
                    <p class="mt-2 text-xs leading-5 text-slate-500">สำหรับบุคลากรมหาวิทยาลัย — ยังไม่เชื่อมต่อระบบ Microsoft 365</p>
                </div>
                <section class="mt-6 rounded-2xl border border-slate-200 bg-slate-50 p-4 sm:p-5" aria-labelledby="driver-login-title">
                    <h3 id="driver-login-title" class="font-bold text-up">พนักงานขับรถ</h3>
                    <p class="mt-1 text-sm leading-6 text-slate-600">เข้าสู่ระบบด้วยเบอร์โทรศัพท์และรหัสผ่าน</p>
                    <?php if (empty($testAuthEnabled)): ?><div role="status" class="mt-3 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm leading-6 text-slate-700">ระบบยืนยันตัวตนบนฐานทดสอบยังปิดอยู่ จึงยังไม่สามารถเข้าสู่ระบบพนักงานขับรถได้</div><?php endif; ?>
                    <form class="mt-4 space-y-4" action="/driver/login" method="post" aria-label="เข้าสู่ระบบพนักงานขับรถ">
                        <input type="hidden" name="_token" value="<?= e(App\Core\Csrf::token()) ?>">
                        <div>
                            <label for="driver-phone" class="mb-2 block text-sm font-semibold">เบอร์โทรศัพท์</label>
                            <input id="driver-phone" name="phone" type="tel" autocomplete="tel" required maxlength="20" <?= empty($testAuthEnabled) ? 'disabled' : '' ?> class="min-h-12 w-full rounded-xl border border-slate-300 bg-white px-4 text-slate-700 focus:border-up focus:outline-none focus:ring-2 focus:ring-up-200 disabled:cursor-not-allowed disabled:bg-slate-100" placeholder="เบอร์โทรศัพท์">
                        </div>
                        <div>
                            <label for="driver-password" class="mb-2 block text-sm font-semibold">รหัสผ่าน</label>
                            <input id="driver-password" name="password" type="password" autocomplete="current-password" required maxlength="1024" <?= empty($testAuthEnabled) ? 'disabled' : '' ?> class="min-h-12 w-full rounded-xl border border-slate-300 bg-white px-4 text-slate-700 focus:border-up focus:outline-none focus:ring-2 focus:ring-up-200 disabled:cursor-not-allowed disabled:bg-slate-100" placeholder="รหัสผ่าน">
                        </div>
                        <button type="submit" <?= empty($testAuthEnabled) ? 'disabled' : '' ?> class="min-h-12 w-full rounded-xl bg-up px-5 py-3 font-bold text-white hover:bg-up-900 focus:outline-none focus:ring-2 focus:ring-gold focus:ring-offset-2 disabled:cursor-not-allowed disabled:bg-slate-300 disabled:text-slate-600">เข้าสู่ระบบพนักงานขับรถ</button>
                    </form>
                    <a href="/driver/register" class="mt-3 inline-flex min-h-11 items-center font-semibold text-up underline decoration-up-300 underline-offset-4 hover:text-up-900 focus:outline-none focus:ring-2 focus:ring-up-200" aria-describedby="driver-login-title">สมัครสมาชิกพนักงานขับรถ</a>
                </section>
                <?php if (!empty($driverRegistrationNotice)): ?><p role="status" class="mt-4 rounded-xl border border-up-200 bg-up-50 p-4 text-sm text-up">หากบัญชีนี้ยังไม่เคยสมัคร สามารถลองเข้าสู่ระบบด้วยเบอร์โทรศัพท์ได้ทันที หากยังเข้าไม่ได้ กรุณาติดต่อผู้ดูแล</p><?php endif; ?>
                <?php if (!empty($error)): ?><div role="alert" class="mt-6 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-900"><?= e($error) ?></div><?php endif; ?>
                <div role="status" class="mt-6 rounded-xl border border-up-200 border-l-4 border-l-gold bg-up-50 p-4 text-sm leading-6 text-slate-800">
                    <strong class="block text-up"><?= !empty($testAuthEnabled) ? 'บัญชีทดสอบภายใน' : 'ระบบเข้าสู่ระบบยังปิดอยู่' ?></strong>
                    <span><?= !empty($testAuthEnabled) ? 'เข้าสู่ระบบด้วยชื่อผู้ใช้และรหัสผ่านของบัญชีทดสอบหรือผู้ดูแลที่ provision ไว้' : 'การเข้าสู่ระบบบัญชีทดสอบและผู้ดูแลยังปิดอยู่' ?></span>
                </div>
                <form class="mt-5 space-y-5" action="/login" method="post" aria-label="เข้าสู่ระบบบัญชีทดสอบและผู้ดูแล">
                    <p class="text-sm font-bold text-slate-700">บัญชีทดสอบและผู้ดูแลระบบ</p>
                    <input type="hidden" name="_token" value="<?= e(App\Core\Csrf::token()) ?>">
                    <div>
                        <label for="username" class="mb-2 block text-sm font-semibold">ชื่อผู้ใช้</label>
                        <input id="username" name="username" type="text" autocomplete="username" required <?= empty($testAuthEnabled) ? 'disabled' : '' ?> class="min-h-12 w-full rounded-xl border border-slate-300 bg-white px-4 text-slate-700 focus:border-up focus:outline-none focus:ring-2 focus:ring-up-200 disabled:cursor-not-allowed disabled:bg-slate-100" placeholder="กรอกชื่อผู้ใช้ (Username)">
                    </div>
                    <div>
                        <label for="password" class="mb-2 block text-sm font-semibold">รหัสผ่าน</label>
                        <input id="password" name="password" type="password" autocomplete="current-password" required <?= empty($testAuthEnabled) ? 'disabled' : '' ?> class="min-h-12 w-full rounded-xl border border-slate-300 bg-white px-4 text-slate-700 focus:border-up focus:outline-none focus:ring-2 focus:ring-up-200 disabled:cursor-not-allowed disabled:bg-slate-100" placeholder="รหัสผ่าน">
                    </div>
                    <button type="submit" <?= empty($testAuthEnabled) ? 'disabled' : '' ?> class="min-h-12 w-full rounded-xl bg-up px-5 py-3 font-bold text-white hover:bg-up-900 focus:outline-none focus:ring-2 focus:ring-gold focus:ring-offset-2 disabled:cursor-not-allowed disabled:bg-slate-300 disabled:text-slate-600" aria-describedby="login-title"><?= !empty($testAuthEnabled) ? 'เข้าสู่ระบบ' : 'ยังไม่เปิดให้เข้าสู่ระบบ' ?></button>
                </form>
                <a href="/" class="mt-6 inline-flex min-h-11 items-center font-semibold text-up hover:underline">กลับหน้าแรก</a>
            </div>
        </section>
    </div>
</main>
</body>
</html>
