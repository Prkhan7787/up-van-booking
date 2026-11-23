# SCHEMA_LEDGER failure — 2026-11-12

No database connection/mutation was executed during this source/offline review.
Live evidence supplied by the operator proves the latest preflight reached
SCHEMA_LEDGER with creation_state NOT_ATTEMPTED / grants_completed 0. This means
ROOT_CONNECT and ROOT_IDENTITY passed. It does not identify the failing schema guard.

## Confirmed Source defects

MigrationRunner::vehicle006Preflight extracts booking column names from the pinned
Migration 002 CREATE TABLE using a type allowlist. The allowlist omitted DATE.
Migration 002 defines application_date DATE, so the old expected list omitted this
column and rejected a correct full Schema 005 with
MIGRATION_006_BOOKING_METADATA_INCOMPLETE_OR_DRIFT. Adding DATE to this parser fixes
the mismatch without changing the migration, checksum or actual schema expectations.

The account utility also hid these static guard codes behind ACCOUNT_PREPARATION_STOP.
Schema errors now preserve a curated code, schema_check, and schema_field when
applicable through PHP and outer PowerShell. No raw exception or row values are shown.
Ledger errors distinguish version, applied state, checksum and statement counts with
the expected pinned migration name. Existing PDO diagnostics remain SQLSTATE/native.

Offline full-Runner regression independently models all 37 booking columns,
including application_date, and passes Schema 005. Missing application_date, an
unexpected requested_vehicle_id, province remaining after 005, missing UNIQUE,
metadata permission failure and dirty ledger still fail closed. No existing guard
is bypassed. This proves the Source defect; live schema correctness still requires
the operator's new read-only result.

## Read-only retry, hidden prompt

```powershell
Set-Location -LiteralPath 'C:\จองรถตู้\up-van-booking'
powershell.exe -NoProfile -ExecutionPolicy Bypass -File '.\scripts\prepare-3307-migrator-account.ps1' -PreflightOnly -ConfirmTarget mysql-3307-37e4d222-dda2-11f1-b503-5c2886f057d5
Write-Host "Exit Code: $LASTEXITCODE"
```

Expected PASS / ACCOUNT_ABSENT_READY / creation_state NOT_ATTEMPTED /
grants_completed 0. Only enter Root password in the hidden prompt. Never send it
to chat. Do not run Execute based only on this source fix.

If it still fails, send only the safe JSON/exit code. Examples: BOOKING_COLUMNS
with MIGRATION_006_BOOKING_METADATA_INCOMPLETE_OR_DRIFT; LEDGER with
MIGRATION_006_LEDGER_CHECKSUM_MISMATCH and 002_booking_design.checksum;
MIGRATION_004_RATE_COLUMN_DRIFT with a column/attribute label.

## Additional live SELECT evidence, if needed

Native hidden password prompt, no reconnect/default-instance fallback:

```powershell
& 'C:\Program Files\MySQL\MySQL Server 8.4\bin\mysql.exe' --no-defaults --no-login-paths --protocol=TCP --host=127.0.0.1 --port=3307 --user=root --password --skip-reconnect --database=up_van_booking_test
```

First run `SELECT @@port,@@server_uuid,@@datadir,DATABASE();` and verify 3307,
37e4d222-dda2-11f1-b503-5c2886f057d5, C:\Users\User\mysql-restore-006-data,
up_van_booking_test. Only then, in that same session:

```sql
SOURCE C:/จองรถตู้/up-van-booking/scripts/migration-006-schema-ledger-readonly.sql
```

The file contains SELECTs only: ledger, column/index/FK metadata and aggregate
counts. It selects no names, contact data, password/hash/token values or vehicle rows.
It does not modify ledger or schema. Expected baseline ledger counts:
001_identity 4/4, 002_booking_design 10/10, 003_roles 1/1,
004_auth_security 2/2, 005_vehicle_registration_only 1/1, all applied;
006 absent. Checksums must match database/manifest.php and MigrationPlan pins.
Expected application_date exists; province/requested_vehicle_id absent; registration
UNIQUE remains; vehicle count should remain 2 if unchanged since operator snapshot.

If another mismatch is found, stop and review it. Do not edit ledger, grant broad
privileges, add/remove columns, change Root password or retry migration to make
these checks pass. MySQL 3306 and Lost & Found are outside this procedure.
