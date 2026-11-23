(() => {
  'use strict';

  const messages = {
    microsoft: 'ระบบเข้าสู่ระบบด้วย Microsoft 365 ยังไม่เปิดให้บริการ',
    'driver-registration': 'ระบบสมัครสมาชิกพนักงานขับรถยังไม่เปิดให้บริการ',
  };

  document.addEventListener('click', (event) => {
    const button = event.target instanceof Element
      ? event.target.closest('[data-login-notice]')
      : null;
    const message = button ? messages[button.dataset.loginNotice] : null;

    if (!message || !window.Swal) return;

    window.Swal.fire({
      icon: 'info',
      titleText: message,
      confirmButtonText: 'รับทราบ',
      buttonsStyling: false,
      customClass: {
        confirmButton: 'rounded-xl bg-up px-5 py-3 font-bold text-white focus:outline-none focus:ring-2 focus:ring-gold focus:ring-offset-2',
      },
    });
  });
})();
