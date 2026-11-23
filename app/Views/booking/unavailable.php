<?php declare(strict_types=1); ?>
<section class="rounded-2xl border border-up-200 bg-white p-6"><h1 class="text-xl font-bold text-up"><?= e($pageTitle ?? 'คำขอยังไม่พร้อมใช้งาน') ?></h1><p class="mt-3 text-sm leading-6 text-slate-700"><?= e($message ?? 'การอ่านคำขอยังรอตรวจสิทธิ์ Runtime และ Read-only Integration ไม่มีการอ่านหรือเปลี่ยนข้อมูลคำขอในคำขอนี้') ?></p></section>
