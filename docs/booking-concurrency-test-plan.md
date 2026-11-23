# Booking concurrency verification — pending separate write approval

No transactions in this plan have been executed. Do not run against production or existing requests.

Prerequisites: verify port 3306, UUID `77218fbd-d66d-11f1-9a0a-5c2886f057d5`, database `up_van_booking_test`, schema and exact runtime grants using `bin/booking-capabilities-readonly.php`. Obtain separate approval for disposable test fixtures and cleanup. Keep HTTP write gates closed; use a CLI integration harness with two independent PDO connections. Both must use native prepared statements. Use a dedicated approved fixture vehicle with no existing requests or assignments.

Every reserving writer must follow this protocol:

1. Before BEGIN, issue `SET TRANSACTION ISOLATION LEVEL READ COMMITTED` (next transaction only).
2. Lock all old/new vehicles with `SELECT id,is_active,passenger_capacity FROM vehicles WHERE id=? FOR UPDATE`, in ascending ID order.
3. For edits, lock the request, recheck version, status and old vehicle against the pre-lock discovery result. Abort on mismatch; never silently retry with a new version.
4. After the vehicle lock, query current committed half-open overlaps (`start < other_end AND end > other_start`); exclude only the current request on edit. Check assignments too.
5. Write request, version and audit/history as appropriate in the same transaction. Commit once; rollback on every failure. Future assignment writers must acquire the same vehicle mutex before inserting/updating assignments.

Two-connection cases for the approved future harness (use barriers, not timing sleeps):

| Case | Connection A | Connection B | Required outcome |
|---|---|---|---|
| Submit / submit | Hold vehicle lock, create submitted fixture, commit | Start before A commits; wait for same vehicle lock, then query conflicts | B sees A and rejects; one request/history/audit set |
| Edit / submit | Edit approved fixture interval while holding vehicle lock | Attempt overlapping submission | Second writer rejects after lock released |
| Stale snapshot regression | Hold vehicle lock; commit a new conflicting submitted fixture | Read officer discovery snapshot before A commit; then wait for vehicle lock | Conflict query after lock sees A under READ COMMITTED |
| Edit / edit | Both begin with same version | Wait for first writer, recheck locked version | Second rejects stale version; no lost update |
| Cross-vehicle moves | Move vehicle 1 to 2 | Move vehicle 2 to 1 | Both lock vehicle IDs ascending; no inverted lock order |
| Boundary | End exactly equals next start | Submit adjoining window | Allowed when capacity/status valid |
| Assignment / edit | Assignment harness holds same vehicle lock | Edit into assignment window | Second sees assignment and rejects |
| Audit failure | Inject audit error after request update | Read after rollback | Original fields/version/history preserved |

Assert row counts, actor IDs, version, status history, audit records and no Section 3 changes using fresh connections after each case. Catch lock timeout/deadlock, rollback fully and surface a retryable error without automatically replaying a submission. Record isolation with `SELECT @@transaction_isolation` inside each transaction. Restore/clean approved fixtures only with explicit write authorization.

Draft creation/update does not reserve a vehicle and cannot enter submitted by itself. Current reserving production candidate paths are `BookingSubmissionService::submit` and `updateOfficerRequest`, both using the same PDO transaction implementation. The generic workflow uses deny-by-default requirements; do not wire another submit/assignment adapter without the vehicle locking protocol above.
