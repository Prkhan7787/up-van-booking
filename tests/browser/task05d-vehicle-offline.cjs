const { chromium } = require('playwright-core');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { pathToFileURL } = require('node:url');
const assert = require('node:assert/strict');

const root = path.resolve(__dirname, '../..');
const temp = fs.mkdtempSync(path.join(os.tmpdir(), 'upvan-vehicles-'));
let browser;
(async () => {
  const html = execFileSync(path.join(root, '.runtime/php84/php.exe'), ['-n', path.join(__dirname, 'render-admin-vehicles-fixture.php')], { cwd: root, encoding: 'utf8' });
  const provinceHtml = execFileSync(path.join(root, '.runtime/php84/php.exe'), ['-n', path.join(__dirname, 'render-admin-vehicles-fixture.php'), 'province'], { cwd: root, encoding: 'utf8' });
  const assets = pathToFileURL(path.join(root, 'public') + path.sep).href + 'assets/';
  const file = path.join(temp, 'vehicles.html');
  fs.writeFileSync(file, html.replace(/((?:href|src)=["'])\/assets\//g, `$1${assets}`));
  const provinceFile = path.join(temp, 'vehicles-province.html');
  fs.writeFileSync(provinceFile, provinceHtml.replace(/((?:href|src)=["'])\/assets\//g, `$1${assets}`));
  browser = await chromium.launch({ channel: 'chrome', headless: true });
  const page = await browser.newPage({ reducedMotion: 'reduce' });
  const errors = [];
  page.on('pageerror', e => errors.push(e.message));
  for (const width of [360, 375, 768, 1024, 1440]) {
    await page.setViewportSize({ width, height: 900 });
    await page.goto(pathToFileURL(file).href);
    assert.equal(await page.getByRole('heading', { name: 'จัดการรถตู้' }).count(), 1);
    assert.equal(await page.locator('main input[placeholder]').count(), 0);
    for (const name of ['label','registration_number','passenger_capacity']) assert.equal(await page.locator(`form[data-vehicle-action="create"] [name="${name}"]`).inputValue(), '');
    assert((await page.locator('main').innerText()).includes('กข 1234'));
    assert.equal(await page.locator('main form[action="/admin/vehicles"][method="post"]').count(), 3);
    const updateId = await page.locator('form[data-vehicle-action="update"] input[name="vehicle_id"]').inputValue();
    assert.equal(await page.locator('form[data-vehicle-action="delete"] input[name="vehicle_id"]').inputValue(), updateId);
    assert.equal(await page.locator('form[data-vehicle-action="delete"] button').innerText(), 'ลบรถตู้');
    assert((await page.locator('form[data-vehicle-action="delete"]').getAttribute('data-admin-confirm')).includes('รักษาประวัติเดิม'));
    assert.equal(await page.locator('main fieldset:disabled').count(), 1);
    assert.equal(await page.locator('main button[type="submit"]:not([disabled])').count(), 0);
    assert.equal(await page.locator('input[name="registration_province"]').count(), 0);
    assert.equal(await page.locator('nav a[href="/admin/vehicles"]').count(), 1);
    assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), `Vehicle page overflow at ${width}px`);
    console.log(`PASS: Admin vehicle page at ${width}px, offline fixture only`);
    await page.goto(pathToFileURL(provinceFile).href);
    assert.equal(await page.locator('input[name="registration_province"]').count(), 2);
    assert.equal(await page.locator('form[data-vehicle-action="update"] input[name="registration_province"]').inputValue(), 'พะเยา');
    assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), `Province-enabled vehicle page overflow at ${width}px`);
    console.log(`PASS: Province-enabled Admin vehicle page at ${width}px, offline fixture only`);
  }
  for (const scenario of ['cards005', 'cards006']) {
    const cardHtml = execFileSync(path.join(root, '.runtime/php84/php.exe'), ['-n', path.join(__dirname, 'render-admin-vehicles-fixture.php'), scenario], { cwd: root, encoding: 'utf8' });
    const cardFile = path.join(temp, `${scenario}.html`);
    fs.writeFileSync(cardFile, cardHtml.replace(/((?:href|src)=["'])\/assets\//g, `$1${assets}`));
    for (const width of [360, 375, 768, 1024, 1440]) {
      await page.setViewportSize({ width, height: 1000 });
      await page.goto(pathToFileURL(cardFile).href);
      const cards = page.locator('[data-vehicle-cards] article');
      assert.equal(await cards.count(), 2);
      assert(!/เปิดใช้งาน|ปิดใช้งาน/.test(await page.locator('[data-vehicle-cards]').innerText()));
      const columns = await page.locator('[data-vehicle-cards]').evaluate(el => getComputedStyle(el).gridTemplateColumns.split(' ').length);
      assert.equal(columns, width >= 768 ? 2 : 1);
      for (let i = 0; i < 2; i++) {
        const card = cards.nth(i);
        const edit = card.locator('[data-vehicle-action="update"]');
        assert.deepEqual(await edit.locator('label').evaluateAll(labels => labels.map(el => el.firstChild.textContent.trim())), ['ชื่อรถ', 'หมายเลขทะเบียน', 'จังหวัดทะเบียน', 'จำนวนที่นั่ง']);
        const province = edit.locator('input[name="registration_province"], input[aria-describedby^="vehicle-province-pending-"]');
        assert.equal(await province.isDisabled(), scenario === 'cards005');
        if (scenario === 'cards006') assert.equal(await province.inputValue(), i === 0 ? 'พะเยา' : 'เชียงใหม่');
        else assert((await edit.innerText()).includes('รอ Migration 006'));
        for (const name of ['label', 'registration_number', 'passenger_capacity']) assert(await edit.locator(`[name="${name}"]`).isEnabled());
        const posted = await edit.evaluate(el => Object.fromEntries(new FormData(el)));
        assert.equal(posted.vehicle_id, String(i + 1));
        assert.equal(Object.hasOwn(posted, 'registration_province'), scenario === 'cards006');
        assert.equal(await card.locator('[data-vehicle-action="delete"] [name="vehicle_id"]').inputValue(), posted.vehicle_id);
        assert.equal(await card.locator('[data-vehicle-action="delete"] svg').count(), 1);
        const buttonBox = await edit.locator('button').boundingBox();
        const formBox = await edit.boundingBox();
        assert(Math.abs(buttonBox.width - formBox.width) <= 2);
      }
      assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1));
      console.log(`PASS: ${scenario} exact card fields/layout at ${width}px; no write submitted`);
    }
  }
  for (const scenario of ['load-error', 'save-error']) {
    const scenarioHtml = execFileSync(path.join(root, '.runtime/php84/php.exe'), ['-n', path.join(__dirname, 'render-admin-vehicles-fixture.php'), scenario], { cwd: root, encoding: 'utf8' });
    const scenarioFile = path.join(temp, `${scenario}.html`);
    fs.writeFileSync(scenarioFile, scenarioHtml.replace(/((?:href|src)=["'])\/assets\//g, `$1${assets}`));
    await page.goto(pathToFileURL(scenarioFile).href);
    await page.waitForFunction(() => typeof window.Swal === 'function');
    if (scenario === 'load-error') {
      for (let refresh = 0; refresh < 2; refresh++) {
        assert.equal(await page.locator('[data-vehicle-load-error]').count(), 1);
        assert.equal(await page.locator('.swal2-popup').count(), 0);
        await page.reload();
      }
      console.log('PASS: GET load failure remains inline after F5 without a save popup');
    } else {
      await page.locator('.swal2-popup').waitFor({ state: 'visible' });
      assert.equal(await page.locator('.swal2-title').innerText(), 'บันทึกไม่สำเร็จ');
      assert.equal(await page.locator('.swal2-html-container').innerText(), 'หมายเลขทะเบียนซ้ำ');
      console.log('PASS: POST save flash displays the intended safe SweetAlert');
    }
  }
  assert.deepEqual(errors, []);
})().catch(e => { console.error(e); process.exitCode = 1; }).finally(async () => {
  if (browser) await browser.close();
  fs.rmSync(temp, { recursive: true, force: true });
});
