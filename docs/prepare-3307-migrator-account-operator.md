# Prepare the Migration 006 account on Restore MySQL 3307

Source/offline preparation only, 2026-11-12. No account, GRANT or migration was
executed. The commands below are for the operator; Execute requires approval.

## ROOT_CONNECT runtime correction

The previous absolute extension_dir under the Thai project path was reproduced
with pdo_mysql/mbstring not loaded even though PHP -m exited 0. Core PDO remained
available, so accessing PDO::MYSQL_ATTR_MULTI_STATEMENTS inside the connection
closure could throw an Error at ROOT_CONNECT before PDO authentication.
The wrapper now uses .runtime/php84/ext relative to its pinned project cwd, matching
scripts/php.ps1. Project PHP is 8.4.27; both modules load with that command.
Runtime/driver validation precedes the hidden prompt, with another PHP-side guard.

Windows PowerShell 5.1 tests round-trip synthetic ASCII, quotes/backslashes/shell
punctuation, Thai/non-BMP Unicode, whitespace, CRLF/LF and embedded NUL from
SecureString/BSTR through UTF-8 stdin to PHP JSON. No synthetic value is printed;
the real root password has not been captured or inspected.

Safe diagnostic categories now distinguish:
- PHP_RUNTIME with PHP_PDO_MYSQL_MISSING / PHP_MBSTRING_MISSING / PHP_VERSION_UNSUPPORTED.
- PDO_ERROR with failure_type=AUTHENTICATION_FAILURE for HY000/1045 or 28000/1045.
- PDO_ERROR with failure_type=CONNECTION_FAILURE for connection/refused/lost codes.
- DATABASE_SELECTION_FAILURE for native 1049; other PDO failures remain safely classified.
- PHP_PROCESS_OR_OUTPUT_FAILURE if the child cannot return a valid safe JSON contract.

Only code, stage, SQLSTATE and native code are forwarded; no raw exception/stderr.
Run section 1 again after this fix. A valid transport/runtime does not prove the
entered password authenticates: the live result must still be reviewed. Keep using
PreflightOnly; this troubleshooting change does not authorize Execute.

## Scope and credentials

- Only TCP 127.0.0.1:3307 / up_van_booking_test.
- Pinned UUID: 37e4d222-dda2-11f1-b503-5c2886f057d5.
- Pinned datadir: C:\Users\User\mysql-restore-006-data.
- Root@localhost is used only for identity/metadata/account inspection and approved
  CREATE USER / GRANT; never MigrationRunner::apply(). No migrations are applied.
- The migrator password is read through the SAME ConnectionFactory config/Env
  loader as migration_test_006, using MIGRATION_TEST_DB_PASSWORD. It is neither
  generated nor changed. Only the destination port is pinned locally to 3307.
  No .env or process environment is written by the utility.
- The root password is requested with Read-Host -AsSecureString and sent to PHP
  through private UTF-8 stdin, not argv, environment variables, files or output.
- No password or hash is reported. Do not use PowerShell tracing/debugging or
  instrument process memory while handling secrets. Do not send passwords to chat.

## Checks and execution boundaries

All modes require the exact confirmation below. Modes are mutually exclusive and
checked before the hidden prompt/process launch. All TCP connections have port
3307 literally in their DSN and multi-statements disabled.

Preflight checks: grant-file SHA256, exact pre profile, config/secret presence,
root's actual port/UUID/datadir/version/database/account/partial_revokes=0, clean
001–005 checksums and statement counts, absent 006, full existing Runner schema
preconditions and absence of the username on ALL hosts. Root metadata uses the
existing read-only Runner/Validators, not an alternate migration engine. The
migrator password cannot be authenticated while the account is absent: preflight
returns credential_verified=false deliberately.

Execute repeats all checks, acquires a named account lock, then repeats identity,
schema/ledger and account absence immediately before CREATE. It creates precisely
up_van_test_migrator@127.0.0.1 with caching_sha2_password, quotes the loaded password
with PDO and applies exactly the seven statements from the approved grant plan.
Before each GRANT, actual connection identity is rechecked. It never uses IF NOT
EXISTS, ALTER USER, password reset, account deletion or automatic retry.

Postflight connects as the new migrator with the loaded credential, checks the
pinned actual identity, calls the existing 006 Grant Guard, and rechecks Schema 005.
Existing vehicle data is never written. Pure Postflight mode contains no CREATE or
GRANT path and only reads the already-created account.

Pinned grant plan:
scripts/migration-006-schema005-migrator-grants.proposed.sql
SHA256: ceb23582813746999e9b84ebc1b95e5102272ee7c5d6f11e86acdf5bc04e490f.
Changing file bytes causes preflight failure; do not change the pin to bypass it.

## 1. Preflight only — run now for review

Use Windows PowerShell 5.1 in a dedicated terminal. Enter only the Restore 3307 root
password in the hidden prompt. Credentials from an old chat are not used.

```powershell
Set-Location -LiteralPath 'C:\จองรถตู้\up-van-booking'
powershell.exe -NoProfile -ExecutionPolicy Bypass -File '.\scripts\prepare-3307-migrator-account.ps1' -PreflightOnly -ConfirmTarget mysql-3307-37e4d222-dda2-11f1-b503-5c2886f057d5
Write-Host "Exit Code: $LASTEXITCODE"
```

Require status=PASS, code=ACCOUNT_ABSENT_READY, creation_state=NOT_ATTEMPTED,
grants_completed=0, credential_verified=false, Exit Code 0. Send only this safe
report for review. DO NOT execute section 2 until creation/grants are approved.

## 2. Account creation/grants — separate approval required

This is the only mutating mode. It creates an account and grants privileges, not a
migration. Reconfirm grant SQL and target before running.

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File '.\scripts\prepare-3307-migrator-account.ps1' -Execute -ConfirmTarget mysql-3307-37e4d222-dda2-11f1-b503-5c2886f057d5
Write-Host "Exit Code: $LASTEXITCODE"
```

Expected PASS / MIGRATOR_CREDENTIALS_AND_GRANTS_VALID, creation_state=CONFIRMED,
grants_completed=7, credential_verified=true, migration_applied=false, Exit Code 0.

Any nonzero exit: STOP. CREATE USER/GRANT do not form an atomic transaction.
creation_state=ATTEMPTED_RESULT_UNKNOWN means a connection failure may have hidden
a successful CREATE. CONFIRMED plus a partial grants_completed count means a
partial account exists. Never rerun Execute, drop/reset the account or regrant
automatically. Inspect root account state and safe diagnostics before a separate
recovery approval. Successful statements are not rolled back by this utility.

## 3. Independent read-only postflight

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File '.\scripts\prepare-3307-migrator-account.ps1' -Postflight -ConfirmTarget mysql-3307-37e4d222-dda2-11f1-b503-5c2886f057d5
Write-Host "Exit Code: $LASTEXITCODE"
```

Expected credential_verified=true with no new CREATE/GRANT. Its creation_state is
NOT_ATTEMPTED and grants_completed=0 because this invocation is read-only.

Optional root inspection, explicitly pinned client; --password alone is hidden:

```powershell
& 'C:\Program Files\MySQL\MySQL Server 8.4\bin\mysql.exe' --no-defaults --no-login-paths --protocol=TCP --host=127.0.0.1 --port=3307 --user=root --password --skip-reconnect --database=up_van_booking_test
```

```sql
SELECT @@port,@@server_uuid,@@datadir,DATABASE(),CURRENT_USER();
SELECT User,Host,account_locked FROM mysql.user WHERE User='up_van_test_migrator';
SHOW GRANTS FOR 'up_van_test_migrator'@'127.0.0.1';
SELECT version,checksum,status,completed_statements,total_statements
FROM schema_migrations ORDER BY version;
SELECT (SELECT COUNT(*) FROM vehicles) AS vehicles,
 (SELECT COUNT(*) FROM booking_requests) AS bookings,
 (SELECT COUNT(*) FROM vehicle_assignments) AS assignments;
```

No global privilege, DROP, GRANT OPTION, broad vehicles SELECT or Runtime DDL is
allowed by the pre-profile. Migration 006 must still be absent; vehicle rows must
remain unchanged. Only after successful account postflight, run the existing
db-migration-006.php --preflight --restore-test-3307 procedure with port override
in a dedicated process. Applying Migration 006 remains a NEW explicit approval.
No instruction here authorizes changing 3306, Production or Lost & Found.
