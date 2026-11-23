[CmdletBinding()]
param(
    [ValidateSet('Preflight','GuardCheck','BatchCheck','Apply','Postflight')][string]$Phase='Preflight',
    [ValidatePattern('^[A-Za-z0-9_-]+$')][string]$GrantLoginPath,
    [ValidatePattern('^[A-Za-z0-9_]+@[A-Za-z0-9_.-]+$')][string]$ExpectedGrantAccount,
    [string]$ApprovedSha256=''
)
$ErrorActionPreference='Stop'
. (Join-Path $PSScriptRoot 'booking-reset-maintenance-grant-diagnostics.ps1')
. (Join-Path $PSScriptRoot 'booking-reset-maintenance-mysql-client.ps1')
if([string]::IsNullOrWhiteSpace($GrantLoginPath) -or [string]::IsNullOrWhiteSpace($ExpectedGrantAccount)){throw 'GRANT_LOGIN_PATH_AND_ACCOUNT_REQUIRED'}
$proposal=Join-Path $PSScriptRoot 'booking-reset-maintenance-grants.proposed.sql'
$expectedHash='ba6ac275cda37cb6d68efccc08313e75d009e0959676b1a959813fd99e75ec46'
$proposalBytes=[IO.File]::ReadAllBytes($proposal)
$hasher=[Security.Cryptography.SHA256]::Create()
try {$actualHash=[BitConverter]::ToString($hasher.ComputeHash($proposalBytes)).Replace('-','').ToLowerInvariant()}
finally {$hasher.Dispose()}
if($actualHash -cne $expectedHash){throw 'MAINTENANCE_PROPOSAL_SHA256_MISMATCH'}
$mysql=Join-Path $env:ProgramFiles 'MySQL/MySQL Server 8.4/bin/mysql.exe'
if(!(Test-Path -LiteralPath $mysql -PathType Leaf)){throw 'MYSQL_84_CLIENT_NOT_FOUND'}
$clientArgs=@('--no-defaults',"--login-path=$GrantLoginPath",'--protocol=TCP','--host=127.0.0.1','--port=3306','--database=up_van_booking_test','--skip-reconnect','--batch','--raw','--skip-column-names')

function Invoke-MysqlCaptured([string]$query,[switch]$ViaStdin) {
    return Invoke-MaintenanceMysqlCaptured -ClientPath $mysql -ClientArgs $clientArgs -Query $query -ViaStdin:$ViaStdin
}
function Assert-Identity {
    $result=Invoke-MysqlCaptured 'SELECT @@port,@@server_uuid,DATABASE(),CURRENT_USER(),VERSION(),@@generated_random_password_length'
    if($result.ExitCode -ne 0){throw "IDENTITY_QUERY_FAILED exit=$($result.ExitCode)"}
    $lines=@($result.Stdout.Trim() -split "`r?`n" | Where-Object {$_ -ne ''})
    if($lines.Count -ne 1){throw 'IDENTITY_ROW_COUNT_MISMATCH'}
    $fields=@($lines[0] -split "`t")
    if($fields.Count -ne 6 -or $fields[0] -cne '3306' -or $fields[1] -cne '77218fbd-d66d-11f1-9a0a-5c2886f057d5' -or $fields[2] -cne 'up_van_booking_test' -or $fields[3] -notmatch '^[A-Za-z0-9_]+@[A-Za-z0-9_.-]+$' -or $fields[3] -match '^(up_van_auth_test|up_van_reset_maintenance)@' -or $fields[4] -notmatch '^8\.4\.' -or $fields[5] -notmatch '^[0-9]+$' -or [int]$fields[5] -lt 12){throw 'TARGET_OR_GRANT_ACCOUNT_MISMATCH'}
    return $fields[3]
}
function Assert-Account([bool]$shouldExist) {
    $result=Invoke-MysqlCaptured "SELECT COUNT(*) FROM mysql.user WHERE User='up_van_reset_maintenance' AND Host='127.0.0.1'"
    if($result.ExitCode -ne 0){throw "ACCOUNT_GUARD_QUERY_FAILED exit=$($result.ExitCode)"}
    $count=$result.Stdout.Trim()
    if($count -cne $(if($shouldExist){'1'}else{'0'})){throw 'MAINTENANCE_ACCOUNT_STATE_MISMATCH'}
}
function Assert-Grants {
    $result=Invoke-MysqlCaptured "SHOW GRANTS FOR 'up_van_reset_maintenance'@'127.0.0.1'"
    if($result.ExitCode -ne 0){throw "SHOW_GRANTS_FAILED exit=$($result.ExitCode)"}
    $grants=@($result.Stdout.Trim() -split "`r?`n" | Where-Object {$_ -ne ''})
    $json=ConvertTo-Json -InputObject $grants -Compress -Depth 5
    $project=Split-Path -Parent $PSScriptRoot
    $php=Join-Path $project '.runtime/php84/php.exe'
    $evaluator=Join-Path $PSScriptRoot 'booking-reset-maintenance-grants-evaluate.php'
    $output=$json | & $php -n $evaluator
    if($LASTEXITCODE -ne 0 -or $output -cne 'MAINTENANCE_GRANTS=PASS'){throw 'MAINTENANCE_GRANTS_MISMATCH'}
}

$account=Assert-Identity
if($account -cne $ExpectedGrantAccount){throw 'GRANT_OPERATOR_ACCOUNT_MISMATCH'}
if($Phase -eq 'Postflight'){
    Assert-Account $true
    Assert-Grants
    Write-Host "POSTFLIGHT=PASS account=$account proposal_sha256=$actualHash"
    exit 0
}
Assert-Account $false
$credentialPath=Join-Path (Join-Path $env:LOCALAPPDATA 'UPVanBooking') 'reset-maintenance.dpapi'
if(Test-Path -LiteralPath $credentialPath){throw 'CREDENTIAL_FILE_ALREADY_EXISTS'}
Write-Host "PREFLIGHT=PASS account=$account proposal_sha256=$actualHash"
if($Phase -eq 'Preflight'){exit 0}

# The marker separates a successful same-session identity guard from CREATE USER.
# The generated password is captured in process memory, never written to stdout.
$guard="SET @target_guard=IF(@@port=3306 AND @@server_uuid='77218fbd-d66d-11f1-9a0a-5c2886f057d5' AND DATABASE()='up_van_booking_test' AND CURRENT_USER()='$account' AND @@generated_random_password_length>=12,'DO 0','INVALID TARGET'); PREPARE target_check FROM @target_guard; EXECUTE target_check; DEALLOCATE PREPARE target_check; SELECT 'UPVAN_GRANT_GUARD_OK'; "
if($Phase -eq 'GuardCheck'){
    $check=Invoke-MysqlCaptured $guard
    if($check.ExitCode -ne 0){throw "GUARD_CHECK_FAILED exit=$($check.ExitCode) $(Get-MaintenanceGrantMysqlError -Stderr $check.Stderr)"}
    if($check.Stdout.Trim() -cne 'UPVAN_GRANT_GUARD_OK'){throw 'GUARD_CHECK_RESULT_MISMATCH'}
    Write-Host "GUARD_CHECK=PASS account=$account proposal_sha256=$actualHash"
    exit 0
}
$sql=[Text.Encoding]::UTF8.GetString($proposalBytes)
if($sql.Length -gt 0 -and [int][char]$sql[0] -eq 0xFEFF){$sql=$sql.Substring(1)}
if($Phase -eq 'BatchCheck'){
    $header=Get-MaintenanceProposalCommentHeader -Proposal $sql
    $check=Invoke-MysqlCaptured (Join-MaintenanceGrantBatch -Guard $guard -Body ($header+"SELECT 'UPVAN_GRANT_BATCH_OK';")) -ViaStdin
    if($check.ExitCode -ne 0 -or $check.InputWriteFailed){throw "BATCH_CHECK_FAILED exit=$($check.ExitCode) input_write_failed=$($check.InputWriteFailed.ToString().ToLowerInvariant()) $(Get-MaintenanceGrantMysqlError -Stderr $check.Stderr)"}
    $checkLines=@($check.Stdout.Trim() -split "`r?`n" | Where-Object {$_ -ne ''})
    if($checkLines.Count -ne 2 -or $checkLines[0] -cne 'UPVAN_GRANT_GUARD_OK' -or $checkLines[1] -cne 'UPVAN_GRANT_BATCH_OK'){throw 'BATCH_CHECK_RESULT_MISMATCH'}
    Write-Host "BATCH_CHECK=PASS account=$account proposal_sha256=$actualHash transport=stdin"
    exit 0
}
if($ApprovedSha256.ToLowerInvariant() -cne $expectedHash){throw 'APPROVED_SHA256_REQUIRED'}
$result=Invoke-MysqlCaptured (Join-MaintenanceGrantBatch -Guard $guard -Body $sql) -ViaStdin
$outputState=Get-MaintenanceGrantApplyOutput -Stdout $result.Stdout -ExitCode $result.ExitCode -Stderr $result.Stderr
if($outputState.Created){
    try {
        $secret=ConvertTo-SecureString -String $outputState.Password -AsPlainText -Force
        $dir=Join-Path $env:LOCALAPPDATA 'UPVanBooking'
        if(!(Test-Path -LiteralPath $dir)){New-Item -ItemType Directory -Path $dir | Out-Null}
        $encrypted=$secret | ConvertFrom-SecureString
        $cipherBytes=[Text.Encoding]::ASCII.GetBytes($encrypted)
        $stream=[IO.File]::Open($credentialPath,[IO.FileMode]::CreateNew,[IO.FileAccess]::Write,[IO.FileShare]::None)
        try {$stream.Write($cipherBytes,0,$cipherBytes.Length)} finally {$stream.Dispose()}
    } catch {
        throw 'CREDENTIAL_STORAGE_FAILED step=dpapi state=partial_account_possible; inspect account and grants read-only, no retry'
    } finally {$outputState.Password=$null}
}
if($result.ExitCode -ne 0 -or $result.InputWriteFailed){throw (Get-MaintenanceGrantApplyFailure -ExitCode $result.ExitCode -Stderr $result.Stderr -GuardPassed $outputState.GuardPassed -Created $outputState.Created -InputWriteFailed $result.InputWriteFailed)}
if($outputState.LineCount -ne 2){throw 'GENERATED_PASSWORD_RESULT_UNVERIFIED step=apply_output state=partial_account_possible; inspect account read-only, no retry'}
Assert-Identity | Out-Null
Assert-Account $true
Assert-Grants
Write-Host "APPLY=PASS POSTFLIGHT=PASS proposal_sha256=$actualHash credential=DPAPI_CURRENT_USER"
