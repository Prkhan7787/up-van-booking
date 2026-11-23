<?php
declare(strict_types=1);
$offset=$days!==[] ? (int)(new DateTimeImmutable($days[0]['date']))->format('w') : 0;
$today=(new DateTimeImmutable('now',new DateTimeZone('Asia/Bangkok')))->format('Y-m-d');
$monthDate=DateTimeImmutable::createFromFormat('!Y-m-d',$month.'-01',new DateTimeZone('Asia/Bangkok'));
$monthDate=$monthDate!==false && $monthDate->format('Y-m')===$month ? $monthDate : new DateTimeImmutable('first day of this month',new DateTimeZone('Asia/Bangkok'));
$toDisplayDate=static function(string $iso):string {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$iso)) return '';
    $date=DateTimeImmutable::createFromFormat('!Y-m-d',$iso,new DateTimeZone('Asia/Bangkok'));
    return $date!==false && $date->format('Y-m-d')===$iso ? $date->format('d/m/Y') : '';
};
$toIsoDate=static fn(string $iso):string=>$toDisplayDate($iso)!==''?$iso:'';
$toTime=static fn(string $time):string=>preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D',$time)?$time:'';
$monthUrl=static fn(string $value):string=>'/bookings/calendar?'.http_build_query(['month'=>$value]+($vehicleId!==null?['vehicle_id'=>$vehicleId]:[]));
?>
<section class="overflow-hidden rounded-2xl border border-up-100 bg-white shadow-sm">
    <div class="border-b border-up-100 bg-gradient-to-r from-up-50 via-white to-white px-4 py-5 sm:px-7 sm:py-7">
        <p class="text-xs font-bold tracking-[0.16em] text-up">UP VAN BOOKING</p>
        <div class="mt-2 flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <h1 class="text-2xl font-black tracking-tight text-up sm:text-3xl">ปฏิทินการใช้รถ</h1>
                <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-700">ดูคำขอและการจัดรถจากข้อมูลจริง เลือกวันและช่วงเวลาเพื่อตรวจว่ามีรถว่างหรือไม่</p>
            </div>
            <form id="calendar-month-form" action="/bookings/calendar" method="get" class="flex w-full justify-center sm:w-auto">
                <div class="inline-flex max-w-full items-end gap-2" style="display:inline-flex">
                <a class="min-h-11 rounded-xl border border-up-200 px-3 py-2 font-semibold text-up" href="<?= e($monthUrl($monthDate->modify('-1 month')->format('Y-m'))) ?>" aria-label="เดือนก่อนหน้า">‹</a>
                <?php if ($vehicleId!==null): ?><input type="hidden" name="vehicle_id" value="<?= e((string)$vehicleId) ?>"><?php endif; ?>
                <label class="min-w-0 text-sm font-semibold text-slate-800">เดือนที่ต้องการดู
                    <input type="month" name="month" value="<?= e($month) ?>" required data-calendar-month class="mt-1 block min-h-11 w-[14rem] max-w-[calc(100vw-8.5rem)] rounded-xl border border-slate-300 bg-white px-3 py-2 text-base focus:border-up focus:outline-none focus:ring-2 focus:ring-up-200">
                </label>
                <?php foreach ($dates as $key=>$value): if ($value!==''): ?><input type="hidden" name="<?= e($key) ?>" value="<?= e($value) ?>"><?php endif; endforeach; ?>
                <a class="min-h-11 rounded-xl border border-up-200 px-3 py-2 font-semibold text-up" href="<?= e($monthUrl($monthDate->modify('+1 month')->format('Y-m'))) ?>" aria-label="เดือนถัดไป">›</a>
                </div>
            </form>
        </div>
    </div>
    <div class="px-3 py-5 sm:px-6 lg:px-8">
        <form action="/bookings/calendar" method="get" data-vehicle-filter class="mb-5 flex flex-col gap-3 rounded-xl border border-up-100 bg-up-50 p-4 sm:flex-row sm:items-end">
            <input type="hidden" name="month" value="<?= e($month) ?>">
            <?php foreach ($dates as $key=>$value): if ($value!==''): ?><input type="hidden" name="<?= e($key) ?>" value="<?= e($value) ?>"><?php endif; endforeach; ?>
            <label class="min-w-0 flex-1 text-sm font-semibold text-up">เลือกรถตามหมายเลขทะเบียน
                <select name="vehicle_id" data-vehicle-filter-select <?= $vehicles===[] ? 'disabled' : '' ?> class="mt-2 min-h-11 w-full rounded-xl border border-up-200 bg-white px-3 text-base text-slate-900 focus:border-up focus:outline-none focus:ring-2 focus:ring-up-200">
                    <?php foreach ($vehicles as $vehicle): ?><option value="<?= e((string)$vehicle['id']) ?>" <?= $vehicleId===(int)$vehicle['id'] ? 'selected' : '' ?>><?= e((string)$vehicle['label'].' · '.(string)$vehicle['registration_number'].(!empty($vehicle['registration_province'])?' · '.(string)$vehicle['registration_province']:'')) ?></option><?php endforeach; ?>
                </select>
            </label>
        </form>
        <?php if ($vehicles===[] && !$vehicleUnavailable): ?><p class="mb-4 text-sm text-slate-700">ยังไม่มีรถตู้ในระบบ</p><?php endif; ?>
        <?php if ($vehicleUnavailable): ?><p class="mb-4 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-950">ยังโหลดรายการรถไม่ได้ โปรดตรวจสิทธิ์อ่านข้อมูลรถของบัญชี Runtime</p><?php endif; ?>
        <?php if ($selectedVehicle!==null): ?><p class="mb-4 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-950">คำขอที่ยื่นแล้วกันช่วงเวลาของรถคันนี้ชั่วคราวจนกว่าจะมีผลพิจารณา</p><?php endif; ?>
        <?php if ($selectedVehicle!==null): ?><p class="mb-4 text-sm font-semibold text-up">กำลังดู <?= e((string)$selectedVehicle['label'].' · '.(string)$selectedVehicle['registration_number'].(!empty($selectedVehicle['registration_province'])?' · '.(string)$selectedVehicle['registration_province']:'')) ?> · รองรับ <?= e((string)$selectedVehicle['passenger_capacity']) ?> คน</p><?php endif; ?>
        <div class="flex flex-wrap gap-x-4 gap-y-2 rounded-xl bg-slate-50 px-3 py-3 text-xs font-semibold text-slate-800 sm:text-sm" aria-label="คำอธิบายสีสถานะ">
            <?php foreach (['submitted','pending_approval','awaiting_assignment','closed_rejected'] as $workflow): ?>
            <?php [$label,$dot]=\App\Modules\Booking\OfficerBookingDisplay::status($workflow); ?>
            <span class="inline-flex items-center gap-2"><span class="size-3 rounded-full <?= e($dot) ?>" aria-hidden="true"></span><?= e($label) ?></span>
            <?php endforeach; ?>
        </div>
        <p class="mt-3 text-xs leading-5 text-slate-600">สถานะคำร้องอ้างอิง Workflow ล่าสุด อนุมัติแล้วไม่ได้หมายถึงจัดรถหรือคนขับแล้ว ข้อมูลจัดรถแสดงแยกต่างหาก การตรวจรถว่างและเวลาซ้อนใช้กฎเดิม</p>
        <?php if ($error!==null): ?><p role="alert" class="mt-4 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-900"><?= e($error) ?></p><?php endif; ?>
        <?php if ($days!==[]): ?>
        <div class="mt-5 rounded-2xl border-2 border-up bg-white p-2 sm:p-4">
        <div class="grid grid-cols-7 gap-1 text-center text-xs font-bold text-slate-600 sm:gap-1.5" aria-hidden="true"><?php foreach (['อา','จ','อ','พ','พฤ','ศ','ส'] as $weekday): ?><span class="py-2"><?= e($weekday) ?></span><?php endforeach; ?></div>
        <div class="grid grid-cols-7 gap-1 sm:gap-1.5" aria-label="วันในปฏิทิน">
            <?php for ($i=0;$i<$offset;$i++): ?><span aria-hidden="true"></span><?php endfor; ?>
            <?php foreach ($days as $day): ?>
            <?php
                $isPast=$day['date']<$today;
                $description='';
                if($isPast)$description.=' · วันที่ผ่านมาแล้ว ไม่สามารถเลือกจองได้';
                $dayColor=$isPast ? 'cursor-not-allowed border-slate-300 bg-slate-200 text-slate-500' : 'border-slate-200 bg-white text-slate-700 hover:border-up-300';
                $selectedDay=!$isPast && $dates['start_date']!=='' && $dates['end_date']!=='' && $day['date']>=$dates['start_date'] && $day['date']<=$dates['end_date'];
                $params=['month'=>$month,'start_date'=>$day['date'],'end_date'=>$day['date'],'start_time'=>$dates['start_time'],'end_time'=>$dates['end_time']];
                if ($vehicleId!==null) $params['vehicle_id']=$vehicleId;
            ?>
            <a data-calendar-day="<?= e($day['date']) ?>" data-calendar-today="<?= e($today) ?>" <?php if (!$isPast): ?>href="<?= e('/bookings/calendar?'.http_build_query($params).'#availability-form') ?>"<?php else: ?>aria-disabled="true" tabindex="-1"<?php endif; ?> class="flex min-h-[72px] min-w-0 flex-col rounded-xl border p-1 text-left transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-up sm:min-h-[80px] sm:p-1.5 <?= $day['date']===$today ? 'ring-2 ring-up ring-offset-2 ' : '' ?><?= $selectedDay ? 'border-2 border-amber-500 ' : '' ?><?= e($dayColor) ?>" aria-current="<?= $day['date']===$today ? 'date' : 'false' ?>" aria-label="<?= e($day['date'].' '.$description.($selectedDay?' วันที่เลือก':'')) ?>">
                <strong class="text-xs leading-4 sm:text-sm"><?= e((string)(int)substr($day['date'],-2)) ?></strong>
                <?php foreach ($day['requests'] ?? [] as $request): ?>
                <?php [$label,$dot]=\App\Modules\Booking\OfficerBookingDisplay::status($request['workflow_status']); ?>
                <span data-calendar-request="<?= e((string)$request['booking_id']) ?>" class="mt-1 flex min-w-0 items-start gap-1 text-[11px] leading-4" style="overflow-wrap:anywhere"><span aria-hidden="true" class="mt-1 size-2 shrink-0 rounded-full <?= e($dot) ?>"></span><span><?= e($label) ?></span></span>
                <?php endforeach; ?>
                <?php if (($day['confirmed'] ?? 0)>0): ?><span class="mt-1 text-[11px] leading-4">จัดรถแล้ว <?= e((string)$day['confirmed']) ?> รายการ</span><?php endif; ?>
            </a>
            <?php endforeach; ?>
        </div>
        </div>
        <p class="mt-4 text-xs leading-5 text-slate-600">การเลือกวันและผลตรวจยังไม่ใช่การจองหรือการอนุมัติ ผลอาจเปลี่ยนเมื่อมีการจัดรถใหม่</p>
        <?php endif; ?>
    </div>
</section>

<section id="availability-form" class="mt-6 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-7">
    <h2 class="text-xl font-bold text-up">จองรถตู้</h2>
    <p class="mt-1 text-sm leading-6 text-slate-600">เลือกวันและเวลาเดินทาง เพื่อไปสู่แบบฟอร์มยื่นคำขอ</p>
    <form action="/bookings/travel-estimate" method="get" data-booking-form-link data-calendar-today="<?= e($today) ?>" data-booking-can-submit="<?= $canPrepareForm ? 'true' : 'false' ?>" class="mt-5">
        <?php if ($vehicleId!==null): ?><input type="hidden" name="vehicle_id" value="<?= e((string)$vehicleId) ?>"><?php endif; ?>
        <input type="hidden" name="start" value="" data-booking-start>
        <input type="hidden" name="end" value="" data-booking-end>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <label class="text-sm font-semibold">วันเริ่ม<div class="mt-2"><input type="text" name="start_date_display" value="<?= e($toDisplayDate($dates['start_date'])) ?>" placeholder="วว/ดด/ปปปป" inputmode="numeric" autocomplete="off" required pattern="\d{2}/\d{2}/\d{4}" data-date-display class="min-h-12 w-full rounded-xl border border-slate-300 bg-white p-3 text-base focus:border-up focus:outline-none focus:ring-2 focus:ring-up-200"><input type="hidden" name="start_date" value="<?= e($toIsoDate($dates['start_date'])) ?>" data-date-value><input type="date" min="<?= e($today) ?>" value="<?= e($toIsoDate($dates['start_date'])) ?>" data-date-picker tabindex="-1" aria-hidden="true" class="sr-only"></div></label>
        <label class="text-sm font-semibold">เวลาเริ่ม<div class="mt-2"><input type="text" name="start_time_display" value="<?= e($toTime($dates['start_time'])) ?>" placeholder="08:00" inputmode="numeric" autocomplete="off" required pattern="(?:[01]\d|2[0-3]):[0-5]\d" data-time-display class="min-h-12 w-full rounded-xl border border-slate-300 bg-white p-3 text-base focus:border-up focus:outline-none focus:ring-2 focus:ring-up-200"><input type="hidden" name="start_time" value="<?= e($toTime($dates['start_time'])) ?>" data-time-value><input type="time" value="<?= e($toTime($dates['start_time'])) ?>" data-time-picker tabindex="-1" aria-hidden="true" class="sr-only"></div></label>
        <label class="text-sm font-semibold">วันสิ้นสุด<div class="mt-2"><input type="text" name="end_date_display" value="<?= e($toDisplayDate($dates['end_date'])) ?>" placeholder="วว/ดด/ปปปป" inputmode="numeric" autocomplete="off" required pattern="\d{2}/\d{2}/\d{4}" data-date-display class="min-h-12 w-full rounded-xl border border-slate-300 bg-white p-3 text-base focus:border-up focus:outline-none focus:ring-2 focus:ring-up-200"><input type="hidden" name="end_date" value="<?= e($toIsoDate($dates['end_date'])) ?>" data-date-value><input type="date" min="<?= e(max($today,$toIsoDate($dates['start_date']))) ?>" value="<?= e($toIsoDate($dates['end_date'])) ?>" data-date-picker tabindex="-1" aria-hidden="true" class="sr-only"></div></label>
        <label class="text-sm font-semibold">เวลาสิ้นสุด<div class="mt-2"><input type="text" name="end_time_display" value="<?= e($toTime($dates['end_time'])) ?>" placeholder="17:00" inputmode="numeric" autocomplete="off" required pattern="(?:[01]\d|2[0-3]):[0-5]\d" data-time-display class="min-h-12 w-full rounded-xl border border-slate-300 bg-white p-3 text-base focus:border-up focus:outline-none focus:ring-2 focus:ring-up-200"><input type="hidden" name="end_time" value="<?= e($toTime($dates['end_time'])) ?>" data-time-value><input type="time" value="<?= e($toTime($dates['end_time'])) ?>" data-time-picker tabindex="-1" aria-hidden="true" class="sr-only"></div></label>
        </div>
        <p data-booking-selection-error role="alert" hidden class="mt-3 rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-900"></p>
        <p data-booking-advance-warning role="status" hidden class="mt-3 rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm leading-6 text-amber-950">ตามเงื่อนไขการขอใช้รถ ควรยื่นคำขอล่วงหน้าอย่างน้อย 5 วันทำการ หากเป็นกรณีเร่งด่วนหรือฉุกเฉิน สามารถยื่นคำขอต่อได้ โดยขึ้นอยู่กับการพิจารณาของเจ้าหน้าที่ <span class="block text-xs">คำเตือนเบื้องต้นนับเฉพาะวันจันทร์–ศุกร์ วันหยุดราชการและข้อยกเว้นยังรอยืนยัน</span></p>
        <button type="submit" data-booking-submit disabled class="mt-5 inline-flex h-[50px] min-h-[50px] w-full items-center justify-center gap-2 rounded-xl bg-up px-3 font-bold leading-tight text-sm text-white hover:bg-up-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-up sm:px-4 sm:text-base disabled:cursor-not-allowed disabled:bg-slate-400"><svg aria-hidden="true" width="20" height="20" viewBox="0 0 24 24" style="display:block;width:20px;height:20px;min-width:20px;min-height:20px;max-width:20px;max-height:20px;flex:0 0 20px;color:currentColor;fill:none;stroke:currentColor" class="shrink-0" stroke-width="1.8"><path d="M3 6.5 8.5 4l7 2.5L21 4v13.5L15.5 20l-7-2.5L3 20V6.5Z"/><path d="M8.5 4v13.5M15.5 6.5V20M12 10l2.5 2.5L12 15"/></svg><span class="min-w-0 text-center">ถัดไป: เลือกเส้นทางและคำนวณค่าเดินทาง</span></button>
        <p data-booking-disabled-reason role="status" class="mt-2 text-sm text-slate-600"></p>
    </form>
    <p class="mt-3 text-xs leading-5 text-slate-600">กรุณาเลือกวันและเวลาที่ถูกต้อง ข้อมูลจะถูกนำไปแสดงในแบบฟอร์มโดยยังไม่ส่งคำขอ</p>

</section>
