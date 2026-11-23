$ErrorActionPreference='Stop'
Set-StrictMode -Version Latest
$root=Split-Path -Parent $PSScriptRoot
$source=Get-Content -LiteralPath (Join-Path $root 'scripts/booking-reset-dry-run.ps1') -Raw
$tokens=$null;$errors=$null
[System.Management.Automation.Language.Parser]::ParseFile((Join-Path $root 'scripts/booking-reset-dry-run.ps1'),[ref]$tokens,[ref]$errors)|Out-Null
if($errors.Count){throw "Dry-run syntax failed: $($errors[0].Message)"}
if($source -match '(?im)^\s*(INSERT\s+INTO|UPDATE\s+\w+\s+SET|DELETE\s+FROM|TRUNCATE\s+TABLE|ALTER\s+TABLE|DROP\s+TABLE)'){throw 'Dry-run contains a mutating SQL statement.'}
foreach($required in @('up_van_booking_test','77218fbd-d66d-11f1-9a0a-5c2886f057d5','VIEW_TABLE_USAGE','booking_requests WHERE deleted_at IS NULL','vehicle_assignments GROUP BY booking_request_id,status','approval_decisions','booking_notifications','booking_status_history','SHOW GRANTS FOR','RESET_DRY_RUN=PASS','APPLY=NOT_IMPLEMENTED')){if($source -notmatch [regex]::Escape($required)){throw "Dry-run missing required read-only guard: $required"}}
if($source -notmatch '\$t\[0\] -ne ''8'' -or \$t\[1\] -ne ''6'' -or \$t\[2\] -ne ''2'''){throw 'Expected request count guard is missing.'}
if($source -notmatch "\('active','completed'\)"){throw 'Assignment blocker guard missing.'}
Write-Output 'PASS: reset dry-run is syntax-valid, read-only, identity/backup/count guarded, and reports preserved records and runtime grants.'
