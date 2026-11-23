const { chromium } = require('playwright-core');
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const path = require('node:path');

const root = path.resolve(__dirname, '../..');
const origin = 'https://approval-fixture.invalid';
function markup(mode) {
  return execFileSync(path.join(root, '.runtime/php84/php.exe'), ['-n', path.join(__dirname, 'render-booking-approval-fixture.php'), mode], { cwd: root, encoding: 'utf8' });
}

(async () => {
  const browser = await chromium.launch({ channel: 'chrome', headless: true });
  try {
    const page = await browser.newPage({ viewport: { width: 1440, height: 960 } });
    const errors = [];
    const posts = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/*', async route => {
      const url = new URL(route.request().url());
      if (url.origin !== origin) return route.abort();
      if (url.pathname.startsWith('/assets/')) {
        return route.fulfill({ path: path.join(root, 'public', url.pathname) });
      }
      if (route.request().method() === 'POST') {
        posts.push([url.pathname, route.request().postData()]);
        return route.fulfill({ json: { status: 'saved' } });
      }
      const mode = url.pathname === '/director/approval-requests' ? 'queue' : url.pathname.slice(1);
      return route.fulfill({ body: markup(mode), contentType: 'text/html; charset=UTF-8' });
    });
    for (const width of [360, 1440]) {
      await page.setViewportSize({ width, height: 960 });
      await page.goto(origin + '/queue');
      assert.equal(await page.locator('thead th').count(), 5);
      assert.match(await page.locator('tbody').innerText(), /รออนุมัติ.*ผู้ขอทดสอบ/s);
      assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1));
    }
    await page.goto(origin + '/officer');
    assert(await page.locator('#requester_name').isEditable());
    assert(await page.locator('#approval-outcome-approved').isDisabled());
    const forward = page.getByRole('button', { name: 'ส่งคำร้องให้ผู้อำนวยการอนุมัติ' });
    assert.equal(await page.locator('#save-officer-booking-edit, #booking-section3-draft').count(), 0);
    await page.locator('#purpose').fill('แก้ก่อนส่ง');
    for (const width of [360, 1440]) {
      await page.setViewportSize({width, height:960});
      const destination = page.locator('#destination');
      for (const [name,value] of Object.entries({
        requester_name:'ผู้ขอ',requester_phone:'ติดต่อภายหลัง',passenger_count:'หลายคน',
        departure_date:'วันจันทร์',departure_time:'เวลาเช้า',return_date:'วันเดียวกัน',return_time:'เย็น'
      })) await page.locator('#' + name).fill(value);
      await page.locator('[name="travel_type"]').first().check();
      await page.locator('[name="expense_source"]').first().check();
      await destination.fill('   ');
      await forward.click();
      assert.deepEqual(await page.locator('#booking-forward [aria-invalid="true"]').evaluateAll(elements=>elements.map(el=>el.name)), ['destination']);
      assert.equal(await destination.evaluate(el=>getComputedStyle(el).outlineColor), 'rgb(180, 35, 24)');
      assert.equal(await page.locator('.a4-field-error').count(), 0);
      assert.equal(posts.length, 0);
      await page.emulateMedia({media:'print'});
      assert.notEqual(await destination.evaluate(el=>getComputedStyle(el).outlineColor), 'rgb(180, 35, 24)');
      await page.emulateMedia({media:'screen'});
      await destination.fill('รอระบุสถานที่');
      assert.equal(await page.locator('#booking-forward [aria-invalid="true"]').count(), 0);
      await forward.click();
      await page.getByRole('button', {name:'ยกเลิก',exact:true}).click();
      assert.equal(posts.length, 0);
    }
    await page.route('**/officer/booking-requests/forward', route => route.fulfill({
      status: 409, json: { message: 'ช่วงเวลาทับซ้อน' }
    }), { times: 1 });
    await forward.click();
    await page.getByRole('button', { name: 'ยืนยัน', exact: true }).click();
    await page.getByRole('heading', { name: 'ยังทำรายการไม่ได้' }).waitFor();
    await page.getByRole('button', { name: 'OK', exact: true }).click();
    assert(page.url().endsWith('/officer'));
    assert.equal(await page.locator('#purpose').inputValue(), 'แก้ก่อนส่ง');
    await forward.click();
    await page.getByRole('button', { name: 'ยืนยัน', exact: true }).click();
    await page.getByRole('heading', { name: 'ส่งคำร้องให้ผู้อำนวยการแล้ว' }).waitFor();
    assert.equal(posts.length, 1);
    assert.match(posts[0][1], /fixture-token/);
    assert.match(posts[0][1], /แก้ก่อนส่ง/);
    assert.match(posts[0][1], /เวลาเช้า/);
    assert.match(posts[0][1], /name="booking_version"[\s\S]*?6/);
    assert(page.url().endsWith('/officer'));
    await Promise.all([page.waitForURL('**/officer/booking-requests'), page.getByRole('button', { name: 'ตกลง', exact: true }).click()]);
    await page.goto(origin + '/admin');
    assert(await page.locator('#approver_printed_name').isEditable());
    assert.equal(await page.locator('#approver_printed_name').evaluate(el => el.form.id), 'booking-forward');
    assert.equal(await page.locator('[data-decision-choice]').count(), 0);
    await page.goto(origin + '/director-gated');
    assert(await page.getByRole('button', { name: 'ส่งผลให้เจ้าหน้าที่รถ', exact: true }).isDisabled());
    await page.goto(origin + '/director');
    for (const width of [320, 360, 800, 801, 1440]) {
      await page.setViewportSize({width, height:960});
      await page.locator('.paper-viewport').evaluate(el => { el.scrollLeft = el.scrollWidth; });
      const paper = await page.locator('.paper').boundingBox();
      const send = await page.locator('[data-publish-decision]').boundingBox();
      assert(send.y - paper.y - paper.height >= 12 && send.y - paper.y - paper.height <= 16);
      assert(Math.abs(send.x + send.width - (paper.x + paper.width)) <= 1);
      assert(send.x >= 0 && send.x + send.width <= width);
    }
    const publish = page.getByRole('button',{name:'ส่งผลให้เจ้าหน้าที่รถ',exact:true});
    assert.equal(await publish.evaluate(el=>getComputedStyle(el).backgroundColor),'rgb(76, 29, 149)');
    assert(!(await page.locator('#requester_name').isEditable()));
    assert.equal(await page.locator('[data-output="vehicle_registration"]').innerText(), 'กท3651 พะเยา');
    assert.equal(await page.getByRole('button',{name:'ส่งคำร้องให้ผู้อำนวยการอนุมัติ'}).count(),0);
    await publish.click();
    await page.getByRole('heading',{name:'กรุณาเลือกผลพิจารณาในส่วนที่ 3',exact:true}).waitFor();
    await page.getByRole('button',{name:'OK',exact:true}).click();
    assert.equal(posts.length,1);
    await page.locator('#approval-outcome-approved').check();
    await page.locator('#approval-outcome-rejected').check();
    assert(!(await page.locator('#approval-outcome-approved').isChecked()));
    await page.locator('#rejection_reason').fill('เหตุผลทดสอบ');
    await publish.click();
    await page.getByRole('heading',{name:'ยืนยันส่งผลให้เจ้าหน้าที่รถ?',exact:true}).waitFor();
    assert.equal(await page.getByRole('dialog').getByRole('textbox').count(),0);
    await page.getByRole('button', { name: 'ยืนยัน', exact: true }).click();
    await page.getByRole('heading', { name: 'บันทึกผลและส่งกลับเจ้าหน้าที่แล้ว' }).waitFor();
    assert.match(posts[1][1], /name="approval_outcome"[\s\S]*?rejected/);
    assert.match(posts[1][1], /เหตุผลทดสอบ/);
    assert.doesNotMatch(posts[1][1], /name="requester_name"/);
    await Promise.all([page.waitForURL('**/director/approval-requests'), page.getByRole('button', { name: 'ตกลง', exact: true }).click()]);
    await page.goto(origin + '/director');
    await page.locator('#approver_printed_name').fill('ข้อความก่อนบันทึก');
    await page.emulateMedia({ media: 'print' });
    assert(!(await page.locator('#booking-director-decision').isVisible()));
    assert(await page.locator('.paper').isVisible());
    assert.equal(await page.locator('#approver_printed_name + .print-value').evaluate(el => getComputedStyle(el).visibility), 'hidden');
    const pdf = await page.pdf({ format: 'A4', scale: 1 });
    assert.deepEqual([...pdf.toString('latin1').matchAll(/\/Count (\d+)/g)].map(match => Number(match[1])), [1]);
    await page.emulateMedia({media:'screen'});
    await page.goto(origin + '/director-admin');
    assert(!(await page.locator('#requester_name').isEditable()));
    assert(await page.locator('[data-output="vehicle_registration"]').isVisible());
    assert.equal(await page.locator('#booking-director-decision button').count(), 1);
    const saveDraft = page.getByRole('button',{name:'บันทึกร่างส่วนที่ 3',exact:true});
    assert.equal(await saveDraft.count(),0);
    for (const role of ['director-admin','director']) {
      for (const [label,outcome] of [['อนุมัติ','approved'],['ไม่อนุมัติ','rejected']]) {
        await page.goto(origin + '/' + role);
        await page.locator('#approver_printed_name').fill('');
        await page.locator('#rejection_reason').fill('');
        await page.locator('#approval-outcome-'+outcome).check();
        const decision = publish;
        const before = posts.length;
        await decision.click();
        await page.getByRole('button',{name:'ยกเลิก',exact:true}).click();
        assert.equal(posts.length,before);
        await decision.click();
        await page.getByRole('heading',{name:'ยืนยันส่งผลให้เจ้าหน้าที่รถ?',exact:true}).waitFor();
        assert.equal(await page.getByRole('dialog').getByRole('textbox').count(),0);
        await page.getByRole('button',{name:'ยืนยัน',exact:true}).click();
        await page.getByRole('heading',{name:'บันทึกผลและส่งกลับเจ้าหน้าที่แล้ว'}).waitFor();
        assert.equal(posts.length,before+1);
        assert.equal(posts.at(-1)[0],'/director/approval-requests/decision');
        assert(posts.at(-1)[1].includes(outcome));
        await Promise.all([page.waitForURL('**/director/approval-requests'),page.getByRole('button',{name:'ตกลง',exact:true}).click()]);
      }
    }
    await page.goto(origin + '/director-admin-gated');
    assert.equal(await saveDraft.count(),0);
    await page.goto(origin + '/director-delegate');
    assert.equal(await saveDraft.count(),0);
    assert(await publish.isEnabled());
    await page.goto(origin + '/officer-result');
    assert.equal(await page.getByRole('button',{name:'ส่งคำร้องให้ผู้อำนวยการอนุมัติ'}).count(),0);
    const notify = page.getByRole('button',{name:'ส่งผลให้ผู้ขอใช้รถ',exact:true});
    await page.emulateMedia({media:'print'});
    assert(!(await notify.isVisible()));
    await page.emulateMedia({media:'screen'});
    await notify.click();
    await page.getByRole('heading',{name:'ยืนยันส่งผลให้ผู้ขอใช้รถ?'}).waitFor();
    await page.getByRole('button',{name:'ยืนยัน',exact:true}).click();
    await page.getByRole('heading',{name:'แจ้งผลให้ผู้ขอใช้รถแล้ว'}).waitFor();
    assert.equal(posts.at(-1)[0],'/officer/booking-requests/notify-requester');
    assert.match(posts.at(-1)[1], /fixture-token/);
    await Promise.all([page.waitForURL('**/officer/booking-requests'),page.getByRole('button',{name:'ตกลง',exact:true}).click()]);
    await page.goto(origin + '/owner-list');
    assert.match(await page.locator('tbody tr').first().innerText(),/อยู่ระหว่างดำเนินการ/);
    assert.doesNotMatch(await page.locator('tbody tr').first().innerText(),/อนุมัติแล้ว/);
    assert.match(await page.locator('tbody tr').last().innerText(),/แจ้งผู้ขอแล้ว/);
    await page.goto(origin + '/owner-wait');
    assert(await page.locator('.owner-pending-result').isVisible());
    assert(!await page.locator('#approval-outcome-rejected').isChecked());
    assert.equal(await page.getByText('เหตุผลทดสอบจากผลพิจารณา',{exact:true}).count(),0);
    await page.goto(origin + '/owner-notified');
    assert(await page.locator('#approval-outcome-rejected').isChecked());
    assert(await page.locator('#approval-outcome-rejected').isDisabled());
    assert(await page.getByText('เหตุผลทดสอบจากผลพิจารณา',{exact:true}).isVisible());
    assert.deepEqual(errors, []);
    console.log('PASS: offline Chromium director queue at 360/1440 px, permissions, confirmation, version, rejection reason, redirect, A4 print.');
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
