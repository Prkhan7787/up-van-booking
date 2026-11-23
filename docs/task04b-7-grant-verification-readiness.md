# Task 04B-7 — Grant Verification & Migration Safety

**Scope:** Source, SQL grant-plan text, offline tests, and documentation only. No MySQL connection, SQL execution, `.env`/credential/gate change, migration, or commit was performed.

## 1. Required Migration Privileges

Source review of the pinned candidate `004_auth_security` finds two statements:

1. `ALTER TABLE users`: add nullable `username`, add `auth_version`, add the username CHECK, and add a UNIQUE index.
2. `CREATE TABLE auth_login_buckets`: create the InnoDB table, primary key, secondary index, and CHECK.

The existing `schema_migrations` ledger is read, inserted, and updated by `MigrationRunner`. Its required table privileges are `SELECT`, `INSERT`, and `UPDATE`. The candidate migration introduces no foreign key and needs no `REFERENCES`; indexes are created as part of the reviewed DDL, so no separate `INDEX` grant is needed. Database-scoped `CREATE` is needed for the new table and also supplies the `CREATE` prerequisite for altering `users`; `users` additionally needs table-scoped `ALTER` and `INSERT`. It does not need `DROP`.

Least-privilege review-only SQL for the already-existing test schema is:

```sql
GRANT CREATE ON `up\_van\_booking\_test`.* TO 'up_van_test_migrator'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE ON `up_van_booking_test`.`schema_migrations` TO 'up_van_test_migrator'@'127.0.0.1';
GRANT ALTER, INSERT ON `up_van_booking_test`.`users` TO 'up_van_test_migrator'@'127.0.0.1';
```

The generated database-scope form omits backslash escaping when `partial_revokes` is enabled. This is a draft for administrator review only; this task did not execute it. The verifier expects only the effective privileges listed above, so pre-existing extra grants (for example database-wide DML from earlier migrations) will be reported as overbroad and need a separately approved grant change. No runtime account privilege is added.

MySQL documents that `ALTER TABLE` requires `ALTER`, `CREATE`, and `INSERT` privilege coverage on the table; database-scope `CREATE` covers the required `CREATE` privilege. [MySQL 8.4 privilege reference](https://dev.mysql.com/doc/refman/8.4/en/privileges-provided.html), [ALTER TABLE](https://dev.mysql.com/doc/refman/8.4/en/alter-table.html). `CREATE TABLE` also creates the declared indexes as part of the statement. [CREATE TABLE](https://dev.mysql.com/doc/refman/8.4/en/create-table.html).

## 2. Grant Plan / Verifier Changes

- `app/Database/Migration004GrantPlan.php` defines the exact expected privilege scopes and produces test-only GRANT text; it never executes SQL.
- `app/Database/GrantVerifier.php` combines database-level and table-level grants into effective coverage for the required tables. It rejects global scope, another schema, wildcard mismatch, unexpected privileges, and `GRANT OPTION`; missing privileges are named by scope. Role-derived, column-level, and unrecognized grant forms return `UNVERIFIED` rather than PASS.
- `scripts/verify-test-migrator-grants.php` is a future read-only verifier that insists both migration gates remain closed, uses the fixed Test Migrator config, validates database/current account identity, and withholds raw grants and credentials from output. It was **not run**.
- `scripts/verify-database-readonly.php` and `scripts/task04b-5-readonly-preflight.php` now use the structured verifier for grant evaluation.
- `ConnectionFactory` permits migration-only table-scoped `ALTER` on `users`, with required `INSERT`/`CREATE` coverage; its runtime connection policy still rejects DDL. Offline checks cover the narrow migration grant shape and runtime rejection of `ALTER`/`DROP`.

Effective privilege evaluation is based on parsed grant scopes, not a substring match. `SHOW GRANTS` does not by itself resolve every possible role configuration; whenever the result contains a role assignment or unsupported grant form, status remains `UNVERIFIED` pending explicit role resolution. No fresh grant evidence was obtained in this task.

## 3. Migration Safety

- Manifest and `MigrationPlan` pin `004_auth_security` to SHA-256 `d63494ce561d8e8de7eb6c1d793404a7b6c5179f3168719be37c844238966dd1`, exactly 2 statements. Migration 001–003 pins were not changed.
- The Runner allowlist accepts only the exact normalized ALTER and CREATE statements for Migration 004. It rejects cross-schema references, `DROP`, `TRUNCATE`, grant changes, database/user creation, and unpinned statements.
- Before execution, Runner requires the target gate, exact database/account identity, pinned manifest/checksums, expected ledger sequence/object inventory, charset/collation, and Migration 004 preconditions. It obtains a per-target advisory lock.
- MySQL DDL may implicitly commit. A failure can leave an ALTER applied with a `started`/`failed` ledger row and the CREATE not yet applied. Runner explicitly stops and requires manual inspection; it does not claim a transaction can roll DDL back, automatically retry, or drop partial objects.
- Backup/recovery preconditions remain those recorded in [Task 04B-6](task04b-6-migration-execution-readiness.md): an isolated, ACL-protected backup of only the test database, verified hash, and a separately approved recovery plan. This task did not create or verify a backup.

## 4. Rate Limiter Policy

Target policy remains: count **failed** credential checks only; cap 5 per normalized username and 20 per IP in 15 minutes; reserve atomically for concurrent requests; persist HMAC digests, never raw username/IP; keep request-flood protection as a separate edge/server control.

Current `AuthService` calls `reserveAttempt()` before account lookup/password verification, while `PdoLoginRateLimitStore` increments `attempt_count` during reservation. It therefore counts successful as well as failed attempts. Migration 004 currently has one `attempt_count` field and cannot safely distinguish reservations/in-flight checks from failed results. Failed-only counting and finalization are **not implemented**; offline tests do not establish the concurrency semantics on MySQL. Before enabling Login, a separately reviewed forward migration and AuthService/store change should add reservation identity/state or an equivalent atomic completion design, define expiry/cleanup, and ensure failures finalize exactly once while successful checks release/complete without incrementing failure counts. No migration or authentication behavior was changed in this task.

## 5. Offline Test Results

PHP Syntax Check passed for all 8 changed PHP files. Offline suites passed:

- Grant Verifier: 14 checks.
- Database Offline Safety: 76 checks.
- Migration Plan Safety: 14 checks.
- Authentication Foundation: 42 functional checks (43 `PASS` output lines including the suite summary).
- Authentication Schema Draft: 16 checks (17 `PASS` output lines including the suite summary).

No test in this task opened a database connection. The MySQL grant verifier and rate limiter were not exercised against MySQL.

## 6. Remaining Blockers and Recommendation

- Live effective grants remain unverified; the prior Task 04B-5 report recorded error 1142 when Preflight attempted to inspect Migrator grants. The dedicated verifier must be run by the administrator using the Test Migrator's own session after approval.
- Test Migrator's existing grants may include privileges beyond this Migration 004-only least-privilege plan; if so, live verification must report FAIL until an separately approved least-privilege correction is made.
- Backup integrity and recovery readiness are not confirmed here.
- Failed-only rate-limit policy conflicts with the current implementation/schema and must be resolved before Login activation; it does not prevent a read-only grant check.
- No live grants, Migration 004, Integration Test, or database operation was performed.

**Recommendation: GO for a separately approved, read-only Live Grant Verification** using `scripts/verify-test-migrator-grants.php`, with both execution gates false. Interpret only an explicit `PASS` as verified; `UNVERIFIED`, `FAIL`, or `BLOCKED` requires stopping and review. **NO-GO for Migration 004 execution or Login activation** until grant evidence, backup/recovery readiness, and the failed-only limiter design are resolved.
