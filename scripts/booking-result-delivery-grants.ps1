[CmdletBinding()]
param(
    [ValidateSet('Preflight','Apply','Postflight')][string]$Phase='Preflight',
    [string]$GrantLoginPath='',
    [string]$ApprovedSha256=''
)
$ErrorActionPreference='Stop'

# Controlled runner for the reviewed result-delivery delta. This script never
# enables an application gate and never creates or changes booking data.
$pin='86ea381b1b4a0593b2406052c74e0e52cc517ae2dcfc6056f826650877f1a23f'
$file=Join-Path $PSScriptRoot 'booking-result-delivery-grant-delta.proposed.sql'
$bytes=[IO.File]::ReadAllBytes($file)
$sha=[Security.Cryptography.SHA256]::Create()
try { $hash=[BitConverter]::ToString($sha.ComputeHash($bytes)).Replace('-','').ToLowerInvariant() } finally { $sha.Dispose() }
if ($hash -cne $pin) { throw 'Proposal SHA-256 mismatch' }

$php=Join-Path $PSScriptRoot 'php.ps1'
function Test-Capabilities([bool]$After) {
    $json=& $php bin/booking-capabilities-readonly.php
    if ($LASTEXITCODE -ne 0) { throw "Read-only capability check failed: $json" }
    $r=$json | ConvertFrom-Json
    foreach ($n in 'submission','officer_read','calendar','admin_users','vehicle_management','officer_edit','forward','section3_draft','decision') {
        $x=$r.capabilities.$n
        if ($x.account_guard.status -ne 'PASS' -or $x.unexpected_privileges.Count -ne 0) { throw "Account Guard failed for $n" }
        if ($x.status -ne 'PASS') { throw "Capability failed: $n" }
        Write-Host "$n $($x.status) / missing $($x.missing_privileges.Count)"
    }
    $notify=$r.capabilities.notify
    if ($After -and ($notify.status -ne 'PASS' -or $notify.missing_privileges.Count -ne 0)) { throw 'Notify capability failed after GRANT' }
    if (!$After) {
        $model=& $php bin/booking-capabilities-readonly.php --model-result-delivery-grant-delta
        if ($LASTEXITCODE -ne 0) { throw "Grant model failed: $model" }
        $m=$model | ConvertFrom-Json
        if ($m.status -ne 'PASS' -or $m.modeled_columns -ne 10) { throw 'Grant model mismatch' }
        if ($notify.unexpected_privileges.Count -ne 0 -or $notify.missing_privileges.Count -ne 10) { throw 'Expected exactly 10 notify privileges before GRANT' }
        $notify.missing_privileges | ForEach-Object { Write-Host "notify missing $_" }
    }
}

if ($Phase -eq 'Postflight') { Test-Capabilities $true; Write-Host 'Postflight PASS'; exit 0 }
Test-Capabilities $false
Write-Host "Preflight PASS / 10 / SHA-256 $hash"
if ($Phase -eq 'Preflight') { exit 0 }

if ($ApprovedSha256.ToLowerInvariant() -ne $pin -or $GrantLoginPath -notmatch '^[a-zA-Z0-9_-]+$') {
    throw 'Apply requires the separately approved SHA-256 and a local grant administrator login path'
}
$mysql=Join-Path $env:ProgramFiles 'MySQL/MySQL Server 8.4/bin/mysql.exe'
$args3306=@('--no-defaults',"--login-path=$GrantLoginPath",'--protocol=TCP','--host=127.0.0.1','--port=3306','--database=up_van_booking_test','--skip-reconnect','--batch')
$guard="SET @check_target=IF(@@port=3306 AND @@server_uuid='77218fbd-d66d-11f1-9a0a-5c2886f057d5' AND DATABASE()='up_van_booking_test','DO 0','INVALID TARGET'); PREPARE target_check FROM @check_target; EXECUTE target_check; DEALLOCATE PREPARE target_check;"
& $mysql @args3306 "--execute=$guard$([Environment]::NewLine)$([Text.Encoding]::UTF8.GetString($bytes))"
if ($LASTEXITCODE -ne 0) { throw 'GRANT stopped. Inspect actual grants for partial application before any further action.' }
Test-Capabilities $true
Write-Host 'Postflight PASS'
