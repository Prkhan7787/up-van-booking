<!doctype html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#512D6D">
    <title><?= e($appName) ?> | มหาวิทยาลัยพะเยา</title>
    <link rel="stylesheet" href="/assets/app.css">
    <link rel="stylesheet" href="/assets/sweetalert2.min.css">
    <script src="/assets/sweetalert2.min.js" defer></script>
    <script src="/assets/app.js" defer></script>
</head>
<body class="min-h-screen bg-slate-50 text-slate-900 antialiased">
<a href="#main" class="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-50 focus:rounded-lg focus:bg-white focus:px-4 focus:py-3">ข้ามไปยังเนื้อหา</a>
<?php require __DIR__ . '/partials/navigation.php'; ?>
<main id="main" class="mx-auto max-w-7xl px-4 py-8 sm:px-6 sm:py-12 lg:px-8">
    <section class="relative isolate overflow-hidden rounded-3xl bg-up px-6 py-8 text-white shadow-xl shadow-up-950/10 sm:px-10 sm:py-10 lg:px-14 lg:py-12">
        <div class="absolute -right-14 -top-24 -z-10 size-72 rounded-full border-[42px] border-white/10" aria-hidden="true"></div>
        <div class="relative z-10 grid items-center gap-5 lg:grid-cols-[1.1fr_.9fr] lg:gap-8">
            <div class="max-w-3xl">
                <p class="mb-4 text-sm font-bold tracking-[.18em] text-gold">UNIVERSITY OF PHAYAO</p>
                <h1 class="text-3xl font-black leading-tight sm:text-5xl">ระบบขออนุญาต<br class="hidden sm:block">ใช้รถตู้มหาวิทยาลัย</h1>
                <p class="mt-5 max-w-2xl text-base leading-7 text-up-100 sm:text-lg">พื้นที่กลางสำหรับการยื่นคำขอ ตรวจสอบ พิจารณา และประสานการใช้รถตู้ของมหาวิทยาลัย</p>
                <div class="mt-8 flex flex-col gap-3 sm:flex-row">
                    <?php if ($workspaceAuthenticated): ?>
                    <a href="/dashboard" class="inline-flex min-h-12 items-center justify-center gap-2 rounded-xl bg-white px-6 py-3 font-bold text-up hover:bg-up-50">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4 11.5 12 4l8 7.5M6.5 10v9h11v-9M10 19v-5h4v5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        ไปยังภาพรวม
                    </a>
                    <?php if (in_array('admin', $workspaceRoles, true)): ?>
                    <a href="/admin/users" class="inline-flex min-h-12 items-center justify-center rounded-xl border border-white/50 px-6 py-3 font-bold text-white hover:bg-white/10">จัดการบัญชีผู้ใช้</a>
                    <?php endif; ?>
                    <?php else: ?>
                    <a href="/login" class="inline-flex min-h-12 items-center justify-center gap-2 rounded-xl bg-white px-6 py-3 font-bold text-up hover:bg-up-50">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4 11.5 12 4l8 7.5M6.5 10v9h11v-9M10 19v-5h4v5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        เข้าสู่ระบบ
                    </a>
                    <a href="/ui-preview/dashboards" class="inline-flex min-h-12 items-center justify-center rounded-xl border border-white/50 px-6 py-3 font-bold text-white hover:bg-white/10">ดูตัวอย่าง Dashboard (ข้อมูลตัวอย่าง)</a>
                    <?php endif; ?>
                </div>
                <div class="mt-6 flex flex-wrap gap-2 text-xs sm:text-sm">
                    <span class="rounded-full border border-white/30 bg-white/10 px-3 py-1.5">Request · Review · Approval</span>
                    <span class="rounded-full border border-white/30 bg-white/10 px-3 py-1.5">Vehicle · Driver · Calendar</span>
                </div>
            </div>
            <figure class="mx-auto w-full max-w-sm lg:max-w-none">
                <img src="/assets/images/university-van-illustration.webp" width="1555" height="687" alt="ภาพประกอบรถตู้สีขาว อ้างอิงจากภาพรถที่ผู้ดูแลให้ ไม่มีตราทางการและไม่ใช่ภาพถ่ายรถจริง" class="mx-auto h-auto w-full max-h-72 object-contain drop-shadow-lg" fetchpriority="high" decoding="async">
                <figcaption class="mt-2 text-center text-xs text-up-100">ภาพประกอบจากภาพอ้างอิง ไม่ใช่ภาพถ่ายรถจริง</figcaption>
            </figure>
        </div>
    </section>

    <section class="mt-8 grid gap-4 md:grid-cols-3" aria-label="ขั้นตอนโดยสรุป">
        <?php $steps = [['01','ยื่นคำขอ','ผู้ขอกรอกข้อมูลการเดินทางและส่งคำขอ'],['02','ตรวจและพิจารณา','เจ้าหน้าที่ตรวจสอบก่อนเสนอผู้อำนวยการหรือผู้รับมอบหมาย'],['03','จัดรถและแจ้งผล','เจ้าหน้าที่แจ้งผลหรือจัดรถและคนขับหลังอนุมัติ']]; ?>
        <?php foreach ($steps as [$number, $title, $description]): ?>
        <article class="rounded-2xl border border-slate-200 bg-white p-5 sm:p-6">
            <div class="flex items-center justify-between gap-4">
                <span class="text-sm font-black tracking-widest text-up"><?= e($number) ?></span>
                <?php if ($number === '01'): ?>
                <svg width="32" height="32" viewBox="0 0 32 32" fill="none" class="text-up" aria-hidden="true"><path d="M8 4h11l7 7v17H8V4Z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/><path d="M19 4v8h7M12 17h10M12 21h7" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><path d="M24 23v6m-3-3h6" stroke="#D4AF37" stroke-width="2" stroke-linecap="round"/></svg>
                <?php elseif ($number === '02'): ?>
                <svg width="32" height="32" viewBox="0 0 32 32" fill="none" class="text-up" aria-hidden="true"><rect x="7" y="5" width="19" height="24" rx="3" stroke="currentColor" stroke-width="2"/><path d="M12 5.5a3 3 0 0 1 6 0v3h-6v-3ZM11 16l3 3 7-7M11 24h10" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><circle cx="25" cy="25" r="4" fill="#D4AF37"/></svg>
                <?php else: ?>
                <svg width="32" height="32" viewBox="0 0 32 32" fill="none" class="text-up" aria-hidden="true"><rect x="4" y="7" width="24" height="21" rx="3" stroke="currentColor" stroke-width="2"/><path d="M10 4v6M22 4v6M4 13h24M10 18h3m5 0h4m-12 5h3" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><path d="M24 23v6m-3-3h6" stroke="#D4AF37" stroke-width="2" stroke-linecap="round"/></svg>
                <?php endif; ?>
            </div>
            <h2 class="mt-3 text-lg font-bold"><?= e($title) ?></h2>
            <p class="mt-2 text-sm leading-6 text-slate-600"><?= e($description) ?></p>
        </article>
        <?php endforeach; ?>
    </section>

    <section class="mt-8 rounded-2xl border border-up-200 bg-white p-5 sm:p-6" aria-labelledby="system-state">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start">
            <span class="grid size-10 shrink-0 place-items-center rounded-full bg-up-50 font-black text-up" aria-hidden="true"><span class="mr-0.5 inline-block size-2 rounded-full bg-gold"></span>i</span>
            <div>
                <?php if ($workspaceAuthenticated): ?>
                <h2 id="system-state" class="font-bold text-up">เข้าสู่ระบบแล้ว</h2>
                <p class="mt-1 max-w-4xl text-sm leading-6 text-slate-700">ฟังก์ชันขอใช้รถและ Workflow ยังอยู่ระหว่างพัฒนา ใช้เมนูภาพรวมเพื่อดูพื้นที่ตามบทบาทของคุณ</p>
                <?php else: ?>
                <h2 id="system-state" class="font-bold text-up">ส่วนติดต่อผู้ใช้กำลังเตรียมพร้อม</h2>
                <p class="mt-1 max-w-4xl text-sm leading-6 text-slate-700">ยังไม่เปิดรับคำขอจริง ภาพรวมตามบทบาทที่เปิดดูได้เป็นเพียงตัวอย่างและไม่มีข้อมูลบัญชีจริง</p>
                <?php endif; ?>
            </div>
        </div>
    </section>
</main>
<footer class="mx-auto max-w-7xl px-4 pb-8 text-sm text-slate-600 sm:px-6 lg:px-8">UP Van Booking · ระบบขออนุญาตใช้รถตู้ มหาวิทยาลัยพะเยา</footer>
</body>
</html>
