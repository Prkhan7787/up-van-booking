const { chromium } = require('playwright-core');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');

// Empty browser documents only: no HTTP server, database or live POST.
(async () => {
  const browser = await chromium.launch({ channel: 'chrome', headless: true });
  try {
    const page = await browser.newPage();
    const script = fs.readFileSync(path.join(__dirname, '../../public/assets/app.js'), 'utf8');
    for (const [html, expected] of [
      ['<div data-admin-notice="success" data-admin-notice-text="ลบรถตู้สำเร็จ"></div>', 'ลบรถตู้สำเร็จ'],
      ['<div data-admin-notice="success"></div>', 'บันทึกสำเร็จ'],
      ['', null],
      ['<div data-admin-notice="success" data-admin-notice-text="&lt;img src=x onerror=alert(1)&gt;"></div>', '<img src=x onerror=alert(1)>'],
    ]) {
      await page.goto('about:blank');
      await page.setContent(html);
      await page.evaluate(() => { window.calls = []; window.Swal = { fire: options => { window.calls.push(options); return Promise.resolve({ isConfirmed: true }); } }; });
      await page.addScriptTag({ content: script });
      const calls = await page.evaluate(() => window.calls);
      assert.equal(calls.length, expected === null ? 0 : 1);
      if (expected !== null) {
        assert.equal(calls[0].titleText, expected);
        assert.equal(calls[0].html, undefined);
        assert.equal(calls[0].title, undefined);
      }
    }
    console.log('PASS: delete popup, shared Admin fallback, refresh without flash and plain-text XSS protection; offline browser only.');
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
