# Task 03 — Database setup, safety และวิธีใช้ CLI

**Phase A เท่านั้น:** เตรียม source และแผนแล้ว ยังไม่สร้างฐานข้อมูล/บัญชีหรือรัน migration ต้องให้ผู้ใช้อนุมัติรายงาน Phase A ก่อน Phase B การมีคำสั่งในเอกสารไม่ใช่การอนุญาตให้ดำเนินการ

## 1. ขอบเขตและ configuration

ฐานหลัก `up_van_booking` และฐานทดสอบที่เสนอ `up_van_booking_test` เท่านั้น ห้ามเลือก Lost & Found หรือ database อื่น ทุก connection ใช้ PDO native prepared statements, exception mode, utf8mb4, UTC และปิด multi-statements ไม่มี endpoint health-check สาธารณะ

`.env` อยู่นอก public และไม่ commit ดูตัวอย่างใน `.env.example` ยังไม่มี credential จริง Account แต่ละ purpose ต้องแยก password และใช้ชื่อขึ้นต้น up_van_:

| Purpose | Environment prefix | Database | ชื่อที่เสนอ |
|---|---|---|---|
| เว็บไซต์/runtime | DB_ | up_van_booking | up_van_app |
| Migration | MIGRATION_DB_ | up_van_booking | up_van_migrator |
| Read-only preflight (หากจัดให้) | PREFLIGHT_DB_ | up_van_booking | up_van_preflight |
| Integration DML | TEST_DB_ | up_van_booking_test | up_van_test |
| Test migration | MIGRATION_TEST_DB_ | up_van_booking_test | up_van_test_migrator |

ค่า HOST/PORT ของแต่ละ prefix ใช้ DB_HOST/DB_PORT เป็น default รับเฉพาะ localhost/127.0.0.1/::1 ไม่เปลี่ยน service/port/firewall/root password ค่า database รับเฉพาะชื่อที่ระบุตาม purpose ไม่รับชื่ออื่นหรือ root user

`app/Core/Database.php` คง facade เดิม และเรียก `app/Database/ConnectionFactory.php`; `config/database.php` เป็น facade ของ config เดียวกัน ไม่มี business logic ใน Views/Controllers อ่าน grants ของบัญชีตนเองเพื่อตรวจ scope และปฏิเสธ global privileges, inherited role grants, GRANT OPTION และ privileges เกิน allowlist ไม่แสดง grant text/DSN/password/exception trace ต่อเว็บหรือ CLI

## 2. PHP CLI แยกจากโปรเจกต์อื่น

PHP ใน PATH ของเครื่องชี้ runtime ใน Lost & Found จึงไม่ใช้ใน Task 03 ดาวน์โหลด portable **PHP 8.4.27 NTS x64** จาก php.net ไป `.runtime/php84` เท่านั้น ตรวจ SHA256 ZIP:

```text
dbae154a0a89791f36c3d48e68bae797878333e7d6cb5721e74617de59b63ab7
```

แหล่งทางการ: [PHP Windows 8.4 downloads](https://www.php.net/downloads.php?version=8.4&os=windows) ไม่เปลี่ยน PATH, registry, IIS, runtime ของโปรเจกต์อื่น หรือ MySQL ตัด `.runtime/` ออกจาก Git

ใช้ wrapper ต่อไปนี้เพื่อปิดการอ่าน php.ini ที่อื่นและโหลด extension จาก project-relative path (รองรับ working directory ภาษาไทย):

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/php.ps1 bin/db-health.php --offline
```

ExecutionPolicy Bypass ใช้เฉพาะ process ของคำสั่ง ไม่แก้ policy ระบบ Wrapper โหลด pdo_mysql/mbstring และไม่มี session สำหรับ CLI การเตรียมนี้ยังไม่ได้เปลี่ยน FastCGI ของเว็บ

## 3. Migration sequence และผล review

| Version | File | Statements / สิ่งที่สร้าง |
|---|---|---|
| 001_identity | database/migrations/001_identity.sql | 4 CREATE TABLE: roles, users, user_roles, audit_logs |
| 002_booking_design | database/migrations/002_booking_design.sql | 9 CREATE TABLE + calendar_events view |
| 003_roles | database/seeds/003_roles.sql | 1 INSERT reference roles 4 ประเภท ไม่มี users |

`database/manifest.php` pin SHA256 ทุกไฟล์ ดู checksum/SQL summary ที่ `docs/task03-migration-plan.json` Role seed แยกจาก DDL และไม่มี test data อยู่ใน migration

**ไม่ใช้ database/schema.sql เดิมใน Phase B**: เก็บไฟล์ Task 01 เป็นประวัติ ไม่แก้ schema ของฐานข้อมูลเดิม และไม่ import ไฟล์เดิมซ้ำร่วมกับ runner สำเนา 001 เปลี่ยนเพียง collation ให้ตรง Task 03 และแยก seed ออก

Candidate 002 แก้:

- collation ทุกตารางเป็น utf8mb4_unicode_ci ตามคำขอ (13 ตารางธุรกิจรวม 001 ใช้แบบเดียวกัน)
- expense choice `driver_personal_funds` → `driver_extra_pay` หมายถึง **เงินเพิ่มพิเศษพนักงานขับรถ** ไม่ใช่เงินส่วนตัว ไม่เปลี่ยนข้อความฟอร์มหรือการเลือกใน UI; single/multiple expense semantics ยังต้องยืนยันก่อนพัฒนาการกรอกจริง
- เอา explicit ON DELETE RESTRICT ออกจาก candidate ที่มี FK columns อยู่ใน CHECK expressions เพื่อสอดคล้องข้อจำกัด CHECK/referential actions ของ MySQL โดยยังใช้พฤติกรรม InnoDB default NO ACTION/RESTRICT ไม่มี cascading deletes
- view ใช้ SQL SECURITY INVOKER ไม่ยกระดับสิทธิ์ผ่าน definer

คง workflow, 10 operational states, approved/rejected แยกใน decisions, generated unique key หนึ่ง active ต่อ booking, replacement history, reviews/notifications และไม่เพิ่ม role หรือบัญชีคนขับ

ตรวจแบบ static: ลำดับ parents ก่อน children, FK types, indexes, CHECK, generated/unique, string index lengths อยู่ในขนาด InnoDB ทั่วไป ไม่มี DROP/ALTER/USE/ปิด FK checks แต่ **ยังไม่ได้พิสูจน์ DDL บน MySQL จริง** ดู [CHECK constraints](https://dev.mysql.com/doc/refman/8.4/en/create-table-check-constraints.html), [FK behavior](https://dev.mysql.com/doc/refman/8.4/en/create-table-foreign-keys.html)

## 4. CLI ที่อ่านอย่างเดียว / offline

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/php.ps1 bin/db-migrate.php --plan
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/php.ps1 bin/db-preflight.php --offline
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/php.ps1 bin/db-health.php --offline
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/php.ps1 tests/database/offline.php
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/php.ps1 tests/database/integration.php
```

ทั้งชุดนี้ไม่เชื่อม DB Health offline ยืนยัน PHP/PDO driver กับ checksum เท่านั้น ไม่รายงานว่าเชื่อมต่อสำเร็จ

หากมี dedicated credentials ที่ได้รับอนุญาต คำสั่ง `bin/db-preflight.php --connect` query metadata เฉพาะชื่อเป้าหมาย ไม่สร้างอะไร ถ้าไม่เห็นชื่อจะรายงาน **not_visible** ไม่สรุปว่าไม่มีฐาน เพราะ INFORMATION_SCHEMA จำกัด visibility ตามสิทธิ์ DBA ต้องยืนยัน absence ก่อนสร้าง หากพบชื่อเดิมต้องรายงานให้ผู้ใช้ review ก่อนเปลี่ยน

`bin/db-health.php --connect` ใช้ runtime account ตรวจ version/database/tables+engine+collation/ledger/checksum และคืน exit 2 ถ้ายังขาด objects หรือ migration dirty ไม่มี public route และไม่ query rows ของโปรเจกต์อื่น

## 5. แผน SQL/สิทธิ์สำหรับ Phase B — ยังไม่ดำเนินการ

ผู้ดูแลที่ได้รับอนุญาตต้องตรวจเฉพาะชื่อฐาน `up_van_booking` / `up_van_booking_test` และชื่อ account ที่เสนอว่ามีอยู่แล้วหรือไม่ ไม่ลอง password ไม่อ่าน credential ของ Lost & Found ไม่ reset ของเดิม

หาก DBA ยืนยันว่าชื่อยังไม่มีและผู้ใช้อนุมัติ จึงสร้างด้วย SQL เหล่านี้ (ไม่มี IF NOT EXISTS เพื่อไม่ซ่อนกรณีชื่อเดิม):

```sql
CREATE DATABASE up_van_booking CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE up_van_booking_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Account creation ต้องเป็น CREATE USER เท่านั้น ไม่ใช้ ALTER USER/CREATE OR REPLACE ชื่อซ้ำให้หยุด ใช้ host localhost หรือ 127.0.0.1 ที่ตรงการเชื่อมต่อจริง ไม่ใช้ '%' รหัสแต่ละบัญชีสุ่มอย่างน้อย 32 bytes ผ่านช่องทางลับและ bind เป็น parameter ใน administrative client ที่รองรับ CREATE USER prepared statement ห้ามใส่ password ใน command line, --password=, terminal transcript, screenshot หรือไฟล์ SQL ที่ commit ไม่แสดงตัวอย่างรหัสที่นำไปใช้จริงได้

แม่แบบ SQL account creation แสดงเป็น pseudocode ที่ต้องแทนด้วย secret parameter ผ่านเครื่องมือ provisioning ที่ได้รับอนุญาต ไม่ใช่คำสั่งรันใน Phase A:

```text
CREATE USER 'up_van_app'@'localhost' IDENTIFIED BY <secret bound privately>;
CREATE USER 'up_van_migrator'@'localhost' IDENTIFIED BY <different secret bound privately>;
CREATE USER 'up_van_test'@'localhost' IDENTIFIED BY <different secret bound privately>;
CREATE USER 'up_van_test_migrator'@'localhost' IDENTIFIED BY <different secret bound privately>;
```

สิทธิ์เสนอให้ผู้ใช้ review:

| Account | Scope / privileges |
|---|---|
| Runtime | SELECT เฉพาะ 13 business tables + calendar_events + schema_migrations ของ up_van_booking, INSERT audit_logs เท่านั้นใน Task 03; เพิ่ม DML ราย feature เมื่อได้รับอนุญาตใน Task ถัดไป |
| Main migration | CREATE, CREATE VIEW, INDEX, REFERENCES, SELECT, INSERT, UPDATE เฉพาะ up_van_booking ไม่มี DROP/ALTER/GRANT OPTION |
| Test migration | แบบเดียวกับ main migration แต่เฉพาะ up_van_booking_test |
| Test harness | SELECT/INSERT/UPDATE/DELETE เฉพาะ up_van_booking_test ไม่มี DDL/global privileges |
| Preflight หากจัดให้ | SELECT เฉพาะ target objects ที่อนุญาต ไม่มี DDL; visibility ที่จำกัดไม่ใช้พิสูจน์ absence |

คำสั่ง GRANT จะแตกตามตาราง/ฐานข้างต้นเท่านั้น ห้าม ON *.* หรือ ALL PRIVILEGES หมายเหตุชื่อ database มี underscore: สำหรับ database-level grants ต้องตรวจ `@@GLOBAL.partial_revokes` ก่อน หาก OFF ให้ escape `_` เป็น `\_` (เช่น `up\_van\_booking`.*) หาก ON ใช้ literal name ส่วน table-level grants ใช้ชื่อ database literal เสมอ ไม่แก้ global variable เพื่อแก้ปัญหา Scope guard ตรวจรูปแบบเหล่านี้ก่อนคืน connection [MySQL GRANT wildcard rules](https://dev.mysql.com/doc/refman/8.4/en/grant.html)

Admin provisioning ต้องเสร็จและบันทึกผลก่อนเปิด gates ใน .env ภายหลังผู้ใช้อนุมัติเท่านั้น: DB_PHASE_B_APPROVED=true และ TEST_DB_EXECUTION_APPROVED=true โดยยังคง false ใน Phase A

## 6. Migration runner safety / Recovery

Phase B ที่ได้รับอนุมัติแล้วเท่านั้น:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/php.ps1 bin/db-migrate.php --apply --confirm=up_van_booking
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/php.ps1 bin/db-migrate.php --apply --confirm=up_van_booking_test
```

- ตรวจ target, MySQL version, database charset/collation และ grants ก่อน DDL
- โหลด checksum-pinned allowlisted SQL จากไฟล์ใน project ไม่รับ SQL/path จาก user input
- GET_LOCK ต่อชื่อฐานเพื่อกัน runner ซ้อน ไม่ล็อก service/ฐานของโปรเจกต์อื่น
- ถ้ามี objects โดยไม่มี ledger ให้หยุด ไม่มี automatic adoption แม้มีเฉพาะตาราง Task 01
- ledger schema_migrations บันทึก version/checksum/started/applied/failed/completed statement count ตรวจ order และ object inventory ก่อนรัน pending
- checksum เปลี่ยน/dirty/unexpected objects/ledger ลำดับผิด ให้หยุด ไม่เขียนทับและไม่ mark applied เอง
- DDL มี implicit commit จึงไม่ครอบ DDL ทั้งชุดด้วย transaction และไม่อ้างว่า rollback ตารางทั้งหมดได้
- error: หยุดทันที บันทึก failed หรือคง started หาก connection ขาด แสดงข้อความปลอดภัย ไม่แสดง SQL exception ที่อาจมี credential

Recovery ที่ไม่ทำลายข้อมูล: เก็บ ledger+inventory/checksum, ตรวจ SHOW CREATE TABLE เฉพาะ target ที่อนุญาตเทียบแต่ละ statement ระวัง crash window ที่ DDL commit แล้วแต่ ledger ยังไม่เพิ่ม count, ขอผู้ใช้ review corrective forward migration หรือ controlled adoption ของ objects ที่พิสูจน์ตรงกัน **ยังไม่มี automatic resume/repair command** ห้ามแก้ checksum ทับ ledger, DROP/RESET, ปิด FK checks หรือ rerun DDL ที่ไม่รู้สถานะ [Implicit commits](https://dev.mysql.com/doc/refman/8.4/en/implicit-commit.html)

## 7. Integration harness — เตรียมแล้ว ยังไม่รัน

```powershell
# Phase B only, after test schema and scoped accounts are provisioned:
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/php.ps1 tests/database/integration.php --execute --confirm=up_van_booking_test
```

ใช้ database ทดสอบเฉพาะที่ migrate ครบและไม่มี business/test rows เก่า (roles/ledger เป็น reference data ได้) ไม่สร้าง schema ใน harness และไม่ใช้ข้อมูล Lost & Found ทดสอบ FK/unique/CHECK/expense/status/หนึ่ง active/replacement รถและคนขับ/completed release พร้อม parallel workers สอง connections ใช้ barrier แล้วทำ booking → vehicle → driver locks กับ overlap query ภายใน READ COMMITTED ตรวจรถเดียว คนขับเดียว ทั้งสอง resource และเวลาชนปลายช่วงที่ต้องผ่าน

จำลอง users rows ใน **ฐานทดสอบเท่านั้น** ใช้ email example.invalid และ password_hash NULL ไม่สร้างบัญชีผู้ใช้จริงหรือ driver Login Data fixtures commit เฉพาะเพื่อให้ worker เห็น หลังจบลบเฉพาะ IDs/actor ของ run นี้ตามลำดับ FK (ไม่ broad delete) หาก error ให้หยุดและ cleanup เฉพาะ synthetic rows หาก cleanup ล้มเหลวจะไม่รายงาน PASS และห้าม reset ฐาน แยกสิทธิ์ DELETE เฉพาะ test account เท่านั้น

Harness ยังไม่ใช่ application service/E2E จึงไม่พิสูจน์ RBAC, Delegate authorization หรือระบบจองจริง การทดสอบ overlap ต้องมีการรันบน MySQL จริงใน Phase B ก่อนจึงอ้างผลได้
