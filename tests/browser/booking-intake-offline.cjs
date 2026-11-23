const { chromium } = require('playwright-core');
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const { pathToFileURL, fileURLToPath } = require('node:url');
const root = path.resolve(__dirname, '../..');
const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'upvan-booking-intake-'));
const artifacts = process.argv[2] || path.join(root, 'artifacts');
fs.mkdirSync(artifacts, { recursive: true });
let browser;
function render(mode) {
  const html = execFileSync(path.join(root, '.runtime/php84/php.exe'), ['-n', path.join(__dirname, 'render-booking-intake-fixture.php'), mode], { cwd: root, encoding: 'utf8' });
  const assets = pathToFileURL(path.join(root, 'public') + path.sep).href + 'assets/';
  const file = path.join(tmp, mode + '.html');
  fs.writeFileSync(file, html.replace(/((?:href|src)=["'])\/assets\//g, '$1' + assets));
  return pathToFileURL(file).href;
}
(async () => {
  browser = await chromium.launch({ channel: 'chrome', headless: true });
  const page = await browser.newPage();
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  await page.route(/^https?:/, route => route.abort());

  for (const width of [360, 768, 1440]) {
    await page.setViewportSize({ width, height: 980 });
    await page.goto(render('officer'));
    assert.equal(await page.locator('h1').innerText(), 'คำร้องที่ส่งแล้ว');
    assert.equal(await page.locator('tbody tr').count(), 0, 'offline officer page has no fabricated requests');
    assert(await page.getByText('ยังไม่มีคำร้องที่ส่งเข้ามา').isVisible());
    assert.equal(await page.locator('a[href^="/officer/booking-requests/detail"]').count(), 0);
    assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), 'officer queue has no horizontal overflow at ' + width + 'px');
    if (width === 1440) await page.screenshot({ path: path.join(artifacts, 'booking-officer-queue-offline-desktop.png'), fullPage: true });
    if (width === 360) await page.screenshot({ path: path.join(artifacts, 'booking-officer-queue-offline-mobile.png'), fullPage: true });
    await page.goto(render('officer-filled'));
    assert.equal(await page.locator('thead th').count(), 7);
    assert.equal(await page.getByText('ยังไม่แจ้งผู้ขอ',{exact:true}).count(), 1);
    assert.equal(await page.getByText('แจ้งผู้ขอแล้ว',{exact:true}).count(), 1);
    assert.equal(await page.locator('[data-booking-recall-button]').count(), 1);
    assert(await page.locator('[data-booking-recall-button]').isDisabled());
    assert.deepEqual(await page.locator('tbody tr td:first-child').allTextContents().then(items => items.map(item => item.trim())),
      ['รอตรวจสอบ', 'รอตรวจสอบ', 'รออนุมัติ', 'อนุมัติแล้ว', 'ไม่อนุมัติ']);
    assert.equal(await page.getByText('UPV-SYSTEM-FIXTURE').count(), 0);
    assert.equal(await page.getByText('ปลายทางทดสอบ', { exact: true }).count(), 4);
    assert.equal((await page.locator('tbody tr').first().locator('td').nth(2).innerText()).trim(), '—');
    assert.equal(await page.getByRole('button', { name: 'ลบ', exact: true }).first().isDisabled(), true);
    assert.equal(await page.getByRole('link', { name: 'ดูรายละเอียด' }).first().getAttribute('href'), '/officer/booking-requests/detail?id=1');
    assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1));
  }

  await page.setViewportSize({ width: 1440, height: 1000 });
  const deletePage = render('officer-delete-enabled');
  await page.goto(deletePage);
  const deleteForm = page.locator('[data-booking-delete]').first();
  // Intercept the synthetic endpoint. The fixture cannot reach the application or a database.
  await deleteForm.evaluate(form => { form.action = 'https://fixture.invalid/booking-delete'; });
  let deletePosts = 0;
  await page.route('https://fixture.invalid/booking-delete', async route => {
    deletePosts++;
    assert.equal(route.request().method(), 'POST');
    assert.match(route.request().postData(), /fixture-csrf/);
    assert.match(route.request().postData(), /name="booking_id"[\s\S]*?1/);
    assert.match(route.request().postData(), /name="booking_version"[\s\S]*?2/);
    fs.copyFileSync(fileURLToPath(render('officer-after-delete')), fileURLToPath(deletePage));
    await route.fulfill({ status: 200, contentType: 'application/json', headers: { 'access-control-allow-origin': '*' }, body: JSON.stringify({ status: 'deleted' }) });
  });
  const deleteButton = deleteForm.getByRole('button', { name: 'ลบ', exact: true });
  await deleteButton.click();
  await page.getByRole('heading', { name: 'ยืนยันการลบคำร้อง?' }).waitFor();
  assert(await page.getByText('คำร้องจะถูกนำออกจากรายการปกติ แต่ยังเก็บประวัติไว้', { exact: true }).isVisible());
  await page.getByRole('button', { name: 'ยกเลิก', exact: true }).click();
  assert.equal(deletePosts, 0);
  assert.equal(await page.locator('tbody tr').count(), 5);
  await deleteButton.click();
  await page.getByRole('button', { name: 'ยืนยันลบ', exact: true }).click();
  await page.locator('a[href="/officer/booking-requests/detail?id=1"]').waitFor({ state: 'detached' });
  assert.equal(deletePosts, 1);
  assert.equal(await page.locator('tbody tr').count(), 4);
  for (const width of [360, 1440]) {
    await page.setViewportSize({width,height:980});
    const recallPage = render('officer-recall-enabled');
    await page.goto(recallPage);
    const form = page.locator('[data-booking-recall]');
    await form.evaluate(el => { el.action = 'https://fixture.invalid/booking-recall'; });
    let requests = 0;
    const mockRecall = async route => {
      requests++;
      assert.equal(route.request().method(), 'POST');
      assert.match(route.request().postData(), /fixture-csrf/);
      assert.match(route.request().postData(), /name="booking_id"[\s\S]*?3/);
      assert.match(route.request().postData(), /name="booking_version"[\s\S]*?2/);
      fs.copyFileSync(fileURLToPath(render('officer-after-recall')),fileURLToPath(recallPage));
      await route.fulfill({json:{status:'saved'},headers:{'access-control-allow-origin':'*'}});
    };
    await page.route('https://fixture.invalid/booking-recall',mockRecall);
    const recall = form.getByRole('button',{name:'ยกเลิกการส่งต่อ',exact:true});
    await recall.click();
    await page.getByRole('heading',{name:'ยกเลิกการส่งต่อคำร้อง?'}).waitFor();
    assert(await page.getByText('คำร้องจะกลับมาให้เจ้าหน้าที่แก้ไข และสามารถส่งให้ผู้อำนวยการพิจารณาอีกครั้งได้',{exact:true}).isVisible());
    await page.getByRole('button',{name:'กลับ',exact:true}).click();
    assert.equal(requests,0);
    await recall.click();
    await page.getByRole('button',{name:'ยืนยันการดึงกลับ',exact:true}).click();
    await recall.waitFor({state:'detached'});
    assert.equal(requests,1);
    assert.equal((await page.locator('tbody tr').nth(2).locator('td').first().innerText()).trim(),'รอตรวจสอบ');
    assert.equal(await page.locator('tbody tr').count(),5);
    assert.equal(await page.getByRole('link',{name:'ดูรายละเอียด'}).nth(2).getAttribute('href'),'/officer/booking-requests/detail?id=3');
    await page.unroute('https://fixture.invalid/booking-recall',mockRecall);
  }
  await page.goto(render('requester'));
  assert.equal(await page.locator('#booking-submit-form').count(), 1);
  assert.equal(await page.locator('#submit-booking-request').isDisabled(), true, 'Requester button is visibly closed until Write Gate approval');
  assert.equal(await page.locator('#booking-submit-form').getAttribute('action'), '/bookings/submit');
  assert.equal(await page.locator('#requester_name').isEditable(), true, 'Requester keeps the existing A4 editable');
  for (const name of ['document_number_prefix', 'request_number_suffix']) {
    const field = page.locator('#' + name);
    assert.equal(await field.inputValue(), '');
    assert.equal(await field.isEditable(), true);
  }
  assert.equal(await page.locator('.approval-section input').first().isEnabled(), false, 'Requester cannot edit Section 3 in A4 preview');
  const desktopButton = await page.locator('#submit-booking-request').boundingBox();
  const desktopPaper = await page.locator('.paper').boundingBox();
  assert(desktopButton.y >= desktopPaper.y + desktopPaper.height, 'submission button sits below the A4 paper');
  assert(desktopButton.width < desktopPaper.width && Math.abs((desktopButton.x + desktopButton.width / 2) - (desktopPaper.x + desktopPaper.width / 2)) < 2, 'submission button is moderate width and centered');
  await page.emulateMedia({ media: 'print' });
  assert.equal(await page.locator('.booking-submit-tools').evaluate(el => getComputedStyle(el).display), 'none', 'submission button is hidden in print media');
  await page.goto(render('document'));
  assert.equal(await page.locator('[data-output="document_number_prefix"]').innerText(), 'กจ 007');
  assert.equal(await page.locator('[data-output="request_number_suffix"]').innerText(), '2569');
  assert.equal(await page.locator('.officer-request-meta').isVisible(), false);
  assert.equal(await page.locator('.paper').getByText('UPV-SYSTEM-FIXTURE').count(), 0);
  await page.emulateMedia({ media: 'screen' });
  await page.goto(render('requester'));
  await page.screenshot({ path: path.join(artifacts, 'booking-a4-requester-submit-gated.png'), fullPage: true });

  await page.goto(render('admin'));
  assert.equal(await page.locator('#booking-submit-form').count(), 1, 'Admin gets the gated own-submission form');
  assert.equal(await page.locator('#submit-booking-request').count(), 1, 'Admin sees the preview-only button');
  assert.equal(await page.locator('#submit-booking-request').isDisabled(), true, 'Admin cannot submit while the gate is closed');
  assert.equal(await page.getByText('ระบบยังไม่เปิดรับคำร้อง', { exact: true }).count(), 1);
  await page.setViewportSize({ width: 360, height: 850 });
  const mobileButton = await page.locator('#submit-booking-request').boundingBox();
  const mobilePaper = await page.locator('.paper').boundingBox();
  assert(mobileButton.y >= mobilePaper.y + mobilePaper.height, 'mobile button is below the A4 paper');
  assert(mobileButton.width < mobilePaper.width && Math.abs((mobileButton.x + mobileButton.width / 2) - 180) < 2, 'mobile button stays centered in the viewport at a moderate width');
  await page.screenshot({ path: path.join(artifacts, 'booking-a4-admin-preview-button-mobile.png'), fullPage: true });
  assert.deepEqual(errors, []);
  console.log('PASS: booking intake offline Chromium desktop/mobile checks; no HTTP requests or database connection.');
})().catch(error => { console.error(error); process.exitCode = 1; }).finally(async () => {
  if (browser) await browser.close();
  for (const file of fs.readdirSync(tmp)) fs.unlinkSync(path.join(tmp, file));
  fs.rmdirSync(tmp);
});
