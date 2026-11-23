const { chromium } = require('playwright-core');
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { pathToFileURL } = require('node:url');

const root = path.resolve(__dirname, '../..');
const php = path.join(root, '.runtime', 'php84', 'php.exe');
const renderer = path.join(__dirname, 'render-ui-preview.php');
const tempDir = fs.mkdtempSync(path.join(os.tmpdir(), 'up-van-responsive-'));
const tempFiles = [];

function renderToFile(routeKey) {
  const html = execFileSync(php, ['-n', renderer, routeKey], { cwd: root, encoding: 'utf8' });
  const publicAssets = pathToFileURL(path.join(root, 'public') + path.sep).href + 'assets/';
  const portableHtml = html.replace(/((?:href|src)=["'])\/assets\//g, `$1${publicAssets}`);
  const file = path.join(tempDir, `${routeKey}.html`);
  fs.writeFileSync(file, portableHtml, 'utf8');
  tempFiles.push(file);
  return pathToFileURL(file).href;
}

let browser;
(async () => {
  browser = await chromium.launch({ channel: 'chrome', headless: true });
  const page = await browser.newPage({ deviceScaleFactor: 1 });
  const routes = ['home', 'home-admin', 'dashboard-auth', 'login', 'dashboards', 'admin-users'];
  const viewports = [
    { width: 360, height: 800, label: 'mobile' },
    { width: 768, height: 1024, label: 'tablet' },
    { width: 1440, height: 960, label: 'desktop' },
  ];
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  for (const viewport of viewports) {
    await page.setViewportSize({ width: viewport.width, height: viewport.height });
    for (const route of routes) {
      const response = await page.goto(renderToFile(route));
      assert(response === null || response.ok(), `${route} renders at ${viewport.label}`);
      assert.equal(await page.locator('h1').count(), 1, `${route} has a rendered page heading at ${viewport.label}`);
      const layout = await page.evaluate(() => ({
        viewport: document.documentElement.clientWidth,
        document: document.documentElement.scrollWidth,
        body: document.body.scrollWidth,
      }));
      assert(layout.document <= layout.viewport + 1 && layout.body <= layout.viewport + 1, `${route} has no horizontal page overflow at ${viewport.label}: ${JSON.stringify(layout)}`);
      const navigation = await page.locator('nav[aria-label="เมนูหลัก"] a').evaluateAll(links => links.map(link => link.getAttribute('href')));
      assert(navigation.every(href => ['/', '/login', '/dashboard', '/bookings/calendar', '/admin/users', '/admin/vehicles', '/admin/system-logs'].includes(href)), 'navigation uses registered live destinations');
      if (route === 'home') {
        assert(navigation.includes('/login') && !navigation.includes('/ui-preview/dashboards'));
        assert.equal(await page.locator('a[href="/ui-preview/dashboards"]').count(), 1, 'one clearly labeled preview link remains');
        assert(!navigation.includes('/dashboard') && !navigation.includes('/admin/users'));
      }
      if (route === 'home-admin') {
        assert(navigation.includes('/dashboard') && navigation.includes('/admin/users') && navigation.includes('/admin/vehicles') && navigation.includes('/admin/system-logs'));
        assert(!navigation.includes('/login'));
        assert.equal(await page.locator('form[action="/logout"] input[name="_token"]').count(), 1);
      }
      if (route === 'dashboard-auth') {
        assert(navigation.includes('/') && navigation.includes('/dashboard'));
        assert(!navigation.includes('/login') && !navigation.includes('/admin/users'));
        assert.equal(await page.locator('form[action="/logout"] input[name="_token"]').count(), 1);
      }
      const van = page.locator('img[src*="university-van-illustration.webp"]');
      if (await van.count()) {
        await van.first().evaluate(img => img.scrollIntoView({ block: 'center' }));
        await van.first().evaluate(img => img.decode());
        assert.equal(await van.first().evaluate(img => img.naturalWidth), 1555, `van WebP loads at ${viewport.label}`);
        assert(await van.first().getAttribute('alt'), 'illustration has alternative text');
      }
    }
    console.log(`PASS: responsive layout at ${viewport.label} (${viewport.width}x${viewport.height})`);
  }
  assert.deepEqual(errors, [], 'no browser runtime errors');
  console.log('PASS: responsive guest/authenticated Home, Login, Dashboard, and Admin User Management');
})().catch(error => {
  console.error(error);
  process.exitCode = 1;
}).finally(async () => {
  if (browser) await browser.close();
  for (const file of tempFiles) if (fs.existsSync(file)) fs.unlinkSync(file);
  if (fs.existsSync(tempDir)) fs.rmdirSync(tempDir);
});
