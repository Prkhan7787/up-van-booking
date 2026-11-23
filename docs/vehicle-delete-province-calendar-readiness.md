# Vehicle retirement, province and calendar readiness

Source/offline preparation only. No database, credentials, gates or grants were changed.

## Vehicle deletion policy

The Delete action always retires the vehicle using `is_active=0` inside a transaction
with row locking and a `vehicle.deleted` audit event. Assignment history is checked
and recorded. Every vehicle row, assignment and requested-vehicle foreign key is
preserved, including accounts with no assignment history. No hard DELETE is enabled
or proposed as a runtime grant. Retired cars remain visible to Admin as inactive
historical records; the active calendar selector excludes them. Existing assignments
are neither cancelled nor reassigned by retirement.

The current schema has no separate deleted flag. Hiding retired cars from Admin's
historical list would require an additional agreed lifecycle policy; do not infer
deletion solely from the pre-existing inactive flag.

## Migration 006

The existing draft adds nullable `vehicles.registration_province VARCHAR(100)` and
nullable `booking_requests.requested_vehicle_id` with index/FK. It preserves the
registration-only unique index. No existing province or requested vehicle is inferred.
MigrationPlan pins its bytes and exact two SQL statements behind `load(true)`;
the default manifest/plan remains 001–005. The same MigrationRunner handles DDL,
exclusive migration lock, preconditions, postconditions and dirty-ledger protection.
Post-006 status validates the new schema rather than reapplying 005's removed-column
postcondition. Any partially applied DDL or ledger stops execution; never retry DDL
automatically. Backup existence and nonzero size are required, not backup contents.

Use a NEW external backup before live migration because the original backup predates
the two vehicles. Approvals are still required for the schema, migrator DDL/FK grants
and the explicit migration_test_006 / Migration006GrantPlan profile. The existing
MigrationTestGrantPlan remains 001–005 and has NOT been broadened. The 006 CLI now
selects its own exact profile and reuses the same credentials. Live grant changes
still require approval. Do not work around either guard with root or global grants.
Runtime province grants are already documented by
VehicleAdminGrantPlan::supplementalProvinceSql(); requested-vehicle SELECT belongs
to Calendar and is not required for Vehicle Management. Verify actual missing grants
and column existence before issuing any grants. No runtime DELETE/DDL is needed.

Read-only preflight (after reviewing the migrator's metadata privileges):

```powershell
Set-Location -LiteralPath 'C:\จองรถตู้\up-van-booking'
$vehicleBackup = 'C:\Users\User\REPLACE_WITH_FRESH_TEST_BACKUP.sql'
powershell.exe -NoProfile -ExecutionPolicy Bypass -File '.\scripts\php.ps1' '.\bin\db-migration-006.php' --preflight "--backup=$vehicleBackup"
Write-Host "Exit Code: $LASTEXITCODE"
```

The CLI also implements `--apply --confirm=up_van_booking_test --backup=...` and
`--postflight --backup=...`. Apply requires the existing test migration approval gate,
test-only identity, reviewed grants, backup, exact baseline ledger and schema. These
are documented modes, NOT authorization to execute them. No gate is opened by CLI.

## Calendar

The fleet query reads active vehicles directly and uses vehicle_id, independent of
requested_vehicle_id. Optional columns are read only after a successful probe.
Only approved effective runtime grant unions are accepted. Fleet reading and schedule
capability are separate; missing schedule grants produce CALENDAR_SCHEDULE_GRANTS_REQUIRED,
not an assertion of availability. Only confirmed active assignments block the same
vehicle using starts_at < requested_end AND ends_at > requested_start. Pending
requests do not reserve vehicles. Booking POST remains unavailable.

## Live validation still required

1. Run scripts/task04c-1a-readonly-preflight.php with all write gates closed.
2. Confirm active cars through a SELECT-only administrator query; compare IDs with
   Calendar dropdown. The inactive car must not be offered for new booking.
3. Open /admin/vehicles and /bookings/calendar without submitting forms. Select the
   active vehicle, confirm vehicle_id persists, and inspect any safe schedule error.
4. Obtain approval separately for a write smoke test of soft retirement. Do not
   delete either existing vehicle as part of source validation.
5. Migration 006 execution is NO-GO until fresh backup, live preflight and scoped
   migrator/runtime privilege review are complete. Never repeat 005.
