# Migration 006 — isolated 3307 rehearsal, approval required

Prepared 2026-11-12. No migration or GRANT has been executed by this preparation.

## Verified locally versus operator evidence

Host read-only inspection: listener 127.0.0.1:3307, server PID 23696, monitor PID 9720;
mysqld executable in MySQL Server 8.4/bin; command line points to the restore datadir
and has no init-file. auto.cnf UUID is 37e4d222-dda2-11f1-b503-5c2886f057d5.
PIDs are observations, never arguments for execution.

Backup C:\Users\User\up_van_booking_test_pre006.sql is 44,529 bytes;
SHA256 26D5B374AF8809EEDD12A45892435FBEBB1117AB8A359E3B307C4607C4FAE766.
Migration candidate checksum is 61c0f5b4fe152c83142d49ba81070a70df2dc352a9073302809cee9696827748.

Restored counts (2 vehicles, 0 requests/assignments), applied 001–005 and absent 006
are supplied by the operator. Additional live SQL, migrator authentication/grants,
and row preservation after apply are NOT VERIFIED by the agent. Root passwords must
be entered only in the native hidden prompt; never send them to chat.

## A. Additional SELECT-only evidence, operator runs now

Windows PowerShell:

```powershell
Set-Location -LiteralPath 'C:\จองรถตู้\up-van-booking'
$mysql006 = 'C:\Program Files\MySQL\MySQL Server 8.4\bin\mysql.exe'
& $mysql006 --no-defaults --no-login-paths --protocol=TCP --host=127.0.0.1 --port=3307 --user=root --password --skip-reconnect --database=up_van_booking_test
```

Run SELECTs below; they do not change rows or schema. Do not select passwords/hashes.

```sql
SELECT @@port,@@server_uuid,@@datadir,VERSION(),DATABASE(),CURRENT_USER();
SELECT TABLE_TYPE,COUNT(*) FROM information_schema.TABLES
WHERE TABLE_SCHEMA='up_van_booking_test' GROUP BY TABLE_TYPE;
SELECT version,checksum,status,completed_statements,total_statements
FROM schema_migrations ORDER BY version;
SELECT (SELECT COUNT(*) FROM vehicles) AS vehicles,
 (SELECT COUNT(*) FROM booking_requests) AS bookings,
 (SELECT COUNT(*) FROM vehicle_assignments) AS assignments,
 (SELECT COUNT(*) FROM users) AS users,
 (SELECT COUNT(*) FROM roles) AS roles,
 (SELECT COUNT(*) FROM user_roles) AS user_roles,
 (SELECT COUNT(*) FROM audit_logs) AS audit_logs;
SELECT COUNT(*) AS calendar_rows FROM calendar_events;
SELECT TABLE_NAME,COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA='up_van_booking_test' AND TABLE_NAME IN ('vehicles','booking_requests')
ORDER BY TABLE_NAME,ORDINAL_POSITION;
SELECT INDEX_NAME,COLUMN_NAME,SEQ_IN_INDEX,NON_UNIQUE FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA='up_van_booking_test' AND TABLE_NAME='vehicles' ORDER BY INDEX_NAME,SEQ_IN_INDEX;
SELECT COUNT(*) AS duplicate_registration_groups FROM
 (SELECT registration_number FROM vehicles GROUP BY registration_number HAVING COUNT(*)>1) AS duplicates;
SELECT User,Host,account_locked FROM mysql.user
WHERE User='up_van_test_migrator';
SHOW GRANTS FOR 'up_van_test_migrator'@'127.0.0.1';
```

Expected identity: port 3307 / pinned restore UUID / datadir
C:\Users\User\mysql-restore-006-data. Expected inventory from the reviewed backup:
15 BASE TABLEs and one view; no migration 006, vehicles=2, bookings=0, assignments=0.
Capture the other counts as baseline; do not guess their values.

The database dump does not establish that MySQL accounts/grants were copied.
If the migrator does not exist or cannot authenticate at 3307, STOP: a separate
account/grant plan and explicit approval are needed. Do not use root in the runner,
weaken the grant guard, or copy plaintext credentials into files.

## B. Controlled runner preflight at 3307, no gates opened

Only after the existing test migrator can authenticate on 3307 and its 006 grants
are approved. The CLI reuses the same migration_test_006 account/guard and Runner.
The extra flag pins configuration port before connecting, then validates actual
port/UUID/datadir/database/account on the same PDO, including before apply.

Use a dedicated PowerShell terminal. The script does not edit .env.

```powershell
$previous006Port = [Environment]::GetEnvironmentVariable('MIGRATION_TEST_DB_PORT','Process')
try {
    $env:MIGRATION_TEST_DB_PORT='3307'
    powershell.exe -NoProfile -ExecutionPolicy Bypass -File '.\scripts\php.ps1' '.\bin\db-migration-006.php' --preflight --restore-test-3307 '--backup=C:\Users\User\up_van_booking_test_pre006.sql'
    if ($LASTEXITCODE -ne 0) { throw '3307 preflight failed; stop, no apply' }
} finally {
    [Environment]::SetEnvironmentVariable('MIGRATION_TEST_DB_PORT',$previous006Port,'Process')
}
```

No password override is supplied. Existing configured migrator credentials must be
valid on the restore instance; authentication failure is not permission to reset it.
Send the safe results and obtain separate approval BEFORE section C.

## C. Future 3307 apply — NOT approved or executed in this preparation

After A/B PASS, verified backup hash and approval, use a dedicated terminal with
all non-migration gates closed. Record baseline vehicle fields locally without
sharing row data. Keep the application pointed at 3306; no traffic/writes to 3307.

```powershell
$previous006Port = [Environment]::GetEnvironmentVariable('MIGRATION_TEST_DB_PORT','Process')
$previous006Gate = [Environment]::GetEnvironmentVariable('TEST_DB_EXECUTION_APPROVED','Process')
try {
    $env:MIGRATION_TEST_DB_PORT='3307'
    $env:TEST_DB_EXECUTION_APPROVED='true'
    powershell.exe -NoProfile -ExecutionPolicy Bypass -File '.\scripts\php.ps1' '.\bin\db-migration-006.php' --apply --restore-test-3307 --confirm=up_van_booking_test '--backup=C:\Users\User\up_van_booking_test_pre006.sql'
    if ($LASTEXITCODE -ne 0) { throw 'STOP: possible partial DDL; never retry automatically' }
} finally {
    [Environment]::SetEnvironmentVariable('TEST_DB_EXECUTION_APPROVED',$previous006Gate,'Process')
    [Environment]::SetEnvironmentVariable('MIGRATION_TEST_DB_PORT',$previous006Port,'Process')
}
```

Two ALTERs are not atomic together. Any error: preserve ledger/schema/logs, do not
rerun DDL, edit ledger, drop columns or restore over a running database automatically.
The existing runner acquires a named lock and rejects dirty/partial ledger/schema.

## D. Postflight and preservation checks

Repeat section B using --postflight instead of --preflight, keeping --restore-test-3307.
The mode does not open an execution gate. Require applied 006 with matching checksum,
2/2 statements, province varchar(100) nullable, requested_vehicle_id bigint unsigned
nullable, idx_booking_requested_vehicle, FK to vehicles(id), and retained registration-only UNIQUE.

With the section A root client pinned to 3307, repeat all counts and run:

```sql
SELECT COUNT(*) AS vehicles,COUNT(registration_province) AS populated_provinces FROM vehicles;
SELECT COUNT(*) AS bookings,COUNT(requested_vehicle_id) AS requested_vehicles FROM booking_requests;
SHOW CREATE TABLE vehicles;
SHOW CREATE TABLE booking_requests;
SELECT COUNT(*) AS calendar_rows FROM calendar_events;
```

Expected counts remain vehicles=2 / bookings=0 / assignments=0; existing vehicle
fields and all unrelated table counts must match baseline. Provinces remain NULL,
not fabricated/backfilled. Additional ledger row is expected; no user/password changes.
Offline service tests do not replace these live preservation checks.

## E. Separate 3306 plan — NO APPLY authorization

In a separate native root session explicitly pinned --port=3306, inspect identity
and SHOW GRANTS for up_van_test_migrator@127.0.0.1 and up_van_auth_test@127.0.0.1.
Do not reuse the 3307 connection/environment or assume its grants apply at 3306.

Previously missing migrator delta (must recheck live before approval):

```sql
-- PROPOSAL ONLY, do not run automatically:
GRANT ALTER, INSERT ON `up_van_booking_test`.`booking_requests`
TO 'up_van_test_migrator'@'127.0.0.1';
GRANT REFERENCES (`id`) ON `up_van_booking_test`.`vehicles`
TO 'up_van_test_migrator'@'127.0.0.1';
```

Existing database CREATE coverage and ledger grants must be reviewed, not duplicated.
Root cannot substitute for the fixed migrator in MigrationRunner::apply(). No Runtime DDL.

Only after rehearsal succeeds: freeze writes, take a NEW 3306 backup, verify it,
review current ledger/schema and exact grant delta, obtain separate GRANT approval,
then run existing CLI --preflight at explicitly configured port 3306. Actual 3306
apply requires another explicit approval; no 3306 apply command is authorized here.

After a separately approved successful migration and postflight, propose only missing
Runtime SELECT/INSERT/UPDATE(registration_province) on vehicles, plus SELECT(requested_vehicle_id)
on booking_requests for Calendar. These grants must wait until columns exist.
Province writes need the existing Vehicle Write Gate, CSRF/RBAC, validation and audit.
Booking POST remains closed. Verify Login, vehicle load/edit, active vehicle dropdown,
vehicle-specific availability and A4 before approving any province save.
