# Pre-006 backup restore verification — review only

No Restore, instance creation, grant change, migration or database connection was executed.

## Artifact checked locally

- File: C:\Users\User\up_van_booking_test_pre006.sql
- Bytes: 44529
- SHA256: 26D5B374AF8809EEDD12A45892435FBEBB1117AB8A359E3B307C4607C4FAE766
- Dump contains CREATE DATABASE IF NOT EXISTS up_van_booking_test and two USE statements
  selecting that name. Supplying a different default database does NOT redirect this dump.
- 15 base table definitions and calendar_events view found.
- Offline parsing of INSERT tuple boundaries: vehicles=2, booking_requests=0,
  vehicle_assignments=0, schema_migrations=5. No row data or credentials were displayed.
- Dump ledger: 001–005 applied, statement counts 4/4, 10/10, 1/1, 2/2, 1/1;
  checksums match the project's pinned values. 006 is absent.
- registration_province and requested_vehicle_id definitions are absent, as expected for 005.
- Dump includes a DEFINER and an SQL SECURITY INVOKER view. Restore errors/warnings
  involving DEFINER must be reviewed, not worked around by changing live grants/accounts.
- Dump includes binary auth_login_buckets data and _binary literals. Do not transcode
  the dump with Get-Content/Set-Content or a text editor. Restore the original bytes.

## Destination policy

Use an administrator-prepared, disposable MySQL **8.4.9 instance** on 127.0.0.1:3307,
with a separate data directory and a different server_uuid from the live port 3306.
It must contain only the standard system schemas, with no existing user data/schema.
Do not repoint the website, .env, runtime or migrator to this instance.

Neither the isolated instance's existence nor its identity/emptiness has been verified.
NO-GO to executing Restore until those checks and separate user approval are complete.
Do not create a differently named schema on the current server as a substitute: the
dump's embedded CREATE DATABASE/USE would still select up_van_booking_test.

## Step 1 — verify the immutable backup, PowerShell

```powershell
Set-Location -LiteralPath 'C:\จองรถตู้\up-van-booking'
$restoreDump = 'C:\Users\User\up_van_booking_test_pre006.sql'
$restoreHash = '26D5B374AF8809EEDD12A45892435FBEBB1117AB8A359E3B307C4607C4FAE766'
if (!(Test-Path -LiteralPath $restoreDump -PathType Leaf)) { throw 'Backup not found' }
if ((Get-Item -LiteralPath $restoreDump).Length -ne 44529) { throw 'Backup size mismatch' }
if ((Get-FileHash -LiteralPath $restoreDump -Algorithm SHA256).Hash -ne $restoreHash) { throw 'Backup checksum mismatch' }
Write-Host 'BACKUP_IDENTITY=PASS'
```

No password is passed as an argument or saved in a file. `--password` alone prompts
interactively. Use mysql.exe from the approved MySQL 8.4 client installation.

## Step 2 — identify the live instance, SELECT only

```powershell
mysql.exe --protocol=TCP --host=127.0.0.1 --port=3306 --user=root --password --execute="SELECT @@server_uuid AS live_uuid, @@port AS live_port, VERSION() AS server_version;"
if ($LASTEXITCODE -ne 0) { throw 'Live identity check failed' }
```

Record only UUID/port/version for comparison. No Restore command belongs in this session.

## Step 3 — connect to the ISOLATED instance and STOP to review the checks

No `--force`, no automatic reconnect, no tee/transcript. Keep this same session open
for the later approved SOURCE so Restore does not silently reconnect elsewhere.

```powershell
mysql.exe --protocol=TCP --host=127.0.0.1 --port=3307 --user=root --password --default-character-set=utf8mb4 --binary-mode --skip-reconnect --show-warnings
```

At the mysql prompt, run only:

```sql
SELECT @@server_uuid AS restore_uuid, @@port AS restore_port,
       VERSION() AS server_version, @@datadir AS restore_datadir;
SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA
WHERE SCHEMA_NAME NOT IN ('mysql','information_schema','performance_schema','sys');
SELECT COUNT(*) AS target_schema_exists FROM INFORMATION_SCHEMA.SCHEMATA
WHERE SCHEMA_NAME='up_van_booking_test';
```

Required: restore_uuid != live_uuid, restore_port=3307, version=8.4.9, independently
confirmed separate data directory, zero user schemas, target_schema_exists=0.
If any fails, exit and stop. Port 3307 alone does not prove isolation. Never delete an
existing schema to make these checks pass. If the connection drops, recheck all identity
conditions before any further action. Do not create/change grants in either instance.

Send the non-secret identity/emptiness results for review; await explicit Restore approval.

## Step 4 — RESTORE: only after separate approval, in the same isolated mysql session

Immediately before SOURCE, rerun Step 1 in another PowerShell window. If the backup
changed, STOP. Ensure the mysql session is still the checked isolated session.

The command below WRITES ONLY to the separately verified disposable instance.
The schema name there intentionally matches the embedded dump name. It is not the live DB.

```sql
SOURCE C:/Users/User/up_van_booking_test_pre006.sql
```

Read the entire completion/error output. Any ERROR makes the rehearsal FAIL even if
later queries succeed: SOURCE can continue after an SQL error. Warnings must be reviewed
explicitly (especially DEFINER). Do not retry, drop/reset data, edit the dump, or change
grants automatically on failure. This is a review plan, not permission to run SOURCE now.

## Step 5 — validate restored data/structure, same isolated session

```sql
SELECT @@server_uuid AS restore_uuid, @@port AS restore_port;
SELECT TABLE_TYPE, COUNT(*) AS object_count
FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA='up_van_booking_test'
GROUP BY TABLE_TYPE;
SELECT TABLE_NAME,TABLE_TYPE,ENGINE,TABLE_COLLATION
FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA='up_van_booking_test'
ORDER BY TABLE_NAME;
SELECT version,checksum,status,completed_statements,total_statements
FROM up_van_booking_test.schema_migrations ORDER BY version;
SELECT (SELECT COUNT(*) FROM up_van_booking_test.vehicles) AS vehicles,
       (SELECT COUNT(*) FROM up_van_booking_test.booking_requests) AS booking_requests,
       (SELECT COUNT(*) FROM up_van_booking_test.vehicle_assignments) AS vehicle_assignments;
SELECT TABLE_NAME,COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA='up_van_booking_test'
  AND TABLE_NAME IN ('vehicles','booking_requests','vehicle_assignments')
ORDER BY TABLE_NAME,ORDINAL_POSITION;
SHOW CREATE TABLE up_van_booking_test.vehicles;
SHOW CREATE TABLE up_van_booking_test.booking_requests;
SHOW CREATE TABLE up_van_booking_test.vehicle_assignments;
SHOW CREATE VIEW up_van_booking_test.calendar_events;
SELECT COUNT(*) AS calendar_rows FROM up_van_booking_test.calendar_events;
CHECK TABLE up_van_booking_test.vehicles,up_van_booking_test.booking_requests,
            up_van_booking_test.vehicle_assignments;
```

Expected: same isolated UUID; 15 BASE TABLE objects plus 1 VIEW; base tables InnoDB/
utf8mb4_unicode_ci; ledger versions/checksums/status/counts exactly match Step 1 artifact
findings and database/manifest.php; no 006; vehicles=2, bookings=0, assignments=0;
province/requested_vehicle columns absent; uq_vehicle_registration_number contains
only registration_number and the old composite index is absent; FK definitions are intact;
calendar view is queryable; CHECK TABLE reports OK. Do not SELECT secret/row contents.

Required table names: approval_decisions, audit_logs, auth_login_buckets,
booking_notifications, booking_passengers, booking_requests, booking_reviews,
booking_status_history, drivers, roles, schema_migrations, user_roles, users,
vehicle_assignments, vehicles; view calendar_events.

Passing these checks plus an error-free full restore establishes a practical restore
rehearsal for this dump. It does not approve Migration 006 or prove every application's
business operation. Runtime/Migrator Grants remain a separate task.

## End state

Report restore_uuid, exact backup hash, restore errors/warnings, inventory, ledger,
counts and schema checks. No screenshot/output may contain passwords, hashes of user
passwords, tokens, binary bucket data, or private user rows. Leave the disposable
instance/data untouched until cleanup is separately authorized. Do not restore the dump
to live 3306, alter .env, open execution gates or issue GRANT/REVOKE.

Client option reference: https://dev.mysql.com/doc/refman/8.4/en/mysql-command-options.html
