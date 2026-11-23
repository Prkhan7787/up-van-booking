# Migration 006 live preparation — 2026-11-11

Decision: GO for backup/read-only inspection; NO-GO for migration execution today.
This review did not connect to MySQL, change grants, create a backup, restore data,
change credentials, open a gate or apply DDL.

## Findings

- Reviewed SQL: database/drafts/006_vehicle_province_and_requested_vehicle.sql.
- SHA-256: 61c0f5b4fe152c83142d49ba81070a70df2dc352a9073302809cee9696827748.
- Two pinned ALTER statements, opt-in via MigrationPlan::load(true). Default manifest remains 001–005.
- Province is VARCHAR(100) NULL; existing rows remain NULL. Registration-only UNIQUE is retained.
- Requested vehicle is BIGINT UNSIGNED NULL, references vehicles.id, with idx_booking_requested_vehicle.
- Live 001–005 applied / 006 absent / two vehicles are user-supplied evidence; not independently queried in this review.
- Source preflight checks baseline ledger checksum/count/order, metadata, duplicate registrations and target.
- The two ALTER statements are not one transaction. A failed second statement leaves the first committed.
  Started/failed ledger or changed schema blocks retry; preserve evidence and stop.
- db-migration-006.php now explicitly selects migration_test_006. It reuses the
  MIGRATION_TEST_DB_* credentials and validates only the test migrator identity.
  Migration006GrantPlan retains the reviewed legacy account grants and adds exactly
  booking_requests ALTER/CREATE/INSERT and vehicles REFERENCES(id). Existing database
  CREATE coverage satisfies CREATE on the child; no duplicate CREATE grant is needed.
  The old migration_test / MigrationTestGrantPlan remains 001–005 and still rejects
  the expanded scope. Use the 006 CLI after approved grant changes, not the old CLI.
- Current migrator SELECT(id) alone cannot establish full booking metadata visibility.
  Approved ALTER on booking_requests supplies object privilege visibility; avoid adding
  SELECT on all booking data merely to make the metadata check pass.
- Live effective grants, current ledger, backup existence and successful restore are NOT VERIFIED here.

## 1. Read-only evidence, root interactive prompt

```powershell
Set-Location -LiteralPath 'C:\จองรถตู้\up-van-booking'
mysql.exe --protocol=TCP --host=127.0.0.1 --port=3306 --user=root --password --database=up_van_booking_test
```

Run only these read-only statements, then exit:

```sql
SELECT DATABASE(), CURRENT_USER(), VERSION(), @@server_uuid, @@GLOBAL.partial_revokes;
SELECT version, checksum, status, completed_statements, total_statements
FROM up_van_booking_test.schema_migrations ORDER BY version;
SELECT COUNT(*) AS vehicles FROM up_van_booking_test.vehicles;
SELECT COUNT(*) AS booking_requests FROM up_van_booking_test.booking_requests;
SELECT COUNT(*) AS vehicle_assignments FROM up_van_booking_test.vehicle_assignments;
SHOW CREATE TABLE up_van_booking_test.vehicles;
SHOW CREATE TABLE up_van_booking_test.booking_requests;
SHOW GRANTS FOR 'up_van_test_migrator'@'127.0.0.1';
SHOW GRANTS FOR 'up_van_auth_test'@'127.0.0.1';
```

Expected: exact test schema; 001–005 applied with matching checksum/statement counts;
006 absent; no province/requested-vehicle columns; vehicles.id BIGINT UNSIGNED PK;
InnoDB tables; registration-only UNIQUE. Determine the exact grant delta from this evidence.

## 2. Fresh backup (no database writes)

Stop application traffic and other schema changes until migration/postflight completes.
Store the dump in a Windows location whose ACL is restricted to authorized administrators:
the dump contains private records and password hashes. Never paste its contents.

```powershell
$vehicleBackup = Join-Path $env:USERPROFILE ('up_van_booking_test_before006_' + (Get-Date -Format 'yyyyMMdd_HHmmss') + '.sql')
if (Test-Path -LiteralPath $vehicleBackup) { throw 'Backup path already exists' }
mysqldump.exe --protocol=TCP --host=127.0.0.1 --port=3306 --user=root --password --single-transaction --quick --routines --events --triggers --no-tablespaces --set-gtid-purged=OFF --default-character-set=utf8mb4 --databases up_van_booking_test "--result-file=$vehicleBackup"
if ($LASTEXITCODE -ne 0) { throw 'Dump failed; do not use this file or continue' }
if (!(Test-Path -LiteralPath $vehicleBackup -PathType Leaf) -or (Get-Item -LiteralPath $vehicleBackup).Length -le 0) { throw 'Backup missing or empty' }
Get-FileHash -LiteralPath $vehicleBackup -Algorithm SHA256
```

The file hash/size and exit code verify only the backup artifact, not restorability.
Approval for a restore rehearsal is separate. Restore ONLY to an already prepared,
isolated MySQL 8.4.9 instance, for example port 3307. Check @@server_uuid there and
at 3306 and prove they differ before restoring. No production or live test restore.
The dump embeds the database name; passing another default database does NOT redirect it.

After isolated-instance approval and identity verification:

```powershell
$restoreSource = $vehicleBackup.Replace('\','/')
mysql.exe --protocol=TCP --host=127.0.0.1 --port=3307 --user=root --password "--execute=source $restoreSource"
if ($LASTEXITCODE -ne 0) { throw 'Restore rehearsal failed' }
mysql.exe --protocol=TCP --host=127.0.0.1 --port=3307 --user=root --password --database=up_van_booking_test --execute="SELECT COUNT(*) AS vehicles FROM vehicles; SELECT version,checksum,status,completed_statements,total_statements FROM schema_migrations ORDER BY version; CHECK TABLE vehicles,booking_requests,vehicle_assignments;"
if ($LASTEXITCODE -ne 0) { throw 'Restored database validation failed' }
```

Compare the restored vehicle rows/count (two if unchanged at the backup snapshot),
all table counts, ledger, checksums, schema/indexes/FKs and CHECK TABLE results with
the frozen source snapshot. Full restore completion is required, not only two rows.
No restore rehearsal has been executed as part of this source review.

## 3. Migrator grant proposal — approval REQUIRED first

Assuming the earlier reported grants still hold, effective CREATE at test-database scope
and ALTER/INSERT on vehicles already cover the first ALTER. Do not grant them twice.
Proposed delta for the second ALTER and FK (verify actual grants first):

```sql
GRANT ALTER, INSERT ON `up_van_booking_test`.`booking_requests`
TO 'up_van_test_migrator'@'127.0.0.1';
GRANT REFERENCES (`id`) ON `up_van_booking_test`.`vehicles`
TO 'up_van_test_migrator'@'127.0.0.1';
```

No DROP, global privileges, GRANT OPTION or Runtime DDL. Existing ledger grants remain.
The exact 006 profile and regression coverage are now implemented; Live grants are
still NOT VERIFIED. The old migration_test guard deliberately rejects expanded scope.
Do not run these grants merely to try the preflight. Run approved SQL as an administrator,
using the root prompt in step 1; then SHOW GRANTS again.

## 4. Controlled read-only preflight

After approved grants and backup rehearsal are complete:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File '.\scripts\php.ps1' '.\bin\db-migration-006.php' --preflight "--backup=$vehicleBackup"
if ($LASTEXITCODE -ne 0) { throw 'Preflight failed; stop' }
```

Preflight must PASS on the test migrator with 006 absent. Failure due to metadata
visibility is not proof of schema drift. Investigate; never weaken checks.

## 5. Controlled apply — separate explicit execution approval REQUIRED

This is a future command matching current Source, not a GO to execute it today.
Use a dedicated PowerShell process so the approval gate cannot affect the web server.

```powershell
$env:DB_PHASE_B_APPROVED='false'
$env:TEST_AUTH_EXECUTION_APPROVED='false'
$env:TEST_ADMIN_PROVISION_APPROVED='false'
$env:TEST_VEHICLE_MANAGEMENT_APPROVED='false'
$env:TEST_DB_EXECUTION_APPROVED='true'
try {
    powershell.exe -NoProfile -ExecutionPolicy Bypass -File '.\scripts\php.ps1' '.\bin\db-migration-006.php' --apply --confirm=up_van_booking_test "--backup=$vehicleBackup"
    if ($LASTEXITCODE -ne 0) { throw 'Migration failed; STOP. Do not retry. Preserve ledger and schema evidence.' }
} finally {
    $env:TEST_DB_EXECUTION_APPROVED='false'
}
```

No Booking POST is introduced or enabled by this procedure. If ALTER partially applies,
DDL cannot be undone using transaction ROLLBACK. Do not delete/edit ledger entries or
rerun the SQL. A separately reviewed recovery or restore is required.

## 6. Postflight, gate closed

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File '.\scripts\php.ps1' '.\bin\db-migration-006.php' --postflight "--backup=$vehicleBackup"
if ($LASTEXITCODE -ne 0) { throw 'Postflight failed; keep application writes stopped' }
```

Require 006 applied 2/2 with pinned checksum, expected nullable columns, requested-vehicle
index/FK and retained registration-only UNIQUE. Compare all original rows/counts with
the backup snapshot; no vehicle should be removed or inferred province backfilled.

## 7. Runtime grants — separate approval, after columns exist

Apply only the actual missing delta after SHOW GRANTS, not blindly the full baseline:

```sql
GRANT SELECT (`registration_province`), INSERT (`registration_province`),
UPDATE (`registration_province`) ON `up_van_booking_test`.`vehicles`
TO 'up_van_auth_test'@'127.0.0.1';
GRANT SELECT (`requested_vehicle_id`) ON `up_van_booking_test`.`booking_requests`
TO 'up_van_auth_test'@'127.0.0.1';
```

The requested-vehicle grant is read-only Calendar support, not permission to submit bookings.
No INSERT/UPDATE on requested_vehicle_id is proposed in this task.

## 8. Read-only runtime validation, then separate province write approval

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File '.\scripts\php.ps1' '.\scripts\task04c-1a-readonly-preflight.php'
if ($LASTEXITCODE -ne 0) { throw 'Runtime preflight failed; do not enable writes' }
```

All write gates must remain closed for that CLI. It validates effective grants including
approved module unions and optional columns. Afterwards inspect Admin cards/Calendar
without posting: old province values are NULL; active-car dropdown uses vehicle_id.
Province input becomes enabled only when the runtime probe confirms readable province
and existing Vehicle Write Gate/CSRF/RBAC conditions allow mutation. After separate write
approval, enter each car's real province, save, refresh and verify audit old/new values.
Do not invent a province or merge it into registration_number. Historical assignments
retain the existing edit restrictions. No actual province save was tested here.
