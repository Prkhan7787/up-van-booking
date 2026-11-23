# Migration 006 failed 1/2 — diagnosis and recovery review

No retry, DDL, GRANT, ledger change, restore or gate opening was performed during
this diagnosis. Source edits are limited to future safe execution diagnostics.

## Confirmed live evidence from Restore 3307

- Pinned actual identity: port 3307, UUID 37e4d222-dda2-11f1-b503-5c2886f057d5,
  datadir C:\Users\User\mysql-restore-006-data, database up_van_booking_test,
  account up_van_test_migrator@127.0.0.1.
- 006 checksum still matches; ledger failed, completed 1/2, failure_code statement_failed.
- Province exists as varchar(100) NULL; requested_vehicle_id/index/FK are absent.
- vehicles.id is bigint unsigned PRIMARY KEY; proposed requested_vehicle_id is
  BIGINT UNSIGNED NULL. No type/sign mismatch found. Parent/child engines are
  InnoDB / utf8mb4_unicode_ci. No fk_booking_requested_vehicle name collision found.
- Existing booking_requests FKs reference users(id); no existing FK was changed.
- Counts remain vehicles=2, bookings=0, assignments=0. Prior post-failure data
  fingerprint confirmed all nine original vehicle fields unchanged.
- SHOW GRANTS has database CREATE (proper escaped schema at partial_revokes=0),
  booking_requests ALTER/INSERT, ledger SELECT/INSERT/UPDATE, and only
  COLUMN REFERENCES(id) on vehicles. No TABLE REFERENCES on vehicles.

## Confirmed privilege-plan defect; original error is not recovered

MySQL 8.4.9 source check_fk_parent_table_access explicitly requires REFERENCES at
TABLE scope on a new foreign key's parent and rejects column-only REFERENCES.
sql_table.cc invokes it for ALTER ADD FOREIGN KEY before the DDL proceeds.

Primary sources:
- https://github.com/mysql/mysql-server/blob/mysql-8.4.9/sql/auth/sql_authorization.cc
  check_fk_parent_table_access, around lines 5552–5585.
- https://github.com/mysql/mysql-server/blob/mysql-8.4.9/sql/sql_table.cc
  around lines 15506–15516.
- https://dev.mysql.com/doc/refman/8.4/en/privileges-provided.html

The project's column-only REFERENCES plan/guard is therefore insufficient for this
server, despite its previous offline/preflight PASS. It is a confirmed blocker for
statement 2, not evidence that type/FK SQL should be rewritten. The server source
predicts 42000/1142 for that privilege rejection. The ORIGINAL PDO error was discarded
by Runner catch(Throwable), so do not claim that its exact native code was recovered.
Other simultaneous failures cannot be categorically excluded without original history.

Direct additional CREATE on booking_requests is not needed: reviewed database CREATE
already supplies effective coverage. No global privilege, broad SELECT or DROP is needed.
A corrected 006-only profile would replace column REFERENCES(id) with TABLE REFERENCES
on precisely up_van_booking_test.vehicles. It is NOT changed/granted in this diagnosis;
profile/source/SQL approval must precede any live privilege reconciliation.

## Recovering original error evidence without repeating DDL

Migrator reads of performance_schema.events_statements_history_long were denied
42000/1142; INNODB_FOREIGN required privilege and returned 42000/1227. These codes
describe the DIAGNOSTIC SELECT attempts, not the original ALTER failure.
Do not grant PROCESS or Performance Schema SELECT to the migrator to inspect history.

Use existing root with a hidden native prompt, explicitly pinned port 3307:

```powershell
Set-Location -LiteralPath 'C:\จองรถตู้\up-van-booking'
& 'C:\Program Files\MySQL\MySQL Server 8.4\bin\mysql.exe' --no-defaults --no-login-paths --protocol=TCP --host=127.0.0.1 --port=3307 --user=root --password --skip-reconnect --database=up_van_booking_test
```

First check SELECT @@port,@@server_uuid,@@datadir,DATABASE(); against the pinned
identity. Only then run, in that same session:

```sql
SOURCE C:/จองรถตู้/up-van-booking/scripts/migration-006-partial-diagnostic-readonly.sql
```

This file contains SELECT/SHOW GRANTS only, no schema/ledger writes. The history
query returns error codes for ALTER only, no SQL text or message text. If the
consumer was disabled, or events aged out, empty history is NOT proof of no error.
SHOW WARNINGS in a new connection cannot recover another closed session's warnings.
InnoDB's latest FK error may be unrelated; a privilege failure can occur before
InnoDB executes anything. Do not dump/share full InnoDB status or raw error logs.

## Future safe execution diagnostics implemented, not executed

For 006 only, Runner now retains safe statement_number and execution_stage
(DDL / LEDGER_PROGRESS / POSTCONDITIONS / LEDGER_APPLIED) plus SQLSTATE/native code
from the original error. No SQL, password, driver message or raw previous exception
is emitted. CLI catches this structured failure before its generic catch. Existing
ledger failure updates and other migration semantics remain unchanged.
This cannot reconstruct the error from the already-completed failed process.

## Recovery choices — design only, no executable recovery authorized

Preferred: preserve this failed restore instance and prepare a narrowly controlled
006 resume capability in the EXISTING Runner, after separate source approval:

1. Preserve a new private backup/snapshot of the partial instance and original
   checksum/ledger/schema/grant evidence; do not overwrite the pre006 backup.
2. Review and correct 006-only table REFERENCES policy; obtain separate approval
   for the exact missing table privilege on 3307. Keep 001–005/runtime profiles intact.
3. Resume preconditions must pin identity, checksum, exact failed 1/2 ledger,
   unchanged 001–005, correct first-column type/nullability, no second column/index/FK,
   metadata visibility, two vehicle fingerprints and no unexpected object drift.
4. After another explicit execution approval, acquire migration lock, recheck on
   the SAME connection, and execute ONLY the pinned second statement. Never redo
   statement 1 or mark the failed ledger applied before DDL/postconditions succeed.
5. Only after complete column/index/FK/UNIQUE and data preservation verification
   may the approved runner record completion/applied. Preserve failure evidence.
   Any failure stops with native safe diagnostics; no automatic rollback or retry.

Alternative: preserve this instance untouched and, with separate infrastructure,
restore and account approvals, rehearse the original migration on a NEW isolated
instance restored from the verified pre006 backup after correcting the privilege
profile. It avoids altering the present failed ledger/schema, but needs more setup.
No restore onto 3306 or overwrite of the current 3307 is authorized.

Do not manually execute statement 2 as root, reset/delete ledger 006, remove province
to force a rerun, disable foreign_key_checks, or use the current --apply command.
The existing guard must continue rejecting this dirty partial state. Current status:
NO-GO for recovery execution; GO only for read-only evidence and separately reviewed
diagnostic/source preparation. MySQL 3306 and Lost & Found remain untouched.
