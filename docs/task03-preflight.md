# Task 03 — Phase A Preflight Report

วันที่ 6 พฤศจิกายน 2026 (Asia/Bangkok) — **หยุดก่อน Phase B**

## 1. สภาพแวดล้อมที่ตรวจพบจริง

| รายการ | ผล |
|---|---|
| Project | C:\จองรถตู้\up-van-booking; มี MVC, A4 preview, Task 01 SQL และ candidate 002 |
| MySQL client | C:\Program Files\MySQL\MySQL Server 8.4\bin\mysql.exe --no-defaults --version = 8.4.9 |
| MySQL server | localhost handshake protocol 10, server_version = 8.4.9; ไม่ส่ง username/password หรือ query |
| Process | mysqld PID 21288 ที่ executable ของ MySQL Server 8.4 ข้างต้น |
| Service | ไม่พบบริการ Windows ที่ชื่อ MySQL/MariaDB หรือมี ProcessId ตรงกับ PID นี้ จึงยืนยัน process ที่ทำงานได้ แต่ไม่อ้างว่าติดตั้งเป็น Windows Service |
| Ports ที่มีอยู่แล้ว | 3306 และ 33060, LocalAddress :: จาก process เดียวกัน; ไม่เปลี่ยน binding/port/firewall/service และไม่ได้ตรวจ firewall exposure |
| PHP ใน PATH | ชี้โปรเจกต์ Lost & Found จึงไม่เรียก/อ่าน/แก้ runtime นั้นใน Task 03 |
| PHP แยกของ project | portable 8.4.27 NTS x64 จาก php.net ตรวจ SHA256 แล้ว; PDO mysql โหลดได้จริงผ่าน project-relative extension_dir |
| Credentials | ไม่มี .env หรือ DB-prefixed environment credentials ที่พร้อมใช้ ผู้ใช้ตอบว่า “รายงานไว้ก่อน ยังไม่มีบัญชีตรวจสอบ” |
| up_van_booking มีอยู่หรือไม่ | **ยังไม่ทราบ** ไม่ลอง login หรือ password ไม่สรุปจากการไม่มีข้อมูลว่า database ยังไม่มี |
| ตาราง Task 01 มีจริงหรือไม่ | ยืนยันได้เฉพาะ definitions ในไฟล์ ไม่ได้ query ตารางจริง |
| up_van_booking_test / account names ที่เสนอมีอยู่หรือไม่ | ยังไม่ทราบ ต้องตรวจด้วยผู้ดูแลที่ได้รับอนุญาตก่อน provision |

MySQL 8.4 เดิมใช้งานอยู่จึงไม่ติดตั้งใหม่ ไม่หยุด/restart server ไม่แก้ root password และไม่แตะไฟล์/config/ฐานข้อมูล Lost & Found การตรวจ TCP handshake ไม่ใช่การทดสอบ PDO authentication

## 2. ผล review / สิ่งที่แก้ก่อนเสนอรัน

1. เพิ่ม candidate 001_identity จาก Task 01 โดยคงตาราง/คอลัมน์เดิม แยก role seed และใช้ utf8mb4_unicode_ci; ไม่แก้ database/schema.sql เดิมและไม่ ALTER ตารางใดจริง
2. candidate 002 ใช้ collation เดียวกันทั้งชุด แก้ expense enum เป็น driver_extra_pay ให้ตรง **เงินเพิ่มพิเศษพนักงานขับรถ** ไม่ใช่ personal funds
3. ตัด explicit referential actions ใน 002 ออกจาก FK ที่มี CHECK-related columns ให้ใช้ default restrictive InnoDB behavior ตามข้อจำกัด CHECK ของ MySQL; ไม่เปลี่ยนเป็น cascade ไม่ปิด FK checks
4. ปรับ calendar_events เป็น SQL SECURITY INVOKER
5. เพิ่ม manifest ที่ pin checksum, runner safety gates/ledger/advisory lock/object inventory และแยก role reference seed จาก test data
6. Connection layer ใช้ PDO เท่านั้น ตรวจ database/host/account/grant scope, utc และไม่เผย PDO messages/DSN/password; ไม่มี public health endpoint

โครงสร้าง SQL ที่ review: **13 business tables + 1 view, 35 FKs, 27 named CHECKs** (นับจาก source ไม่ใช่ metadata server) และ generated unique key 1 active assignment/booking คง replacement chain, booking_reviews, booking_notifications, indexes รถ/คนขับ/คิว/owner/history และสถานะ workflow เดิมทั้งหมด

approved/rejected อยู่ approval_decisions.outcome ส่วน operational states awaiting_assignment/awaiting_rejection_notice/assigned/completed/cancelled/closed_rejected ไม่เปลี่ยนชื่อหรือเพิ่มเส้นทาง workflow ใหม่ Cross-row overlap/authorization/decision-vs-state consistency ยังต้องใช้ service/harness ตาม docs/task02-concurrency.md ไม่อ้างว่า unique/CHECK กัน overlap เอง

อ้างอิง review จาก [MySQL CHECK](https://dev.mysql.com/doc/refman/8.4/en/create-table-check-constraints.html), [FK](https://dev.mysql.com/doc/refman/8.4/en/create-table-foreign-keys.html), [implicit commits](https://dev.mysql.com/doc/refman/8.4/en/implicit-commit.html), [GRANT escaping](https://dev.mysql.com/doc/refman/8.4/en/grant.html) เป็นการเทียบเอกสาร/ตรวจ source ยังไม่ใช่ execution compatibility proof

## 3. SQL/Migration ที่เสนอให้อนุมัติ

รายละเอียด [task03-migration-plan.json](task03-migration-plan.json) และ [คู่มือ setup](task03-database-setup.md)

| ลำดับ | File | Statements | SHA256 |
|---|---|---|---|
| 001 | database/migrations/001_identity.sql | 4 DDL | a8afc40e4e422b640caf40289dc92734f71f2bcb26f3141ccd8f7b81257464d8 |
| 002 | database/migrations/002_booking_design.sql | 10 DDL รวม view | 298e8702b4c0480797bb3fd926e80374491abba7768560f8002cbc858be946aa |
| 003 | database/seeds/003_roles.sql | 1 INSERT reference roles | 43e89c65e36df2aa401182f2fefb6fa13289f7a3e9bbde32062057cb5b4495f2 |

Runner จะสร้าง schema_migrations อีก 1 ตารางสำหรับ ledger ใน target เท่านั้น ไม่สร้างฐานข้อมูล/บัญชีจาก runner ไม่ใช้ legacy schema.sql หรือทดสอบข้อมูลใน production database

Phase B provisioning ที่เสนอ **ถ้าตรวจยืนยันชื่อว่างและได้รับอนุมัติ**:

- CREATE DATABASE up_van_booking / up_van_booking_test ด้วย utf8mb4_unicode_ci ไม่มี IF NOT EXISTS เพื่อไม่ซ่อนฐานเดิม
- CREATE USER up_van_app, up_van_migrator, up_van_test, up_van_test_migrator แบบ local-only และ password สุ่มแยกผ่านช่องทางลับ ไม่ใช้ root เป็น runtime ไม่แก้บัญชีเดิม
- GRANT แบบ scoped ตามตารางสิทธิ์ในคู่มือ ไม่มี global/ALL/GRANT OPTION/DROP/ALTER; พิจารณา escape underscore ตาม @@GLOBAL.partial_revokes โดยไม่เปลี่ยน global setting
- หลังตรวจ source/ชื่อและผู้ใช้อนุมัติ จึงเปิด local execution gates และรัน migrations กับฐานหลัก/ฐานทดสอบตามลำดับ
- ใช้ synthetic fixtures เฉพาะฐานทดสอบ แล้ว cleanup เฉพาะ IDs ของ run; ไม่สร้าง users rows จริงในฐานหลัก

หากพบ database/account เดิมต้องหยุดและรายงานก่อนเปลี่ยน แม้ table set บางส่วนดูตรงกัน ไม่มี automatic reset/adopt/overwrite ไม่มีการขอให้ส่ง password ในแชต

## 4. ไฟล์ที่เพิ่ม/แก้

- PDO/config: app/Database/ConnectionFactory.php, SafetyException.php, app/Core/Database.php, config/database.php, .env.example
- Runner: app/Database/SqlStatements.php, MigrationPlan.php, MigrationRunner.php; database/manifest.php; migrations/001_identity.sql; candidate 002; seeds/003_roles.sql
- CLI: bootstrap/cli.php; bin/db-preflight.php, bin/db-health.php, bin/db-migrate.php; scripts/php.ps1
- Tests: tests/database/offline.php, integration.php, assignment-worker.php และแก้ test expectations ใน tests/task02-design.php ให้ตรง candidate
- Docs: task03-preflight.md, task03-database-setup.md, task03-migration-plan.json, README และข้อความอัปเดต candidate ใน task02-design.md/form-ui-specification.md
- .gitignore เพิ่ม .runtime/; runtime binaries/ZIP และ credentials ไม่ commit

ไม่มีการแก้ CSS/HTML/JS/fixtures/route ของแบบฟอร์ม A4 ไม่มี Login/OAuth/SSO, workflow/RBAC changes, Git commit หรือ push

## 5. ผลทดสอบ Phase A

- PHP syntax ผ่าน **32 ไฟล์** ด้วย portable PHP แยกของ project
- `tests/database/offline.php` ผ่าน **27 checks**: lexer/checksum plan, target/root/host/DSN guards, approval gate, expense/collation/view, global/other-schema/role/GRANT OPTION/underscore-wildcard privilege rejection โดยไม่ connect DB
- `tests/task02-design.php` ผ่าน **283 checks** หลังแก้ candidate expectations
- `tests/smoke.php` ผ่าน **7 checks** เดิม
- db-migrate --plan, db-preflight --offline และ db-health --offline ทำงาน; health offline รายงาน PHP 8.4.27 / PDO mysql ไม่แสดง credentials
- ทดสอบ **negative execution guards** ด้วย gates=false: migration apply และ integration execute คืน exit 1 พร้อมข้อความ Phase B not enabled ก่อนสร้าง connection จึงไม่มี DDL/DML จริง
- integration harness แบบไม่ใส่ --execute แสดงแผนเท่านั้น ไม่ connect

ระหว่างตรวจพบปัญหา relative DLL path เมื่อ cwd เป็นภาษาไทยและ assertion ที่นับ ON DELETE ใน comment แก้ wrapper/lexer-based check แล้ว ผลข้างต้นเป็นผลหลังแก้ ไม่ใช่ผลก่อนแก้

## 6. สิ่งที่ยังไม่ผ่านการทดสอบจริง

ยังไม่มี PDO authentication, schema import, real FK/unique/CHECK enforcement, generated-column behavior, migration ledger on MySQL, actual grants, rollback, two-connection concurrency หรือ cleanup execution ที่ผ่านจริง เพราะ **ยังไม่เริ่ม Phase B และไม่มีบัญชีตรวจสอบ** Harness ที่เตรียมไว้ไม่ใช่การยืนยันระบบจอง End-to-End

เตรียม harness สำหรับ FK/unique/CHECK/valid expense/invalid state/หนึ่ง active/replacement รถและคนขับ/completed release และ concurrent vehicle/driver/same-resource/boundary scenarios แต่ยังไม่อ้างผล Integration PASS

ไม่รัน browser/print tests ใหม่ใน Task 03 เพราะห้ามเปลี่ยน UI และไม่ได้เปลี่ยนไฟล์ UI ผล UI ก่อนหน้าอยู่ form-ui-verification.md

## 7. ความพร้อม ความเสี่ยง และคำขออนุมัติ

พร้อมให้ตรวจทานแผนและ source แต่ยังยืนยันฐานจริงไม่ได้ ต้องมี credentials ของผู้ดูแลที่ได้รับอนุญาต/บัญชีโครงการเฉพาะผ่าน .env หรือช่องทางลับ ไม่ใช้ credential Lost & Found และตรวจ existence ก่อนเขียนทุกครั้ง

ความเสี่ยงหลัก: ยังไม่ทราบว่ามี target เดิมหรือไม่; DDL อาจ commit บางส่วน; grants ที่มี underscore ต้องตั้ง scope ถูกต้อง; schema/execution/harness ยังไม่ผ่าน MySQL integration และกติกาการเลือกค่าใช้จ่าย/requiredness/Delegate authority บางส่วนยังรอยืนยันก่อนทำ feature จริง

**Phase B: ไม่ได้เริ่ม** — ไม่สร้าง database/user, ไม่รัน migration หรือ fixtures, ไม่เปลี่ยนข้อมูลเดิม

ขอผู้ใช้อนุมัติ Phase B ตามแผนนี้ก่อน โดยหลังอนุมัติยังต้องตรวจชื่อฐาน/account เดิมด้วย credential ที่ได้รับอนุญาต หากมีอยู่แล้วหรือสภาพไม่ตรงรายงาน จะหยุดให้ผู้ใช้ review ก่อนเปลี่ยนตามข้อกำหนดในคำขอ “หยุดทำงานหลัง Phase A และขออนุญาตผู้ใช้ก่อนสร้างฐานข้อมูล บัญชี หรือรัน Migration จริง”
