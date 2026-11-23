# Task 05B — integration boundaries and open decisions

Source/offline implementation only. No database connection, migration, gate change or grant execution is part of this task.

## System Log

GET /admin/system-logs uses the existing authentication middleware, logs.view policy, shared layout and test-only auth_test connection. The controller repeats authorization before constructing the repository. The repository verifies native PDO settings, database/account identity and the complete SystemLogGrantPlan before reading events. There are no mutation routes or repository methods.

Filters: exact action code, numeric actor ID, local Bangkok from/to dates, bounded page number. Dates convert to UTC with an exclusive next-day upper bound. Pagination reads 26 records, displays 25 and orders created_at DESC/id DESC. Actor IDs avoid requiring a users join or additional identity columns.

Only known Admin event role/status metadata is displayed, and only recognized role keys and boolean status values. Authentication metadata and all unknown event metadata are withheld, including values under innocuous keys. Raw metadata never enters the view. Current event coverage is Authentication and Admin User Management, not the future Booking workflow.

Proposed supplemental grant (not executed):

```sql
GRANT SELECT (`id`, `user_id`, `action`, `entity_type`, `entity_id`, `metadata`, `created_at`)
ON `up_van_booking_test`.`audit_logs`
TO 'up_van_auth_test'@'127.0.0.1';
```

This table-scoped identifier contains literal underscores: the database-pattern wildcard issue applies to schema-wide grants, not this table scope. Existing audit INSERT remains necessary. No audit UPDATE/DELETE or DDL is proposed. Live grants are UNVERIFIED. ConnectionFactory and the existing Admin repository accept either their prior reviewed matrices or this exact extension, and still reject extra privileges.

## Booking foundation

The existing app/Modules/Booking directory now contains immutable persisted state, repository/service contracts, an injected scope resolver and business-requirement guard. No HTTP Booking route or PDO Booking repository is installed. PendingBookingRequirements denies every mutation until an approved implementation supplies the required evidence. A posted boolean, role, delegate reference or resource ID is not proof of authority or availability.

Transition transaction: lock persisted booking -> check permission/ownership/scope/version -> verify trusted prerequisites -> compare-and-swap version -> append status history -> append audit -> commit. Any failure, including audit failure, must roll back all writes. No blind retry after an uncertain commit. Resource locking and actual assignment/decision writes must join this same transaction in Phase C/D; the current generic state service alone is insufficient to execute the complete workflow.

Existing states remain unchanged. Admin receives booking.view_all only; decision/assignment still require the relevant additional role, scope and verified prerequisites. A future route must obtain the principal from existing authentication middleware, check permission and CSRF, and use only allowlisted DTO fields. Services never take client role assignments.

Draft creation/update and approved notification/resource-revision events require dedicated future methods; a same-state transition is currently denied rather than fabricating a history record. Rejected and completed/cancelled records do not automatically reopen.

## A4 field mapping

| Document area | Existing source of truth | Pending data |
|---|---|---|
| Number, application/travel dates, requester identity/contact, destination/purpose/count, expense source and pickup/dropoff/coordinator | booking_requests | submission requiredness, numbering policy, attachment storage |
| Part 2 review/proposal/official identity and dates | booking_reviews and actor identity | proposed vehicle/driver identifiers and an immutable document snapshot |
| Part 3 outcome, reason, director/delegate authority and time | approval_decisions | server-verified delegation evidence; printed identity snapshot |
| Actual vehicle/driver, acknowledgment and actual return | vehicle_assignments | operational evidence and stable names/registration at document publication |
| Status history and notifications | booking_status_history, booking_notifications, audit_logs | document delivery evidence and workflow integration |

The UI fixture has separate date-day/month/year fields; the persistent model has DATE/DATETIME values. A future server-owned presenter must split Bangkok dates, use Buddhist calendar years for the official form, and read actual return from actual_return_at rather than the planned return. Typed preview names are not electronic signatures. Reuse the current form renderer and print CSS; keep fixture data isolated from official document data. Do not synthesize signatures or use the registration/driver prefilled in the manual as production defaults.

Proposed resources in Part 2 must not be stored as an active vehicle_assignments row before approval. A reviewed forward migration is needed for proposed resource data and stable publication snapshots; a separate approved migration is also needed for attachments, operational mileage/fuel and reporting records where absent. Do not modify migrations 001–004 or overload free-text notes as hidden structured storage.

## Open decisions (no guessed defaults)

- Five working days: inclusion of submission/travel date, cut-off time, official and university holidays, source and update ownership.
- Urgent request exceptions and who may authorize them.
- Resource reservation/expiry while approval is pending; a proposal is not a confirmed assignment.
- Director/delegate authority: issuer, subject, scope, validity, revocation and evidence. Proposed design: separate server-managed authority records, verified on each decision; no automatic authority from role alone. Self-approval/separation of duties is also pending.
- Required fields and acceptable supporting documents; manual page 8 has no exhaustive required markers.
- Single versus multiple funding sources (existing schema stores one value).
- Officer/approver scopes, driver-record entry and verification responsibility, fleet-return/completion evidence.
- Notification success evidence, cancellation/rescheduling and whether changed resources require renewed approval.

## Integration readiness

Read-only System Log integration needs administrator-approved exact SELECT privileges and a live verifier check. Phase C may implement approved guards/presenters, but operational Booking execution remains blocked by the open decisions and forward-migration review. No test or production data has been written by this task.

Prepared read-only capability command (not run during implementation):

```powershell
Set-Location -LiteralPath 'C:\จองรถตู้\up-van-booking'
powershell.exe -NoProfile -ExecutionPolicy Bypass -File scripts/php.ps1 scripts/verify-system-log-capability.php
```

This reports missing/unexpected privilege names without returning log contents. It does not enable gates or apply privileges. The new browser fixtures are CLI-only and render fabricated data to local files; they are not public Preview routes.

## File inventory

Added:
- app/Services/RoleLabels.php
- app/Database/SystemLogGrantPlan.php
- app/Core/SystemLogUnavailableException.php
- app/Contracts/SystemLogRepository.php
- app/Models/SystemLogFilter.php
- app/Services/SafeAuditDetails.php
- app/Repositories/PdoSystemLogRepository.php
- app/Services/SystemLogService.php
- app/Services/SystemLogFactory.php
- app/Controllers/SystemLogController.php
- app/Views/admin/system-logs.php
- app/Modules/Booking/BookingState.php
- app/Modules/Booking/BookingScope.php
- app/Modules/Booking/BookingRequirements.php
- app/Modules/Booking/PendingBookingRequirements.php
- app/Modules/Booking/BookingTransitionPolicy.php
- app/Modules/Booking/BookingRepository.php
- app/Modules/Booking/BookingWorkflow.php
- app/Modules/Booking/BookingWorkflowService.php
- scripts/verify-system-log-capability.php
- tests/task05b-system-logs-offline.php
- tests/task05b-booking-offline.php
- tests/browser/render-task05b-fixture.php
- tests/browser/task05b-offline.cjs
- docs/task05b-foundation.md

Modified:
- app/Services/AuthorizationPolicy.php
- app/Database/ConnectionFactory.php
- app/Repositories/PdoAdminUserRepository.php
- app/Services/UiFoundationService.php
- app/Views/partials/navigation.php
- app/Views/layouts/workspace.php
- app/Views/auth/dashboard.php
- app/Views/home.php
- app/Views/admin/users.php
- app/Views/form-preview.php
- routes/web.php
- public/index.php
- public/assets/form-preview.css
- public/assets/app.css (generated by Tailwind build)
- scripts/task04c-1a-readonly-preflight.php
- tests/admin-users-offline.php
- tests/ui-foundation.php
- tests/browser/home-responsive.cjs
