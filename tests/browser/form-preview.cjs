const { chromium } = require('playwright-core');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');

let browser;
(async () => {
  browser = await chromium.launch({ channel: 'chrome', headless: true });
  const page = await browser.newPage({ viewport: { width: 1280, height: 1300 }, deviceScaleFactor: 1, hasTouch: true });
  const out = path.resolve(__dirname, '../../docs/form-preview');
  fs.mkdirSync(out, { recursive: true });
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  page.on('console', msg => { if (msg.type() === 'error') errors.push(msg.text()); });
  const response = await page.goto('http://127.0.0.1:8088/form-preview');
  assert.equal(response.status(), 200);
  await page.evaluate(() => document.fonts.ready);
  await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
  const destination = page.locator('#destination');
  const defaultDestinationFont = await destination.evaluate(el => getComputedStyle(el).fontSize);
  await destination.fill('');
  assert.equal(await page.locator('#print-warning').isVisible(), false, 'blank destination has no overflow warning');
  await destination.fill('ก');
  assert.equal(await page.locator('#print-warning').isVisible(), false, 'one character fits without a false overflow warning');
  assert.equal(await destination.evaluate(el => getComputedStyle(el).fontSize), defaultDestinationFont, 'one character keeps the default A4 font size');
  await destination.fill('ลำปาง');
  assert.equal(await page.locator('#print-warning').isVisible(), false, 'short Thai destination fits');
  const autoFitText = 'ก'.repeat(24);
  await destination.fill(autoFitText);
  assert.equal(await page.locator('#print-warning').isVisible(), false, 'long text that auto-fits the field does not warn');
  await destination.fill('ก'.repeat(300));
  assert.equal(await page.locator('#print-warning').isVisible(), true, 'warning appears only when text still overflows after auto-fit');
  assert.equal(await destination.inputValue(), 'ก'.repeat(300), 'overflow detection never deletes user text');
  await destination.fill('ลำปาง');
  assert.equal(await page.locator('#print-warning').isVisible(), false, 'shortening text clears the stale warning');
  await page.emulateMedia({ media: 'print' });
  assert.equal(await page.locator('#destination + .print-value').textContent(), 'ลำปาง', 'Print A4 mirrors the full destination text');
  await page.emulateMedia({ media: 'screen' });
  assert.equal(await page.locator('input[type=text], textarea').count(), 33);
  const exampleActions = page.locator('.preview-tools details a[href^="/form-preview?example="]');
  assert.equal(await exampleActions.count(), 1, 'one example action remains');
  assert.equal((await exampleActions.first().innerText()).trim(), 'ตัวอย่างคำขอ');
  assert.equal(await exampleActions.first().getAttribute('href'), '/form-preview?example=completed');
  assert.equal(await page.locator('.prototype-notice, #travel-transfer-notice').count(), 0, 'no prototype explanation above the A4');
  assert.equal(await page.locator('.officer-section input, .approval-section input, .driver-section input').count(), 0);
  assert.equal(await page.locator('form').count(), 0, 'prototype cannot submit');
  assert.equal(await page.locator('input').evaluateAll(inputs => inputs.every(input => input.closest('.requester-section,.notes-section,.travel-options'))), true);
  assert.equal(await page.locator('[data-output=vehicle_registration]').textContent(), '');
  const inkBlue = 'rgb(21, 71, 160)';
  assert.equal(await page.locator('#requester_name').evaluate(el => getComputedStyle(el).color), inkBlue, 'typed values use pen blue');
  assert.equal(await page.locator('#document-title').evaluate(el => getComputedStyle(el).color), 'rgb(0, 0, 0)', 'document text stays black');
  assert.equal(await page.locator('.field').first().evaluate(el => getComputedStyle(el).borderBottomColor), 'rgb(0, 0, 0)', 'dotted lines stay black');
  assert.equal(await page.locator('[data-output=driver_name]').textContent(), '');
  await page.locator('.paper').screenshot({ path: path.join(out, 'blank-desktop.png') });
  const legacyResponse = await page.goto('http://127.0.0.1:8088/bookings/new');
  assert.equal(legacyResponse.status(), 404, 'legacy form route is no longer available');
  const dateParts = Object.fromEntries(new Intl.DateTimeFormat('en-CA', { timeZone:'Asia/Bangkok',year:'numeric',month:'2-digit',day:'2-digit' }).formatToParts(new Date()).map(p=>[p.type,p.value]));
  const todayUtc = Date.UTC(Number(dateParts.year),Number(dateParts.month)-1,Number(dateParts.day));
  const isoDay = offset => new Date(todayUtc + offset*86400000).toISOString().slice(0,10);
  const transfer = new URLSearchParams({
    vehicle_id:'1', vehicle_label:'รถคันที่ 1', vehicle_registration:'กท3651', vehicle_province:'พะเยา',
    start:isoDay(3)+'T08:00', end:isoDay(3)+'T17:00', start_date:isoDay(3), start_time:'08:00', end_date:isoDay(3), end_time:'17:00',
    origin:'สถาบันนวัตกรรมการเรียนรู้ มหาวิทยาลัยพะเยา', destination:'เชียงใหม่', destination_source:'search',
    origin_lat:'19.031572', origin_lng:'99.893832', destination_lat:'18.7883', destination_lng:'98.9853',
    route_outbound_m:'120000', route_return_m:'125000', route_duration_ms:'7200000', route_return_duration_ms:'7500000', route_source:'osrm',
    fuel_type:'แก๊สโซฮอล์ 95 S EVO', fuel_efficiency_reference_km_per_l:'8.96666666666667', fuel_price_baht_per_l:'40', fuel_price_date:'17 พ.ย. 69', fuel_price_source:'bangchak_oil_price_api'
  });
  const transferResponse = await page.goto('http://127.0.0.1:8088/form-preview?'+transfer.toString());
  assert.equal(transferResponse.status(), 200, 'travel estimate reaches the original A4 page');
  assert.equal(await page.locator('#destination').inputValue(),'เชียงใหม่');
  const expectedThaiDate = new Intl.DateTimeFormat('th-TH-u-ca-buddhist',{timeZone:'Asia/Bangkok',day:'numeric',month:'long',year:'numeric'}).format(new Date(todayUtc+3*86400000));
  assert.equal(await page.locator('#departure_date').inputValue(),expectedThaiDate);
  assert.equal(await page.locator('#departure_time').inputValue(),'08:00');
  assert.equal(await page.locator('.prototype-notice, #travel-transfer-notice').count(), 0, 'transfer summary is hidden while searched destination remains populated');
  assert.equal(await page.locator('form').count(),0,'A4 preview has no booking submit form');
  await page.locator('#purpose').fill('แก้ไขได้โดยผู้ดูแลในโหมด Preview');
  assert.equal(await page.locator('#purpose + .print-value').textContent(),'แก้ไขได้โดยผู้ดูแลในโหมด Preview');
  await page.locator('.paper').screenshot({ path: path.join(out, 'travel-transfer-a4-desktop.png') });
  await page.emulateMedia({media:'print'});
  const transferPdf=await page.pdf({path:path.join(out,'travel-transfer-a4.pdf'),preferCSSPageSize:true,printBackground:true,displayHeaderFooter:false});
  assert.equal((transferPdf.toString('latin1').match(/\/Type\s*\/Page\b/g)||[]).length,1,'transferred A4 still prints on one page');
  await page.emulateMedia({media:'screen'});
  const mapTransfer = new URLSearchParams(transfer);
  mapTransfer.set('destination',''); mapTransfer.set('destination_source','map');
  const mapOnlyResponse = await page.goto('http://127.0.0.1:8088/form-preview?'+mapTransfer.toString());
  assert.equal(mapOnlyResponse.status(),200,'coordinate-only destination remains valid for route preview');
  assert.equal(await page.locator('#destination').inputValue(),'','map pin leaves the A4 destination blank');
  assert.equal(await page.locator('body').innerText().then(t=>t.includes('จุดที่เลือกบนแผนที่')),false,'map placeholder is not sent into the A4');
  await page.locator('#destination').fill('ปลายทางที่ผู้ใช้กรอกเอง');
  assert.equal(await page.locator('#destination').inputValue(),'ปลายทางที่ผู้ใช้กรอกเอง','user-entered A4 destination remains editable');
  const staleMapTransfer = new URLSearchParams(mapTransfer);
  staleMapTransfer.set('destination','เชียงใหม่');
  const staleMapResponse = await page.goto('http://127.0.0.1:8088/form-preview?'+staleMapTransfer.toString());
  assert.equal(staleMapResponse.status(),422,'stale searched name is rejected for a new map pin');
  await page.goto('http://127.0.0.1:8088/form-preview?'+transfer.toString());
  const metrics = await page.evaluate(() => {
    const paper = document.querySelector('.paper').getBoundingClientRect();
    const names = ['.main-table','.requester-section','.officer-section','.approval-section','.driver-table','.notes-section','.document-reminders'];
    return Object.fromEntries(names.map(name => {
      const r = document.querySelector(name).getBoundingClientRect();
      return [name, { x: r.x - paper.x, y: r.y - paper.y, width: r.width, height: r.height }];
    }));
  });
  async function assertNormalFixtureLayout(label) {
    const h = await page.locator('.main-table').evaluate(el => el.getBoundingClientRect().height);
    assert(h <= metrics['.main-table'].height + 2, label + ' does not expand the normal document grid');
    await page.emulateMedia({media:'print'});
    const buffer = await page.pdf({path:path.join(out,`${label}-a4.pdf`),preferCSSPageSize:true,printBackground:true,displayHeaderFooter:false});
    assert.equal((buffer.toString('latin1').match(/\/Type\s*\/Page\b/g)||[]).length,1,label + ' prints on one A4 page');
    await page.emulateMedia({media:'screen'});
  }
  const fields = await page.locator('input[type=text]').evaluateAll(inputs => inputs.map(input => {
    const r = input.getBoundingClientRect();
    const paper = document.querySelector('.paper').getBoundingClientRect();
    return { name: input.name, label: input.getAttribute('aria-label'), x: r.x-paper.x, y: r.y-paper.y, width: r.width, height: r.height };
  }));
  assert(fields.every(field => field.width > 5 && field.height > 5 && field.label));
  await page.goto('http://127.0.0.1:8088/form-preview?example=requester');
  await page.evaluate(() => document.fonts.ready);
  assert.equal(await page.locator('#passenger_count').inputValue(), '8');
  assert.equal(await page.locator('[data-output=vehicle_registration]').textContent(), '');
  await page.locator('.paper').screenshot({ path: path.join(out, 'requester-desktop.png') });
  await page.locator('#purpose').fill('ประชุมทดสอบการกรอก');
  assert.equal(await page.locator('#purpose + .print-value').textContent(), 'ประชุมทดสอบการกรอก');
  assert.equal(await page.locator('#print-warning').isVisible(), false, 'normal fixture fits its dotted lines');
  const screenPositions = await page.locator('.field').evaluateAll(fields => fields.map(el => ({x:el.getBoundingClientRect().x,y:el.getBoundingClientRect().y})));
  await page.emulateMedia({ media: 'print' });
  const printPositions = await page.locator('.field').evaluateAll(fields => fields.map(el => ({x:el.getBoundingClientRect().x,y:el.getBoundingClientRect().y})));
  // Paper moves when toolbar is hidden; compare relative field positions.
  const dx = printPositions[0].x-screenPositions[0].x;
  const dy = printPositions[0].y-screenPositions[0].y;
  const printDrift = Math.max(...screenPositions.map((r,i) => Math.max(Math.abs(printPositions[i].x-r.x-dx),Math.abs(printPositions[i].y-r.y-dy))));
  console.log('Screen/print field drift (px):',printDrift);
  assert(printDrift <= 2, 'normal screen/print fields remain aligned');
  assert.equal(await page.locator('.preview-tools').isVisible(), false);
  assert.equal(await page.locator('#purpose').isVisible(), false);
  assert.equal(await page.locator('#purpose + .print-value').isVisible(), true);
  assert.equal(await page.locator('#purpose + .print-value').evaluate(el => getComputedStyle(el).color), inkBlue, 'printed values retain pen blue');
  await page.locator('.paper').screenshot({ path: path.join(out, 'requester-print.png') });
  const pdf = await page.pdf({ path: path.join(out, 'requester-a4.pdf'), preferCSSPageSize: true, printBackground: true, displayHeaderFooter: false });
  const pageCount = (pdf.toString('latin1').match(/\/Type\s*\/Page\b/g) || []).length;
  assert.equal(pageCount, 1, 'normal sample prints on one A4 page');
  const mediaBox = pdf.toString('latin1').match(/\/MediaBox\s*\[\s*0\s+0\s+([\d.]+)\s+([\d.]+)\s*\]/);
  assert(mediaBox && Math.abs(Number(mediaBox[1])-595.28)<1 && Math.abs(Number(mediaBox[2])-841.89)<1, 'PDF page is A4 portrait in points');
  assert(await page.evaluate(() => document.querySelector('.document-reminders').getBoundingClientRect().bottom <= document.querySelector('.paper').getBoundingClientRect().bottom), 'footer fits normal printed page');
  await page.emulateMedia({ media: 'screen' });
  await page.goto('http://127.0.0.1:8088/form-preview?example=assigned');
  await page.evaluate(() => document.fonts.ready);
  assert.equal(await page.locator('[data-output=vehicle_registration]').textContent(), 'นข 1234 พะเยา');
  assert.equal(await page.locator('[data-output=vehicle_registration]').evaluate(el => getComputedStyle(el).color), inkBlue, 'populated outputs use pen blue');
  assert.equal(await page.locator('.approval-section .circle.checked').count(), 1);
  await page.locator('.paper').screenshot({ path: path.join(out, 'assigned-desktop.png') });
  await assertNormalFixtureLayout('assigned');
  await page.goto('http://127.0.0.1:8088/form-preview?example=rejected');
  assert.equal(await page.locator('[data-output=vehicle_registration]').textContent(), '');
  assert.equal(await page.locator('.approval-section .circle.checked').count(), 1);
  await page.locator('.paper').screenshot({ path: path.join(out, 'rejected-desktop.png') });
  await assertNormalFixtureLayout('rejected');
  await page.goto('http://127.0.0.1:8088/form-preview?example=completed');
  assert.equal(await page.locator('[data-output=actual_return_time]').textContent(), '16:20');
  assert.equal(await page.locator('[data-output=acknowledging_driver_name]').textContent(), 'นายทดสอบ ใจดี');
  await page.locator('.paper').screenshot({ path: path.join(out, 'completed-desktop.png') });
  await assertNormalFixtureLayout('completed');
  await page.goto('http://127.0.0.1:8088/form-preview?example=assigned');
  await page.setViewportSize({ width: 390, height: 844 });
  const mobile = await page.evaluate(() => ({
    width: document.querySelector('.paper').getBoundingClientRect().width,
    client: document.querySelector('.paper-viewport').clientWidth,
    scroll: document.querySelector('.paper-viewport').scrollWidth,
  }));
  assert(mobile.width > 790 && mobile.scroll > mobile.client, 'mobile keeps A4 and horizontal scrolling');
  await page.screenshot({ path: path.join(out, 'mobile-left.png') });
  await page.locator('.paper-viewport').evaluate(el => { el.scrollLeft = el.scrollWidth; });
  await page.screenshot({ path: path.join(out, 'mobile-right.png') });
  const viewportChecks = [];
  for (const size of [{width:1920,height:1080},{width:1366,height:768},{width:390,height:844},{width:360,height:800}]) {
    await page.setViewportSize(size);
    for (const example of ['blank','requester']) {
      await page.goto('http://127.0.0.1:8088/form-preview' + (example === 'blank' ? '' : '?example=requester'));
      await page.evaluate(() => document.fonts.ready);
      const geometry = await page.evaluate(() => {
        const paper = document.querySelector('.paper').getBoundingClientRect();
        const fields = [...document.querySelectorAll('.field input')];
        return { paperWidth:paper.width,paperLeft:paper.left,
          fieldsInside:fields.every(el => { const r=el.getBoundingClientRect(); const owner=el.closest('.requester-section,.notes-section').getBoundingClientRect(); return r.left>=owner.left-1 && r.right<=owner.right+1 && r.width>5; }),
          mainWidth:document.querySelector('.main-table').getBoundingClientRect().width,
          driverWidth:document.querySelector('.driver-table').getBoundingClientRect().width };
      });
      assert(geometry.paperWidth>793 && geometry.paperWidth<795 && geometry.fieldsInside, 'fixed A4 and fields remain inside document at every viewport');
      if (size.width>800) assert(Math.abs(geometry.paperLeft-(size.width-geometry.paperWidth)/2)<1,'desktop A4 centered');
      if (size.width<800) {
        await page.locator('#requester_name').tap();
        await page.locator('#requester_name').fill(example==='blank' ? 'ทดสอบแตะกรอก' : 'นางสาวตัวอย่าง ใจดี');
        assert.equal(await page.locator('#requester_name').inputValue(),example==='blank' ? 'ทดสอบแตะกรอก' : 'นางสาวตัวอย่าง ใจดี');
        if(example==='blank') await page.locator('#requester_name').fill('');
        await page.locator('.paper-viewport').evaluate(el => { el.scrollLeft=0; });
        await page.evaluate(() => window.scrollTo(0,0));
      }
      await page.screenshot({path:path.join(out,`${example}-${size.width}x${size.height}.png`)});
      viewportChecks.push({ ...size,example,...geometry });
    }
  }
  await page.setViewportSize({ width: 1280, height: 1300 });
  await page.goto('http://127.0.0.1:8088/form-preview?example[]=invalid');
  assert.equal(await page.locator('#requester_name').inputValue(), '', 'invalid example input falls back to blank');
  const unsafe = '<img src=x onerror=alert(1)>';
  await page.locator('#purpose').fill(unsafe);
  assert.equal(await page.locator('#purpose + .print-value').textContent(), unsafe);
  assert.equal(await page.locator('#purpose + .print-value img').count(), 0);
  await page.locator('#purpose').fill('ข้อความยาวสำหรับทดสอบ '.repeat(25));
  assert.equal(await page.locator('#print-warning').isVisible(), true);
  await page.screenshot({ path: path.join(out, 'long-content-screen.png') });
  await page.emulateMedia({ media: 'print' });
  const longPrint = await page.locator('#purpose + .print-value').evaluate(el => ({ text: el.textContent, height: el.getBoundingClientRect().height, scroll: el.scrollHeight, client: el.clientHeight }));
  await page.locator('.paper').screenshot({ path: path.join(out, 'long-content-print.png') });
  assert(longPrint.height > 20 && longPrint.scroll <= longPrint.client + 1, 'long value wraps without clipping');
  assert.deepEqual(errors, [], 'no browser JavaScript or CSP errors');
  fs.writeFileSync(path.join(out, 'browser-results.json'), JSON.stringify({ status: 'passed', inkBlue, viewportChecks, normalPdfPages: pageCount, pdfPagePoints: mediaBox.slice(1).map(Number), printFieldDriftPx: printDrift, metrics, fields, mobile, longPrint, browserErrors: errors }, null, 2));
  console.log('PASS form preview: read-only sections, fields, fixtures, input/print sync, A4 PDF, mobile scroll, overflow wrapping, XSS text and browser errors');
  console.log(JSON.stringify({ normalPdfPages: pageCount, metrics, mobile }));
  await browser.close();
})().catch(async error => { console.error(error); if (browser) await browser.close(); process.exit(1); });
