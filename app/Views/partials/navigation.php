<?php
declare(strict_types=1);
$navigationAuthenticated = $workspaceAuthenticated ?? false;
$navigationRoles = $workspaceRoles ?? [];
$navigationPolicy = new App\Services\AuthorizationPolicy();
?>
<header class="border-b border-slate-200 bg-white">
    <div class="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-3 px-4 py-4 sm:px-6 lg:px-8">
        <a href="/" class="flex min-h-12 items-center gap-3" aria-label="UP Van Booking หน้าแรก">
            <span class="grid size-11 place-items-center rounded-xl bg-up text-lg font-black text-white">UP</span>
            <span><strong class="block text-base text-up">UP Van Booking</strong><span class="text-sm text-slate-600">มหาวิทยาลัยพะเยา</span></span>
        </a>
        <nav class="flex flex-wrap items-center justify-end gap-2" aria-label="เมนูหลัก">
            <?php if ($navigationAuthenticated): ?>
                <a class="inline-flex min-h-11 items-center rounded-lg px-4 py-2.5 text-sm font-semibold <?= ($activeNav ?? '') === 'home' ? 'bg-up text-white' : 'text-slate-700 hover:bg-slate-100' ?>" href="/">หน้าแรก</a>
                <a class="inline-flex min-h-11 items-center rounded-lg px-4 py-2.5 text-sm font-semibold <?= ($activeNav ?? '') === 'dashboard' ? 'bg-up text-white' : 'text-slate-700 hover:bg-slate-100' ?>" href="/dashboard">ภาพรวม</a>
                <a class="inline-flex min-h-11 items-center rounded-lg px-4 py-2.5 text-sm font-semibold <?= ($activeNav ?? '') === 'my-requests' ? 'bg-up text-white' : 'text-slate-700 hover:bg-slate-100' ?>" href="/my/booking-requests">คำร้องของฉัน</a>
                <?php if ($navigationPolicy->allows($navigationRoles, 'calendar.view')): ?>
                    <a class="inline-flex min-h-11 items-center rounded-lg px-4 py-2.5 text-sm font-semibold <?= ($activeNav ?? '') === 'booking-calendar' ? 'bg-up text-white' : 'text-slate-700 hover:bg-slate-100' ?>" href="/bookings/calendar">ขอใช้รถ</a>
                <?php endif; ?>
                <?php if ($navigationPolicy->allows($navigationRoles, 'booking.director_queue.view')): ?>
                    <a class="inline-flex min-h-11 items-center rounded-lg px-4 py-2.5 text-sm font-semibold <?= ($activeNav ?? '') === 'director-approval' ? 'bg-up text-white' : 'text-slate-700 hover:bg-slate-100' ?>" href="/director/approval-requests">คำร้องรออนุมัติ</a>
                <?php endif; ?>
                <?php if ($navigationPolicy->allows($navigationRoles, 'booking.officer_queue.view')): ?>
                    <a class="inline-flex min-h-11 items-center rounded-lg px-4 py-2.5 text-sm font-semibold <?= ($activeNav ?? '') === 'booking-officer' ? 'bg-up text-white' : 'text-slate-700 hover:bg-slate-100' ?>" href="/officer/booking-requests">คำร้องงานยานพาหนะ</a>
                <?php endif; ?>
                <?php if ($navigationPolicy->allows($navigationRoles, 'users.manage')): ?>
                    <a class="inline-flex min-h-11 items-center rounded-lg px-4 py-2.5 text-sm font-semibold <?= ($activeNav ?? '') === 'admin-users' ? 'bg-up text-white' : 'text-slate-700 hover:bg-slate-100' ?>" href="/admin/users">จัดการบัญชีผู้ใช้</a>
                <?php endif; ?>
                <?php if ($navigationPolicy->allows($navigationRoles, 'vehicles.admin')): ?>
                    <a class="inline-flex min-h-11 items-center rounded-lg px-4 py-2.5 text-sm font-semibold <?= ($activeNav ?? '') === 'admin-vehicles' ? 'bg-up text-white' : 'text-slate-700 hover:bg-slate-100' ?>" href="/admin/vehicles">จัดการรถตู้</a>
                <?php endif; ?>
                <?php if ($navigationPolicy->allows($navigationRoles, 'logs.view')): ?>
                    <a class="inline-flex min-h-11 items-center rounded-lg px-4 py-2.5 text-sm font-semibold <?= ($activeNav ?? '') === 'system-logs' ? 'bg-up text-white' : 'text-slate-700 hover:bg-slate-100' ?>" href="/admin/system-logs">System Log</a>
                <?php endif; ?>
                <form action="/logout" method="post" class="inline-flex">
                    <input type="hidden" name="_token" value="<?= e(App\Core\Csrf::token()) ?>">
                    <button type="submit" class="inline-flex min-h-11 items-center rounded-lg border border-slate-300 px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">ออกจากระบบ</button>
                </form>
            <?php else: ?>
                <a class="inline-flex min-h-11 items-center rounded-lg bg-up px-4 py-2.5 text-sm font-bold text-white hover:bg-up-900" href="/login">เข้าสู่ระบบ</a>
            <?php endif; ?>
        </nav>
    </div>
</header>
