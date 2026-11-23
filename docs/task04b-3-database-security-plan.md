# Task 04B-3 — Database Schema & Security Preparation

Status: **Design and offline review only. No database connection or migration execution was performed.**

## Existing schema and evidence

Reviewed migrations `001_identity`, `002_booking_design`, seed `003_roles`, `database/manifest.php`, authentication foundation classes, and `scripts/verify-database-readonly.php`. The manifest pins only 001–003; their files and checksums were not changed.

`users` currently has `id`, unique `email`, `display_name`, nullable `password_hash`, `is_active`, and timestamps. It has neither `username` nor `auth_version`. `roles` and `user_roles` support the four seeded roles and multiple role assignments. `audit_logs` has actor, action, entity, JSON metadata and time fields. No persistent login-attempt table or PDO AuthRepository implementation was found. Migration 003 seeds roles; it does not provision an Admin account.

The verification script encodes expected grants, but these are source expectations, not evidence of live grants. No `SHOW GRANTS` was run in this task. Runtime is currently expected to have SELECT only, which is insufficient for authentication password rehash, login rate state, audit writes, and Admin account management.

## Draft migration and dependencies

Draft: `database/drafts/004_auth_security.sql`. It is intentionally outside the manifest and therefore unavailable to the Migration Runner. It proposes forward additions: nullable ASCII username with case-insensitive unique collation and format check; `auth_version` defaulting to 1; and an InnoDB HMAC-digest login bucket table with scope, attempt window, block and cleanup timestamps. It contains no destructive or privilege statements.

Username remains nullable initially because no reliable mapping from existing email/display name to the desired username has been approved. Before making it NOT NULL, audit nulls, normalize candidates under the exact ASCII rule, resolve case-insensitive duplicates with an administrator-approved mapping, backfill, then issue a separate forward-only constraint migration. MySQL unique indexes allow multiple NULLs, enabling this staged rollout.

The draft uses MySQL 8 CHECK constraints and `REGEXP_LIKE`; confirm server behavior through an approved test migration review. The bucket table has no user FK because buckets can represent unknown usernames and source addresses. Use `(bucket_scope, HMAC digest)` as key and never save raw usernames/IPs. A bounded cleanup job is required later. Runtime need not have DELETE if expired windows reset through upsert; retention cleanup needs a separately scoped maintenance permission/process.

**Runner blocker:** `MigrationPlan::load()` only allows CREATE TABLE/View or role seed statements and rejects ALTER. This draft cannot be run by the current runner. Do not bypass it or put the draft in the manifest. A reviewed forward-migration mechanism and offline safety coverage are prerequisites; no runner change was made.

`auth_identities` is unnecessary for local username/password authentication. Keep it for a later SSO migration with unique binary/case-sensitive `(issuer, subject)` and a user FK; never auto-link solely by email.

## Authentication policy review

| Policy | Requested default | Current source evidence / gap |
|---|---|---|
| Username | ASCII 3–50, letters/digits/`_.-`, case-insensitive unique | No username field/validator; login accepts a normalized generic identifier. Draft adds storage constraint; application normalization/validation remains required. |
| Password | minimum 6 | `PasswordHasher` uses Argon2id and verification/rehash APIs; no central minimum-length validator was found. Enforce in account create/reset service before activation. |
| Account rate limit | 5 failures per 15 min | Current limiter accepts one shared configurable limit; it separates identifier and IP HMAC digests, but checks then records in separate operations. |
| IP rate limit | 20 failures per 15 min | Same shared limit as account; distinct 20 policy is not represented. |
| Idle / absolute session | 30 min / 8 hours | `SessionManager` constants match 1800 / 28800 seconds. |
| Session revocation | auth_version | Middleware reads fresh auth state and compares version; database column is absent. |
| Account creation | Admin only, default Requester, no public registration | RBAC policy exists, but real Admin actions are not enabled. No initial Admin CLI provisioner was found. |

Rate-limit race: current `allows()` and failure recording are separate, so simultaneous requests can pass checks before increments land. The future PDO store must atomically lock/reserve both identifier and IP buckets in deterministic scope order, check separate caps (5 and 20), and update in one transaction. Use domain-separated HMAC keys. Preserve generic login errors and dummy-hash verification. Open policy question: whether successful login clears identifier failures; recommended clear identifier bucket only after success, while preserving IP abuse state, atomically.

## Least-privilege plan (not applied)

| Account | Proposed minimum future access |
|---|---|
| `up_van_preflight` | SELECT on approved schema objects only; read-only. |
| `up_van_migrator` | DDL/seed privileges only on `up_van_booking`, for separately approved migrations. |
| `up_van_app` | SELECT users/roles/user_roles; UPDATE only needed auth columns for rehash/auth-version; atomic SELECT/INSERT/UPDATE on `auth_login_buckets`; INSERT audit events; later Admin actions need narrowly scoped INSERT/UPDATE on users and SELECT/INSERT/DELETE on user_roles. No DDL, global rights, or GRANT OPTION. |
| `up_van_test` | Test-schema-only DML required by approved test harness. |
| `up_van_test_migrator` | Migration-only privileges on `up_van_booking_test`. |

These are recommendations, not confirmed live grants. Prefer column/table-scoped grants where supported. Review `ConnectionFactory` allowlisting before applying column-level grants because its parser may reject them. Test accounts must have no production schema grants. No grant changes were made.

## Security checklist and readiness

- **Account enumeration:** partially prepared: generic failure and dummy hash exist. Verify all paths have comparable work and no account-specific messages/logs.
- **Session revocation:** middleware design exists; blocked until auth_version migration and a PDO repository that reloads active/version/roles every request.
- **CSRF:** shared token/validation primitives exist; future POST routes must invoke them. No login routes were enabled.
- **Rate limit:** not ready; split policies, persistent PDO store, atomic transaction and concurrency tests are missing.
- **Password:** Argon2id is implemented; minimum length policy and deployment benchmark/configuration remain.
- **Admin escalation:** server authorization denies by default. Last-admin protection and self-role-change protections need transactional service/repository enforcement.
- **Sensitive logging:** safe event logger interfaces exist; maintain event allowlists; never log password, hash, HMAC key, or raw identifier/IP in metadata.
- **Cookies/HTTPS:** Secure is enabled based on HTTPS/override; deployment must enforce HTTPS and trust proxy headers only when explicitly configured.
- **Normalization:** current trim/lowercase behavior is not the specified ASCII validation; implement one canonical validator for create/update/lookup/rate bucket/uniqueness.

Readiness for test migration on `up_van_booking_test`: **WARNING / NOT READY**. Draft is statically checked but unregistered and runner rejects ALTER; data backfill, PDO adapters, runtime grants and atomic rate-limit design remain unresolved. No DB or gate was accessed or changed.

## Open questions before Task 04B-4

1. Are existing email-only rows expected on the test DB, and who approves username backfill/duplicate resolution?
2. Count failed attempts only or every login attempt? Should success clear identifier, IP, both, or neither buckets?
3. What retention period and cleanup process are approved for rate-limit buckets and audit events?
4. Should lockout last until the 15-minute window expires or use a separate cooldown?
5. What IIS HTTPS/proxy topology defines trusted client IP and secure-cookie behavior?
6. Should runtime account management share the runtime credential or use a separate service credential?

## Offline validation

`tests/auth-schema-draft.php` checks draft shape, forbidden operations, manifest exclusion, and unchanged 001–003 checksums. It cannot prove MySQL execution compatibility. Migration integration needs separate explicit approval.
