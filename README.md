# UP Van Booking — Task 01

Task 02 Revision: ปรับแบบออกแบบตามรายการข้อมูลแบบฟอร์มแล้วที่ [Workflow, Field Mapping, ER, State Transitions, RBAC และ Open Questions](docs/task02-design.md) และ [Transaction/Overlap](docs/task02-concurrency.md) ยืนยันหนึ่ง Active Assignment ต่อคำขอ ไม่มี Buffer Time และยกเลิกหลังอนุมัติผ่านเจ้าหน้าที่เท่านั้น พร้อม candidate migration `database/migrations/002_booking_design.sql` (9 ตารางใหม่และ calendar view) **ยังไม่ได้รันและห้ามรันใน Task นี้** ดู [ผลตรวจ Task 02 Revision](docs/task02-verification.md) ทดสอบ design contract โดยไม่เชื่อมฐานข้อมูลด้วย `php tests/task02-design.php` ได้รับภาพต้นฉบับในงาน UI ภายหลังแล้ว แต่ยังต้องยืนยันความหมายและ Required/Optional บางช่อง ไม่มีการเพิ่ม Login หรือฟังก์ชันจองรถ

ระบบขออนุญาตใช้รถตู้ มหาวิทยาลัยพะเยา แยกจาก Lost and Found โดยสมบูรณ์
PHP 8.4 / MySQL 8.4 / IIS / PDO / Tailwind CSS / SweetAlert2
ยังไม่มี Login, OAuth, SSO หรือฟังก์ชันจองรถ และหน้าแรกไม่ต้องเชื่อมฐานข้อมูล

## โครงสร้าง

```text
app/
  Controllers/HomeController.php
  Core/                 Env, Database, Csrf, View
  Models/User.php
  Services/             ModuleCatalog, AuditLogger
  Modules/README.md     ขอบเขต Booking, Approval, Calendar, Map
  Views/home.php
  helpers.php           e() สำหรับ escape HTML
bootstrap/app.php       autoload, environment, session
config/database.php
database/schema.sql
public/                 IIS physical path เท่านั้น
  index.php
  web.config
  assets/               compiled CSS, SweetAlert2, JavaScript
resources/css/app.css
routes/web.php
scripts/copy-assets.cjs
storage/logs/           private; ไม่ commit log
storage/sessions/       private; ไม่ commit session
tests/smoke.php
.env.example
.gitignore
package.json
package-lock.json
```

## ตั้งค่า

1. ใช้ PHP 8.4 NTS ที่ติดตั้งแยกสำหรับโปรเจกต์นี้ พร้อม `pdo_mysql`, `mbstring`, `openssl`, `session` และ Visual C++ runtime ที่ตรงรุ่น ไม่ใช้หรือแก้ runtime ของโปรเจกต์อื่นในการติดตั้งจริง
2. คัดลอก `.env.example` เป็น `.env` ที่ root ของโปรเจกต์ หากมี `.env` อยู่แล้ว ให้ตรวจสอบก่อน ห้ามเขียนทับ
3. กำหนดค่าผ่าน `.env` หรือ environment ของ process (environment มีลำดับความสำคัญสูงกว่า) ตัว parser รองรับ `KEY=value`, quoted values และ comment เต็มบรรทัด ไม่รองรับ multiline, interpolation หรือ inline comments
4. ใส่ DB_USERNAME และ DB_PASSWORD ของบัญชีใหม่เฉพาะระบบ ห้าม commit credential จริง ค่ารหัสผ่านที่เว้นว่างในตัวอย่างไม่ใช่บัญชีพร้อมใช้
5. ถ้าแก้ CSS หรืออัปเดต assets ให้ใช้ Node.js LTS แล้วรัน `npm ci` และ `npm run build` ที่ root; deploy compiled assets และ lockfile ด้วย ไม่ต้องติดตั้ง Node.js บน production

## MySQL 8.4 — Task 03 Phase A

**ยังไม่สร้างฐานข้อมูล บัญชี หรือรัน migration** ผู้ใช้กำหนดให้อนุมัติ Phase A ก่อน Phase B ดู [รายงาน Preflight](docs/task03-preflight.md), [Database setup / CLI / Recovery](docs/task03-database-setup.md) และ [แผนพร้อม checksum](docs/task03-migration-plan.json)

ใช้ฐานหลัก `up_van_booking` และเสนอฐานทดสอบ `up_van_booking_test` โดยกำหนด utf8mb4 / utf8mb4_unicode_ci / InnoDB ต้องตรวจว่าชื่อเดิมมีอยู่หรือไม่และได้รับอนุญาตก่อนเขียน ห้ามใช้ runtime credential ของ Lost & Found หรือ root เป็นบัญชีเว็บไซต์

Migration path ใหม่: 001_identity.sql → 002_booking_design.sql → seeds/003_roles.sql ผ่าน runner ที่ pin checksum เท่านั้น **database/schema.sql เดิมเป็นเอกสาร Task 01 ห้าม import ซ้ำร่วมกับ runner** หากมีตารางเดิมแล้ว runner จะไม่ adopt/overwrite โดยอัตโนมัติ

PHP portable แยกใน `.runtime/php84` (ignored) เพื่อไม่ใช้ runtime ของ Lost & Found ทดสอบแผนโดยไม่เชื่อม DB:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/php.ps1 bin/db-health.php --offline
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/php.ps1 bin/db-migrate.php --plan
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/php.ps1 tests/database/offline.php
```

ดู `.env.example` สำหรับ prefix ของบัญชี runtime/migration/test ทั้งสอง execution gates ต้องเป็น false ใน Phase A และไม่มี credential จริงใน source ไม่มี public database health endpoint

## IIS

1. เปิด IIS พร้อม CGI/FastCGI, Static Content, Default Document และติดตั้ง URL Rewrite Module
2. สร้าง Application Pool ใหม่ชื่อ `up-van-booking` เลือก No Managed Code และสร้าง Website แยกชื่อเดียวกัน
3. ตั้ง Physical Path เป็น `C:\จองรถตู้\up-van-booking\public` เท่านั้น ห้ามใช้ project root ตั้ง binding เป็น `http`, host `localhost`, port `8088` โดยตรวจว่า port ไม่ชนเว็บไซต์เดิม
4. ตั้ง FastCGI และ Handler Mapping สำหรับ `*.php` ไปยัง `php-cgi.exe` ของ PHP 8.4 ที่ติดตั้งแยก คงค่าอื่นของเว็บไซต์เดิมไว้ `public/web.config` เตรียม default document และ URL Rewrite ให้แล้ว
5. ให้ `IIS AppPool\up-van-booking` Read/Execute ที่ project และ Read ที่ `.env`; ให้ Modify เฉพาะ `storage\logs` และ `storage\sessions` ไม่ให้เขียน source/config/public จำกัด ACL ของ `.env` เฉพาะผู้ดูแลและ identity ที่จำเป็น
6. ตั้ง php.ini: `display_errors=Off`, `log_errors=On`, `expose_php=Off`, `cgi.force_redirect=0` และเปิด `pdo_mysql` จากนั้น recycle เฉพาะ pool ใหม่นี้
7. เปิด http://localhost:8088/ หน้าแรกควรแสดงได้แม้ยังไม่ตั้ง DB ตรวจ `/assets/app.css`, หน้า 404 และตรวจว่า `/.env`, `/config/database.php`, `/storage/` ไม่เปิดเผยข้อมูล
8. เมื่อใช้ HTTPS ตั้ง `SESSION_SECURE=true` และ APP_URL ให้ตรง URL จริง ใช้ `false` เฉพาะ localhost HTTP

หาก IIS แจ้ง 500.19 ให้ตรวจ URL Rewrite และ section locking ใน IIS ก่อน ไม่แก้ configuration ของเว็บไซต์อื่น

อ้างอิง: [PHP on IIS](https://www.php.net/manual/en/install.windows.iis.php), [IIS FastCGI](https://learn.microsoft.com/en-us/iis/configuration/system.webserver/fastcgi/), [URL Rewrite](https://learn.microsoft.com/en-us/iis/extensions/url-rewrite-module/url-rewrite-module-configuration-reference), [Tailwind CLI](https://tailwindcss.com/docs/installation/tailwind-cli)

## ทดสอบบน localhost โดยไม่ใช้ IIS

```powershell
php tests/smoke.php
php -S 127.0.0.1:8088 -t public
```

เปิด http://127.0.0.1:8088/ ใช้ built-in server สำหรับพัฒนาเท่านั้น ไม่ใช่ production และไม่ได้จำลอง IIS request filtering

## Security conventions

- SQL ที่รับข้อมูลภายนอกใช้ PDO prepare/execute; emulated prepares ปิดอยู่
- ทุก POST ผ่าน CSRF middleware ก่อน route; form ในอนาคตต้องใส่ `<input type="hidden" name="_token" value="<?= e(\App\Core\Csrf::token()) ?>">` ปัจจุบันไม่มี POST form
- Escape HTML ผ่าน `e()`; URL, JavaScript และ JSON ต้องใช้ encoding ให้ตรง context ห้ามนำ input มาประกอบชื่อ view
- รหัสผ่านในอนาคตใช้ `password_hash(PASSWORD_DEFAULT)` และ `password_verify()` เท่านั้น SSO accounts เก็บ hash เป็น NULL ได้
- AuditLogger รับ metadata แบบ allowlist เท่านั้น ห้ามส่ง password/token/credential ลง log; action ที่เปลี่ยนข้อมูลควรบันทึก audit ใน transaction เดียวกับ business change
- Session แยกชื่อและ storage, HttpOnly, SameSite=Lax, strict mode; regenerate session ID เมื่อเพิ่ม Login
- CSP โหลด scripts/styles จาก local assets; ใช้ SweetAlert2 แบบ text ไม่ส่ง HTML จากผู้ใช้
- ไม่ commit `.env`, session, logs, dependencies หรือ private keys

## ขอบเขตการตรวจสอบ / ขั้นตอนถัดไป

ดูผลทดสอบจริงใน `docs/verification.md` ฐานข้อมูลและ IIS ต้องทดสอบบนเครื่องที่ติดตั้งก่อนใช้งานจริง

ก่อน Task 03: ยืนยัน Open Questions ที่เหลือในเอกสาร Task 02 Revision โดยเฉพาะช่องบังคับจากต้นฉบับ อำนาจ Delegate และหลักฐานการแจ้งผล/สิ้นสุดการเดินทาง จากนั้นกำหนด scope implementation และการอนุญาตทดสอบ MySQL แยกก่อนเพิ่ม migration runner หรือ integration tests งาน revision นี้ยังไม่อนุญาตรัน migration หรือเพิ่ม Login/OAuth/SSO

## ต้นแบบหน้ากรอกตามเอกสาร A4 — Task 02 UI

เปิด [แบบฟอร์มต้นแบบ](http://127.0.0.1:8088/form-preview) หลังรัน PHP server ตามด้านบน ใช้ภาพที่ผู้ใช้แนบเป็น Visual Reference กรอกได้เฉพาะส่วนที่ 1 และหมายเหตุ ไม่มีการบันทึก/ส่งคำขอ ส่วนเจ้าหน้าที่/ผลพิจารณา/คนขับเป็น read-only outputs มีหน้าตัวอย่างข้อมูลจำลองให้เลือกเหนือเอกสารและปุ่มพิมพ์ A4 หน้าเดิม `/` ไม่เปลี่ยน

ดู [UI Specification และ DB mapping](docs/form-ui-specification.md), [ผลตรวจและข้อแตกต่าง](docs/form-ui-verification.md), [ภาพต้นฉบับเทียบเว็บ](docs/form-preview/reference-vs-web.png) และ [ตัวอย่าง PDF A4](docs/form-preview/requester-a4.pdf)

ทดสอบ browser: เปิด PHP server ก่อน แล้วรัน `npm ci` และ `node tests/browser/form-preview.cjs` (ต้องมี Google Chrome ติดตั้ง) จากนั้นสร้างภาพเทียบด้วย `powershell -NoProfile -File scripts/compare-form.ps1` Playwright Core เป็น dev dependency ไม่ต้อง deploy ขึ้น IIS

งาน UI เดิมพบคำว่า “เงินเพิ่มพิเศษพนักงานขับรถ” ต่อมา Task 03 Phase A แก้ candidate เป็น `driver_extra_pay` แล้วให้ตรงข้อความนี้ โดยยังไม่รัน migration; ยังต้องยืนยันกติกาเลือกแหล่งค่าใช้จ่ายก่อนพัฒนา feature จริง
