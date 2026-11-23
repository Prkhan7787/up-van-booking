# Migration 006 grant phase fix — 2026-11-12

Source/offline only. No account creation, GRANT, database connection, migration, .env,
SQL/checksum/ledger mutation or Production deployment was performed.

## Root cause and implementation

006 inherited the legacy vehicles SELECT list including registration_province, which
is absent at schema 005. A new account could neither receive that column grant nor
satisfy the guard. GrantVerifier itself was correct and remains unchanged.

Migration006GrantPlan now defines explicit pre/post profiles:

- pre: only the nine existing vehicles columns; province SELECT is unexpected.
- post: same minimum grants. Postflight reads information_schema, not province or
  requested_vehicle_id row values, so neither new column needs SELECT for the migrator.
- post legacy compatibility: the exact same minimum plus SELECT(registration_province).
  No table-wide vehicles SELECT and no requested_vehicle_id SELECT are permitted.

ConnectionFactory's 006 guard determines phase on the SAME PDO using the existing
MigrationRunner::status() and vehicle006Preflight(). All pinned baseline ledger rows,
counts and checksums must be clean. Schema 005 must pass full 006 preflight; applied
006 must pass existing postconditions (columns/types/nullability, FK/index, retained
registration UNIQUE). Missing/invisible metadata, half-applied DDL or dirty ledger
stop the operation; no fallback phase or relaxed generic grant guard is used.

The pre/post nine-column profile allows the same approved account to migrate and
postflight without any mid-migration GRANT. An old 3306 account still carrying a stale
province column grant will fail the strict pre profile: investigate and request a
separate reviewed grant reconciliation, never silently REVOKE or widen the pre profile.
001–005 profiles, Runner code/ledger logic, Migration 006 SQL and its two statements
remain unchanged. Pinned checksum:
61c0f5b4fe152c83142d49ba81070a70df2dc352a9073302809cee9696827748.

## Privilege scope

The actual two ALTERs need ALTER plus effective CREATE/INSERT coverage on vehicles
and booking_requests, and REFERENCES(vehicles.id) for the FK. The reviewed database
CREATE supplies CREATE coverage; do not duplicate table CREATE grants.

Ledger uses SELECT/INSERT/UPDATE(schema_migrations). Existing Runner/profile baseline
also requires users INSERT/ALTER, auth_login_buckets SELECT, nine vehicles SELECT
columns, booking_requests SELECT(id), and vehicle_assignments SELECT(id). Some of these
are inherited Runner baseline privileges rather than direct requirements of the two
006 statements. They are kept to preserve the explicit existing contract, not claimed
to be the theoretical minimum for running the ALTERs alone. No new broad SELECT added.

## SQL for separate approval, Schema 005 / restore port 3307

Review scripts/migration-006-schema005-migrator-grants.proposed.sql. It has seven
GRANTs and no account/password SQL. It is tested against BOTH the pre and minimum post
profiles. Its database scope escaping requires partial_revokes=0. For partial_revokes=1,
replace only database CREATE scope with literal `up_van_booking_test`.*; never execute
both variants. CREATE USER and credential handling remain separate approvals.

SQL cannot identify a port. Before any approved mutation, verify actual port 3307,
UUID 37e4d222-dda2-11f1-b503-5c2886f057d5, datadir C:\Users\User\mysql-restore-006-data,
database up_van_booking_test, account absence and clean 001–005 ledger on the SAME
non-reconnecting connection. Never SOURCE this proposal into the 3306 session.

After separately approved account creation/grants, use the existing operator plan's
--preflight --restore-test-3307 command with MIGRATION_TEST_DB_PORT=3307 only in a
dedicated process. No gate is needed for preflight. Require grants, identity and schema
PASS before requesting separate migration approval. Do not use root in MigrationRunner.

GO for reviewing/approving the revised account/grant plan and subsequent read-only
preflight. Migration execution remains pending actual account/grants/backup/preflight
results and separate approval.
