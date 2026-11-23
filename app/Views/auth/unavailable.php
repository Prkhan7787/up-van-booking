<!doctype html>
<html lang="th">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= e($pageTitle ?? 'ยังไม่เปิดใช้งาน') ?> | UP Van Booking</title><link rel="stylesheet" href="/assets/app.css"></head>
<body class="grid min-h-screen place-items-center bg-slate-50 px-4 py-10 text-slate-900">
<main class="w-full max-w-xl rounded-3xl border border-slate-200 bg-white p-7 shadow-lg sm:p-10">
    <span class="inline-flex items-center gap-2 rounded-full border border-up-200 bg-up-50 px-3 py-1.5 text-sm font-bold text-up"><span class="size-2 rounded-full bg-gold" aria-hidden="true"></span>บริการยังไม่เปิด</span>
    <h1 class="mt-5 text-2xl font-black"><?= e($pageTitle ?? 'ยังไม่เปิดใช้งาน') ?></h1>
    <p class="mt-3 leading-7 text-slate-700"><?= e($message ?? 'หน้านี้ยังไม่พร้อมใช้งาน') ?></p>
    <a href="/" class="mt-7 inline-flex min-h-12 items-center rounded-xl bg-up px-5 py-3 font-bold text-white">กลับหน้าแรก</a>
</main>
</body>
</html>
