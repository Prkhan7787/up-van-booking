# Task 04B-5 — Read-only Database Preflight

## Follow-up: SQL Error 1064 correction

Static review found `CURRENT_USER` was used as an unquoted SELECT alias (`AS current_user`). MySQL 8.4 classifies `CURRENT_USER` as a reserved word, so the alias is now `account_identity`; references in the report use the same key. The preflight catches a failing PDO query with a safe `failed_query_step` label plus SQLSTATE/native code, without including SQL text or exception messages. The same label is included when the optional Test Migrator `SHOW GRANTS` query is denied. This syntax fix was not exercised against MySQL because database connections are prohibited for this correction.

## Outcome: BLOCKED

Preflight was attempted only after both migration gates were checked through the project's Environment Loader without printing their values. Production Gate: **closed**. Test Execution Gate: **closed**.

The script verified that the fixed `test` connection purpose maps to `up_van_booking_test` / `up_van_test`, then attempted PDO MySQL using that dedicated test account. PDO failed before a connection was established: **SQLSTATE 2002 / Native Error 2002**. No database query ran. No fallback account, root credential, production database, or Lost & Found connection was attempted. No database, grant, credential, or gate was changed.

### Verification results

| Item | Result |
|---|---|
| Target requested | `up_van_booking_test` |
| Production/Test gates | Both closed |
| PDO connection | **BLOCKED — SQLSTATE 2002 / Native 2002** |
| Database identity / MySQL version | Unavailable; connection not established |
| Current account `SHOW GRANTS` | Unavailable |
| Test migrator `SHOW GRANTS` | Unverified; no connection |
| Migration ledger and checksums in DB | Unavailable; no connection |
| Schema, constraints, preconditions, and user data | Unavailable; no connection |
| Migration 004 manifest / allowlist | Static verification only; passed in Offline tests |
| Production and `lost_found` | Not connected or queried |

Since the connection was not established, there is no evidence about existing test rows, missing usernames, invalid usernames, case-insensitive duplicates, or object conflicts. The Migration Runner's static manifest validation verifies the local candidate checksum and statement count; it cannot verify the live test ledger.

### Safe next step for the administrator

Restore/verify the local MySQL endpoint and the already configured `up_van_test` credentials outside this tool, then rerun:

```powershell
Set-Location -LiteralPath 'C:\จองรถตู้\up-van-booking'
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/php.ps1 scripts/task04b-5-readonly-preflight.php
```

The script checks both gates before connecting, binds only to the fixed test purpose, starts a read-only transaction, and rolls it back. It does not print the `.env` contents or password. It reads the test account's own grants and attempts to inspect `up_van_test_migrator`; if that is denied, the migrator grants remain **Unverified**. Do not enable either gate for this read-only check.

## Username data plan

If the username column is absent, the preflight reports the number of existing `users` rows that will require a manually approved username mapping; it does not reveal user data or invent usernames from email. If the column already exists, it reports only aggregate counts for NULL, invalid-format, and case-insensitive duplicate usernames. Any unexpected partial 004 objects are a stop condition. Before eventual `NOT NULL`, an administrator must approve mappings, resolve duplicates, backfill explicitly, and rerun aggregate checks.

## Failed-attempt rate-limit review

The current Task 04B-4 implementation reserves a slot before password verification, so the 5-per-username and 20-per-IP counters count all login attempts, including successful ones. This is not the requested failed-only policy. It has not been changed in Task 04B-5 to avoid altering active authentication behavior without a separate impact decision; Login remains unwired.

Proposed failed-only flow: keep a separate request-flood limiter (for example IIS/reverse-proxy endpoint throttling) that counts requests, while the authentication service uses MySQL transactions to reserve in-flight capacity for both HMAC username/IP buckets before password verification. Finalize the reservation atomically afterward: release pending capacity for success without adding a failure, or add one failure to both counters for a failed login. Lock rows in deterministic order. Persist only HMAC digests. Give each reservation an opaque token and expiry so a worker crash cannot strand in-flight capacity; cleanup expired reservations under a bounded maintenance process. This design prevents concurrent requests from all passing a check while ensuring only failed credentials count toward the 5/15-minute and 20/15-minute policies.

`tests/auth-failed-rate-limit-proposal.php` is an in-memory model for thresholds, concurrency admission, success release, and window expiry. It does not change AuthService, use MySQL, or prove InnoDB correctness. Validate locks, crash expiry, deadlocks and isolation with separately approved MySQL tests before adopting the design. The current candidate 004 schema (`attempt_count`) would need a reviewed amendment to represent failure counters and expiring reservations before any migration run.

## Status and approval boundary

**FAIL / BLOCKED for live preflight.** No live DB facts can be reported. Static migration checks remain PASS. Before Migration 004 execution, obtain a successful read-only test preflight, verify the exact Test Migrator grant (or report it Unverified and resolve through an authorized administrator), review data/ledger/object findings, choose the failed-only limiter policy, and receive separate execution approval. This task stopped without running Migration 004.
