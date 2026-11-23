<?php
declare(strict_types=1);
$workspaceAuthenticated=$workspaceAuthenticated ?? false;
$workspaceRoles=$workspaceRoles ?? [];
$mainWidth=match($activeNav ?? '') {
    'dashboard'=>'max-w-5xl',
    'booking'=>'max-w-6xl',
    default=>'max-w-7xl',
};
?>
<!doctype html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle ?? 'พื้นที่ทำงาน') ?> | UP Van Booking</title>
    <link rel="stylesheet" href="/assets/app.css">
    <link rel="stylesheet" href="/assets/sweetalert2.min.css">
    <script src="/assets/sweetalert2.min.js" defer<?= isset($_SERVER['TRAVEL_MAP_NONCE']) ? ' nonce="'.e($_SERVER['TRAVEL_MAP_NONCE']).'"' : '' ?>></script>
    <script src="/assets/app.js?v=booking-recall-1" defer<?= isset($_SERVER['TRAVEL_MAP_NONCE']) ? ' nonce="'.e($_SERVER['TRAVEL_MAP_NONCE']).'"' : '' ?>></script>
</head>
<body class="min-h-screen bg-slate-50 text-slate-900">
<a href="#main" class="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-50 focus:rounded-lg focus:bg-white focus:px-4 focus:py-3">ข้ามไปยังเนื้อหา</a>
<?php require dirname(__DIR__) . '/partials/navigation.php'; ?>
<?php if (!$workspaceAuthenticated): ?>
<div class="border-b border-up-200 bg-up-50">
    <div class="mx-auto flex max-w-7xl gap-3 px-4 py-3 text-sm leading-6 text-slate-800 sm:px-6 lg:px-8">
        <span class="mt-0.5 font-black text-gold" aria-hidden="true">!</span>
        <p><strong>ตัวอย่าง UI เท่านั้น · ยังไม่มีการยืนยันตัวตน</strong> หน้านี้ไม่ใช่พื้นที่ผู้ใช้ที่เข้าสู่ระบบ และไม่มีข้อมูลบัญชีจริงหรือการทำรายการ</p>
    </div>
</div>
<?php endif; ?>
<div class="mx-auto w-full max-w-7xl px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
    <main id="main" class="mx-auto w-full min-w-0 <?= e($mainWidth) ?>">
        <?= $contentHtml ?>
    </main>
</div>
<footer class="mx-auto max-w-7xl px-4 pb-8 text-sm text-slate-600 sm:px-6 lg:px-8">UP Van Booking · มหาวิทยาลัยพะเยา</footer>
</body>
</html>
