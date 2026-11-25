'use strict';
// Shared A4 controls for preview, printing and authorized workflow actions.
const paper = document.getElementById('document');
const warning = document.getElementById('print-warning');
const sampleReadOnly = document.body.dataset.formState === 'sample-readonly';
const textMeasureCanvas = document.createElement('canvas');
const textMeasureContext = textMeasureCanvas.getContext('2d');
let fontMeasurementReady = !document.fonts;
const decisionRadios = [...paper.querySelectorAll('input[name="approval_outcome"]')].filter(input => !input.disabled);
const rejectionReason = paper.querySelector('[data-approval-reason]');

if (sampleReadOnly) {
  // Keep the sample immutable as a form state, even if browser-side markup is re-rendered.
  paper.querySelectorAll('input:not([type="radio"]):not([type="checkbox"]), textarea').forEach(input => { input.readOnly = true; });
  paper.querySelectorAll('input[type="radio"], input[type="checkbox"], select, button').forEach(input => { input.disabled = true; });
  ['beforeinput', 'paste', 'cut', 'drop'].forEach(type => paper.addEventListener(type, event => event.preventDefault(), true));
}

function fitTextField(input) {
  if (!input || !textMeasureContext) return;
  const style = getComputedStyle(input);
  // Never fit against a hidden input. Print CSS removes its layout box; the size
  // measured while visible is retained for the matching print mirror.
  const renderedWidth = input.clientWidth;
  if (renderedWidth <= 0) return false;
  const available = Math.max(0, renderedWidth - parseFloat(style.paddingLeft || '0') - parseFloat(style.paddingRight || '0') - 2);
  const maxPt = 15; // Match the surrounding A4 body text; shrink only overflowing fields.
  const minPt = 9.375; // 12.5 CSS px at 96 dpi.
  let sizePt = maxPt;
  while (sizePt > minPt) {
    textMeasureContext.font = style.fontStyle + ' ' + style.fontWeight + ' ' + sizePt + 'pt ' + style.fontFamily;
    const widestLine = (input.value || ' ').split(/\r?\n/).reduce((widest, line) => Math.max(widest, textMeasureContext.measureText(line || ' ').width), 0);
    if (widestLine <= available) break;
    sizePt = Math.max(minPt, sizePt - 0.25);
  }
  textMeasureContext.font = style.fontStyle + ' ' + style.fontWeight + ' ' + sizePt + 'pt ' + style.fontFamily;
  const widestLine = (input.value || ' ').split(/\r?\n/).reduce((widest, line) => Math.max(widest, textMeasureContext.measureText(line || ' ').width), 0);
  input.style.fontSize = sizePt + 'pt';
  return widestLine > available + 2;
}

function syncApprovalReason() {
  if (sampleReadOnly || !rejectionReason || rejectionReason.form?.matches('[data-workflow-action]')) return;
  const rejected = decisionRadios.some(input => input.checked && input.value === 'rejected');
  rejectionReason.required = rejected;
  rejectionReason.setAttribute('aria-required', String(rejected));
  if (!rejected) rejectionReason.setCustomValidity('');
}

function syncPrintValues() {
  let hasOverflow = false;
  const printMode = window.matchMedia?.('print').matches === true;
  if (!fontMeasurementReady && !printMode) {
    paper.querySelectorAll('.field input, .field textarea').forEach((input) => {
      const printValue = input.nextElementSibling;
      printValue.textContent = input.value;
      printValue.style.fontSize = input.style.fontSize || '15pt';
    });
    warning.hidden = true;
    return;
  }
  paper.querySelectorAll('.field input, .field textarea').forEach((input) => {
    const textOverflowsWidth = printMode ? input.classList.contains('overflowing') : fitTextField(input);
    const printValue = input.nextElementSibling;
    printValue.textContent = input.value;
    printValue.style.fontSize = input.style.fontSize || '15pt';
    const overflowing = printMode
      ? input.classList.contains('overflowing')
      // Every editable A4 field is a single visual line (the destination textarea
      // explicitly uses wrap="off"). scrollHeight includes the font line box and
      // padding even when the glyphs fit, so it is not evidence of text overflow.
      : textOverflowsWidth || input.scrollWidth > input.clientWidth + 2;
    input.classList.toggle('overflowing', overflowing);
    hasOverflow ||= overflowing;
  });
  const destinationInput = paper.querySelector('[data-document-destination]');
  if (destinationInput && destinationInput.value.trim()) {
    const paperStyle = getComputedStyle(paper);
    const pageBottom = paper.getBoundingClientRect().top + parseFloat(paperStyle.minHeight || '1122.5') - parseFloat(paperStyle.paddingBottom || '0');
    hasOverflow ||= destinationInput.getBoundingClientRect().bottom > pageBottom + 1;
  }
  warning.hidden = !hasOverflow;
}
paper.addEventListener('input', syncPrintValues);
decisionRadios.forEach(input => input.addEventListener('change', syncApprovalReason));
rejectionReason?.addEventListener('input', () => {
  if (rejectionReason.value.trim()) rejectionReason.setCustomValidity('');
  else if (rejectionReason.required) rejectionReason.setCustomValidity('กรุณากรอกเหตุผลเมื่อเลือกไม่อนุญาต');
});
window.addEventListener('beforeprint', syncPrintValues);
document.getElementById('print-document').addEventListener('click', async () => {
  syncApprovalReason();
  if (rejectionReason?.required && !rejectionReason.value.trim()) {
    rejectionReason.setCustomValidity('กรุณากรอกเหตุผลเมื่อเลือกไม่อนุญาต');
    rejectionReason.reportValidity();
    return;
  }
  if (document.fonts?.ready) await document.fonts.ready;
  await new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)));
  fontMeasurementReady = true;
  syncPrintValues();
  window.print();
});
syncApprovalReason();
if (document.fonts?.ready) {
  document.fonts.ready.then(() => {
    fontMeasurementReady = true;
    requestAnimationFrame(() => requestAnimationFrame(syncPrintValues));
  });
} else {
  fontMeasurementReady = true;
  requestAnimationFrame(() => requestAnimationFrame(syncPrintValues));
}

const submitForm = document.getElementById('booking-submit-form');
const submitButton = document.getElementById('submit-booking-request');
if (submitForm && submitButton) {
  // Blank fields are valid for intake; server validates all supplied values.
  const validationSummary = submitForm.querySelector('[data-booking-validation-summary]');
  const requiredFields = [
    { key: 'requester_name', label: 'ชื่อผู้ขอใช้รถ', message: 'กรอกชื่อผู้ขอใช้รถ' },
    { key: 'requester_phone', label: 'เบอร์โทรศัพท์ผู้ขอ', message: 'กรอกเบอร์โทรศัพท์ที่เจ้าหน้าที่ติดต่อได้' },
    { key: 'purpose', label: 'วัตถุประสงค์', message: 'ระบุเหตุผลหรือวัตถุประสงค์การเดินทาง' },
    { key: 'destination', label: 'สถานที่เดินทาง', message: 'ระบุสถานที่หรือปลายทาง' },
    { key: 'passenger_count', label: 'จำนวนผู้เดินทาง', message: 'ระบุจำนวนผู้เดินทางอย่างน้อย 1 คน' },
    { key: 'travel_type', label: 'ประเภทการเดินทาง', message: 'เลือกภายในจังหวัดหรือต่างจังหวัด' },
    { key: 'expense_source', label: 'แหล่งค่าใช้จ่าย', message: 'เลือกแหล่งค่าใช้จ่าย' }
  ];
  let validationAttempted = false;
  function fieldElements(name) {
    if (name === 'travel_type') return [...submitForm.querySelectorAll('[name="travel_type"]')];
    if (name === 'expense_source') return [...submitForm.querySelectorAll('[name="expense_source"]')];
    const field = submitForm.elements.namedItem(name);
    return field instanceof HTMLElement ? [field] : [];
  }
  function setFieldInvalid(name, invalid) {
    const elements = fieldElements(name);
    elements.forEach((element) => {
      element.classList.toggle('booking-field-invalid', invalid);
      if (invalid) element.setAttribute('aria-invalid', 'true');
      else element.removeAttribute('aria-invalid');
    });
    const group = name === 'travel_type' ? submitForm.querySelector('.travel-options')
      : name === 'expense_source' ? submitForm.querySelector('fieldset.expenses') : null;
    group?.classList.toggle('booking-group-invalid', invalid);
    if (group) {
      if (invalid) group.setAttribute('aria-invalid', 'true');
      else group.removeAttribute('aria-invalid');
    }
  }
  function validateRequiredFields() {
    const missing = [];
    for (const field of requiredFields) {
      const controls = fieldElements(field.key);
      const value = field.key === 'travel_type' || field.key === 'expense_source'
        ? controls.some(control => control instanceof HTMLInputElement && control.checked)
        : controls.some(control => 'value' in control && String(control.value).trim() !== '');
      setFieldInvalid(field.key, !value);
      if (!value) missing.push(field);
    }
    const windowFields = ['departure_date', 'departure_time', 'return_date', 'return_time'];
    const missingWindowFields = windowFields.filter(name => {
      const field = submitForm.elements.namedItem(name);
      return !(field instanceof HTMLInputElement) || field.value.trim() === '';
    });
    missingWindowFields.forEach(name => setFieldInvalid(name, true));
    windowFields.filter(name => !missingWindowFields.includes(name)).forEach(name => setFieldInvalid(name, false));
    const vehicle = submitForm.elements.namedItem('vehicle_id');
    const hasVehicle = vehicle instanceof HTMLInputElement && /^\d+$/.test(vehicle.value) && Number(vehicle.value) > 0;
    const scheduleMissing = !hasVehicle || missingWindowFields.length > 0;
    if (validationSummary instanceof HTMLElement) {
      validationSummary.replaceChildren();
      if (missing.length || scheduleMissing) {
        const title = document.createElement('strong');
        title.textContent = 'ข้อมูลที่ยังไม่ครบ';
        validationSummary.append(title);
        const list = document.createElement('ul');
        for (const item of missing) {
          const row = document.createElement('li');
          const link = document.createElement('a');
          link.href = '#';
          link.dataset.bookingFocus = item.key;
          link.textContent = item.label + ': ' + item.message;
          row.append(link);
          list.append(row);
        }
        if (scheduleMissing) {
          if (!hasVehicle) {
            const row = document.createElement('li');
            const link = document.createElement('a');
            link.href = '/bookings/calendar';
            link.textContent = 'เลือกรถและวันเวลาเดินทางจากหน้าปฏิทิน';
            row.append(link);
            list.append(row);
          }
          const windowLabels = {
            departure_date: 'วันที่ออกเดินทาง', departure_time: 'เวลาออกเดินทาง',
            return_date: 'วันที่กลับ', return_time: 'เวลากลับ'
          };
          for (const name of missingWindowFields) {
            const row = document.createElement('li');
            const link = document.createElement('a');
            link.href = '#';
            link.dataset.bookingFocus = name;
            link.textContent = 'กรอก' + windowLabels[name];
            row.append(link);
            list.append(row);
          }
        }
        validationSummary.append(list);
        const note = document.createElement('p');
        note.textContent = 'ยังส่งคำร้องได้ แต่เจ้าหน้าที่จะเห็นสถานะข้อมูลไม่ครบ และยังไม่กันช่วงเวลารถจนกว่าจะระบุข้อมูลให้ครบ';
        validationSummary.append(note);
        validationSummary.hidden = false;
      } else {
        validationSummary.hidden = true;
      }
    }
    return missing.length === 0 && !scheduleMissing;
  }
  validationSummary?.addEventListener('click', (event) => {
    const link = event.target instanceof Element ? event.target.closest('[data-booking-focus]') : null;
    if (!(link instanceof HTMLAnchorElement)) return;
    event.preventDefault();
    fieldElements(link.dataset.bookingFocus || '')[0]?.focus();
  });
  submitForm.addEventListener('input', () => {
    if (validationAttempted) validateRequiredFields();
  });
  submitForm.addEventListener('change', () => {
    if (validationAttempted) validateRequiredFields();
  });
  submitForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (submitButton.disabled || submitForm.dataset.submissionEnabled !== 'true') return;
    if (!submitForm.reportValidity()) return;
    validationAttempted = true;
    const complete = validateRequiredFields();
    if (!complete) validationSummary?.focus();
    if (typeof window.Swal === 'undefined') {
      window.alert('ไม่สามารถเปิดหน้าต่างยืนยันได้ กรุณาโหลดหน้าใหม่');
      return;
    }
    const confirmation = await window.Swal.fire({
      icon: 'question',
      title: 'ยืนยันส่งคำร้อง?',
      text: complete ? 'ส่งข้อมูลตามที่กรอกให้เจ้าหน้าที่ตรวจสอบ' : 'มีช่องบังคับที่ยังไม่ครบ คุณยังส่งคำร้องได้ แต่คำร้องจะถูกระบุว่าข้อมูลไม่ครบและยังไม่กันช่วงเวลารถ',
      showCancelButton: true,
      confirmButtonText: 'ส่งคำร้อง',
      cancelButtonText: 'กลับไปตรวจข้อมูล',
      confirmButtonColor: '#4c1d95'
    });
    if (!confirmation.isConfirmed || submitButton.disabled || submitForm.dataset.submissionEnabled !== 'true') return;
    submitButton.disabled = true;
    submitButton.setAttribute('aria-busy', 'true');
    try {
      const response = await fetch(submitForm.action, {
        method: 'POST',
        body: new FormData(submitForm),
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
      });
      const result = await response.json();
      if (!response.ok) {
        if (result.error === 'SUBMISSION_GATE_CLOSED') submitButton.disabled = true;
        else submitButton.disabled = false;
        await window.Swal.fire({ icon: 'error', title: 'ยังส่งคำร้องไม่ได้', text: result.message || 'กรุณาตรวจข้อมูลและลองใหม่' });
        return;
      }
      submitForm.dataset.submissionEnabled = 'false';
      const acknowledgement = await window.Swal.fire({
        icon: 'success',
        title: 'ส่งคำร้องแล้ว',
        text: 'เลขคำร้อง ' + result.request_number + ' · ส่งคำร้องแล้ว' + (result.information_complete === false ? ' · ข้อมูลยังไม่ครบ' : '') + (result.schedule_reserved === false ? ' · ยังไม่ยืนยันการจอง' : ''),
        confirmButtonText: 'OK',
        allowOutsideClick: false,
        allowEscapeKey: false,
        confirmButtonColor: '#4c1d95'
      });
      if (acknowledgement.isConfirmed) window.location.assign('/my/booking-requests');
    } catch (_) {
      if (submitForm.dataset.submissionEnabled === 'false') return;
      submitButton.disabled = false;
      await window.Swal.fire({ icon: 'error', title: 'ระบบขัดข้องชั่วคราว', text: 'ยังไม่ได้รับการยืนยันว่าคำร้องถูกส่งแล้ว กรุณาตรวจรายการคำร้องก่อนส่งซ้ำ' });
    } finally {
      submitButton.removeAttribute('aria-busy');
    }
  });
}

let activeWorkflowAction = false;
for (const workflowForm of document.querySelectorAll('[data-workflow-action]')) {
  workflowForm.addEventListener('submit', (event) => event.preventDefault());
  const buttons = [...workflowForm.querySelectorAll('[data-workflow-button]:not(:disabled)')];
  const isForward = workflowForm.id === 'booking-forward';
  const errorIcon = isForward ? {} : { icon: 'error' };
  const requiredFields = new Set((workflowForm.dataset.requiredFields || '').split(' '));
  const editableRequired = [...workflowForm.elements].filter(field =>
    requiredFields.has(field.name) && !field.disabled && !field.readOnly);
  let attempted = false;
  function markEmptyFields() {
    const values = new FormData(workflowForm);
    let firstEmpty;
    for (const field of editableRequired) {
      if ((values.get(field.name) || '').trim()) {
        field.removeAttribute('aria-invalid');
      } else {
        field.setAttribute('aria-invalid', 'true');
        firstEmpty ||= field;
      }
    }
    return firstEmpty;
  }
  workflowForm.addEventListener('input', () => {
    if (attempted) markEmptyFields();
  });
  for (const button of buttons) {
    button.addEventListener('click', async () => {
      if (activeWorkflowAction || button.disabled || typeof window.Swal === 'undefined') return;
      activeWorkflowAction = true;
      let saved = false;
      try {
        if (isForward) {
          attempted = true;
          const firstEmpty = markEmptyFields();
          if (firstEmpty) {
            firstEmpty.focus();
            return;
          }
        }
        const payload = new FormData(workflowForm);
        const publishing = button.hasAttribute('data-publish-decision');
        const outcome = payload.get('approval_outcome');
        if (publishing && !['approved', 'rejected'].includes(outcome)) {
          await window.Swal.fire({ icon: 'warning', title: 'กรุณาเลือกผลพิจารณาในส่วนที่ 3' });
          return;
        }
        const label = button.textContent.trim();
        const confirmation = await window.Swal.fire({
          icon: 'question', title: 'ยืนยัน' + label + '?',
          text: publishing ? (outcome === 'approved' ? 'ผลพิจารณา: อนุมัติ' : 'ผลพิจารณา: ไม่อนุมัติ') : undefined,
          showCancelButton: true, confirmButtonText: 'ยืนยัน', cancelButtonText: 'ยกเลิก', confirmButtonColor: '#4c1d95'
        });
        if (!confirmation.isConfirmed) return;
        buttons.forEach((control) => { control.disabled = true; });
        const action = button.hasAttribute('formaction') ? button.formAction : workflowForm.action;
        const response = await fetch(action, { method: 'POST', body: payload, credentials: 'same-origin', headers: { Accept: 'application/json' } });
        const result = await response.json().catch(() => ({}));
        if (!response.ok || result.status !== 'saved') {
          await window.Swal.fire({ ...errorIcon, title: 'ยังทำรายการไม่ได้', text: result.message || 'กรุณาโหลดข้อมูลคำร้องใหม่ก่อนลองอีกครั้ง' });
          return;
        }
        saved = true;
        await window.Swal.fire({
          icon: 'success', title: button.dataset.draftSuccess || workflowForm.dataset.success, confirmButtonText: 'ตกลง',
          allowOutsideClick: false, allowEscapeKey: false
        });
        if (!button.dataset.draftSuccess && workflowForm.dataset.next) window.location.assign(workflowForm.dataset.next);
        else window.location.reload();
      } catch {
        await window.Swal.fire({ ...errorIcon, title: 'ยังไม่ได้รับการยืนยัน', text: 'กรุณาตรวจสถานะคำร้องก่อนลองใหม่' });
      } finally {
        if (!saved) {
          activeWorkflowAction = false;
          buttons.forEach((control) => { control.disabled = false; });
        }
      }
    });
  }
}
