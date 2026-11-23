<?php declare(strict_types=1);
$fuelReference=\App\Modules\Booking\FuelEconomyReferenceCatalog::forFuelType((string)($selectedVehicle['fuel_type']??''));
?>
<section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-8" id="travel-estimate" data-fuel-reference="<?= e(json_encode($fuelReference,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)) ?>" data-origin-lat="19.031572" data-origin-lng="99.893832" data-origin-label="<?= e((string)$originLabel) ?>" data-fuel-type="<?= e((string)($selectedVehicle['fuel_type'] ?? '')) ?>">
    <p class="text-sm font-bold text-up">เครื่องมือภายในสำหรับผู้ดูแลระบบ</p><h1 class="mt-2 text-2xl font-black text-up">แผนที่และคำนวณค่าเดินทาง</h1>
    <p class="mt-2 text-sm text-slate-700">ใช้ข้อมูลคำนวณเป็นข้อมูลอ้างอิงภายในสำหรับตรวจสอบการเดินทาง</p>
    <?php if ($travelError!==null): ?><p role="alert" class="mt-4 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-900"><?= e($travelError) ?></p><?php endif; ?>
    <?php if ($selectedVehicle!==null && $selection!==null): ?><p class="mt-4 rounded-xl bg-up-50 p-4 text-sm text-up">รถที่ขอใช้: <?= e((string)$selectedVehicle['label']) ?> · <?= e((string)$selectedVehicle['registration_number']) ?><?= !empty($selectedVehicle['registration_province']) ? ' · '.e((string)$selectedVehicle['registration_province']) : '' ?> · <?= e($selection->startLocal) ?> ถึง <?= e($selection->endLocal) ?></p><?php endif; ?>
    <div class="mt-6 grid gap-4 sm:grid-cols-2" aria-label="เลือกต้นทางและปลายทาง">
        <div class="travel-location-card rounded-xl border border-slate-200 p-4">
            <label for="travel-origin-search" class="block text-sm font-bold text-slate-700">ต้นทาง</label>
            <input id="travel-origin-search" type="search" maxlength="160" autocomplete="off" aria-label="ค้นหาต้นทาง" aria-controls="travel-origin-results" class="mt-2 min-h-12 w-full rounded-xl border border-slate-300 bg-white p-3 text-sm focus:border-up focus:ring-2 focus:ring-up-200" placeholder="ค้นหาต้นทาง" value="<?= e((string)$originLabel) ?>">
            <div id="travel-origin-results" class="travel-search-results" role="listbox" aria-label="ผลค้นหาต้นทาง" aria-live="polite" hidden></div>
            <p id="travel-origin-location" class="mt-2 break-words text-xs text-slate-600" aria-live="polite">พิกัด 19.031572, 99.893832</p>
        </div>
        <div class="travel-location-card rounded-xl border border-slate-200 p-4">
            <label for="travel-destination-search" class="block text-sm font-bold text-slate-700">ปลายทาง</label>
            <input id="travel-destination-search" type="search" maxlength="500" autocomplete="off" aria-label="ค้นหาปลายทาง" aria-controls="travel-destination-results" class="mt-2 min-h-12 w-full rounded-xl border border-slate-300 bg-white p-3 text-sm focus:border-up focus:ring-2 focus:ring-up-200" placeholder="ค้นหาปลายทาง">
            <div id="travel-destination-results" class="travel-search-results" role="listbox" aria-label="ผลค้นหาปลายทาง" aria-live="polite" hidden></div>
            <p id="travel-destination-location" class="mt-2 break-words text-xs text-slate-600" aria-live="polite">ยังไม่ได้เลือกปลายทาง</p>
        </div>
    </div>
    <div class="mt-4 flex flex-wrap items-end gap-3">
        <p class="min-w-0 text-sm text-slate-600" id="travel-map-instructions">คอมพิวเตอร์: คลิกซ้ายเลือกต้นทาง · คลิกขวาเลือกปลายทาง<br>จอสัมผัส: แตะเลือกต้นทาง · กดค้างเลือกปลายทาง</p>
        <div class="ml-auto flex shrink-0 items-center gap-3 text-xs text-slate-600" aria-label="คำอธิบายสีหมุด">
            <span class="whitespace-nowrap"><i class="travel-marker-swatch travel-marker-swatch-origin" aria-hidden="true"></i>ต้นทาง</span>
            <span class="whitespace-nowrap"><i class="travel-marker-swatch travel-marker-swatch-destination" aria-hidden="true"></i>ปลายทาง</span>
        </div>
    </div>
    <div id="travel-map" class="travel-map mt-5 w-full max-w-full overflow-hidden rounded-2xl border border-up-200 bg-up-50" role="region" aria-label="แผนที่ OpenStreetMap สำหรับเลือกต้นทางหรือปลายทาง" tabindex="0" aria-describedby="travel-map-instructions"></div>
    <p id="travel-map-status" role="status" aria-live="polite" class="sr-only">กำลังโหลดแผนที่ OpenStreetMap…</p>
    <section class="travel-summary mt-6 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6" aria-labelledby="travel-summary-title">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div><p class="text-xs font-bold uppercase tracking-wide text-up">สรุปเส้นทางและเชื้อเพลิง</p><h2 id="travel-summary-title" class="mt-1 text-xl font-black text-up">คำนวณค่าเดินทาง</h2></div>
            <span class="rounded-full bg-up-50 px-3 py-1 text-xs font-semibold text-up">อิงเส้นทางถนน OSRM</span>
        </div>
        <p id="travel-route-status" role="status" aria-live="polite" hidden class="mt-3 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm leading-relaxed text-amber-950"></p>
        <section class="travel-cost-hero mt-4 rounded-2xl bg-up p-5 text-white sm:p-6" aria-label="ค่าใช้จ่ายน้ำมันโดยประมาณ">
            <p class="text-sm font-semibold text-white/85">ค่าน้ำมันโดยประมาณ</p>
            <p data-travel-value="fuelCost" class="mt-1 break-words text-3xl font-black leading-tight sm:text-4xl">ยังไม่มีข้อมูลที่ตรวจสอบได้</p>
            <p class="mt-2 text-sm text-white/90">ช่วงประมาณการ <span data-travel-value="costRange" class="font-bold">ยังไม่มีข้อมูลที่ตรวจสอบได้</span></p>
            <p id="travel-fuel-estimate-status" role="status" aria-live="polite" class="mt-3 rounded-xl bg-white/10 px-3 py-2 text-sm text-white">รอเส้นทาง ราคาน้ำมัน และอัตราอ้างอิงที่ตรวจสอบได้ก่อนคำนวณ</p>
        </section>
        <section class="mt-5" aria-labelledby="travel-distance-title">
            <h3 id="travel-distance-title" class="mb-2 text-sm font-bold text-slate-800">ระยะทาง</h3>
            <dl class="travel-distance-grid grid gap-3 sm:grid-cols-3">
                <?php foreach (['outbound'=>'ขาไป','returning'=>'ขากลับ','roundtrip'=>'รวมไป–กลับ'] as $key=>$label): ?><div class="travel-metric-card rounded-xl border border-slate-200 bg-slate-50 p-3 sm:p-4"><dt class="text-sm font-semibold text-slate-600"><?= e($label) ?></dt><dd data-travel-value="<?= e($key) ?>" class="mt-1 break-words text-xl font-bold text-up">ยังไม่มีข้อมูลที่ตรวจสอบได้</dd></div><?php endforeach; ?>
            </dl>
        </section>
        <section class="mt-5" aria-labelledby="travel-duration-title">
            <h3 id="travel-duration-title" class="mb-2 text-sm font-bold text-slate-800">เวลาเดินทางโดยประมาณ</h3>
            <dl class="travel-duration-grid grid gap-3 sm:grid-cols-3">
                <div class="travel-total-duration rounded-xl border border-up-200 bg-up-50 p-3 sm:p-4"><dt class="text-sm font-semibold text-up">รวมไป–กลับ</dt><dd data-travel-value="totalDuration" class="mt-1 break-words text-2xl font-black text-up">ยังไม่มีข้อมูลที่ตรวจสอบได้</dd></div>
                <?php foreach (['duration'=>'ขาไป','returnDuration'=>'ขากลับ'] as $key=>$label): ?><div class="rounded-xl border border-slate-200 bg-white p-3 sm:p-4"><dt class="text-sm font-semibold text-slate-600"><?= e($label) ?></dt><dd data-travel-value="<?= e($key) ?>" class="mt-1 break-words text-lg font-semibold text-slate-700">ยังไม่มีข้อมูลที่ตรวจสอบได้</dd></div><?php endforeach; ?>
            </dl>
            <p class="mt-2 text-xs text-slate-500">เวลาไม่รวมข้อมูลจราจรสดและจุดแวะ</p>
        </section>
        <section class="mt-5" aria-labelledby="travel-inputs-title">
            <h3 id="travel-inputs-title" class="mb-2 text-sm font-bold text-slate-800">ข้อมูลประกอบการคำนวณ</h3>
            <dl class="travel-input-grid grid items-start gap-3 sm:grid-cols-2">
                <?php foreach (['fuelType'=>'ชนิดน้ำมัน','efficiency'=>'อัตราสิ้นเปลืองอ้างอิง','litres'=>'ปริมาณน้ำมันโดยประมาณ','fuelPrice'=>'ราคาน้ำมันต่อลิตร'] as $key=>$label): ?><div class="travel-input-card rounded-xl border border-slate-200 bg-white p-3"><dt class="text-xs font-semibold text-slate-500"><?= e($label) ?></dt><dd data-travel-value="<?= e($key) ?>" class="mt-1 break-words text-sm font-semibold text-slate-800">ยังไม่มีข้อมูลที่ตรวจสอบได้</dd></div><?php endforeach; ?>
            </dl>
        </section>
        <details class="travel-source-panel mt-5 rounded-xl border border-slate-200 bg-slate-50 p-4">
            <summary class="travel-source-summary flex min-h-11 w-full cursor-pointer list-none items-center justify-between gap-3 rounded-lg text-left font-bold text-up focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-up [&::-webkit-details-marker]:hidden" aria-expanded="false">
                <span>แหล่งอ้างอิงและข้อควรทราบ</span>
                <svg class="travel-source-chevron h-5 w-5 shrink-0 text-up" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="m5 7.5 5 5 5-5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </summary>
            <div class="travel-source-content pt-3">
                <?php if ($fuelReference!==null): ?>
                    <p class="text-sm text-slate-700"><a class="font-semibold text-up underline underline-offset-2" href="<?= e($fuelReference['source_url']) ?>#page=232" target="_blank" rel="noopener noreferrer">สนข. รายงานตุลาคม 2566 ตาราง 4.2.4-3 หน้า 4-49–4-50</a> · หน่วย กม./ลิตร</p>
                    <p class="mt-2 text-sm leading-relaxed text-slate-700"><?= e($fuelReference['method']) ?></p><p class="mt-2 text-sm leading-relaxed text-slate-600"><?= e($fuelReference['limitations']) ?></p><p class="mt-2 text-sm leading-relaxed text-slate-600"><?= e($fuelReference['note']) ?></p>
                <?php else: ?><p class="text-sm text-slate-700">ไม่มีอัตราสิ้นเปลืองอ้างอิงที่ยืนยันว่าเหมาะกับชนิดน้ำมันนี้ จึงไม่คำนวณปริมาณน้ำมันหรือค่าใช้จ่าย</p><?php endif; ?>
                <p data-travel-price-note class="mt-3 text-sm text-slate-700">ราคาน้ำมันอ้างอิงกรุงเทพฯ อาจต่างจากพื้นที่อื่น</p>
                <p class="mt-3 text-sm leading-relaxed text-slate-600">ค่าใช้จ่ายน้ำมันเป็นเพียงการประมาณการจากระยะทางบนแผนที่และอัตราสิ้นเปลืองอ้างอิงของรถตู้บางกลุ่ม ค่าใช้จ่ายจริงอาจแตกต่างจากที่แสดง เนื่องจากการเปลี่ยนเส้นทาง การแวะจุดอื่น ความเร็วในการขับขี่ สภาพการจราจร น้ำหนักบรรทุก สภาพเครื่องยนต์ และการใช้เครื่องปรับอากาศ</p>
            </div>
        </details>
        <dl class="sr-only">
            <?php foreach (['vehicle'=>'รถตู้ที่เลือก','origin'=>'ต้นทาง','destination'=>'ปลายทาง'] as $key=>$label): ?><div><dt><?= e($label) ?></dt><dd data-travel-value="<?= e($key) ?>"><?= $key==='vehicle' && $selectedVehicle!==null ? e((string)$selectedVehicle['label']) : ($key==='origin' ? e($originLabel) : 'ยังไม่มีข้อมูลที่ตรวจสอบได้') ?></dd></div><?php endforeach; ?>
        </dl>
    </section>
    <form action="/form-preview" method="get" class="mt-6" id="travel-next-form" data-can-open-form="<?= !empty($canOpenBookingForm) ? 'true' : 'false' ?>">
        <?php if ($selectedVehicle!==null && $selection!==null): ?>
            <input type="hidden" name="vehicle_id" value="<?= e((string)$selectedVehicle['id']) ?>">
            <input type="hidden" name="vehicle_label" value="<?= e((string)$selectedVehicle['label']) ?>">
            <input type="hidden" name="vehicle_registration" value="<?= e((string)$selectedVehicle['registration_number']) ?>">
            <input type="hidden" name="vehicle_province" value="<?= e((string)($selectedVehicle['registration_province'] ?? '')) ?>">
            <input type="hidden" name="start" value="<?= e($selection->startLocal) ?>"><input type="hidden" name="end" value="<?= e($selection->endLocal) ?>">
        <?php endif; ?>
        <input type="hidden" id="travel-origin" name="origin" value="<?= e($originLabel) ?>"><input type="hidden" id="travel-destination" name="destination" value=""><input type="hidden" id="travel-destination-source" name="destination_source" value="">
        <?php foreach (['origin_lat','origin_lng','destination_lat','destination_lng','route_outbound_m','route_return_m','route_duration_ms','route_return_duration_ms','route_source','fuel_type','fuel_efficiency_reference_km_per_l','fuel_price_baht_per_l','fuel_price_date','fuel_litres','fuel_cost_baht'] as $field): ?><input type="hidden" name="<?= e($field) ?>" id="travel-<?= e(str_replace('_','-',$field)) ?>"><?php endforeach; ?>
        <input type="hidden" name="fuel_price_source" id="travel-fuel-price-source" value="bangchak_oil_price_api">
        <p class="mt-2 text-xs text-slate-600">ผลเส้นทางและค่าใช้จ่ายเป็นประมาณการ หน้าแบบฟอร์มยังไม่บันทึกคำขอ และ Backend ต้องตรวจข้อมูลใหม่เมื่อเปิด Booking POST</p>
        <?php if (empty($canPrepareBooking) && !empty($canOpenBookingForm)): ?><p class="mt-3 text-sm text-slate-600">โหมดตัวอย่างสำหรับผู้ดูแลระบบ · เปิดดูและกรอกแบบฟอร์มได้ แต่ไม่สามารถยื่นคำขอ</p><?php endif; ?>
        <button type="submit" disabled class="mt-5 min-h-12 w-full rounded-xl bg-up px-5 py-3 font-bold text-white disabled:bg-slate-400">ดำเนินการกรอกแบบฟอร์มจองรถตู้</button>
    </form>
</section>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="" referrerpolicy="strict-origin-when-cross-origin">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin="" defer<?= isset($_SERVER['TRAVEL_MAP_NONCE']) ? ' nonce="'.e($_SERVER['TRAVEL_MAP_NONCE']).'"' : '' ?>></script>
<script src="/assets/travel-estimate.js" defer<?= isset($_SERVER['TRAVEL_MAP_NONCE']) ? ' nonce="'.e($_SERVER['TRAVEL_MAP_NONCE']).'"' : '' ?>></script>
