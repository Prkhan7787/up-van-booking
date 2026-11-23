# Controlled Migration 006 Resume — review only

No connection to either MySQL instance, GRANT, Resume, restore, ledger update or
gate opening was performed while preparing this source and offline tests.

Files changed: app/Database/Migration006GrantPlan.php, GrantVerifier.php,
ConnectionFactory.php, MigrationRunner.php, MigrationExecutionException.php,
Restore006MigratorPreparation.php; new Migration006ResumeValidator.php;
bin/db-migration-006.php; scripts/migration-006-schema005-migrator-grants.proposed.sql,
migration-006-migrator-grants.proposed.sql and new migration-006-3307-recovery-grant.proposed.sql;
tests/database/migration-006-grants-offline.php, migration-006-execution-diagnostics-offline.php,
new migration-006-resume-offline.php and migration-006-resume-preflight-offline.php;
this operator document. No Migration 001–006 SQL was modified.

## Scope and corrected privileges

Only 127.0.0.1:3307, UUID 37e4d222-dda2-11f1-b503-5c2886f057d5,
datadir C:\Users\User\mysql-restore-006-data, up_van_booking_test,
up_van_test_migrator@127.0.0.1.

Migration006GrantPlan now requires TABLE REFERENCES on exactly vehicles.
001–005 profiles and Runtime profiles are unchanged. The generic verifier's new
explicit opt-in accepts the historical REFERENCES(id) duplicate ONLY when required
table REFERENCES also exists, and only for vehicles.id in Profile 006. MySQL SHOW
GRANTS can retain both. No need to REVOKE it; column-only remains insufficient.
No new SELECT, global/DDL database grant, DROP or GRANT OPTION is allowed.

Review SQL: scripts/migration-006-3307-recovery-grant.proposed.sql.
The corrected full Schema 005 grant proposal has SHA256
6bbbd2780daf0723f1c70604e08e9462dd3c41e71ae87fee6f85a450f145a12d;
its preparation-tool pin is updated too. This is a GRANT PLAN change, not a change
to the original Migration 006 SQL/checksum:
61c0f5b4fe152c83142d49ba81070a70df2dc352a9073302809cee9696827748.

## Guarded recovery semantics

The EXISTING Runner now provides vehicle006ResumePreflight/resumeVehicle006.
CLI --resume-preflight/--resume require --restore-test-3307 and a connection purpose
that rejects port 3306 BEFORE opening PDO. Every write rechecks actual identity.
Normal --apply and normal connection guards still reject partial migration state.
Root is not a recovery runner.

Preconditions: exact clean pinned 001–005; exact 006 checksum, failed 1/2,
failure_code=statement_failed; existing nullable varchar(100) province at the correct
position; unchanged registration UNIQUE; parent id BIGINT UNSIGNED PRIMARY KEY;
exact original booking column list including application_date; absent second column,
index and globally named new FK; unchanged original FKs including referenced schema;
InnoDB engines, utf8mb4_unicode_ci, exact object inventory, two vehicle rows with
all nine original fields matching the recorded SHA256
8a8561bcf8ae8d774d24e0a1960a30699bfc6d712b77c130f5490b61b9337f7d;
bookings=0 and assignments=0. Any mismatch stops, with no adoption/reset.

Execute additionally requires: original verified 44,529-byte backup/hash, a DIFFERENT
nonempty private backup of the CURRENT partial state plus its explicitly confirmed
SHA256, existing TEST_DB_EXECUTION_APPROVED=true, and database confirmation.
The tool never opens a gate or creates a backup itself.

It acquires the same existing named migration lock, repeats the complete preflight,
executes only pinned statements[1] (Statement 2), verifies all existing postconditions,
referenced schema and original vehicle fingerprint, then atomically updates the
ledger to applied 2/2 with finished_at/failure_code=NULL using a conditional UPDATE
requiring the original failed 1/2 row/checksum. No Statement 1 execution path exists.
The private execution helper is exercised with fake PDO, never a live test account.

If DDL or postconditions fail: the original failed ledger is preserved. If DDL
succeeds but recording completion fails: STOP; the new schema now blocks a retry.
A connection failure during the ledger UPDATE can leave its result unknown: inspect
read-only before any further action. No automatic reset/rollback/retry or manual
ledger edit is allowed. Already-applied state is rejected by Resume preflight.

## A. GRANT approval — separate from recovery approval

Open native root only after approval; --password prompts hidden:

```powershell
Set-Location -LiteralPath 'C:\จองรถตู้\up-van-booking'
& 'C:\Program Files\MySQL\MySQL Server 8.4\bin\mysql.exe' --no-defaults --no-login-paths --protocol=TCP --host=127.0.0.1 --port=3307 --user=root --password --skip-reconnect --database=up_van_booking_test
```

In that SAME session, verify port/UUID/datadir/database immediately before the one
approved privilege change. Do not continue on any mismatch:

```sql
SELECT @@port,@@server_uuid,@@datadir,DATABASE();
SHOW GRANTS FOR 'up_van_test_migrator'@'127.0.0.1';
-- ONLY after the separate GRANT approval:
GRANT REFERENCES ON `up_van_booking_test`.`vehicles`
TO 'up_van_test_migrator'@'127.0.0.1';
SHOW GRANTS FOR 'up_van_test_migrator'@'127.0.0.1';
```

Do not run the whole full-account grant plan or grant anything on 3306.

## B. Read-only Resume preflight after approved GRANT

No migration gate required. Use a dedicated Windows PowerShell terminal.

```powershell
$previousResumePort=[Environment]::GetEnvironmentVariable('MIGRATION_TEST_DB_PORT','Process')
try {
    $env:MIGRATION_TEST_DB_PORT='3307'
    powershell.exe -NoProfile -ExecutionPolicy Bypass -File '.\scripts\php.ps1' '.\bin\db-migration-006.php' --resume-preflight --restore-test-3307 '--backup=C:\Users\User\up_van_booking_test_pre006.sql'
    if($LASTEXITCODE -ne 0){throw 'STOP: Resume preflight failed'}
} finally {[Environment]::SetEnvironmentVariable('MIGRATION_TEST_DB_PORT',$previousResumePort,'Process')}
```

Require RESUME_STATEMENT_2_READY and correct restore_identity. Send safe results
for review; a PASS does NOT authorize Execute.

## C. Preserve the CURRENT failed-state backup, no restore

After separately confirming the Root connection identity on 3307, create a new
private folder/file, never overwrite the pre006 backup. Existing ACL helper is reused:

```powershell
. '.\scripts\lib\mysql-3307-recovery-acl.ps1'
$partialFolder=Join-Path $env:USERPROFILE ('mysql-3307-partial006-'+[guid]::NewGuid().ToString('N'))
[void](New-Item -ItemType Directory -Path $partialFolder -ErrorAction Stop)
Set-PrivateRecoveryAcl $partialFolder $true
$partialBackup=Join-Path $partialFolder 'up_van_booking_test_failed006.sql'
[IO.File]::WriteAllBytes($partialBackup,[byte[]]@())
Set-PrivateRecoveryAcl $partialBackup $false
& 'C:\Program Files\MySQL\MySQL Server 8.4\bin\mysqldump.exe' --no-defaults --protocol=TCP --host=127.0.0.1 --port=3307 --user=root --password --single-transaction --quick --routines --events --triggers --no-tablespaces --set-gtid-purged=OFF --databases up_van_booking_test "--result-file=$partialBackup"
if($LASTEXITCODE -ne 0 -or (Get-Item -LiteralPath $partialBackup).Length -le 0){throw 'STOP: partial-state backup failed'}
Assert-PrivateRecoveryAcl $partialBackup $false
$partialHash=(Get-FileHash -LiteralPath $partialBackup -Algorithm SHA256).Hash.ToLowerInvariant()
Get-Item -LiteralPath $partialBackup | Select-Object FullName,Length
Write-Host "Partial backup SHA256: $partialHash"
```

Backups contain private data and password hashes; never print their contents.
Hash/nonempty checks alone do not prove restorability. No restore is authorized here.
Preserve original and current ledger/schema/grant evidence with the approved backup.

## D. Recovery Execute — NEW explicit approval required

Do NOT run now. $partialBackup/$partialHash must be the reviewed fresh partial-state
artifact from C. The original backup remains pinned separately. The only gate
opening is the operator's explicit line below, in this dedicated process:

```powershell
$previousResumePort=[Environment]::GetEnvironmentVariable('MIGRATION_TEST_DB_PORT','Process')
$previousResumeGate=[Environment]::GetEnvironmentVariable('TEST_DB_EXECUTION_APPROVED','Process')
try {
    $env:MIGRATION_TEST_DB_PORT='3307'
    $env:TEST_DB_EXECUTION_APPROVED='true'
    powershell.exe -NoProfile -ExecutionPolicy Bypass -File '.\scripts\php.ps1' '.\bin\db-migration-006.php' --resume --restore-test-3307 --confirm=up_van_booking_test '--backup=C:\Users\User\up_van_booking_test_pre006.sql' "--partial-backup=$partialBackup" "--partial-backup-sha256=$partialHash"
    if($LASTEXITCODE -ne 0){throw 'STOP: Recovery failed; no automatic retry or rollback'}
} finally {
    [Environment]::SetEnvironmentVariable('TEST_DB_EXECUTION_APPROVED',$previousResumeGate,'Process')
    [Environment]::SetEnvironmentVariable('MIGRATION_TEST_DB_PORT',$previousResumePort,'Process')
}
```

Expected RESUME_COMPLETE, executed_statements=[2], applied 2/2. Then run the existing
--postflight --restore-test-3307 with port override 3307 (as in B), plus a read-only
vehicle fingerprint comparison. No Runtime grants, Booking POST or vehicle edits
are enabled by recovery. MySQL 3306, .env, Production and Lost & Found remain untouched.
