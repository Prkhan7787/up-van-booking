const { chromium } = require('playwright-core');
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { pathToFileURL } = require('node:url');

const root = path.resolve(__dirname, '../..');
const php = path.join(root, '.runtime', 'php84', 'php.exe');
const tempDir = fs.mkdtempSync(path.join(os.tmpdir(), 'up-van-workspace-'));
const cases = [
  ['dashboard', 'render-ui-preview.php', 'dashboard-auth'],
  ['admin-users', 'render-admin-users-fixture.php', ''],
  ['system-logs', 'render-task05b-fixture.php', 'logs'],
  ['calendar', 'render-booking-calendar-fixture.php', 'requester'],
];
const viewports = [360, 375, 768, 1024, 1440];

function render(name, renderer, mode) {
  const html = execFileSync(php, ['-n', path.join(__dirname, renderer), mode], { cwd: root, encoding: 'utf8' });
  const assets = pathToFileURL(path.join(root, 'public') + path.sep).href + 'assets/';
  const portable = html.replace(/((?:href|src)=["'])\/assets\//g, `$1${assets}`);
  const file = path.join(tempDir, `${name}.html`);
  fs.writeFileSync(file, portable, 'utf8');
  return pathToFileURL(file).href;
}

let browser;
(async () => {
  const pages = cases.map(([name, renderer, mode]) => [name, render(name, renderer, mode)]);
  browser = await chromium.launch({ channel: 'chrome', headless: true });
  const page = await browser.newPage({ deviceScaleFactor: 1 });
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));

  for (const width of viewports) {
    await page.setViewportSize({ width, height: 900 });
    for (const [name, url] of pages) {
      await page.goto(url);
      const layout = await page.evaluate(() => {
        const main = document.querySelector('main#main');
        const container = main?.parentElement;
        const rect = main?.getBoundingClientRect();
        const outer = container?.getBoundingClientRect();
        return {
          viewport: document.documentElement.clientWidth,
          scrollWidth: document.documentElement.scrollWidth,
          bodyWidth: document.body.scrollWidth,
          mainWidth: rect?.width,
          mainLeft: rect?.left,
          outerLeft: outer?.left,
          outerRight: outer?.right,
          centeredDifference: rect && outer ? Math.abs((rect.left + rect.right) - (outer.left + outer.right)) : null,
          asideCount: document.querySelectorAll('aside').length,
        };
      });
      assert.equal(layout.asideCount, 0, `${name}: role sidebar removed at ${width}px`);
      assert(layout.mainWidth > 0, `${name}: main content exists at ${width}px`);
      assert(layout.centeredDifference <= 2, `${name}: main content centered at ${width}px: ${JSON.stringify(layout)}`);
      assert(layout.scrollWidth <= layout.viewport + 1 && layout.bodyWidth <= layout.viewport + 1,
        `${name}: no horizontal overflow at ${width}px: ${JSON.stringify(layout)}`);
      if (width === 1440 && name !== 'dashboard') {
        assert(layout.mainWidth >= 1000, `${name}: uses available desktop width: ${layout.mainWidth}`);
      }
      const nav = await page.locator('nav[aria-label="เมนูหลัก"] a').evaluateAll(links => links.map(a => a.getAttribute('href')));
      assert(nav.includes('/') && nav.includes('/dashboard'), `${name}: top navigation preserved`);
      assert.equal(await page.locator('form[action="/logout"] input[name="_token"]').count(), 1,
        `${name}: authenticated logout remains available`);
    }
    console.log(`PASS: shared workspace layout at ${width}px (${pages.length} pages)`);
  }
  assert.deepEqual(errors, [], 'no browser runtime errors');
})().catch(error => {
  console.error(error);
  process.exitCode = 1;
}).finally(async () => {
  if (browser) await browser.close();
  fs.rmSync(tempDir, { recursive: true, force: true });
});
