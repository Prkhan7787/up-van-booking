# 3307 Migration 006 migrator account — review only, BLOCKED

> Historical blocker analysis. The Source blocker is now fixed; use
> migration-006-grant-profile-fix.md and the tested
> scripts/migration-006-schema005-migrator-grants.proposed.sql for current review.
> Account creation, grants and migration still require separate approval.

2026-11-12. No CREATE USER, GRANT, migration, credential or .env change was executed.

## Evidence and target

Operator evidence: Root login PASS, port 3307, UUID
37e4d222-dda2-11f1-b503-5c2886f057d5; mysql.user query for
up_van_test_migrator returned no rows. Database up_van_booking_test has 001–005
applied, 006 absent, two vehicles and no bookings/assignments.
Fresh live SQL verification is pending the operator's native hidden password prompt.
Do not use passwords previously sent to chat or read/display any stored credential.

## Confirmed blocker: a new account cannot satisfy the current profile

Migration006GrantPlan::rules inherits MigrationTestGrantPlan::rules, including
SELECT(vehicles.registration_province). On schema 005 that column is absent.
MySQL's mysql_table_grant validates every column grant against actual table fields
and returns ER_BAD_FIELD_ERROR when a named field does not exist:
https://github.com/mysql/mysql-server/blob/mysql-8.4.9/sql/auth/sql_authorization.cc
(mysql_table_grant, around lines 2483–2511).

Therefore the existing full grant list cannot be granted to a fresh 3307 account
before 006. Granting only the existing nine columns is executable SQL but FAILS
the current guard with column.vehicles.registration_province:SELECT missing.
Do not grant table-wide SELECT, edit mysql grant tables, add the column manually,
use root in MigrationRunner or skip the grant guard to work around this.

Proposed next source change, requiring review before account provisioning:
give the explicit 006 profile a phase-aware province rule, validated against exact
schema-005 / schema-006 metadata. Keep 001–005 profile unchanged; allow no new
privileges. Ensure before/after-DDL grant verification can resume and postflight
without requiring a grant on an absent column. After the column exists, review
the exact province SELECT delta separately. This change is NOT implemented here.

## A. Reconfirm with SELECT only

```powershell
Set-Location -LiteralPath 'C:\จองรถตู้\up-van-booking'
$mysql3307 = 'C:\Program Files\MySQL\MySQL Server 8.4\bin\mysql.exe'
& $mysql3307 --no-defaults --no-login-paths --protocol=TCP --host=127.0.0.1 --port=3307 --user=root --password --skip-reconnect --database=up_van_booking_test
```

In that same session, run only:

```sql
SELECT @@port,@@server_uuid,@@datadir,DATABASE(),CURRENT_USER(),@@GLOBAL.partial_revokes;
SELECT User,Host,account_locked FROM mysql.user WHERE User='up_van_test_migrator';
SELECT version,checksum,status,completed_statements,total_statements
FROM schema_migrations ORDER BY version;
SELECT TABLE_NAME,COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA='up_van_booking_test' AND
 ((TABLE_NAME='vehicles' AND COLUMN_NAME IN ('id','registration_number','registration_province'))
 OR (TABLE_NAME='booking_requests' AND COLUMN_NAME='requested_vehicle_id'));
SELECT INDEX_NAME,COLUMN_NAME,SEQ_IN_INDEX,NON_UNIQUE FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA='up_van_booking_test' AND TABLE_NAME='vehicles'
AND INDEX_NAME='uq_vehicle_registration_number';
SELECT (SELECT COUNT(*) FROM vehicles) AS vehicles,
 (SELECT COUNT(*) FROM booking_requests) AS bookings,
 (SELECT COUNT(*) FROM vehicle_assignments) AS assignments;
```

Require port 3307, UUID above, datadir C:\Users\User\mysql-restore-006-data,
database up_van_booking_test, zero account rows, ledger 001–005 checksums/counts
matching Manifest, no 006, no new columns, retained registration-only UNIQUE.
No SQL statement can encode a TCP port in GRANT. Connection identity must be checked
immediately before account creation, on the very same connection; do not reconnect
automatically or use an unqualified/default mysql client.

## B. Account and grant proposal — NOT EXECUTABLE AS A PLAN YET

Account creation must be a separately approved operation that receives root via
hidden prompt and uses the existing MIGRATION_TEST_DB_PASSWORD in memory through
the project Env loader. No secret appears in argv, SQL files, console, history or
exception output. A random password returned to the console is not proposed.
Do not use an empty/default password, IF NOT EXISTS, ALTER USER or reset credentials.

Review-only CREATE USER template (the token below is not a password to deploy):

```sql
-- TEMPLATE ONLY. Build in memory after pinned identity + absence checks.
CREATE USER 'up_van_test_migrator'@'127.0.0.1'
IDENTIFIED WITH caching_sha2_password BY '<IN_MEMORY_CONFIGURED_PASSWORD>';
```

After the source blocker is resolved, the pre-006 grant candidate is:

```sql
-- REVIEW ONLY; do not execute while the profile blocker remains.
-- Use this escaped database scope ONLY when @@GLOBAL.partial_revokes=0.
GRANT CREATE ON `up\_van\_booking\_test`.*
TO 'up_van_test_migrator'@'127.0.0.1';
GRANT SELECT,INSERT,UPDATE ON `up_van_booking_test`.`schema_migrations`
TO 'up_van_test_migrator'@'127.0.0.1';
GRANT INSERT,ALTER ON `up_van_booking_test`.`users`
TO 'up_van_test_migrator'@'127.0.0.1';
GRANT SELECT ON `up_van_booking_test`.`auth_login_buckets`
TO 'up_van_test_migrator'@'127.0.0.1';
GRANT SELECT (`id`,`registration_number`,`label`,`passenger_capacity`,`is_active`,
 `created_by`,`updated_by`,`created_at`,`updated_at`),INSERT,ALTER,REFERENCES (`id`)
ON `up_van_booking_test`.`vehicles`
TO 'up_van_test_migrator'@'127.0.0.1';
GRANT SELECT (`id`),INSERT,ALTER ON `up_van_booking_test`.`booking_requests`
TO 'up_van_test_migrator'@'127.0.0.1';
GRANT SELECT (`id`) ON `up_van_booking_test`.`vehicle_assignments`
TO 'up_van_test_migrator'@'127.0.0.1';
```

If partial_revokes=1, use literal `up_van_booking_test`.* for database CREATE instead;
do not execute both variants. Table/column-qualified database names remain literal.
This is the minimum candidate matching the inherited profile, NOT the minimal set
for two ALTER statements alone: users INSERT/ALTER and database CREATE remain legacy
profile requirements. The actual 006 additions are vehicle ALTER/INSERT, booking
ALTER/INSERT with effective CREATE coverage, and REFERENCES(vehicles.id).
Metadata/ledger SELECTs support the existing Runner checks. No global privilege,
DROP, GRANT OPTION, broad database SELECT or Runtime DDL is proposed.

Current profile additionally demands SELECT(registration_province); it cannot be
included above until the column exists. The pre-006 candidate deliberately remains
NO-GO rather than pretending it passes the current guard.

## C. Credential compatibility and verification after separately approved creation

Runner uses migration_test_006 -> MIGRATION_TEST_DB_* and accepts only
up_van_test_migrator@127.0.0.1; root is rejected. The 3307 account's password must
match the configured password, without changing the 3306 account or .env.
Configuration presence alone is not successful PDO authentication.

In the root session pinned to 3307, post-creation read-only checks:

```sql
SELECT @@port,@@server_uuid,@@datadir,DATABASE();
SELECT User,Host,account_locked FROM mysql.user WHERE User='up_van_test_migrator';
SHOW GRANTS FOR 'up_van_test_migrator'@'127.0.0.1';
```

Then, only once the profile is fixed and credentials/grants are ready, run:

```powershell
$previous3307Port = [Environment]::GetEnvironmentVariable('MIGRATION_TEST_DB_PORT','Process')
try {
    $env:MIGRATION_TEST_DB_PORT='3307'
    powershell.exe -NoProfile -ExecutionPolicy Bypass -File '.\scripts\php.ps1' '.\bin\db-migration-006.php' --preflight --restore-test-3307 '--backup=C:\Users\User\up_van_booking_test_pre006.sql'
    if ($LASTEXITCODE -ne 0) { throw 'STOP: read-only migrator preflight failed' }
} finally {
    [Environment]::SetEnvironmentVariable('MIGRATION_TEST_DB_PORT',$previous3307Port,'Process')
}
```

No migration gate is opened here. Require PASS with actual port/UUID/datadir/account
in restore_identity. No account/migration operation is approved by this document.
If account creation or grants partially fail, preserve evidence and stop; no auto
drop/reset/regrant. 3306, Production, vehicles and Lost & Found remain untouched.
