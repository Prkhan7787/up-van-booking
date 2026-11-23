document.addEventListener('submit', (event) => {
  const form = event.target;
  if (!(form instanceof HTMLFormElement) || !form.matches('[data-unavailable-form]')) return;
  event.preventDefault();
  if (!window.Swal) return;
  Swal.fire({
    title: 'ยังไม่เปิดใช้งาน',
    text: 'แบบฟอร์มนี้เป็นตัวอย่าง ไม่มีการส่งหรือตรวจสอบข้อมูลบัญชี.',
    icon: 'info',
    confirmButtonText: 'รับทราบ',
    buttonsStyling: false,
    customClass: { confirmButton: 'min-h-11 rounded-xl bg-up px-5 py-3 font-bold text-white' },
  });
});

if (document.body?.dataset.authError === 'invalid' && window.Swal) {
  Swal.fire({
    title: 'เข้าสู่ระบบไม่สำเร็จ',
    text: 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง',
    icon: 'error',
    confirmButtonText: 'ลองอีกครั้ง',
    buttonsStyling: false,
    customClass: { confirmButton: 'min-h-11 rounded-xl bg-up px-5 py-3 font-bold text-white' },
  });
}

document.addEventListener('submit', async (event) => {
  const form = event.target;
  if (!(form instanceof HTMLFormElement) || !form.matches('[data-admin-confirm]')) return;
  if (form.dataset.adminConfirmed === 'true') {
    delete form.dataset.adminConfirmed;
    return;
  }
  event.preventDefault();
  if (form.dataset.adminPending === 'true') return;
  if (form.matches('[data-account-create]')) {
    const password = form.elements.namedItem('password');
    const confirmation = form.elements.namedItem('password_confirmation');
    if (!(password instanceof HTMLInputElement) || !(confirmation instanceof HTMLInputElement)) return;
    confirmation.setCustomValidity(password.value !== confirmation.value ? 'รหัสผ่านและการยืนยันไม่ตรงกัน' : '');
    password.setCustomValidity(Array.from(password.value).length < 6 ? 'รหัสผ่านต้องมีอย่างน้อย 6 ตัวอักษร' : '');
    if (!form.reportValidity()) return;
  }
  form.dataset.adminPending = 'true';
  try {
    let confirmationText = 'ยืนยันการเปลี่ยนแปลงและบันทึกประวัติระบบ';
    if (form.matches('[data-primary-role-editor]')) {
      const before = JSON.parse(form.dataset.originalRoles || '[]');
      const labels = JSON.parse(form.dataset.roleLabels || '{}');
      const previous = form.elements.namedItem('previous_role').value;
      const next = form.elements.namedItem('primary_role').value;
      const after = [...new Set([...before.filter(role => role !== previous), next])];
      const removed = before.filter(role => !after.includes(role));
      const names = roles => roles.map(role => labels[role] || role).join(', ');
      confirmationText = `สิทธิ์หลังบันทึก: ${names(after)}${removed.length ? ` · ถอดสิทธิ์: ${names(removed)}` : ' · คงสิทธิ์เดิมครบ'}`;
    }
    if (form.matches('[data-vehicle-action]')) confirmationText = 'ยืนยันรายการรถตู้ ระบบจะบันทึกประวัติการเปลี่ยนแปลง';
    const accepted = window.Swal
      ? (await Swal.fire({
          title: form.dataset.adminConfirm,
          text: form.matches('[data-account-create]')
            ? 'ยืนยันสร้างบัญชีพร้อมบทบาทที่เลือก ระบบจะบันทึกประวัติให้อัตโนมัติ'
            : confirmationText,
          icon: 'question', showCancelButton: true,
          confirmButtonText: 'ยืนยัน', cancelButtonText: 'ยกเลิก', buttonsStyling: false,
          customClass: {
            confirmButton: 'min-h-11 rounded-xl bg-up px-5 py-3 font-bold text-white',
            cancelButton: 'ml-2 min-h-11 rounded-xl border border-slate-300 bg-white px-5 py-3 font-bold text-slate-700',
          },
        })).isConfirmed
      : window.confirm(form.matches('[data-vehicle-action]') ? 'ยืนยันการจัดการรถตู้?' : 'ยืนยันการดำเนินการจัดการบัญชีผู้ใช้?');
    if (accepted) { form.dataset.adminConfirmed = 'true'; form.requestSubmit(); }
  } finally {
    delete form.dataset.adminPending;
  }
});

document.addEventListener('click', async (event) => {
  if (!(event.target instanceof Element)) return;
  const button = event.target.closest('[data-booking-delete-button], [data-booking-recall-button]');
  if (!(button instanceof HTMLButtonElement) || button.disabled || !window.Swal) return;
  const form = button.form;
  const recall = button.hasAttribute('data-booking-recall-button');
  if (!form?.matches(recall ? '[data-booking-recall]' : '[data-booking-delete]')) return;

  button.disabled = true;
  let completed = false;
  try {
    const result = await window.Swal.fire({
      title: recall ? 'ยกเลิกการส่งต่อคำร้อง?' : 'ยืนยันการลบคำร้อง?',
      text: recall ? 'คำร้องจะกลับมาให้เจ้าหน้าที่แก้ไข และสามารถส่งให้ผู้อำนวยการพิจารณาอีกครั้งได้' : 'คำร้องจะถูกนำออกจากรายการปกติ แต่ยังเก็บประวัติไว้',
      icon: 'warning',
      showCancelButton: true,
      focusCancel: true,
      confirmButtonText: recall ? 'ยืนยันการดึงกลับ' : 'ยืนยันลบ',
      cancelButtonText: recall ? 'กลับ' : 'ยกเลิก',
      confirmButtonColor: recall ? '#4c1d95' : '#b91c1c'
    });
    if (!result.isConfirmed) return;
    const response = await fetch(form.action, {
      method: 'POST', body: new FormData(form), headers: { Accept: 'application/json' }
    });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok || payload.status !== (recall ? 'saved' : 'deleted')) throw new Error(payload.message || (recall ? 'ดึงคำร้องกลับไม่สำเร็จ' : 'ลบคำร้องไม่สำเร็จ'));
    completed = true;
    window.location.reload();
  } catch (error) {
    await window.Swal.fire({ icon: 'error', title: recall ? 'ดึงคำร้องกลับไม่ได้' : 'ลบคำร้องไม่ได้', text: error.message });
  } finally {
    if (!completed) button.disabled = false;
  }
});

const calendarMonth = document.querySelector('[data-calendar-month]');
const calendarMonthForm = document.querySelector('#calendar-month-form');
if (calendarMonth instanceof HTMLInputElement && calendarMonthForm instanceof HTMLFormElement) {
  calendarMonth.addEventListener('click', () => {
    try { calendarMonth.showPicker?.(); } catch { calendarMonth.focus(); }
  });
  calendarMonth.addEventListener('change', () => {
    if (calendarMonth.value && calendarMonthForm.reportValidity()) calendarMonthForm.requestSubmit();
  });
}

const vehicleFilter = document.querySelector('[data-vehicle-filter]');
if (vehicleFilter instanceof HTMLFormElement) {
  const vehicleSelect = vehicleFilter.querySelector('[data-vehicle-filter-select]');
  if (vehicleSelect instanceof HTMLSelectElement) {
    vehicleSelect.addEventListener('change', () => {
      if (vehicleSelect.value && vehicleFilter.reportValidity()) vehicleFilter.requestSubmit();
    });
  }
}

const bookingFormLink = document.querySelector('[data-booking-form-link]');

document.addEventListener('click', (event) => {
  const dayLink = event.target instanceof Element ? event.target.closest('[data-calendar-day]') : null;
  if (!(dayLink instanceof HTMLAnchorElement)) return;
  const today = dayLink.dataset.calendarToday || '';
  if (dayLink.dataset.calendarDay && today && dayLink.dataset.calendarDay < today) {
    event.preventDefault();
    const error = document.querySelector('[data-booking-selection-error]');
    if (error instanceof HTMLElement) {
      error.textContent = 'ไม่สามารถเลือกวันที่ผ่านมาแล้วเพื่อจองรถตู้ได้';
      error.hidden = false;
      error.scrollIntoView({block: 'nearest'});
    }
  }
});

function parseDmyDate(value) {
  const match = /^(\d{2})\/(\d{2})\/(\d{4})$/.exec(value);
  if (!match) return '';
  const [, dayText, monthText, yearText] = match;
  const day = Number(dayText), month = Number(monthText), year = Number(yearText);
  if (year < 1000 || month < 1 || month > 12 || day < 1 || day > 31) return '';
  const check = new Date(Date.UTC(year, month, 0)).getUTCDate();
  if (day > check) return '';
  return `${yearText}-${monthText}-${dayText}`;
}

function refreshBookingDateRangeError(form) {
  if (!(form instanceof HTMLFormElement)) return;
  const startDisplay = form.elements.namedItem('start_date_display');
  const endDisplay = form.elements.namedItem('end_date_display');
  const startValue = form.elements.namedItem('start_date');
  const endValue = form.elements.namedItem('end_date');
  const error = form.querySelector('[data-booking-selection-error]');
  if (!(startDisplay instanceof HTMLInputElement) || !(endDisplay instanceof HTMLInputElement)
    || !(startValue instanceof HTMLInputElement) || !(endValue instanceof HTMLInputElement)
    || !(error instanceof HTMLElement)) return;
  const rangeInvalid = Boolean(startValue.value && endValue.value) && endValue.value < startValue.value;
  if (rangeInvalid) {
    error.dataset.errorKind = 'date-range';
    error.textContent = 'วันสิ้นสุดเดิมอยู่ก่อนวันเริ่ม กรุณาเลือกวันสิ้นสุดใหม่';
    error.hidden = false;
    endDisplay.setCustomValidity(error.textContent);
    return;
  }
  endDisplay.setCustomValidity(endDisplay.value === '' || parseDmyDate(endDisplay.value.trim()) ? '' : 'กรุณากรอกวันที่รูปแบบ dd/mm/yyyy เช่น 19/11/2026');
  if (error.dataset.errorKind === 'date-range') {
    error.textContent = '';
    error.hidden = true;
    delete error.dataset.errorKind;
  }
}

document.querySelectorAll('[data-date-display]').forEach((display) => {
  if (!(display instanceof HTMLInputElement)) return;
  const group = display.parentElement;
  const value = group?.querySelector('[data-date-value]');
  const picker = group?.querySelector('[data-date-picker]');
  if (!(value instanceof HTMLInputElement) || !(picker instanceof HTMLInputElement)) return;
  const sync = () => {
    const iso = parseDmyDate(display.value.trim());
    const today = bookingFormLink?.dataset.calendarToday || '';
    if (iso && today && iso < today) {
      display.setCustomValidity('ไม่สามารถเลือกวันที่ผ่านมาแล้วเพื่อจองรถตู้ได้');
      value.value = '';
      picker.value = '';
      if (display.name === 'start_date_display') {
        const endValue = bookingFormLink?.elements.namedItem('end_date');
        const endDisplay = bookingFormLink?.elements.namedItem('end_date_display');
        const endPicker = bookingFormLink?.querySelector('[name="end_date"] + [data-date-picker]');
        if (endValue instanceof HTMLInputElement) endValue.value = '';
        if (endDisplay instanceof HTMLInputElement) endDisplay.value = '';
        if (endPicker instanceof HTMLInputElement) endPicker.value = '';
      }
      bookingFormLink?.dispatchEvent(new Event('booking-selection-change'));
      return;
    }
    value.value = iso;
    picker.value = iso;
    if (value.name === 'start_date' && bookingFormLink instanceof HTMLFormElement) {
      const endValue = bookingFormLink.elements.namedItem('end_date');
      const endDisplay = bookingFormLink.elements.namedItem('end_date_display');
      const endPicker = bookingFormLink.querySelector('[name="end_date"] + [data-date-picker]');
      if (endPicker instanceof HTMLInputElement) endPicker.min = iso || bookingFormLink.dataset.calendarToday || '';
      if (endValue instanceof HTMLInputElement && endDisplay instanceof HTMLInputElement) refreshBookingDateRangeError(bookingFormLink);
    }
    display.setCustomValidity(display.value === '' || iso ? '' : 'กรุณากรอกวันที่รูปแบบ dd/mm/yyyy เช่น 19/11/2026');
    refreshBookingDateRangeError(bookingFormLink);
    bookingFormLink?.dispatchEvent(new Event('booking-selection-change'));
  };
  display.addEventListener('input', sync);
  display.addEventListener('change', sync);
  picker.min = value.name === 'start_date' ? bookingFormLink?.dataset.calendarToday || '' : picker.min;
  picker.addEventListener('input', () => {
    if (!picker.value) return;
    const [year, month, day] = picker.value.split('-');
    display.value = `${day}/${month}/${year}`;
    sync();
  });
  picker.addEventListener('change', () => picker.dispatchEvent(new Event('input', { bubbles: true })));
  const openPicker = () => {
    try {
      if (typeof picker.showPicker === 'function') picker.showPicker();
      else { picker.focus(); picker.click(); }
    } catch { picker.focus(); picker.click(); }
  };
  display.addEventListener('click', openPicker);
  display.addEventListener('keydown', (event) => {
    if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); openPicker(); }
  });
  sync();
});

document.querySelectorAll('[data-time-display]').forEach((display) => {
  if (!(display instanceof HTMLInputElement)) return;
  const group = display.parentElement;
  const value = group?.querySelector('[data-time-value]');
  const picker = group?.querySelector('[data-time-picker]');
  if (!(value instanceof HTMLInputElement) || !(picker instanceof HTMLInputElement)) return;
  const sync = () => {
    const time = display.value.trim();
    const valid = /^(?:[01]\d|2[0-3]):[0-5]\d$/.test(time);
    value.value = valid ? time : '';
    picker.value = valid ? time : '';
    display.setCustomValidity(time === '' || valid ? '' : 'กรุณากรอกเวลาแบบ 24 ชั่วโมง เช่น 08:00');
    bookingFormLink?.dispatchEvent(new Event('booking-selection-change'));
  };
  display.addEventListener('input', sync);
  display.addEventListener('change', sync);
  picker.addEventListener('input', () => { display.value = picker.value; sync(); });
  picker.addEventListener('change', () => picker.dispatchEvent(new Event('input', { bubbles: true })));
  const openPicker = () => {
    try {
      if (typeof picker.showPicker === 'function') picker.showPicker();
      else { picker.focus(); picker.click(); }
    } catch { picker.focus(); picker.click(); }
  };
  display.addEventListener('click', openPicker);
  display.addEventListener('keydown', (event) => {
    if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); openPicker(); }
  });
  sync();
});

if (bookingFormLink instanceof HTMLFormElement) {
  const submitButton = bookingFormLink.querySelector('[data-booking-submit]');
  const disabledReason = bookingFormLink.querySelector('[data-booking-disabled-reason]');
  const canSubmit = bookingFormLink.dataset.bookingCanSubmit === 'true';
  const advanceWarning = bookingFormLink.querySelector('[data-booking-advance-warning]');
  const isUnderFiveWeekdays = (iso, today) => {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(iso) || !/^\d{4}-\d{2}-\d{2}$/.test(today)) return false;
    const [y, m, d] = today.split('-').map(Number);
    const cursor = new Date(Date.UTC(y, m - 1, d));
    let weekdays = 0;
    while (weekdays < 5) {
      cursor.setUTCDate(cursor.getUTCDate() + 1);
      const weekday = cursor.getUTCDay();
      if (weekday >= 1 && weekday <= 5) weekdays += 1;
    }
    const earliest = `${cursor.getUTCFullYear()}-${String(cursor.getUTCMonth() + 1).padStart(2, '0')}-${String(cursor.getUTCDate()).padStart(2, '0')}`;
    return iso < earliest;
  };
  const refreshSubmitState = () => {
    if (!(submitButton instanceof HTMLButtonElement)) return;
    const vehicle = bookingFormLink.elements.namedItem('vehicle_id');
    const startDate = bookingFormLink.elements.namedItem('start_date');
    const startTime = bookingFormLink.elements.namedItem('start_time');
    const endDate = bookingFormLink.elements.namedItem('end_date');
    const endTime = bookingFormLink.elements.namedItem('end_time');
    const today = bookingFormLink.dataset.calendarToday || '';
    const dateOk = (value) => /^\d{4}-\d{2}-\d{2}$/.test(value || '') && value >= today;
    const timeOk = (value) => /^(?:[01]\d|2[0-3]):[0-5]\d$/.test(value || '');
    const dateOrdered = startDate instanceof HTMLInputElement && endDate instanceof HTMLInputElement && endDate.value >= startDate.value;
    const ordered = startDate instanceof HTMLInputElement && startTime instanceof HTMLInputElement
      && endDate instanceof HTMLInputElement && endTime instanceof HTMLInputElement
      && `${startDate.value}T${startTime.value}` < `${endDate.value}T${endTime.value}`;
    submitButton.disabled = !canSubmit || !(vehicle instanceof HTMLInputElement) || !/^\d+$/.test(vehicle.value)
      || !(startDate instanceof HTMLInputElement) || !(endDate instanceof HTMLInputElement)
      || !(startTime instanceof HTMLInputElement) || !(endTime instanceof HTMLInputElement)
      || !dateOk(startDate.value) || !dateOk(endDate.value) || !dateOrdered || !timeOk(startTime.value) || !timeOk(endTime.value) || !ordered;
    if (advanceWarning instanceof HTMLElement && startDate instanceof HTMLInputElement) advanceWarning.hidden = !isUnderFiveWeekdays(startDate.value, today);
    if (disabledReason instanceof HTMLElement) disabledReason.textContent = !canSubmit ? 'บทบาทนี้ไม่มีสิทธิ์เข้าดูหน้าแผนที่' : !(vehicle instanceof HTMLInputElement) ? 'ยังไม่มีรถตู้ที่เลือกได้' : !dateOk(startDate?.value) || !dateOk(endDate?.value) ? 'กรุณาเลือกวันที่ไม่ย้อนหลังจากวันนี้' : !timeOk(startTime?.value) || !timeOk(endTime?.value) ? 'กรุณาเลือกเวลาเริ่มและเวลาสิ้นสุด' : !ordered ? 'วันเวลาสิ้นสุดต้องอยู่หลังวันเวลาเริ่ม' : '';
  };
  bookingFormLink.addEventListener('booking-selection-change', refreshSubmitState);
  bookingFormLink.querySelectorAll('[data-date-display],[data-time-display]').forEach((input) => input.addEventListener('input', refreshSubmitState));
  refreshSubmitState();
  bookingFormLink.addEventListener('submit', (event) => {
    bookingFormLink.querySelectorAll('[data-date-display]').forEach((display) => {
      if (display instanceof HTMLInputElement) display.dispatchEvent(new Event('input', { bubbles: true }));
    });
    const startDate = bookingFormLink.elements.namedItem('start_date');
    const startTime = bookingFormLink.elements.namedItem('start_time');
    const endDate = bookingFormLink.elements.namedItem('end_date');
    const endTime = bookingFormLink.elements.namedItem('end_time');
    const vehicleId = bookingFormLink.elements.namedItem('vehicle_id');
    const start = bookingFormLink.querySelector('[data-booking-start]');
    const end = bookingFormLink.querySelector('[data-booking-end]');
    const error = bookingFormLink.querySelector('[data-booking-selection-error]');
    if (!(startDate instanceof HTMLInputElement) || !(startTime instanceof HTMLInputElement)
      || !(endDate instanceof HTMLInputElement) || !(endTime instanceof HTMLInputElement)
      || !(vehicleId instanceof HTMLInputElement) || !(start instanceof HTMLInputElement)
      || !(end instanceof HTMLInputElement) || !(error instanceof HTMLElement)) return;
    error.hidden = true;
    error.textContent = '';
    if (!bookingFormLink.reportValidity()) { event.preventDefault(); return; }
    const startValue = `${startDate.value}T${startTime.value}`;
    const endValue = `${endDate.value}T${endTime.value}`;
    const today = bookingFormLink.dataset.calendarToday || '';
    const localDateTimePattern = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/;
    if (!vehicleId.value || !/^\d+$/.test(vehicleId.value)) error.textContent = 'กรุณาเลือกรถตู้ที่พร้อมใช้งาน';
    else if (!today || startDate.value < today || endDate.value < today) error.textContent = 'ไม่สามารถเลือกวันที่ผ่านมาแล้วเพื่อจองรถตู้ได้';
    else if (endDate.value < startDate.value) error.textContent = 'วันสิ้นสุดต้องไม่น้อยกว่าวันเริ่ม';
    else if (!localDateTimePattern.test(startValue) || !localDateTimePattern.test(endValue) || endValue <= startValue) error.textContent = 'วันและเวลาสิ้นสุดต้องอยู่หลังวันและเวลาเริ่มต้น';
    if (error.textContent) {
      error.hidden = false;
      event.preventDefault();
      return;
    }
    start.value = startValue;
    end.value = endValue;
  });
}

document.addEventListener('input', (event) => {
  if (event.target instanceof HTMLInputElement && event.target.form?.matches('[data-account-create]')) {
    for (const name of ['password', 'password_confirmation']) {
      const input = event.target.form.elements.namedItem(name);
      if (input instanceof HTMLInputElement) input.setCustomValidity('');
    }
  }
});

const adminNotice = document.querySelector('[data-admin-notice="success"]');
if (adminNotice && window.Swal) {
  Swal.fire({ titleText: adminNotice.dataset.adminNoticeText || 'บันทึกสำเร็จ', icon: 'success', confirmButtonText: 'รับทราบ', buttonsStyling: false,
    customClass: { confirmButton: 'min-h-11 rounded-xl bg-up px-5 py-3 font-bold text-white' } });
}

const vehicleError = document.querySelector('[data-vehicle-save-error]');
if (vehicleError && window.Swal) {
  Swal.fire({ title: 'บันทึกไม่สำเร็จ', text: vehicleError.textContent.trim(), icon: 'error', confirmButtonText: 'รับทราบ', buttonsStyling: false,
    customClass: { confirmButton: 'min-h-11 rounded-xl bg-up px-5 py-3 font-bold text-white' } });
}
