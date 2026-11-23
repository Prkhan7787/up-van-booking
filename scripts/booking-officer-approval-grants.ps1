[CmdletBinding()]
param(
    [ValidateSet('Preflight','Apply','Postflight')][string]$Phase='Preflight',
    [string]$GrantLoginPath='',
    [string]$ApprovedSha256=''
)
$ErrorActionPreference='Stop'
$pin='371a9c54d2e798e80214b61e062db8f1b7b92d653eb5aaa7176b702ad1a58e30'
$file=Join-Path $PSScriptRoot 'booking-officer-approval-grant-delta.proposed.sql'
$bytes=[IO.File]::ReadAllBytes($file)
$sha=[Security.Cryptography.SHA256]::Create()
try { $hash=[BitConverter]::ToString($sha.ComputeHash($bytes)).Replace('-','').ToLowerInvariant() } finally { $sha.Dispose() }
if ($hash -cne $pin) {throw 'Proposal SHA-256 mismatch'}
$php=Join-Path $PSScriptRoot 'php.ps1'
function Test-Capabilities([bool]$After) {
    $json=& $php bin/booking-capabilities-readonly.php
    if ($LASTEXITCODE -ne 0) { throw "Read-only capability check failed: $json" }
    $r=$json | ConvertFrom-Json
    foreach ($n in 'submission','officer_read','calendar','admin_users','vehicle_management','officer_edit','forward','section3_draft','decision') {
        $x=$r.capabilities.$n
        if ($x.account_guard.status -ne 'PASS' -or $x.unexpected_privileges.Count -ne 0) {throw "Account Guard failed for $n"}
        if (($After -or $n -in 'submission','officer_read','calendar','admin_users','vehicle_management') -and $x.status -ne 'PASS') {throw "Capability failed: $n"}
        Write-Host "$n $($x.status) / missing $($x.missing_privileges.Count)"
    }
    if (!$After) {
        $model=& $php bin/booking-capabilities-readonly.php --model-approval-grant-delta
        if ($LASTEXITCODE -ne 0) {throw "Grant model failed: $model"}
        $m=$model | ConvertFrom-Json
        if ($m.status -ne 'PASS' -or $m.modeled_columns -ne 43) {throw 'Grant model mismatch'}
    }
}
if ($Phase -eq 'Postflight') {Test-Capabilities $true; return}
Test-Capabilities $false
Write-Host "Preflight PASS / 43 / SHA-256 $hash"
if ($Phase -eq 'Preflight') {return}
if ($ApprovedSha256 -ne $pin -or $GrantLoginPath -notmatch '^[a-zA-Z0-9_-]+$') {throw 'Apply requires the separately approved SHA-256 and a local grant administrator login path'}
$mysql=Join-Path $env:ProgramFiles 'MySQL/MySQL Server 8.4/bin/mysql.exe'
$args3306=@('--no-defaults',"--login-path=$GrantLoginPath",'--protocol=TCP','--host=127.0.0.1','--port=3306','--database=up_van_booking_test','--skip-reconnect','--batch')
$guard="SET @check_target=IF(@@port=3306 AND @@server_uuid='77218fbd-d66d-11f1-9a0a-5c2886f057d5' AND DATABASE()='up_van_booking_test','DO 0','INVALID TARGET'); PREPARE target_check FROM @check_target; EXECUTE target_check; DEALLOCATE PREPARE target_check;"
$sql=$guard+[Environment]::NewLine+[Text.Encoding]::UTF8.GetString($bytes)
& $mysql @args3306 "--execute=$sql"
if ($LASTEXITCODE -ne 0) {throw 'GRANT stopped. Inspect actual grants for partial application before any further action.'}
Test-Capabilities $true
Write-Host 'Postflight PASS'
