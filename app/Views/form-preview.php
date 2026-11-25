<?php
declare(strict_types=1);
$documentMode=$documentMode ?? false;
$officerEditMode=!empty($officerEditMode) && !$documentMode;
$adminDraftMode=!empty($adminDraftMode) && !$documentMode;
$directorDecisionMode=!empty($directorDecisionMode) && !$documentMode;
$workflowMode=$documentMode || $officerEditMode || $adminDraftMode || $directorDecisionMode;
$approvalForm=$adminDraftMode ? 'booking-forward' : (($directorDecisionMode) ? 'booking-director-decision' : null);
$approvalFormAttribute=$approvalForm === null ? '' : 'form="'.e($approvalForm).'"';
$exampleReadOnly=!empty($exampleReadOnly) && !$documentMode;
$formPermissions=$formPermissions ?? [];
$transferContext=$transferContext ?? null;
$canEditRequestFields=!empty($formPermissions['requestFields']) && !$documentMode;
$canEditVehicle=!empty($formPermissions['vehicleFields']) && !$documentMode;
$canEditSection3=!empty($formPermissions['section3']) && !$documentMode;
$canSubmit=!empty($formPermissions['canSubmit']) && !$documentMode && !$exampleReadOnly;
$canOfficerEdit=$officerEditMode;
$example=$example ?? '';
// Local rendering functions; no business logic or database access in the view.
$field = static function (string $name, string $label, string $width = '', string $inputmode = 'text', ?bool $editable = null, bool $required = false, string $extraAttribute = '', bool $multiline = false) use ($formData,$documentMode,$exampleReadOnly,$canEditRequestFields,$officerEditMode,$transferContext): void {
    $value = (string) ($formData[$name] ?? '');
    $editable = $editable ?? $canEditRequestFields;
    if($name==='vehicle_registration'&&$transferContext!==null && !$officerEditMode)$editable=false;
    ?>
    <span class="field <?= e($width) ?><?= $multiline ? ' destination-field' : '' ?>"><?php if ($multiline): ?><textarea name="<?= e($name) ?>" id="<?= e($name) ?>" aria-label="<?= e($label) ?>" rows="1" wrap="off" autocomplete="off" spellcheck="false" <?= (!$editable || $documentMode || $exampleReadOnly) ? 'readonly' : '' ?> <?= $extraAttribute ?>><?= e($value) ?></textarea><?php else: ?><input type="text" name="<?= e($name) ?>" id="<?= e($name) ?>" aria-label="<?= e($label) ?>" inputmode="<?= e($inputmode) ?>" value="<?= e($value) ?>" autocomplete="off" spellcheck="false" <?= (!$editable || $documentMode || $exampleReadOnly) ? 'readonly' : '' ?> <?= $required && $editable && !$documentMode && !$exampleReadOnly ? 'required aria-required="true"' : '' ?> <?= $extraAttribute ?>><?php endif; ?><span class="print-value" aria-hidden="true"><?= e($value) ?></span></span>
    <?php
};
$output = static function (string $name, string $label, string $width = '') use ($formData): void {
    ?><span class="document-output <?= e($width) ?>" data-output="<?= e($name) ?>" aria-label="<?= e($label) ?>"><?= e((string) ($formData[$name] ?? '')) ?></span><?php
};
$previewField = static function (string $name, string $label, string $width = '', string $inputmode = 'text') use ($field,$output,$documentMode,$workflowMode,$canEditRequestFields): void {
    if (!$documentMode) $field($name,$label,$width,$inputmode,!$workflowMode || $canEditRequestFields); else $output($name,$label,$width);
};
$vehicleField = static function (string $name, string $label, string $width = '', string $inputmode = 'text') use ($field,$output,$canEditVehicle,$documentMode,$workflowMode): void {
    if (!$documentMode && (!$workflowMode || $canEditVehicle)) $field($name,$label,$width,$inputmode,true); else $output($name,$label,$width);
};
$approvalField = static function (string $name, string $label, string $width = '', bool $required = false, string $extraAttribute = '') use ($field,$output,$canEditSection3,$documentMode,$approvalFormAttribute): void {
    if (!$documentMode) $field($name,$label,$width,'text',$canEditSection3,$required,trim($extraAttribute.' '.$approvalFormAttribute)); else $output($name,$label,$width);
};
$dateFields = static function (string $prefix, string $label) use ($field): void {
    ?>วันที่<?php $field($prefix . '_day', $label . ' วันที่', 'day', 'numeric'); ?>เดือน<?php $field($prefix . '_month', $label . ' เดือน', 'month'); ?>พ.ศ.<?php $field($prefix . '_year', $label . ' พ.ศ.', 'year', 'numeric'); ?><?php
};
?>
<!doctype html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $workflowMode ? 'ใบขออนุญาตใช้รถตู้' : 'ต้นแบบใบขออนุญาตใช้รถตู้' ?> | UP Van Booking</title>
    <link rel="icon" href="/assets/form-preview-icon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="/assets/form-preview.css?v=publish-decision-1">
    <link rel="stylesheet" href="/assets/sweetalert2.min.css">
    <script src="/assets/form-preview.js?v=publish-decision-1" defer></script>
    <script src="/assets/sweetalert2.min.js" defer></script>
</head>
<body data-form-state="<?= $exampleReadOnly ? 'sample-readonly' : 'editable' ?>">
<?php if ($workflowMode): ?>
<nav class="preview-tools" aria-label="เครื่องมือเอกสาร"><a href="<?= e($documentBack ?? '/dashboard') ?>">กลับหน้าระบบ</a><button type="button" id="print-document">พิมพ์ A4</button></nav>
<?php if ($officerEditMode): ?><p class="prototype-notice" role="status">แก้ไขข้อมูลคำร้องได้ตามสิทธิ์</p><?php else: ?>
<p class="prototype-notice">เอกสารจากข้อมูลคำขอ · ช่องลายมือชื่อเป็นชื่อพิมพ์ ไม่ใช่ลายเซ็นอิเล็กทรอนิกส์</p>
<?php endif; ?>
<?php if ($adminDraftMode): ?><p class="prototype-notice">ส่วนที่ 3 สำหรับผู้ดูแลระบบเป็นฉบับร่าง ผลจริงบันทึกโดยผู้อำนวยการหรือผู้รับมอบอำนาจ</p><?php endif; ?>
<?php if (isset($officerRequestMeta['schedule_reserved']) && !$officerRequestMeta['schedule_reserved']): ?><p class="prototype-notice" role="status">ยังไม่ยืนยันการจอง · ยังไม่กันช่วงเวลารถ</p><?php endif; ?>
<?php if (!empty($officerRequestMeta)): ?>
<?php
$display = \App\Modules\Booking\OfficerBookingDisplay::class;
[$statusLabel] = $display::status((string)$officerRequestMeta['status']);
$vehicleLabel = trim(implode(' ', [
    $officerRequestMeta['vehicle_label'] ?? '',
    $officerRequestMeta['vehicle_registration'] ?? '',
    $officerRequestMeta['vehicle_province'] ?? '',
]));
?>
<section class="officer-request-meta" aria-label="ข้อมูลคำร้องที่เจ้าหน้าที่รับ">
    <strong>เลขคำร้องระบบ <?= e((string)$officerRequestMeta['request_number']) ?></strong>
    <span>สถานะ: <?= e($statusLabel) ?></span>
    <span>ส่งเมื่อ: <?= e($display::date($officerRequestMeta['submitted_at'] ?? null)) ?></span>
    <span>รถที่ขอ: <?= e($vehicleLabel !== '' ? $vehicleLabel : 'ยังไม่ระบุรถ') ?></span>
    <span>ออกเดินทาง: <?= e($display::date($officerRequestMeta['requested_start_at'] ?? null)) ?></span>
    <span>เดินทางกลับ: <?= e($display::date($officerRequestMeta['requested_end_at'] ?? null)) ?></span>
    <span>จุดหมาย: <?= e(($officerRequestMeta['destination'] ?? '') ?: '—') ?></span>
</section>
<?php endif; ?>
<?php else: ?>
<nav class="preview-tools" aria-label="เครื่องมือต้นแบบ">
    <a href="/">หน้าเริ่มต้น</a>
    <details><summary>ตัวอย่างสำหรับตรวจแบบฟอร์ม</summary>
    <a href="/form-preview" <?= $example === 'blank' ? 'aria-current="page"' : '' ?>>เอกสารว่าง</a>
    <a href="/form-preview?example=completed" <?= $example === 'completed' ? 'aria-current="page"' : '' ?>>ตัวอย่างคำขอ</a>
    </details>
    <button type="button" id="print-document">พิมพ์ A4</button>
    <?php if (!empty($formPermissions['previewNotice'])): ?><span class="preview-mode-note" role="status"><?= e((string)$formPermissions['previewNotice']) ?></span><?php endif; ?>
</nav>
<?php if (($transferError ?? null)!==null): ?>
<p class="prototype-notice" role="alert"><?= e($transferError) ?> · ไม่มีการบันทึกหรือส่งคำขอ</p>
<?php endif; ?>
<?php endif; ?>
<p id="print-warning" class="print-warning" role="alert" hidden>ข้อความบางช่องยาวเกินพื้นที่ ระบบย่อได้ต่ำสุด 12.5px หากยังไม่พอดี โปรดปรับข้อความให้กระชับก่อนพิมพ์ ระบบจะไม่ตัดข้อมูลที่กรอกทิ้ง</p>
<?php if ($canOfficerEdit): ?>
<form id="booking-forward" data-required-fields="<?= e(implode(' ', \App\Modules\Booking\BookingSubmissionInput::FORWARD_REQUIRED_FIELDS)) ?>" novalidate action="/officer/booking-requests/forward" method="post" data-workflow-action data-success="ส่งคำร้องให้ผู้อำนวยการแล้ว" data-next="/officer/booking-requests">
<input type="hidden" name="_token" value="<?= e($csrfToken ?? '') ?>">
<input type="hidden" name="booking_id" value="<?= e((string)($bookingId ?? '')) ?>">
<input type="hidden" name="booking_version" value="<?= e((string)($bookingVersion ?? '')) ?>">
<?php elseif ($canSubmit): ?>
<form id="booking-submit-form" action="/bookings/submit" method="post" data-submission-enabled="<?= !empty($submissionGateOpen) ? 'true' : 'false' ?>">
<input type="hidden" name="_token" value="<?= e($csrfToken ?? '') ?>">
<input type="hidden" name="submission_key" value="<?= e($submissionKey ?? '') ?>">
<input type="hidden" name="vehicle_id" value="<?= e((string)($transferContext['vehicle_id'] ?? '')) ?>">
<?php endif; ?>
<main class="paper-viewport" tabindex="0" aria-label="แบบฟอร์ม A4 เลื่อนแนวนอนเพื่อดูเอกสาร">
<article class="paper" id="document" aria-labelledby="document-title">
    <header class="document-header">
        <h1 id="document-title">ใบขออนุญาตใช้รถตู้ของสถาบันนวัตกรรมการเรียนรู้ มหาวิทยาลัยพะเยา</h1>
        <div class="document-number"><span class="document-number-label">เลขที่</span><?php $previewField('document_number_prefix', 'เลขที่เอกสารก่อนเครื่องหมายทับ', 'document-number-input'); ?><span class="document-number-divider" aria-hidden="true">/</span><?php $previewField('request_number_suffix', 'เลขที่เอกสารหลังเครื่องหมายทับ', 'document-number-input'); ?></div>
        <div class="travel-options" role="radiogroup" aria-label="ประเภทการเดินทาง">
            <label><input class="square" type="radio" name="travel_type" value="within_province" <?= ($formData['travel_type'] ?? '') === 'within_province' ? 'checked' : '' ?> <?= (!$canEditRequestFields || $documentMode || $exampleReadOnly) ? 'disabled' : '' ?>>ไปปฏิบัติงานภายในจังหวัดพะเยา</label>
            <label><input class="square" type="radio" name="travel_type" value="outside_province" <?= ($formData['travel_type'] ?? '') === 'outside_province' ? 'checked' : '' ?> <?= (!$canEditRequestFields || $documentMode || $exampleReadOnly) ? 'disabled' : '' ?>>ไปปฏิบัติงานต่างจังหวัด</label>
        </div>
    </header>
    <div class="main-table">
        <section class="requester-section" aria-labelledby="section-one">
            <h2 id="section-one">ส่วนที่ 1</h2>
            <div class="line application-date"><?php $dateFields('application', 'วันที่ยื่นคำขอ'); ?></div>
            <p class="recipient">เรียน&nbsp; ผู้อำนวยการสถาบันนวัตกรรมการเรียนรู้</p>
            <div class="line indent">ด้วย<?php $field('requester_name', 'ชื่อผู้ขอใช้รถ'); ?></div>
            <div class="line">ตำแหน่ง<?php $field('requester_position', 'ตำแหน่ง'); ?>สังกัด<?php $field('requester_affiliation', 'สังกัด'); ?></div>
            <div class="line"><?php $field('requester_affiliation_continued', 'สังกัด ต่อบรรทัด'); ?>มีความประสงค์ขออนุญาต</div>
            <div class="line destination-line">ใช้รถตู้ ไป<?php $field('destination', 'สถานที่เดินทาง', '', 'text', null, false, 'data-document-destination', true); ?></div>
            <div class="line">เพื่อ<?php $field('purpose', 'วัตถุประสงค์'); ?></div>
            <div class="line">มีคนนั่ง<?php $field('passenger_count', 'จำนวนผู้เดินทาง', 'count', 'numeric'); ?>คน</div>
            <div class="line">ในวันที่<?php $field('departure_date', 'วันที่ออกเดินทาง'); ?>เวลา<?php $field('departure_time', 'เวลาออกเดินทาง', 'time'); ?>น.</div>
            <div class="line">ถึงวันที่<?php $field('return_date', 'วันที่กลับ'); ?>เวลา<?php $field('return_time', 'เวลากลับ', 'time'); ?>น.</div>
            <fieldset class="expenses">
                <legend>ค่าใช้จ่าย ค่าเบี้ยเลี้ยงและค่าน้ำมันเชื้อเพลิง</legend>
                <label class="expense-line"><input class="circle" type="radio" name="expense_source" value="institute_budget" <?= ($formData['expense_source'] ?? '') === 'institute_budget' ? 'checked' : '' ?> <?= (!$canEditRequestFields || $documentMode || $exampleReadOnly) ? 'disabled' : '' ?>>เบิกจากเงินงบประมาณของสถาบันนวัตกรรมการเรียนรู้</label>
                <div class="expense-line"><label><input class="circle" type="radio" name="expense_source" value="project_budget" <?= ($formData['expense_source'] ?? '') === 'project_budget' ? 'checked' : '' ?> <?= (!$canEditRequestFields || $documentMode || $exampleReadOnly) ? 'disabled' : '' ?>>เบิกจากเงินงบประมาณโครงการ:</label><?php $field('project_expense_details', 'รายละเอียดงบประมาณโครงการ'); ?></div>
                <label class="expense-line"><input class="circle" type="radio" name="expense_source" value="other_expenses" <?= ($formData['expense_source'] ?? '') === 'other_expenses' ? 'checked' : '' ?> <?= (!$canEditRequestFields || $documentMode || $exampleReadOnly) ? 'disabled' : '' ?>>เบิกจากค่าใช้จ่ายอื่นๆ</label>
                <label class="expense-line"><input class="circle" type="radio" name="expense_source" value="driver_extra_pay" <?= ($formData['expense_source'] ?? '') === 'driver_extra_pay' ? 'checked' : '' ?> <?= (!$canEditRequestFields || $documentMode || $exampleReadOnly) ? 'disabled' : '' ?>>เงินเพิ่มพิเศษพนักงานขับรถ</label>
            </fieldset>
            <div class="line"><?php $field('expense_source_details', 'รายละเอียดค่าใช้จ่ายเพิ่มเติม'); ?></div>
            <div class="requester-signature">
                <div class="line indent">ลงชื่อ<?php $field('requester_signature', 'ช่องลงชื่อผู้ขอ (ชื่อพิมพ์ต้นแบบ ไม่ใช่ลายเซ็นอิเล็กทรอนิกส์)'); ?>ผู้ขออนุญาตใช้รถ</div>
                <div class="line name-line">(<?php $field('requester_printed_name', 'ชื่อผู้ขอในวงเล็บ'); ?>)</div>
            </div>
            <div class="line requester-phone">เบอร์โทร<?php $field('requester_phone', 'เบอร์โทรศัพท์ผู้ขอ', '', 'tel'); ?></div>
        </section>
        <div class="officer-approval-column">
            <section class="officer-section" aria-labelledby="section-two">
                <h2 id="section-two">ส่วนที่ 2</h2>
                <p class="line">เรียน ผู้อำนวยการสถาบันนวัตกรรมการเรียนรู้</p>
                <p class="officer-copy">งานยานพาหนะได้ตรวจสอบและดำเนินการจัดรถตู้</p>
                <div class="line indent">หมายเลขทะเบียน<?php $vehicleField('vehicle_registration', 'ทะเบียนรถที่จัดสรร'); ?></div>
                <div class="line indent">พนักงานขับรถ<?php $vehicleField('driver_name', 'พนักงานขับรถที่จัดสรร'); ?></div>
                <div class="officer-signature">
                    <div class="line">ลงชื่อ<?php $vehicleField('officer_signature', 'ลายมือชื่อเจ้าหน้าที่'); ?></div>
                    <div class="line">(<?php $vehicleField('officer_printed_name', 'ชื่อเจ้าหน้าที่'); ?>)</div>
                    <p>เจ้าหน้าที่ผู้ได้รับมอบหมายงานยานพาหนะ</p>
                    <div class="line signature-date"><?php $vehicleField('officer_day', 'วันที่เจ้าหน้าที่', 'day', 'numeric'); ?>/<?php $vehicleField('officer_month', 'เดือนเจ้าหน้าที่', 'day'); ?>/<?php $vehicleField('officer_year', 'ปีเจ้าหน้าที่', 'year', 'numeric'); ?></div>
                </div>
            </section>
            <section class="approval-section<?= $approvalForm !== null ? ' section3-uncommitted' : '' ?>" aria-labelledby="section-three">
                <h2 id="section-three">ส่วนที่ 3</h2>
                <?php if (!empty($ownerAwaitingResult)): ?><p class="owner-pending-result">อยู่ระหว่างดำเนินการ</p><?php endif; ?>
                <div role="radiogroup" aria-labelledby="section-three">
                <div class="decision-line">
                    <input class="circle" id="approval-outcome-approved" type="radio" name="approval_outcome" <?= $approvalFormAttribute ?> value="approved" aria-label="อนุญาต" <?= ($formData['approval_outcome'] ?? '') === 'approved' ? 'checked' : '' ?> <?= (!$canEditSection3 || $documentMode || $exampleReadOnly) ? 'disabled' : '' ?>><label for="approval-outcome-approved">อนุญาต</label>
                </div>
                <div class="decision-line">
                    <input class="circle" id="approval-outcome-rejected" type="radio" name="approval_outcome" <?= $approvalFormAttribute ?> value="rejected" aria-label="ไม่อนุญาต" data-approval-decision="rejected" <?= ($formData['approval_outcome'] ?? '') === 'rejected' ? 'checked' : '' ?> <?= (!$canEditSection3 || $documentMode || $exampleReadOnly) ? 'disabled' : '' ?>><label for="approval-outcome-rejected">ไม่สามารถจัดรถให้ได้ เนื่องจาก</label><?php $approvalField('rejection_reason', 'เหตุผลไม่สามารถจัดรถให้ได้', '', !$directorDecisionMode && ($formData['approval_outcome'] ?? '') === 'rejected', 'data-approval-reason'); ?>
                </div>
                </div>
                <div class="line reason-continuation"><?php $approvalField('rejection_reason_continued', 'เหตุผลต่อบรรทัด'); ?></div>
                <div class="approval-signature">
                    <div class="line">(<?php $approvalField('approver_printed_name', 'ชื่อผู้พิจารณา'); ?>)</div>
                    <div class="line"><?php $approvalField('approver_position', 'ตำแหน่งผู้พิจารณา'); ?></div>
                    <div class="line signature-date"><?php $approvalField('decision_day', 'วันที่พิจารณา', 'day'); ?>/<?php $approvalField('decision_month', 'เดือนพิจารณา', 'day'); ?>/<?php $approvalField('decision_year', 'ปีพิจารณา', 'year'); ?></div>
                </div>
            </section>
        </div>
    </div>
    <section class="driver-section" aria-labelledby="driver-heading">
        <h2 id="driver-heading">สำหรับพนักงานขับรถ</h2>
        <div class="driver-table">
            <div class="driver-acknowledgement">
                <p>รับทราบและปฏิบัติ</p>
                <div class="line driver-signature">ลงชื่อ<?php $previewField('driver_signature', 'ลายมือชื่อรับทราบงาน'); ?>พนักงานขับรถ</div>
                <div class="line name-line">(<?php $previewField('acknowledging_driver_name', 'ชื่อคนขับที่รับทราบงาน'); ?>)</div>
            </div>
            <div class="driver-return">
                <p>รถยนต์กลับถึงมหาวิทยาลัยพะเยา</p>
                <div class="line return-date">วันที่<?php $previewField('actual_return_day', 'วันที่กลับจริง', 'day', 'numeric'); ?>เดือน<?php $previewField('actual_return_month', 'เดือนกลับจริง', 'month'); ?>พ.ศ.<?php $previewField('actual_return_year', 'พ.ศ.กลับจริง', 'year', 'numeric'); ?></div>
                <div class="line">เวลา<?php $previewField('actual_return_time', 'เวลากลับจริง', 'return-time'); ?>น.</div>
            </div>
        </div>
    </section>
    <section class="notes-section" aria-labelledby="notes-heading">
        <h2 id="notes-heading">หมายเหตุ</h2>
        <div class="line">ชื่อผู้ที่จะไปรับ<?php $field('pickup_person_name', 'ชื่อผู้ที่จะไปรับ'); ?>เบอร์โทรศัพท์<?php $field('pickup_person_phone', 'เบอร์โทรศัพท์ผู้ที่จะไปรับ', 'phone', 'tel'); ?></div>
        <div class="line">สถานที่รับ<?php $field('pickup_location', 'สถานที่รับ'); ?><?php $dateFields('pickup', 'วันรับ'); ?>เวลา<?php $field('pickup_time', 'เวลารับ', 'time'); ?>น.</div>
        <div class="line">สถานที่ส่ง<?php $field('dropoff_location', 'สถานที่ส่ง'); ?><?php $dateFields('dropoff', 'วันส่ง'); ?>เวลา<?php $field('dropoff_time', 'เวลาส่ง', 'time'); ?>น.</div>
        <div class="line">ชื่อผู้ประสาน<?php $field('coordinator_name', 'ชื่อผู้ประสานงาน'); ?>เบอร์โทรศัพท์<?php $field('coordinator_phone', 'เบอร์โทรศัพท์ผู้ประสานงาน', 'phone', 'tel'); ?></div>
        <p class="attachment-note">ให้แนบสำเนาบันทึกการขออนุมัติไปปฏิบัติงาน หรือสำเนาหนังสือเชิญ/อบรม/สัมมนา หรือสำเนาขออนุมัติโครงการ หรือเอกสารอื่นๆ ที่เกี่ยวข้อง</p>
    </section>
    <footer class="document-reminders">
        <h2>ข้อควรจำ</h2>
        <ol>
            <li>เมื่อเกิดความเสียหายขึ้น เป็นหน้าที่ของผู้ขออนุญาตใช้รถยนต์ ที่จะต้องติดตามดำเนินเรื่อง และเสนอให้มหาวิทยาลัยทราบโดยด่วน</li>
            <li>การเดินทางนอกเส้นทางที่ไปราชการโดยไม่มีเหตุอันควร ถ้าเกิดความเสียหายขึ้น ผู้ขออนุญาตใช้รถยนต์ต้องรับผิดชอบ</li>
            <li>เมื่อปฏิบัติราชการเสร็จแล้วต้องรีบนำรถยนต์กลับโดยด่วน</li>
        </ol>
    </footer>
</article>
<?php if ($directorDecisionMode): ?>
<form id="booking-director-decision" class="booking-submit-tools" method="post" action="/director/approval-requests/decision"
      data-workflow-action data-success="บันทึกผลและส่งกลับเจ้าหน้าที่แล้ว" data-next="/director/approval-requests">
    <input type="hidden" name="_token" value="<?= e($csrfToken ?? '') ?>">
    <input type="hidden" name="booking_id" value="<?= (int)($bookingId ?? 0) ?>">
    <input type="hidden" name="booking_version" value="<?= (int)($bookingVersion ?? 0) ?>">
    <input type="hidden" name="publish_result" value="1">
    <div class="director-decision-buttons">
        <button type="button" class="booking-submit-button" data-workflow-button data-publish-decision <?= !empty($decisionEnabled) ? '' : 'disabled' ?>>ส่งผลให้เจ้าหน้าที่รถ</button>
    </div>
    <?php if (empty($decisionEnabled)): ?><p class="booking-submit-note" role="status">ระบบยังไม่เปิดให้บันทึกผลพิจารณา</p><?php endif; ?>
</form>
<?php endif; ?>
<?php if (is_array($decisionDiagnostic ?? null)): ?>
<aside class="decision-diagnostic" aria-label="Local read-only decision diagnostic">
    <strong>Local decision diagnostic (read-only)</strong>
    <dl>
        <dt>Authenticated User ID</dt><dd><?= (int)$decisionDiagnostic['user_id'] ?></dd>
        <dt>Roles</dt><dd><?= e(implode(', ', $decisionDiagnostic['roles'])) ?></dd>
        <dt>booking.decision</dt><dd><?= !empty($decisionDiagnostic['booking_decision']) ? 'true' : 'false' ?></dd>
        <dt>Decision Authority</dt><dd><?= !empty($decisionDiagnostic['authority']) ? 'true' : 'false' ?></dd>
        <dt>Booking Status</dt><dd><?= e($decisionDiagnostic['booking_status']) ?></dd>
        <dt>Is Booking Owner</dt><dd><?= !empty($decisionDiagnostic['is_booking_owner']) ? 'true' : 'false' ?></dd>
        <dt>canDecide</dt><dd><?= !empty($decisionDiagnostic['can_decide']) ? 'true' : 'false' ?></dd>
        <dt>Reason</dt><dd><?= e(implode(', ', $decisionDiagnostic['reasons'])) ?: 'all decision guards passed' ?></dd>
    </dl>
</aside>
<?php endif; ?>
<?php if (!empty($showNotify)): ?>
<form id="booking-notify-requester" class="booking-submit-tools" method="post" action="/officer/booking-requests/notify-requester"
      data-workflow-action data-success="แจ้งผลให้ผู้ขอใช้รถแล้ว" data-next="/officer/booking-requests">
    <input type="hidden" name="_token" value="<?= e($csrfToken ?? '') ?>">
    <input type="hidden" name="booking_id" value="<?= (int)($bookingId ?? 0) ?>">
    <input type="hidden" name="booking_version" value="<?= (int)($bookingVersion ?? 0) ?>">
    <div class="director-decision-buttons director-draft-buttons">
        <button type="button" class="booking-submit-button" data-workflow-button <?= !empty($notifyEnabled) ? '' : 'disabled' ?>>ส่งผลให้ผู้ขอใช้รถ</button>
    </div>
    <?php if (empty($notifyEnabled)): ?><p class="booking-submit-note" role="status">ระบบยังไม่เปิดให้แจ้งผล</p><?php endif; ?>
</form>
<?php endif; ?>
</main>
<?php if ($canOfficerEdit): ?>
<section class="booking-submit-tools" aria-label="ส่งต่อคำร้อง">
    <button type="button" class="booking-submit-button" data-workflow-button <?= empty($forwardEnabled) ? 'disabled' : '' ?>>ส่งคำร้องให้ผู้อำนวยการอนุมัติ</button>
</section>
<?php elseif (!$workflowMode): ?>
<section class="booking-submit-tools" aria-label="ส่งคำร้อง">
    <div class="booking-validation-summary" data-booking-validation-summary role="alert" aria-live="polite" tabindex="-1" hidden></div>
    <button type="<?= $canSubmit ? 'submit' : 'button' ?>" id="submit-booking-request" class="booking-submit-button" <?= !$canSubmit || empty($submissionGateOpen) || $exampleReadOnly ? 'disabled' : '' ?>>ส่งคำร้องให้เจ้าหน้าที่รถ</button>
    <?php if ($exampleReadOnly): ?><p class="booking-submit-note" role="status">แบบฟอร์มตัวอย่างไม่สามารถส่งคำร้องได้</p>
    <?php elseif (!$canSubmit): ?><p class="booking-submit-note" role="status">บัญชีนี้ไม่มีสิทธิ์ส่งคำร้อง</p>
    <?php elseif (empty($submissionGateOpen)): ?><p class="booking-submit-note" role="status">ระบบยังไม่เปิดรับคำร้อง</p>
    <?php endif; ?>
</section>
<?php endif; ?>
<?php if ($canOfficerEdit || $canSubmit): ?>
</form>
<?php endif; ?>
<?php
$actions = [];
if (!empty($showForward) && !$canOfficerEdit && !$directorDecisionMode && empty($showNotify)) {
    $actions[] = ['id'=>'booking-forward', 'url'=>'/officer/booking-requests/forward', 'enabled'=>!empty($forwardEnabled),
        'label'=>'ส่งคำร้องให้ผู้อำนวยการอนุมัติ', 'success'=>'ส่งคำร้องให้ผู้อำนวยการแล้ว', 'next'=>'/officer/booking-requests'];
}
?>
<?php foreach ($actions as $action): ?>
<form id="<?= e($action['id']) ?>" <?= $action['id'] === 'booking-forward' ? 'novalidate' : '' ?> class="booking-submit-tools" method="post" action="<?= e($action['url']) ?>"
      data-workflow-action data-success="<?= e($action['success']) ?>" data-next="<?= e($action['next']) ?>">
    <input type="hidden" name="_token" value="<?= e($csrfToken ?? '') ?>">
    <input type="hidden" name="booking_id" value="<?= (int)($bookingId ?? 0) ?>">
    <input type="hidden" name="booking_version" value="<?= (int)($bookingVersion ?? 0) ?>">
    <button type="button" class="booking-submit-button" data-workflow-button <?= $action['enabled'] ? '' : 'disabled' ?>><?= e($action['label']) ?></button>
    <?php if (!$action['enabled']): ?><p class="booking-submit-note" role="status">ระบบยังไม่เปิดให้ทำรายการนี้</p><?php endif; ?>
</form>
<?php endforeach; ?>
</body>
</html>
