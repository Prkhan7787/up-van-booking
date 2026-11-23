# Task 03 Stage D — Integration test readiness

Updated: 2026-11-09 (Asia/Bangkok).

## Decision and evidence

**Stage D status: PASS — Database Integration Completed.** The administrator-reported
rerun passed all 103 checks, with postflight PASS and both gates false. This records
database integration coverage only; it is not a Booking Application End-to-End Test.
The earlier Worker startup failure and its confirmed fix remain documented below.
Task 04 — Login & RBAC is the next step.

The existing integration harness and worker were extended. workflow-cases.php is a
helper loaded by that harness, not another runner or an application workflow service.

## Prepared coverage

Eight principal scenarios: Director approved/rejected, Delegate approved/rejected,
concurrent same vehicle, concurrent same driver, concurrent both resources, and
adjacent windows. The administrator-reported successful run completed **103 named checks**:

| Group | Prepared checks | Runtime result |
|---|---:|---|
| Existing representative constraints, replacements and release | 11 | PASS |
| Distinct actor/role fixtures | 1 | PASS |
| Director approved workflow | 21 | PASS |
| Director rejected workflow | 19 | PASS |
| Delegate approved workflow | 22 | PASS |
| Delegate rejected workflow | 20 | PASS |
| Four two-worker outcomes plus four atomic-write assertions | 8 | PASS |
| Post-cleanup absence of this run's records | 1 | PASS |
| Total | 103 | PASS — administrator-reported latest run |

Each workflow checks draft creation by requester; valid passenger persistence;
passenger NOT NULL/actor FK/positive count checks; submission by requester;
under_review and pending_approval by officer; officer review/proposal values;
decision actor, authority, outcome, reason, and return-to-officer timestamp;
officer notification matching the decision; and exact status history with actor/version
and audit metadata for every transition. Rejected paths end at closed_rejected with
no assignment or calendar event and no return-for-revision transition.

Approved paths check assigned actor/state, active calendar values, cancelled
assignment exclusion, and completed calendar visibility. Delegate decisions must
have a nonblank authority reference; rejected decisions must have a reason.
Notification channel validation and authority enum validation are negative cases.

The original constraint cases remain: invalid passenger booking FK, duplicate email,
vehicle capacity, invalid status and expense source, valid driver_extra_pay,
one-active-assignment uniqueness, and invalid assignment window. Replacement
links are now asserted explicitly instead of merely selecting them.

## Scope and schema limitations

- These are database integration fixtures, not RBAC, login, notification delivery,
  delegation-document verification, or application E2E tests.
- Director and Delegate both use the existing approver role. authority_kind and
  authority_reference distinguish their decisions; the schema has no delegation
  authority table or actor-to-authority enforcement. No such schema guarantee is claimed.
- Passenger count versus actual passenger rows, vehicle capacity versus booking count,
  legal transition order, and decision-versus-notification/assignment consistency are
  cross-row/application rules. Fixtures assert the intended relationships; the current
  schema does not enforce them against arbitrary SQL. There is no passenger name
  nonblank CHECK or passenger duplicate UNIQUE constraint; none was invented.
- Notification reason is stored in approval_decisions.reason. booking_notifications
  has no reason column; tests link the notification outcome to the verified decision.
- Constraints are representative, not an exhaustive mutation test of all 35 FKs,
  27 CHECKs, and 10 UNIQUE constraints.

## Race conditions and worker safety

Two separate PHP worker processes retain READY/GO barriers, READ COMMITTED,
booking -> vehicle -> driver FOR UPDATE locking, current overlap reads, and atomic
assignment + booking version + status history + audit writes. Conflict cases expect
one assignment; adjacent [09:00,10:00) / [10:00,11:00) expect two. Parent assertions
verify winners' persisted state/history/audit and that losers have no partial writes.

Workers additionally require production gate false, a 24-hex run tag, an active
harness advisory lock, matching synthetic actor email and booking owner, an approved
decision, and existing active vehicle/driver rows. All connections remain bound by
ConnectionFactory to up_van_test@127.0.0.1 and up_van_booking_test.

The barrier does not instrument observed lock waiting. Deadlock retry, simultaneous
replacement/release, and time-extension contention are outside these prepared cases.
The harness proves only its worker protocol when executed; all future application
writers must follow the same lock protocol for overlap protection.

## Harness lock and isolation

GET_LOCK('up_van_booking_test:stage_d_integration', 0) is acquired on the parent
connection before ledger/empty-table checks. A second harness fails without inserting
fixtures. RELEASE_LOCK runs in an outer finally even if cleanup fails; connection
closure also releases MySQL named locks. This names only the test target and does
not lock production tables or databases. It serializes cooperating harnesses, not
arbitrary external writers; use the dedicated test database exclusively during a run.

The harness rejects pre-existing business rows. Roles and migration ledger are retained.
Synthetic actors have random-tag example.invalid emails, NULL password hashes,
synthetic vehicles/drivers, and future windows. Constraint mutations use savepoints.
Setup and workflow fixture writes share one parent transaction, committed only so
worker connections can see fixtures. Each worker commits its own atomic unit.

## Exact cleanup and error handling

The parent's query helper records exact auto-increment IDs after successful inserts.
Worker audit metadata records the random run tag plus assignment/history IDs in the
same transaction as those rows. Cleanup can recover those exact IDs even if a worker
commits and its stdout response is lost. Worker processes are terminated/closed in
pair() finally before parent cleanup begins.

Cleanup rolls back an open parent transaction, verifies synthetic user tag ownership,
and deletes only recorded row IDs in child-first order. Assignment IDs descend to
respect replacement FKs. Role cleanup targets only the specific role inserted for
each synthetic user, then user deletion requires both recorded ID and tag. No other
run's actor, booking, or row IDs are selected for deletion. No broad reset, TRUNCATE,
DROP, schema changes, or foreign-key bypass exists.

After cleanup commits, SELECT assertions check every recorded row ID and related
run booking/user/resource IDs, including calendar events, for zero residue. Cleanup
failure is fatal and prints a non-secret run tag; no automatic reset/retry is attempted.
PASS aggregate is printed only after the test body, cleanup, and lock release succeed.
A killed process, machine shutdown, or lost database connection can still prevent
cleanup; inspect only that printed run's records before deciding recovery.

## Offline verification

- PHP syntax checks: integration.php, assignment-worker.php, workflow-cases.php.
- Existing database offline safety suite: 67 checks PASS; no database connection.
- PowerShell execution wrapper without -Execute: NOT RUN guard confirmed.
- Static review: target/account guards, dependency deletion order, worker transaction
  protocol, source-to-coverage mapping, and unchanged checksum-pinned migration plan.

The latest administrator-reported Stage D run passed on MySQL. This confirms the
prepared database constraints, workflow fixtures, concurrency outcomes, atomic writes,
and cleanup for this harness. It does not test the booking application UI, HTTP routes,
authentication, RBAC enforcement, or end-to-end request submission; no Booking
Application End-to-End PASS is claimed.

## Files changed

- tests/database/integration.php: named assertions, workflow helper inclusion, advisory
  lock, recorded cleanup inventory, worker postconditions, and residue verification.
- tests/database/assignment-worker.php: production gate guard, run ownership checks,
  active resource checks, and atomic cleanup metadata.
- tests/database/workflow-cases.php: four full workflow fixture scenarios and assertions.
- scripts/stage-d-integration.ps1: explicitly gated future execution wrapper.
- docs/task03-stage-d-readiness.md: this updated mapping and procedure.

## Stage D execution procedure (record)

The administrator used the following command. The wrapper first runs read-only
preflight with both gates false. Only after PASS does it set the process-local test
gate true for PHP/workers; finally it sets the gate false and runs postflight. The
Production Gate is never assigned and persistent `.env` values remain unchanged. A
separate PowerShell process is used because `scripts/php.ps1` calls `exit`.

```powershell
Set-Location -LiteralPath 'C:\จองรถตู้\up-van-booking'
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/stage-d-integration.ps1 -Execute
```

Keep persistent `.env` gates false. Omit `-Execute` for preparation-only use; the
wrapper exits without loading PHP or connecting. The successful latest run and its
postflight are recorded below.

## Stage D execution record and Worker startup correction — 2026-11-09

The administrator ran `scripts/stage-d-integration.ps1 -Execute`. The reported
preflight passed for `up_van_booking_test` using `up_van_test`; the reported grant
set was SELECT/INSERT/UPDATE/DELETE. Migrations 001–003 and their checksums/counts
matched. Inventory was 14 tables, 1 view, 35 foreign keys, 27 checks, 10 unique
constraints, and 58 indexes. Director and Delegate workflow/constraint assertions in
the log passed, including `cleanup.no_run_residue`. The run then failed with
`Could not start test worker.` The wrapper's postflight passed and both gates were
reported false. This is a partial run, not an Integration PASS. The test harness had
created synthetic rows in the dedicated test database and reported that its cleanup
left no run residue; no production or Lost & Found database was targeted.

Root cause was confirmed with a no-database PHP child-process diagnostic on the same
PHP 8.4.27 runtime. Because the project directory contains Thai characters,
`PHP_BINARY` exposed a path whose Thai characters were replaced with `?`; the path
did not exist and `proc_open()` failed. A child process launched with the project-
relative `.runtime/php84/php.exe` and the project as its working directory started
successfully. A second smoke check confirmed PDO MySQL loaded in that child and
datetime arguments containing spaces were preserved.

`tests/database/integration.php` now launches Workers using that project-relative
runtime and classifies startup/Worker failures without emitting raw warnings or
diagnostics. The PowerShell wrapper's process-local Test Gate is inherited by its
PHP process and Worker; Worker database access remains through the `test` purpose,
bound to `up_van_booking_test` and `up_van_test`. The assignment Worker and wrapper
were inspected and did not require changes.

After the correction, PHP syntax checks passed for `integration.php`,
`assignment-worker.php`, and `stage-d-preflight.php`; 67 Offline Safety checks passed;
the offline Migration Plan loaded with its three checksums; and the no-database child
process smoke check passed. At the time of this first-failure record, the corrected
Integration suite had not yet been rerun. That status is superseded by the latest run
record below.

Changed for this correction: `tests/database/integration.php` and this execution
record. No Migration SQL, Manifest, `.env`, credentials, grants, or database were
changed in the correction.

## Latest Stage D rerun — PASS — 2026-11-09

The administrator reran the wrapper after the Worker startup correction. Reported
Run ID: `a4672d20b8cfe47de8a69c84`.

- Integration harness: PASS — 103 checks.
- Concurrency: PASS — vehicle, driver, both resources, and adjacent-window boundary.
- Atomic writes: PASS — all four scenarios.
- Cleanup: PASS — `cleanup.no_run_residue`.
- Postflight: PASS.
- Production Gate: `false`; Test Gate: `false`.

This result completes Stage D database integration for the test database. It is not a
Booking Application End-to-End Test and does not claim UI, HTTP, authentication, or
application-level RBAC verification. The project can proceed to Task 04 — Login & RBAC
planning/implementation. No database access or source change was made while recording
this administrator-provided result.
