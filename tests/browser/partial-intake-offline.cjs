const { chromium } = require('playwright-core');
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const http = require('node:http');
const root = path.resolve(__dirname, '../..');
const html = execFileSync(path.join(root, '.runtime/php84/php.exe'), ['-n', path.join(__dirname, 'render-partial-intake-fixture.php')], { cwd: root, encoding: 'utf8' });
const officerHtml = execFileSync(path.join(root, '.runtime/php84/php.exe'), ['-n', path.join(__dirname, 'render-partial-intake-fixture.php'), 'officer'], { cwd: root, encoding: 'utf8' });
const server = http.createServer((req, res) => {
  if (req.method !== 'GET') { res.writeHead(405); return res.end('No backend writes in fixture server'); }
  if (req.url === '/officer-fixture') { res.setHeader('Content-Type', 'text/html; charset=utf-8'); return res.end(officerHtml); }
  if (req.url === '/') { res.setHeader('Content-Type', 'text/html; charset=utf-8'); return res.end(html); }
  const asset = req.url.split('?')[0];
  if (!/^\/assets\/[a-z0-9.-]+$/.test(asset)) { res.writeHead(404); return res.end(); }
  const file = path.join(root, 'public', asset);
  if (!fs.existsSync(file)) { res.writeHead(404); return res.end(); }
  res.setHeader('Content-Type', asset.endsWith('.js') ? 'text/javascript' : asset.endsWith('.css') ? 'text/css' : 'image/svg+xml');
  res.end(fs.readFileSync(file));
});
let browser;
(async () => {
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  const base = 'http://127.0.0.1:' + server.address().port;
  browser = await chromium.launch({ channel: 'chrome', headless: true });
  const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
  const errors = []; page.on('pageerror', e => errors.push(e.message));
  await page.addInitScript(() => { window.__printCalls = 0; window.print = () => { window.__printCalls++; }; });
  let posted = 0, payload = '';
  await page.route('**/bookings/submit', async route => {
    posted++; payload = route.request().postData();
    await route.fulfill({ status: 201, contentType: 'application/json', body: JSON.stringify({ request_number: 'OFFLINE-UI-FIXTURE', status: 'submitted', information_complete: false, schedule_reserved: false }) });
  });
  for (const mode of ['blank', 'partial', 'full']) {
    await page.goto(base);
    assert.equal(await page.locator('#submit-booking-request').isEnabled(), true, 'fixture preview of future approved gate supports blank intake');
    assert.equal(await page.locator('#approval-outcome-approved').isEnabled(), false);
    assert.equal(await page.locator('#requester_name').getAttribute('required'), null);
    if (mode !== 'blank') await page.locator('#purpose').fill('OFFLINE UI TEST');
    if (mode === 'partial') await page.locator('#departure_date').fill('20 พฤศจิกายน 2569');
    if (mode === 'full') {
      for (const [id, value] of Object.entries({ requester_name: 'Offline Requester', requester_phone: '0000000000', destination: 'Offline destination', passenger_count: '4', departure_date: '20 พฤศจิกายน 2569', departure_time: '08:00', return_date: '20 พฤศจิกายน 2569', return_time: '17:00' })) await page.locator('#' + id).fill(value);
      await page.locator('[name=vehicle_id]').evaluate(el => { el.value = '7'; });
      await page.locator('[name=travel_type][value=outside_province]').check();
      await page.locator('[name=expense_source][value=institute_budget]').check();
    }
    const before = posted;
    await page.locator('#submit-booking-request').click();
    await page.getByRole('button', { name: 'ส่งคำร้อง', exact: true }).click();
    await page.getByText('เลขคำร้อง OFFLINE-UI-FIXTURE', { exact: false }).waitFor();
    assert.equal(posted, before + 1, mode + ' submits once without printing or PDF');
    assert.equal(await page.evaluate(() => window.__printCalls), 0);
    assert(payload.includes('name="purpose"'));
    assert.equal(await page.locator('#submit-booking-request').isEnabled(), false, 'successful submission cannot be repeated');
    console.log('PASS: ' + mode + ' A4 submission with mocked network only; no database');
  }
  await page.setViewportSize({ width: 360, height: 850 }); await page.goto(base);
  assert.equal(await page.locator('#submit-booking-request').isEnabled(), true);
  await page.emulateMedia({ media: 'print' });
  assert.equal(await page.locator('.booking-submit-tools').evaluate(el => getComputedStyle(el).display), 'none');
  await page.emulateMedia({ media: 'screen' });
  await page.goto(base + '/officer-fixture');
  const registration = page.locator('#vehicle_registration');
  assert.equal(await registration.inputValue(), 'TEST-001');
  assert(await registration.isEditable());
  assert.equal(await page.locator('#officer-requested-vehicle').count(), 0);
  await registration.fill('ทดสอบทะเบียน พะเยา');
  await page.emulateMedia({media:'print'});
  assert.equal(await registration.evaluate(el => el.nextElementSibling.textContent), 'ทดสอบทะเบียน พะเยา');
  await page.emulateMedia({media:'screen'});
  assert.equal(await page.locator('#approval-outcome-approved').isEnabled(), false);
  console.log('PASS: officer document text updates registration and print mirror; no POST');
  assert.deepEqual(errors, []);
  console.log('PASS: mobile/print separation; no real gates opened, no real HTTP backend or database writes');
})().catch(e => { console.error(e); process.exitCode = 1; }).finally(async () => { if (browser) await browser.close(); await new Promise(resolve => server.close(resolve)); });
