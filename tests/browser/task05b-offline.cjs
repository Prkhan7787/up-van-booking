const { chromium } = require('playwright-core');
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const { pathToFileURL } = require('node:url');
const root = path.resolve(__dirname, '../..');
const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'upvan-05b-'));
const artifacts = process.argv[2];
if (artifacts) fs.mkdirSync(artifacts, { recursive: true });
let browser;
function render(mode) {
  const html = execFileSync(path.join(root, '.runtime/php84/php.exe'), ['-n', path.join(__dirname, 'render-task05b-fixture.php'), mode], { cwd: root, encoding: 'utf8' });
  const assets = pathToFileURL(path.join(root, 'public') + path.sep).href + 'assets/';
  const file = path.join(tmp, mode + '.html');
  const blank = pathToFileURL(path.join(tmp, 'blank.html')).href;
  const sample = pathToFileURL(path.join(tmp, 'completed.html')).href;
  fs.writeFileSync(file, html
    .replace(/((?:href|src)=["'])\/assets\//g, `$1${assets}`)
    .replace('href="/form-preview?example=completed"', `href="${sample}"`)
    .replace('href="/form-preview"', `href="${blank}"`));
  return pathToFileURL(file).href;
}
(async () => {
  browser = await chromium.launch({ channel: 'chrome', headless: true });
  const page = await browser.newPage();
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  // Offline fixtures only. Fail if any script attempts HTTP/database-backed routing.
  await page.route(/^https?:/, route => route.abort());
  for (const width of [360, 768, 1440]) {
    await page.setViewportSize({ width, height: 960 });
    await page.goto(render('logs'));
    assert.equal(await page.locator('h1').innerText(), 'System Log');
    assert.equal(await page.locator('tbody tr').count(), 25);
    assert.equal(await page.locator('form[method=post]').count(), 1, 'only the existing CSRF logout form');
    assert.equal(await page.locator('form[action="/admin/system-logs"]').getAttribute('method'), 'get');
    assert(!(await page.locator('body').innerText()).includes('DO_NOT_RENDER'));
    assert(await page.locator('tbody tr').first().innerText().then(text => text.includes('10/11/2026 04:05')));
    assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1));
    await page.locator('tbody details').first().locator('summary').click();
    assert(await page.locator('tbody details').first().evaluate(el => el.open));
    await page.evaluate(() => { scrollTo(0, 0); document.querySelectorAll('.overflow-x-auto').forEach(el => { el.scrollLeft = 0; }); });
    if (artifacts) await page.screenshot({ path: path.join(artifacts, `system-log-${width}.png`), fullPage: false });
    console.log(`PASS: System Log at ${width}px, safe metadata, GET filters and responsive shell`);
  }
  await page.setViewportSize({ width: 1440, height: 1300 });
  render('completed');
  await page.goto(render('blank'));
  await page.locator('.preview-tools summary').click();
  await page.getByRole('link', { name: 'ตัวอย่างคำขอ' }).click();
  assert.equal(await page.locator('body').getAttribute('data-form-state'), 'sample-readonly', 'the sample toolbar action loads read-only form state');
  assert.equal(await page.locator('#requester_name').isEditable(), false);
  await page.locator('.preview-tools summary').click();
  await page.getByRole('link', { name: 'เอกสารว่าง' }).click();
  assert.equal(await page.locator('body').getAttribute('data-form-state'), 'editable', 'blank toolbar action exits sample state');
  assert.equal(await page.locator('#requester_name').inputValue(), '', 'blank action clears sample fixture data');
  assert.equal(await page.locator('#requester_name').isEditable(), true, 'blank form restores role permissions');
  for (const mode of ['blank', 'requester', 'assigned', 'rejected', 'completed']) {
    await page.goto(render(mode));
    const sampleReadOnly = mode === 'completed';
    assert.equal(await page.locator('body').getAttribute('data-form-state'), sampleReadOnly ? 'sample-readonly' : 'editable');
    assert.equal(await page.locator('input[type=text], textarea').count() > 33, true);
    if (!sampleReadOnly) {
      assert.equal(await page.locator('#booking-submit-form').count(), 1, 'authenticated role sees the gated own-submission form');
      assert.equal(await page.locator('#submit-booking-request').isDisabled(), true, 'submission remains disabled while the write gate is closed');
    } else {
      assert.equal(await page.locator('#booking-submit-form').count(), 0, 'read-only sample has no submission form');
    }
    assert.equal(await page.locator('#submit-booking-request').count(), 1, 'every authenticated Preview role sees the button below A4');
    assert.equal(await page.locator('#submit-booking-request').isDisabled(), true, 'button stays disabled in the closed-gate offline fixture');
    const buttonBox = await page.locator('#submit-booking-request').boundingBox();
    const paperBox = await page.locator('.paper').boundingBox();
    assert(buttonBox.y >= paperBox.y + paperBox.height, 'button follows the actual A4 paper');
    const canEditSection3 = !sampleReadOnly && mode !== 'requester';
    assert.equal(await page.locator('#approval-outcome-approved').isEnabled(), canEditSection3, 'only an approver preview can edit Section 3; samples and requester previews are locked');
    assert.equal(await page.locator('#requester_name').isEditable(), !sampleReadOnly, 'sample text fields are read-only; blank preview stays editable');
    assert.equal(await page.locator('#document_number_prefix').isEditable(), !sampleReadOnly, 'document number follows the form state');
    assert.equal(await page.locator('#vehicle_registration').isEditable(), !sampleReadOnly, 'Section 2 follows the form state');
    assert.equal(await page.locator('#driver_signature').isEditable(), !sampleReadOnly, 'driver section follows the form state');
    assert.equal(await page.locator('#actual_return_time').isEditable(), !sampleReadOnly, 'driver return fields follow the form state');
    if (sampleReadOnly) {
      assert.equal(await page.locator('#requester_name').getAttribute('readonly'), '');
      assert.equal(await page.locator('input[type=radio]:not(:disabled)').count(), 0, 'no sample radio can be changed');
      assert.equal(await page.locator('#booking-submit-form').count(), 0, 'sample data cannot be submitted');
      const originalSampleName = await page.locator('#requester_name').inputValue();
      await page.locator('#requester_name').evaluate(el => el.dispatchEvent(new Event('beforeinput', { bubbles: true, cancelable: true })));
      assert.equal(await page.locator('#requester_name').inputValue(), originalSampleName, 'sample event handlers never replace fixture text');
    }
    assert(Math.abs(await page.locator('.paper').evaluate(el => el.getBoundingClientRect().width) - 793.7) < 1);
    await page.locator('.preview-tools summary').click();
    assert.equal(await page.locator('.preview-tools details a').count(), 2, 'blank form and single example action remain');
    await page.emulateMedia({ media: 'print' });
    assert.equal(await page.locator('.preview-tools').evaluate(el => getComputedStyle(el).display), 'none');
    assert.equal(await page.locator('.booking-submit-tools').evaluate(el => getComputedStyle(el).display), 'none', 'submit button is hidden in print');
    const pdf = await page.pdf({ preferCSSPageSize: true, printBackground: true });
    assert.equal((pdf.toString('latin1').match(/\/Type\s*\/Page\b/g) || []).length, 1, `${mode} prints one A4 page`);
    if (mode === 'completed' && artifacts) await page.screenshot({ path: path.join(artifacts, 'form-preview-readonly-sample-desktop.png'), fullPage: true });
    await page.emulateMedia({ media: 'screen' });
    console.log(`PASS: ${mode} A4 single page; sample read-only state is ${sampleReadOnly}; three-part document preserved`);
  }
  for (const width of [360, 768, 1440]) {
    await page.setViewportSize({ width, height: 960 });
    await page.goto(render('requester'));
    assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), 'paper scrolls within viewport');
    assert.equal(await page.locator('#requester_name').evaluate(el => getComputedStyle(el).color), 'rgb(21, 71, 160)');
  }
  await page.setViewportSize({ width: 360, height: 850 });
  await page.goto(render('requester-sample'));
  assert.equal(await page.locator('#requester_name').isEditable(), false, 'sample is read-only on mobile');
  assert.equal(await page.locator('#approval-outcome-approved').isEnabled(), false, 'sample radio remains disabled on mobile');
  assert.equal(await page.locator('#booking-submit-form').count(), 0, 'requester cannot submit sample data');
  await page.evaluate(() => { window.__printCalls = 0; window.print = () => { window.__printCalls += 1; }; });
  await page.locator('#print-document').click();
  assert.equal(await page.evaluate(() => window.__printCalls), 1, 'mobile Print A4 control remains usable');
  assert.notEqual(await page.locator('#requester_name').inputValue(), '', 'mobile print action preserves the sample data');
  if (artifacts) await page.screenshot({ path: path.join(artifacts, 'form-preview-readonly-sample-mobile.png'), fullPage: true });
  await page.goto(render('transfer'));
  assert.equal(await page.locator('body').getAttribute('data-form-state'), 'editable', 'Travel Estimate transfer never enters sample state');
  assert.equal(await page.locator('#requester_name').isEditable(), true, 'transferred request fields remain editable');
  assert.equal(await page.locator('#destination').inputValue(), '', 'map-only destination stays blank for user editing');
  assert.equal(await page.locator('#departure_time').inputValue(), '08:00', 'Travel Estimate dates and times remain populated');
  for (const role of ['requester', 'vehicle_officer', 'approver', 'admin']) {
    await page.goto(render(`role-${role}`));
    assert.equal(await page.locator('#requester_name').isEditable(), true, `${role} can edit Section 1 text`);
    assert.equal(await page.locator('#destination').isEditable(), true, `${role} can edit destination`);
    assert.equal(await page.locator('input[name="travel_type"]').first().isEnabled(), true, `${role} can select a travel type`);
    assert.equal(await page.locator('input[name="expense_source"]').first().isEnabled(), true, `${role} can select an expense option`);
    const canDecide = role === 'approver';
    assert.equal(await page.locator('#approval-outcome-approved').isEnabled(), canDecide, role + ' Section 3 permission follows decision authority');
    for (const id of ['document_number_prefix', 'vehicle_registration', 'driver_signature', 'actual_return_time']) {
      assert.equal(await page.locator('#' + id).isEditable(), true, role + ' can preview-edit non-approval document fields');
    }
    assert.equal(await page.locator('#rejection_reason').isEditable(), canDecide, role + ' approval reason follows decision authority');
  }
  await page.setViewportSize({ width: 1440, height: 1100 });
  await page.goto(render('role-admin'));
  const section3Box = await page.locator('.approval-section').evaluate(el => {
    const heading = el.querySelector('h2').getBoundingClientRect();
    const parent = el.getBoundingClientRect();
    const paddingTop = parseFloat(getComputedStyle(el).paddingTop);
    const section1Padding = getComputedStyle(document.querySelector('.requester-section')).paddingTop;
    return { headingTop: heading.top, headingBottom: heading.bottom, parentTop: parent.top, parentBottom: parent.bottom, paddingTop, section1Padding };
  });
  assert.equal(section3Box.paddingTop, parseFloat(section3Box.section1Padding), 'Section 3 heading uses the same top inset as Section 1');
  assert(section3Box.headingTop >= section3Box.parentTop + section3Box.paddingTop - 0.5, 'Section 3 heading clears the top boundary');
  assert(section3Box.headingBottom <= section3Box.parentBottom, 'Section 3 heading stays inside its section box');
  const destination = page.locator('#destination');
  const printDestination = destination.locator('xpath=following-sibling::*[1]');
  for (const text of ['เชียงใหม่', 'กรุงเทพมหานคร']) {
    await destination.fill(text);
    assert.equal(await printDestination.innerText(), text, `${text} remains intact in the print mirror`);
    assert.equal(await destination.evaluate(el => Math.abs(parseFloat(getComputedStyle(el).fontSize) - 20) < 0.1), true, `${text} starts at the same 15pt size as the A4 body text`);
  }
  const longPlace = 'ลำปาง, ถนนซุปเปอร์ไฮเวย์สายลำปาง-งาว, จังหวัดลำปาง, ประเทศไทย';
  await destination.fill(longPlace);
  assert.equal(await printDestination.innerText(), longPlace, 'long Thai destination is not truncated in A4 print content');
  assert.equal(await destination.evaluate(el => { const size = parseFloat(getComputedStyle(el).fontSize); return size >= 12.5 - 0.1 && size <= 20 + 0.1; }), true, 'long destination auto-fits between 12.5px minimum and the normal 15pt size');
  assert.equal(await destination.evaluate(el => el.clientHeight <= 20 && el.scrollHeight <= el.clientHeight + 1), true, 'destination remains a fixed single line instead of adding rows');
  assert.equal(await destination.evaluate(el => el.getBoundingClientRect().bottom <= el.parentElement.getBoundingClientRect().bottom + 0.5), true, 'destination text box stays inside the dotted field edge');
  assert.equal(await destination.evaluate(el => { const current = el.getBoundingClientRect(); const next = el.closest('.line').nextElementSibling.getBoundingClientRect(); return current.bottom <= next.top + 1; }), true, 'single-line screen destination does not move the following document field');
  await page.emulateMedia({ media: 'print' });
  assert.equal(await printDestination.evaluate(el => getComputedStyle(el).whiteSpace), 'nowrap', 'print destination stays on one line');
  assert.equal(await destination.getAttribute('wrap'), 'off', 'destination input does not create extra text lines');
  assert.equal(await printDestination.evaluate(el => getComputedStyle(el).overflow), 'hidden', 'print destination stays inside its existing field bounds');
  assert.equal(await printDestination.evaluate(el => getComputedStyle(el).fontSize), await destination.evaluate(el => getComputedStyle(el).fontSize), 'print text uses the same measured fitted size');
  assert.equal(await printDestination.evaluate(el => { const current = el.getBoundingClientRect(); const next = el.closest('.line').nextElementSibling.getBoundingClientRect(); return current.bottom <= next.top + 1; }), true, 'single-line print destination does not move the following document field');
  const longPdf = await page.pdf({ preferCSSPageSize: true, printBackground: true });
  assert((longPdf.toString('latin1').match(/\/Type\s*\/Page\b/g) || []).length >= 1, 'long destination renders to A4 without being omitted');
  await page.emulateMedia({ media: 'screen' });
  const documentNumber = page.locator('#document_number_prefix');
  const numberBox = page.locator('.document-number');
  assert.equal(await numberBox.locator('.document-number-label').innerText(), 'เลขที่', 'document number label stays on one line');
  assert.equal(await numberBox.locator('.document-number-divider').innerText(), '/', 'document number uses the requested slash separator');
  const suffix = page.locator('#request_number_suffix');
  assert.equal(await numberBox.evaluate(el => getComputedStyle(el).whiteSpace), 'nowrap', 'document number box cannot wrap the label');
  await documentNumber.fill('123456');
  assert.equal(await documentNumber.inputValue(), '123456', 'document number can be typed and edited');
  await suffix.fill('789');
  assert.equal(await suffix.inputValue(), '789', 'document number suffix after slash is a separate editable input');
  await documentNumber.fill('เลขที่ทดสอบข้อความที่ยาวเกินช่อง');
  assert.equal(await documentNumber.locator('xpath=following-sibling::*[1]').innerText(), 'เลขที่ทดสอบข้อความที่ยาวเกินช่อง', 'number field print mirror is synchronized');
  assert.equal(await documentNumber.evaluate(el => parseFloat(getComputedStyle(el).fontSize) >= 12.5 - 0.1), true, 'number field auto-fits without going below 12.5px');
  assert.equal(await documentNumber.evaluate(el => getComputedStyle(el.parentElement).alignItems), 'center', 'field text is vertically centered');
  await documentNumber.fill('1');
  assert.equal(await documentNumber.evaluate(el => Math.abs(parseFloat(getComputedStyle(el).fontSize) - 20) < 0.1), true, 'short text returns to the normal A4 15pt size');
  const shortField = page.locator('#vehicle_registration');
  await shortField.fill('ทดสอบ');
  assert.equal(await shortField.evaluate(el => Math.abs(parseFloat(getComputedStyle(el).fontSize) - 20) < 0.1), true, 'short text in another field remains at the normal A4 15pt size');
  const longField = page.locator('#requester_name');
  await longField.fill('ข้อความยาวสำหรับทดสอบการย่อขนาด '.repeat(8));
  assert.equal(await longField.evaluate(el => parseFloat(getComputedStyle(el).fontSize) < 20), true, 'only the field whose text overflows is reduced');
  assert.equal(await shortField.evaluate(el => Math.abs(parseFloat(getComputedStyle(el).fontSize) - 20) < 0.1), true, 'fitting one long field does not shrink other fields');
  await page.emulateMedia({ media: 'print' });
  await page.evaluate(() => window.dispatchEvent(new Event('beforeprint')));
  assert.equal(await shortField.locator('xpath=following-sibling::*[1]').evaluate(el => parseFloat(getComputedStyle(el).fontSize)), 20, 'beforeprint preserves body-sized text for short content despite hidden inputs');
  assert.equal(await shortField.locator('xpath=following-sibling::*[1]').evaluate(el => el.style.fontSize), '15pt', 'print mirror retains the measured 15pt size rather than shrinking hidden input content');
  if (artifacts) {
    const paper = await page.locator('.paper').boundingBox();
    await page.screenshot({ path: path.join(artifacts, 'form-preview-a4-print-font.png'), clip: { x: paper.x, y: paper.y, width: paper.width, height: 330 } });
  }
  await page.emulateMedia({ media: 'screen' });
  assert.equal(await numberBox.evaluate(el => { const box = el.getBoundingClientRect(); const title = document.querySelector('#document-title').getBoundingClientRect(); return box.right <= title.right + 1 && box.top >= title.bottom - 1; }), true, 'number box remains in the upper-right header area without extending into the heading');
  await documentNumber.fill('123');
  await suffix.fill('456');
  await longField.fill('ทดสอบ');
  await longField.evaluate(el => el.blur());
  if (artifacts) {
    const paper = await page.locator('.paper').boundingBox();
    await page.screenshot({ path: path.join(artifacts, 'form-preview-a4-fields.png'), clip: { x: paper.x, y: paper.y, width: paper.width, height: 330 } });
  }
  await destination.fill('ข้อความปลายทางที่ผู้ใช้แก้ไขเอง');
  assert.equal(await printDestination.innerText(), 'ข้อความปลายทางที่ผู้ใช้แก้ไขเอง', 'manual Thai edits sync to print');
  await destination.focus();
  await destination.pressSequentially(' เชียงใหม่');
  assert.equal(await destination.evaluate(el => document.activeElement === el), true, 'typing keeps destination focus');
  assert.equal(await destination.inputValue(), 'ข้อความปลายทางที่ผู้ใช้แก้ไขเอง เชียงใหม่', 'typing is not overwritten by autofit');
  const pastedText = 'ข้อความที่วางภาษาไทย '.repeat(20);
  await destination.evaluate((el, text) => { el.value = text; el.dispatchEvent(new InputEvent('input', { bubbles: true, inputType: 'insertFromPaste', data: text })); }, pastedText);
  assert.equal(await printDestination.innerText(), pastedText, 'pasted text updates complete print mirror');
  await destination.focus();
  await destination.press('Control+A');
  await destination.press('Backspace');
  assert.equal(await destination.inputValue(), '', 'deleting text remains possible');
  assert.equal(await destination.evaluate(el => Math.abs(parseFloat(getComputedStyle(el).fontSize) - 20) < 0.1), true, 'font returns to the normal A4 15pt size after text is shortened');
  const overPageText = 'ปลายทางทดสอบภาษาไทย '.repeat(500);
  await destination.fill(overPageText);
  assert.equal(await destination.inputValue(), overPageText, 'long destination is not silently capped');
  assert.equal(await printDestination.innerText(), overPageText, 'long destination remains complete in print content');
  assert.equal(await page.locator('#print-warning').isVisible(), true, 'an entry that extends beyond A4 raises a visible warning');
  assert.equal(await destination.evaluate(el => { const size = parseFloat(getComputedStyle(el).fontSize); return size >= 12.5 - 0.1 && size <= 20 + 0.1; }), true, 'over-page textarea retains the 12.5px minimum while showing the overflow warning');
  assert.equal(await destination.evaluate(el => el.scrollHeight <= el.clientHeight + 1), true, 'overlong destination does not increase the field height');
  assert.deepEqual(errors, []);
  console.log('PASS: Task 05B offline browser/A4 regression; no web server or database');
})().catch(error => { console.error(error); process.exitCode = 1; }).finally(async () => {
  if (browser) await browser.close();
  for (const file of fs.readdirSync(tmp)) fs.unlinkSync(path.join(tmp, file));
  fs.rmdirSync(tmp);
});

