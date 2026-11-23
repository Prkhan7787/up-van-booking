# Task 04B-6 — Migration 004 Execution Readiness

**ขอบเขต:** ตรวจ Source/SQL และ Offline Tests เท่านั้น ไม่มีการเชื่อมต่อหรือเขียนฐานข้อมูล ไม่เปลี่ยน Grants, Credentials, `.env` หรือ Gates และไม่แตะ Production/Lost & Found

## สรุปจาก Preflight ที่ผู้ดูแลรายงาน

ฐาน `up_van_booking_test`, MySQL 8.4.9, Ledger 001–003 valid, Migration 004 ยังไม่ apply, preconditions ผ่าน, `users` มี 0 แถว และ Test Migrator grants ยัง Unverified (1142) ข้อมูลนี้เป็นผลที่ผู้ดูแลแจ้ง ไม่ได้ตรวจซ้ำใน Task นี้

## Test Migrator Grant Verification Plan

ไม่พบ SQL provisioning script ที่สร้าง/GRANT สิทธิ์บัญชี `up_van_test_migrator` โดยตรงใน Source ที่ตรวจพบ แผนใน [task03-database-setup.md](task03-database-setup.md) ระบุ Test migration เหมือน Main migration แต่ไม่ได้รวม ALTER ส่วน `verify-database-readonly.php` คาด privilege รวมแบบ exact และระดับ database เท่านั้น จึงจะรายงาน mismatch หากเพิ่ม table-scoped ALTER โดยยังไม่ปรับ verifier แยกต่างหาก

ให้ผู้ดูแลตรวจบัญชีตัวเองโดยไม่ใช้ root และไม่ส่งรหัสผ่านใน command line:

```powershell
mysql --no-defaults --host=127.0.0.1 --port=3306 --user=up_van_test_migrator --password up_van_booking_test
```

ให้พิมพ์รหัสผ่านเมื่อ CLI prompt เท่านั้น แล้วรันคำสั่งอ่านอย่างเดียว:

```sql
SELECT DATABASE() AS database_name, CURRENT_USER() AS account_identity, VERSION() AS server_version;
SELECT @@GLOBAL.partial_revokes AS partial_revokes;
SHOW GRANTS FOR CURRENT_USER;
```

ตรวจว่าฐานเป็น `up_van_booking_test`, account เป็นบัญชี Test Migrator ที่ host ตรงกับการเชื่อมต่อ และ grants ไม่มี global scope, `GRANT OPTION`, `DROP`, role grant หรือสิทธิ์ไปฐานอื่น การรัน Migration 004 ต้องมี `ALTER` เฉพาะตาราง `users` (`ALTER ON up_van_booking_test.users`) แยกจากสิทธิ์สร้างตาราง/ledger ใน schema Test เท่านั้น `ConnectionFactory` อนุญาต ALTER เฉพาะ table `users` สำหรับ migration purpose และปฏิเสธ ALTER สำหรับ runtime; ไม่มี ALTER/DROP ใน allowlist สำหรับ runtime

ข้อควรตรวจ: Grant plan เก่าไม่ระบุ ALTER และบัญชี Preflight ได้ 1142 เมื่อพยายามอ่าน Grants ของ Migrator ดังนั้นสิทธิ์จริงยัง **Unverified** ห้ามเพิ่มสิทธิ์โดยคาดเดา หากต้องแก้ Grant ต้องให้ผู้ดูแลที่มีอำนาจอนุมัติสิทธิ์รายตารางและทบทวน `verify-database-readonly.php` ก่อน

## Migration Execution Safety

- Candidate 004 อยู่ใน Manifest เป็น `forward`; SHA-256 `d63494ce561d8e8de7eb6c1d793404a7b6c5179f3168719be37c844238966dd1`; 2 statements
- Runner pin version/path/checksum/count/object metadata และอนุญาตเฉพาะ ALTER ที่ตรวจทานไว้กับ CREATE TABLE ที่ตรงรูปแบบที่ pin เท่านั้น; 001–003 checksum เดิมไม่เปลี่ยน
- ก่อน apply Runner ตรวจ target, Gate, database identity/current account, schema charset/collation, ledger order/checksums และ object inventory; มี precondition หยุดเมื่อพบคอลัมน์/table ของ 004 อยู่ก่อน
- หลัง apply มี postconditions ตรวจ username/auth_version, unique/check constraints และ rate-bucket table/columns
- คำสั่งเตรียมไว้สำหรับอนาคตเท่านั้น และ **ห้ามรันจนกว่าจะได้รับอนุมัติแยก**:

```powershell
Set-Location -LiteralPath 'C:\จองรถตู้\up-van-booking'
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/php.ps1 bin/db-migrate.php --apply --confirm=up_van_booking_test
```

คำสั่งนี้ต้องใช้ `TEST_DB_EXECUTION_APPROVED=true` ที่เปิดโดยผู้ดูแลหลังอนุมัติเท่านั้น ส่วน Production Gate ต้องคง `false`; ตอนนี้ไม่ได้เปิด Gate ใด ๆ

MySQL DDL อาจ implicit commit: statement ALTER อาจ commit ก่อน statement CREATE TABLE หรือก่อน ledger ถูกปรับเป็น applied ข้อผิดพลาด/โปรเซสหยุดอาจทิ้ง `started`/`failed` ledger หรือ schema บางส่วนไว้ Runner จะหยุดและไม่ auto-retry, rollback DDL, DROP หรือซ่อม schema

## Backup / Recovery Plan

ก่อน execution ที่ได้รับอนุมัติ ให้ผู้ดูแล:

1. หยุด writer/test worker ที่ใช้ฐานทดสอบ และตรวจ preflight/ledger/object inventory ล่าสุด
2. สร้าง credential login path หรือ option file ที่ ACL จำกัดเฉพาะผู้ดูแล; ห้ามวาง password ในคำสั่ง, transcript, repository หรือรายงาน
3. dump เฉพาะ `up_van_booking_test` ด้วย `mysqldump --single-transaction --routines --events --triggers --databases up_van_booking_test` ไปยังโฟลเดอร์ backup นอก repository ที่จำกัด ACL
4. ตรวจไฟล์ dump ไม่ว่าง, คำนวณ SHA-256 และบันทึกเวลาขนาดไฟล์/hash ใน change record
5. หากต้องพิสูจน์การ restore ให้ใช้ฐาน scratch แยกที่ผู้ดูแลอนุมัติเท่านั้น; อย่า restore ทับฐาน Test เดิมใน Task นี้

ถ้า Migration ล้มเหลว: หยุดทันที, คงฐานเดิมไว้, เก็บ safe output, ตรวจ `schema_migrations` และ `INFORMATION_SCHEMA` แบบ read-only เทียบกับ backup และ statement count; ห้าม retry/ลบ object อัตโนมัติ การกู้คืนต้องมีรายงาน partial state และแผน forward repair หรือ controlled adoption ที่อนุมัติแยก การ restore backup ลง target เดิมเป็นการเขียน/ทับข้อมูลและต้องขออนุมัติเฉพาะกิจ

ยังไม่ได้สร้าง Backup ใน Task นี้และไม่มีการตรวจว่ามี `mysqldump`/login path พร้อมใช้งานบนเครื่องจริง

## Postflight Checklist (หลังได้รับอนุมัติในอนาคต)

เริ่มทุกครั้งด้วยการยืนยัน `DATABASE()`, `CURRENT_USER()`, `VERSION()` ต้องเป็น Test เท่านั้น จากนั้นตรวจ:

```sql
SELECT version, checksum, status, completed_statements, total_statements
FROM schema_migrations WHERE version='004_auth_security';

SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, CHARACTER_SET_NAME, COLLATION_NAME,
       COLUMN_DEFAULT, CHARACTER_MAXIMUM_LENGTH
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA='up_van_booking_test' AND TABLE_NAME='users'
  AND COLUMN_NAME IN ('username','auth_version');

SELECT TABLE_NAME, CONSTRAINT_NAME, CONSTRAINT_TYPE
FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
WHERE TABLE_SCHEMA='up_van_booking_test'
  AND (TABLE_NAME='users' OR TABLE_NAME='auth_login_buckets');

SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, COLUMN_NAME
FROM INFORMATION_SCHEMA.STATISTICS
WHERE TABLE_SCHEMA='up_van_booking_test'
  AND (TABLE_NAME='users' AND INDEX_NAME='uq_users_username'
       OR TABLE_NAME='auth_login_buckets');

SELECT ENGINE, TABLE_COLLATION FROM INFORMATION_SCHEMA.TABLES
WHERE TABLE_SCHEMA='up_van_booking_test' AND TABLE_NAME='auth_login_buckets';
```

Expected: ledger 004 applied with manifest checksum, completed/total `2/2`; username nullable ASCII `varchar(50)` and unique/check constraints; `auth_version bigint unsigned NOT NULL DEFAULT 1`; `auth_login_buckets` InnoDB, expected collation, binary digest and indexes/check. Also run Runner read-only status and compare actual object inventory. Confirm 001–003 unchanged and Production/Lost & Found untouched. If any mismatch, stop and do not retry.

## Atomic Rate Limiter MySQL Test Plan

`PdoLoginRateLimitStore` currently counts **all** login attempts (successful included) and uses one transaction, deterministic bucket lock order, `INSERT IGNORE`, `SELECT ... FOR UPDATE`, then updates both HMAC buckets atomically. Task 04B-5 proposed failed-only counting, but that proposal is not implemented. Resolve policy/schema before validating failed-only behavior.

After Migration 004 and separate approval for a test that writes data:

1. Use only `up_van_test`/`up_van_booking_test`; create unique run-scoped HMAC digests in memory, never raw usernames/IPs in the table.
2. Start synchronized worker processes against the real MySQL 8.4/InnoDB store. For one username across unique IPs expect exactly 5 reservations accepted, sixth and later denied. For one IP across unique usernames expect exactly 20 accepted, twenty-first denied. For the same username/IP pair expect the stricter username cap.
3. Verify the 900-second window boundary, rollback/failure behavior, zero raw identifier/IP storage and no cross-target connection.
4. In `finally`, delete only the exact test-run `(scope,digest)` keys using bound parameters and verify no residue. If cleanup fails, report residue; never broad-delete or reset the schema.

Offline boundary/static tests do not prove MySQL lock scheduling, deadlock behavior, crash recovery, or isolation under concurrency; these require the approved Integration Test.

## Runtime Account Review

Source policy uses `allowDdl=false` for runtime connections, whose allowlist excludes ALTER and DROP; offline tests reject both. The verifier also declares runtime expected privileges without DDL. This proves the connection guard's policy, **not live MySQL grants**; actual runtime Grants were not inspected in this task. Migration accounts remain separate and must not be used by the website.

## Blockers and Recommendation

- **Blocker:** Test Migrator grants are Unverified (reported 1142); exact ALTER-on-users permission and schema DDL privileges need evidence from Migrator's own `SHOW GRANTS`.
- **Blocker:** Existing Grant documentation/verifier does not include the newly required table-scoped ALTER, so verification workflow must be reconciled before execution.
- **Blocker:** Backup tooling, protected destination, backup integrity and restoration rehearsal are not yet confirmed.
- **Decision needed:** current atomic limiter counts successful attempts; choose this policy or approve a failed-only schema/implementation amendment.
- **Not established here:** no fresh preflight/postflight, no MySQL concurrency test, and no live runtime SHOW GRANTS.

**Recommendation: NO-GO for Migration 004 execution.** Offline source review supports a controlled Test-only run after the blockers are cleared and explicit migration approval is given. This task made no database changes and stops here.
