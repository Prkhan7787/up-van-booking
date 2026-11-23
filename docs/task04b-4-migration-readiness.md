# Task 04B-4 — Authentication Migration Readiness

**Scope:** source, draft SQL, documentation and Offline tests only. No database connection, migration execution, gate change, credential change or live grant inspection occurred.

## Username migration strategy

Migration 001 defines unique email and nullable `password_hash`; it has no username column. Candidate 004 first adds `username VARCHAR(50) CHARACTER SET ascii COLLATE ascii_general_ci NULL`, a format CHECK for ASCII letters/digits/underscore/dot/hyphen, and a unique index. It does not derive usernames from email, display name, or any other data. All existing rows start with NULL; multiple NULLs are allowed under the unique index.

Before a future `NOT NULL` change, inspect the target test schema and rows read-only. After candidate 004 only, recommended checks are:

```sql
SELECT COUNT(*) AS users_missing_username FROM users WHERE username IS NULL;
SELECT LOWER(username) AS normalized_username, COUNT(*) AS occurrences
FROM users WHERE username IS NOT NULL GROUP BY LOWER(username) HAVING COUNT(*) > 1;
SELECT id FROM users
WHERE username IS NOT NULL AND NOT REGEXP_LIKE(username, '^[A-Za-z0-9_.-]{3,50}$', 'c');
```

For every missing or duplicate row, an authorized administrator must supply and approve a mapping. Review candidate format and case-insensitive duplicates before applying a separately approved backfill. Re-run the checks; only when missing/invalid/duplicate counts are zero should a later forward-only migration enforce NOT NULL. No automatic backfill is included. Migration 004's unique index can be created while the column is nullable; if existing target schema drift or an unexpected username column is found, stop and investigate rather than repair automatically.

## Atomic rate-limit design

The persistent store uses `auth_login_buckets(bucket_scope, bucket_digest, window_started_at, attempt_count, ...)`. Digests are raw 32-byte HMAC-SHA256 output using domain-separated `identifier:` and `source:` prefixes; raw username/IP values are not stored. Limits are 5 username attempts and 20 source-IP attempts in a 900-second fixed window.

`PdoLoginRateLimitStore::reserveAttempt()` requires native PDO MySQL prepares and exception mode. It opens one transaction, sorts the two bucket keys for deterministic lock ordering, `INSERT IGNORE`s missing keys, selects both rows `FOR UPDATE`, evaluates each separate limit, increments both only when neither is at the cap, and commits as one reservation. If either is at its cap, it rolls back (including any newly inserted empty bucket) and denies the authentication attempt. Errors roll back and propagate, so callers must fail closed. The reservation happens before account lookup and password verification; this also reduces timing/account-existence distinctions and avoids leaving empty bucket rows for already-blocked requests.

The selected interpretation counts all login attempts, including successful ones, for the 15-minute window. This is clear to implement atomically but can inconvenience frequent legitimate logins. Offline tests cover thresholds and exact window expiry with an in-memory atomic fake; only a multi-connection MySQL 8.4 test can validate actual InnoDB locking, insert contention, deadlock behavior and isolation semantics. HMAC key provisioning/rotation and bounded cleanup of expired bucket rows remain operational requirements.

## Forward-only Migration Runner

The Migration Runner now recognizes 004 only as a pinned `forward` migration. The manifest includes its SHA-256 and statement count; code pins the exact paths, checksums and statement counts for 001–004, preserving all original 001–003 checksum values. 004 statements pass only exact normalized allowlist strings: the reviewed `ALTER TABLE users` and `CREATE TABLE auth_login_buckets`. Other ALTER, CREATE, qualified/cross-schema references, DROP, TRUNCATE, database/user creation and privilege statements are rejected. Runner also validates migration order, exact target/current account and existing ledger checksums as before. Direct `apply()` keeps target gate and connection identity checks. Offline `--plan=up_van_booking_test` is static and reports `database_accessed:false`.

After 004 is marked applied, `status()` checks username column attributes, auth_version type/default, unique username index, expected CHECK constraints, rate table engine/collation and digest/window/count column types. Object inventory still records the new rate table; the altered users table is checked through these targeted postconditions.

MySQL DDL may implicitly commit. The runner does not rely on rollback to reverse ALTER; on interruption it records failure/dirty ledger evidence where possible and stops. No automatic retry, rollback, cleanup or schema repair is performed. A failed/partial 004 needs human inspection and a separately reviewed forward recovery plan.

## Least privilege plan (not applied or live-verified)

| Identity | Required scope |
|---|---|
| Runtime `up_van_app` | SELECT users/roles/user_roles; UPDATE limited auth columns for rehash/auth_version; SELECT/INSERT/UPDATE on `auth_login_buckets`; INSERT into `audit_logs`. Add narrowly scoped users/user_roles DML only when Admin management is enabled. No DDL, global privileges, GRANT OPTION, or root. |
| Migration `up_van_migrator` / test migrator | `ALTER` only on the `users` table, plus schema-scoped CREATE and the existing seed/DDL privileges required by reviewed migrations, only on the respective intended schema. Keep separate from runtime. |
| Preflight | Read-only SELECT scope. |

The existing `ConnectionFactory` grant validator currently allows a narrow set of database/table grants but may not support column-level scope. Review its parser and test exact `SHOW GRANTS` output before any future grant change. This task did not inspect live grants or change them.

## Authentication policy coverage

- Username validation/normalization is centralized as ASCII 3–50 and lowercase canonical lookup; database uniqueness uses `ascii_general_ci`.
- `PasswordHasher::hash()` now enforces the six-character minimum for all newly set hashes. Existing legacy passwords can still be verified; opportunistic rehash is skipped if a legacy password is below minimum, so users are not locked out during migration.
- `PasswordHasher` uses Argon2id; `password_verify` and rehash support already exist.
- SessionManager timeouts remain 30 minutes idle / 8 hours absolute. Middleware compares fresh `auth_version` and active status; persistence is blocked until 004 and PDO AuthRepository integration.
- Authorization policy denies unknown role/permission by default. Login and Admin actions remain unwired/disabled. Admin-only account creation, default Requester and no public registration remain required server-side constraints for the next implementation stage; no initial Admin provisioning CLI exists yet.
- CSRF token comparison uses `hash_equals`; future POST routes still need mandatory route-level enforcement. Error reporting is correlation-ID-only and auth event metadata must remain allowlisted and secret-free.

## Results and readiness

PASS: 004 migration-plan offline checks; draft static checks and unchanged 001–003 hashes; Authentication Foundation Offline tests; Database Offline Safety suite; PHP syntax checks; test-target offline migration plan. No MySQL integration was run.

**Readiness: READY FOR SEPARATELY APPROVED TEST-DB MIGRATION PREFLIGHT, NOT READY TO EXECUTE.** Before execution, confirm the test DB's current ledger/object inventory through the approved read-only preflight, review data/backfill findings, verify live scoped grants, and obtain explicit approval for migration execution. Do not connect or execute from this task.

## Remaining risks / open decisions

1. The policy counts successful sign-ins toward the username and IP thresholds. Confirm this interpretation or explicitly choose failed-attempt-only semantics before user-facing Login is enabled.
2. Determine HMAC secret rotation behavior; rotating it invalidates existing rate-limit keys.
3. Set retention/cleanup bounds and an owner for expired bucket rows.
4. Test 004 CHECK and collation behavior, Row Locking, and concurrency on MySQL 8.4 before relying on them.
5. Define manual username mapping approvals if test rows exist.
6. Decide whether Admin user management uses the runtime DB identity or a separate service identity; least privilege favors separation.
7. Create and review the secure initial-Admin CLI flow before Login launch.
