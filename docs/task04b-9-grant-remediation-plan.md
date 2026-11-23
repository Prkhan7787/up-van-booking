# Task 04B-9 — Test Migrator Grant Remediation Plan

**Scope:** Source and reported Grant Verification result only. No MySQL connection or SQL execution was performed. This plan does not authorize GRANT, REVOKE, Migration 004, or changes to either gate.

## Root Cause

The reported account has database-scoped `SELECT`, `INSERT`, `UPDATE`, `REFERENCES`, `INDEX`, and `CREATE VIEW` privileges. Those grants are broader than Migration 004 needs. They likely reflect privileges retained from earlier schema migrations, which have already been applied according to the previous preflight report. The Grant Verifier correctly marks them unexpected for the Migration 004-only role. The reported `table.users` missing item means its effective grants do not yet satisfy Migration 004's required `ALTER`, `CREATE`, and `INSERT` coverage for that table.

This conclusion uses the administrator-provided live verification result; this task did not independently query MySQL. Direct grants and inherited role grants must be distinguished before remediation. A direct `REVOKE` will not remove privileges inherited through a role.

## Required Privileges for Migration 004

Reviewed source files are `database/drafts/004_auth_security.sql`, `app/Database/Migration004GrantPlan.php`, `app/Database/MigrationPlan.php`, and `app/Database/MigrationRunner.php`.

| Scope | Required effective privileges | Reason |
|---|---|---|
| Database `up_van_booking_test` | `CREATE` | Create the new `auth_login_buckets` table; also supplies the CREATE prerequisite when altering `users`. |
| Table `schema_migrations` | `SELECT`, `INSERT`, `UPDATE` | Runner reads the ledger, inserts the migration-started row, advances statement counts, and marks completion/failure. |
| Table `users` | `ALTER`, `CREATE`, `INSERT` | Alter columns/check/unique index. `CREATE` is covered by the required database-level CREATE privilege. |

No `DROP`, `DELETE`, `CREATE VIEW`, `INDEX`, or `REFERENCES` is required by Migration 004. Its indexes are declared inline in the reviewed DDL, and it adds no foreign keys. Runtime permissions are not part of this remediation.

The MySQL 8.4 reference states that `ALTER TABLE` requires `ALTER`, `CREATE`, and `INSERT`; creating the table also creates its declared indexes. [ALTER TABLE privileges](https://dev.mysql.com/doc/refman/8.4/en/alter-table.html), [MySQL privileges](https://dev.mysql.com/doc/refman/8.4/en/privileges-provided.html), [CREATE TABLE](https://dev.mysql.com/doc/refman/8.4/en/create-table.html).

## Exact Proposed SQL (Review Only)

The following direct-grant plan assumes the matching direct grants appear in `SHOW GRANTS`, `@@GLOBAL.partial_revokes = 0`, and the schema-level privilege text uses the escaped Test schema scope. It is not approved for execution yet.

```sql
-- Remove only the reported excess direct privileges from this Test schema.
REVOKE SELECT, INSERT, UPDATE, REFERENCES, INDEX, CREATE VIEW
ON `up\_van\_booking\_test`.*
FROM 'up_van_test_migrator'@'127.0.0.1';

-- Retain/ensure only the schema-level CREATE needed to create auth_login_buckets.
GRANT CREATE
ON `up\_van\_booking\_test`.*
TO 'up_van_test_migrator'@'127.0.0.1';

-- Limit ledger data access to the existing migration ledger table.
GRANT SELECT, INSERT, UPDATE
ON `up_van_booking_test`.`schema_migrations`
TO 'up_van_test_migrator'@'127.0.0.1';

-- ALTER's CREATE prerequisite is inherited from database-scoped CREATE.
GRANT ALTER, INSERT
ON `up_van_booking_test`.`users`
TO 'up_van_test_migrator'@'127.0.0.1';
```

Execute the `REVOKE` only if each listed privilege is confirmed as a **direct grant on this exact schema scope**. If `SHOW GRANTS` uses a different scope spelling or privileges are split among grants, stop and prepare matching REVOKE statements from the observed output rather than broadening the target. Keep other `up_van_booking_test` objects untouched. MySQL's database-level grant syntax treats `_` as a wildcard when `partial_revokes=0`, so each underscore must be escaped as `\_`; when partial revokes is enabled, unescaped underscores are literal. Wildcard use in database-level grants is deprecated, so the server setting and grant text must be checked at execution time. [MySQL 8.4 GRANT](https://dev.mysql.com/doc/refman/8.4/en/grant.html).

### Role and inherited privilege handling

Before applying the SQL, inspect both direct grants and role assignments. From the Test Migrator session, record:

```sql
SELECT DATABASE(), CURRENT_USER(), CURRENT_ROLE(), @@GLOBAL.partial_revokes;
SHOW GRANTS FOR CURRENT_USER;
```

An administrator may additionally inspect:

```sql
SHOW GRANTS FOR 'up_van_test_migrator'@'127.0.0.1';
```

For every assigned/enabled role shown, inspect its grants (and nested roles) using `SHOW GRANTS FOR 'role_name'@'role_host'` and `SHOW GRANTS ... USING role` where appropriate. MySQL `SHOW GRANTS` lists role assignments, while its `USING` form can show the privileges associated with named roles. [SHOW GRANTS](https://dev.mysql.com/doc/refman/8.4/en/show-grants.html).

Do not revoke privileges from a shared role, because that changes every account inheriting that role. If a dedicated, excess role is assigned only to this Test Migrator, the administrator can remove that role assignment with the exact observed role identity:

```sql
REVOKE 'confirmed_role_name'@'confirmed_role_host'
FROM 'up_van_test_migrator'@'127.0.0.1';
```

This role statement is a template, not executable as written. Do not replace role names by guesswork. If mandatory/default/active roles or global grants still provide excess privileges, **NO-GO** until their effective permissions are resolved without impacting unrelated accounts. `GrantVerifier` deliberately returns `UNVERIFIED` for unresolved role/unsupported grant forms.

## Impact Analysis

- The Runner needs the three ledger privileges on the existing `schema_migrations` table after 001–003 are applied; removing database-wide DML does not prevent that if the table-scoped ledger grant is present.
- `CREATE` remains database-scoped because the future table does not exist yet and must be created. That privilege allows creation of other tables in this schema too; the DB privilege model cannot scope CREATE to a not-yet-existing table. The Migration Runner's pinned allowlist and checksum remain a separate safety boundary.
- `ALTER` is restricted to `users`; `INSERT` on `users` is required by MySQL's `ALTER TABLE` privilege rule. `CREATE` is inherited from the database grant.
- `REFERENCES`, `INDEX`, and `CREATE VIEW` are unnecessary for this migration. Revoking them at schema scope may break ad hoc/manual work, but should not affect the pinned 004 migration.
- Do not remove `SELECT`/`INSERT`/`UPDATE` from the ledger table, or MigrationRunner status/apply will fail. Do not touch `up_van_booking`, `lost_found`, MySQL system schemas, or runtime accounts.
- Any DDL failure can leave a partial migration because MySQL DDL can implicitly commit. This grant remediation itself does not modify schema data or run DDL.

## Verification Procedure (After Separate Approval)

1. Capture the pre-change account `SHOW GRANTS`, role assignments, active/default roles, and `partial_revokes` value in an administrator-controlled change record. Do not record passwords.
2. Have the authorized administrator apply only the exact direct-grant changes justified by the captured output. Do not change either execution gate.
3. Reconnect as `up_van_test_migrator`; verify `DATABASE() = up_van_booking_test`, `CURRENT_USER() = up_van_test_migrator@127.0.0.1`, server version, and `partial_revokes`.
4. Run `SHOW GRANTS FOR CURRENT_USER` and inspect effective role grants. Then run the project verifier from PowerShell:

```powershell
Set-Location -LiteralPath 'C:\จองรถตู้\up-van-booking'
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/php.ps1 scripts/verify-test-migrator-grants.php
```

5. Expected result: `PASS`, no missing or unexpected privileges, no unresolved role privileges, target identity exactly Test. `FAIL`, `UNVERIFIED`, or `BLOCKED` means stop. This script checks Grants read-only; it does not run Migration 004.
6. Confirm Production and Test migration gates remain false. Do not run a Migration or a write test as part of grant verification.

The verifier's `SHOW GRANTS` output should be reviewed privately by the administrator; do not paste raw output into chat if it contains other account names or infrastructure details.

## Rollback Plan

Before changes, preserve exact pre-change grants and role assignments in a secure administrator change record. If the grant check fails after remediation, restore only the prior grants on this Test account and exact Test schema/table scopes. For the reported direct schema privileges, the rollback template is:

```sql
GRANT SELECT, INSERT, UPDATE, REFERENCES, INDEX, CREATE VIEW
ON `up\_van\_booking\_test`.*
TO 'up_van_test_migrator'@'127.0.0.1';
```

Then restore any pre-existing table-scoped grants or role assignments from the captured record, only after checking whether the role is shared. Do not use `GRANT ALL`, global grants, broad wildcard schemas, or modify another account. A rollback restores the known pre-change grant posture, including its excess privileges; it is a temporary recovery action, not an approved final state. Re-run the read-only verifier and stop before Migration execution.

## GO / NO-GO

**GO:** Prepare for a separately approved grant change after direct-vs-role provenance is confirmed and the administrator has a secure rollback record.

**NO-GO:** Execute GRANT/REVOKE now, run Migration 004, or enable Login. The reported result does not establish whether the excess privileges are direct or role-inherited, and the effective role set has not been supplied. No database access or privilege change occurred in this task.
