$ErrorActionPreference='Stop'
$root=Split-Path -Parent $PSScriptRoot
$wrapper=Join-Path $root 'scripts/booking-reset-maintenance-run.ps1'
$runner=Join-Path $root 'scripts/booking-reset-runner.php'
$tokens=$null;$errors=$null
[System.Management.Automation.Language.Parser]::ParseFile($wrapper,[ref]$tokens,[ref]$errors)|Out-Null
if($errors.Count -ne 0){throw 'PowerShell parser rejected maintenance runner wrapper'}
$source=Get-Content -LiteralPath $wrapper -Raw
$reset=Get-Content -LiteralPath $runner -Raw
$diagnosticLine=@(Get-Content -LiteralPath $wrapper | Where-Object { $_.StartsWith('    $safeError=[regex]::Match(') })
if($diagnosticLine.Count -ne 1 -or $diagnosticLine[0] -notmatch "\[regex\]::Match\(\`$stderr,'([^']+)'\)"){throw 'Safe diagnostic pattern is unavailable'}
$safeDiagnosticPattern=$Matches[1]
foreach($fixture in @('RESET=FAIL step=rehearsal_update sqlstate=HY093 mysql_code=UNKNOWN','RESET=FAIL step=request_table_lock sqlstate=42000 mysql_code=1142','RESET=FAIL step=rehearsal_audit reason=AUDIT_ROW_COUNT_ID_29')){
    if(![regex]::IsMatch($fixture,$safeDiagnosticPattern)){throw 'Expected safe runner diagnostic is hidden'}
}
if([regex]::IsMatch('RESET=FAIL step=rehearsal_update reason=Password=secret',$safeDiagnosticPattern)){throw 'Unsafe diagnostic was accepted'}
if(!$source.Contains('RESET_DIAGNOSTIC_UNAVAILABLE')){throw 'Missing safe fallback for unclassified child failures'}
foreach($marker in @("[ValidateSet('Preflight','Rehearsal','Apply')]",'ACTOR_ID_11_REQUIRED','EXPLICIT_APPLY_SCOPE_REQUIRED','Read-MaintenanceDpapiCredential','RESET_MAINTENANCE_DB_PASSWORD','RESET_MAINTENANCE_DB_PORT',"$([char]36)psi.EnvironmentVariables['RESET_MAINTENANCE_DB_PASSWORD']=''",'--actor-id=11','RESET_RUNNER_FAILED','RESET_APPLY_POSTFLIGHT_MISSING')){
    if(!$source.Contains($marker)){throw "Missing controlled rehearsal wrapper guard: $marker"}
}
if($source -match '(?i)\b(?:CREATE USER|GRANT|TRUNCATE)\b' -or $source -match '(?m)^\s*(?:Write-Host|Write-Output)\s+.*(?:password|secret|cipher)'){throw 'Wrapper contains an unrelated write or credential output'}
if(!$reset.Contains("'preflight','rehearsal','apply'") -or ([regex]::Matches($reset,[regex]::Escape('$db->commit()'))).Count -ne 1){throw 'Reset runner has an unreviewed mode or commit path'}
if($source.IndexOf('EXPLICIT_APPLY_SCOPE_REQUIRED') -gt $source.IndexOf('Read-MaintenanceDpapiCredential')){throw 'Apply scope must be checked before DPAPI credential access'}
if(!$source.Contains('if($Phase -eq ''Apply''){$psi.Arguments += " --approved-scope=$expectedScope"}')){throw 'Wrapper must pass explicit scope only for Apply'}
foreach($marker in @('BookingWriteWindow::acquireMaintenance()','resetMaintenanceWindow();','SELECT GET_LOCK(:name,0)','SELECT RELEASE_LOCK(:name)','FOR UPDATE','FOR SHARE','$db->rollBack()','resetBackupCheck()','resetCheckSnapshot($rows)','POST_ROLLBACK_STATE_MISMATCH')){
    if(!$reset.Contains($marker)){throw "Missing reset guard: $marker"}
}
$lock=$reset.IndexOf('SELECT GET_LOCK(:name,0)')
$release=$reset.IndexOf('SELECT RELEASE_LOCK(:name)')
$pass=$reset.IndexOf('echo "REHEARSAL=PASS')
if($lock -lt 0 -or $release -le $lock -or $pass -le $release){throw 'Named lock must be released before reporting rehearsal success'}
if($reset.IndexOf('echo ''POSTFLIGHT=PASS') -le $release){throw 'Apply success must follow named lock release'}
Write-Output 'PASS: DPAPI reset wrapper, explicit Apply scope, lock release and rollback source; no MySQL connection made.'
