# Partial A4 intake — approval steps (not executed)

Target: MySQL 127.0.0.1:3306, UUID `77218fbd-d66d-11f1-9a0a-5c2886f057d5`, `up_van_booking_test` only.

Current read-only inspection: Officer Read PASS; legacy Submission lacks 38 column privileges. Partial intake also needs `booking_requests.a4_request_data` JSON NULL (Draft008), with SELECT and INSERT. The combined partial-Submission proposal contains 40 missing column privileges. Officer Edit remains separately gated and needs its own UPDATE approval, including UPDATE(a4_request_data). Do not enable submission from a legacy-grants PASS.

Draft008 is not applied or registered. Review/approve it separately, verify the existing backup, and extend the existing controlled runner/checksum/Schema guard before execution; do not execute the DDL manually. Existing normalized fields are nullable. The CHECK constraint for travel timestamps requires both NULL or both non-NULL and ordered. Snapshot stores incomplete individual entries; normalized timestamps stay NULL until both datetimes are valid. Pending requests without a complete vehicle/time window reserve nothing. Complete vehicle/time windows still pass capacity/conflict checks and acquire the vehicle lock. Section 2/driver text is advisory; only the existing assignment workflow can create actual assignments. Section 3 is excluded from all intake payloads.

After schema approval/application and a verified backup, the following PowerShell is ready for the separately approved GRANT step. It uses an existing administrative Login Path, does not overwrite one, and never receives a password in script/command arguments. Choose the partial-Submission file; the legacy file is provided only for reviewing the original 38-column delta. This step does not open feature gates.

```powershell
Set-Location -LiteralPath 'C:\จองรถตู้\up-van-booking'
$ErrorActionPreference = 'Stop'
$mysqlExe = 'C:\Program Files\MySQL\MySQL Server 8.4\bin\mysql.exe'
$grantLoginPath = Read-Host 'Existing administrative MySQL Login Path'
if ($grantLoginPath -notmatch '\A[A-Za-z0-9_-]{1,32}\z') { throw 'Invalid Login Path' }
$mysqlArgs = @("--login-path=$grantLoginPath", '--protocol=TCP', '--host=127.0.0.1', '--port=3306', '--database=up_van_booking_test', '--default-character-set=utf8mb4', '--batch', '--raw', '--binary-mode', '--skip-column-names')
$identity = 'SELECT CONCAT(@@port, CHAR(9), @@server_uuid, CHAR(9), DATABASE());' | & $mysqlExe @mysqlArgs
if ($LASTEXITCODE -ne 0 -or ($identity -join "`n").Trim() -cne "3306`t77218fbd-d66d-11f1-9a0a-5c2886f057d5`tup_van_booking_test") { throw 'Identity mismatch; stopped' }
$shape = "SELECT CONCAT(COLUMN_TYPE, CHAR(9), IS_NULLABLE) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='up_van_booking_test' AND TABLE_NAME='booking_requests' AND COLUMN_NAME='a4_request_data';" | & $mysqlExe @mysqlArgs
if ($LASTEXITCODE -ne 0 -or ($shape -join "`n").Trim() -ine "json`tYES") { throw 'Draft008 schema not verified; no GRANT started' }
# Execute the next two lines ONLY after explicit approval of the 40-column proposal.
Get-Content -LiteralPath '.\scripts\booking-submission-partial-grant-delta.proposed.sql' -Raw | & $mysqlExe @mysqlArgs
if ($LASTEXITCODE -ne 0) { throw 'GRANT failed or partially applied; stop and inspect SHOW GRANTS, do not retry blindly' }
powershell.exe -NoProfile -ExecutionPolicy Bypass -File '.\scripts\php.ps1' '.\bin\booking-capabilities-readonly.php'
if ($LASTEXITCODE -ne 0) { throw 'Read-only preflight failed' }
```

Before opening a Gate, inspect the JSON: submission.status and officer_read.status must be PASS; missing_privileges and unexpected_privileges empty; account_guard.status PASS; snapshot must be PRESENT/readable. Officer Edit can remain FAIL and must stay closed. A PHP exit code 0 means diagnostics ran, not that capability checks passed.

Concurrent tests require separate write approval. Follow `booking-concurrency-test-plan.md` on an isolated copy or dedicated disposable fixtures, with two independent connections and barriers. Include blank/partial intake (no reservation), two overlapping complete submissions (one winner), partial-to-complete officer edit racing with submit (one winner), stale version rejection and audit rollback. Every failing transaction must rollback its own request/history/audit changes. For successful-commit tests, use an isolated cloned test database with its own explicitly reviewed target configuration; remove the clone only under approved cleanup. Do not delete application rows or audit history on the normal test database. Rollback alone cannot undo a committed concurrency fixture. No harness or data-writing test was run in this task.

Submission is independent of printing/PDF: the submit button is type=submit, print button type=button. Submission performs no print call, PDF upload or PDF-generation check. Blank/partial/full cases are tested with a memory repository and mocked browser network; these are not evidence of MySQL inserts.
