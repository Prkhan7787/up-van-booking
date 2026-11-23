$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot '..\scripts\lib\provision-php-runtime.ps1')
$root = Join-Path $env:TEMP ('up-van-preflight-propagation-' + [Guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path (Join-Path $root 'scripts') -Force | Out-Null
$launcher = Join-Path $root 'scripts/php.ps1'
$checks = 0
function Assert-Check([bool]$Condition, [string]$Name) {
    if (-not $Condition) { throw "Preflight propagation regression failed: $Name" }
    $script:checks++
    Write-Output "PASS: $Name"
}

try {
    @'
param([string]$ScriptName, [string]$Target)
Write-Output '{"status":"FAIL","step":"PDO_CONNECT","code":"PDO_ERROR","sqlstate":"HY000","native_code":2002}'
exit 1
'@ | Set-Content -LiteralPath $launcher -Encoding UTF8
    $pdo = Invoke-UpVanProvisionPhp -ProjectRoot $root -Purpose preflight
    $pdoDiagnostic = Format-UpVanProvisionPreflightFailure -Output $pdo.Output -ExitCode $pdo.ExitCode
    Assert-Check ($pdo.ExitCode -eq 1 -and $pdoDiagnostic -eq 'Read-only Preflight diagnostic: STEP=PDO_CONNECT CODE=PDO_ERROR SQLSTATE=HY000 NATIVE_CODE=2002') 'PDO_CONNECT diagnostic and error codes reach the outer PowerShell caller'

    @'
param([string]$ScriptName, [string]$Target)
Write-Output 'raw exception and synthetic-password-must-not-appear'
exit 7
'@ | Set-Content -LiteralPath $launcher -Encoding UTF8
    $processFailure = Invoke-UpVanProvisionPhp -ProjectRoot $root -Purpose preflight
    $safeFallback = Format-UpVanProvisionPreflightFailure -Output $processFailure.Output -ExitCode $processFailure.ExitCode
    Assert-Check ($processFailure.ExitCode -eq 7 -and $safeFallback -eq 'Read-only Preflight diagnostic: STEP=PHP_PROCESS CODE=NO_SAFE_DIAGNOSTIC EXIT_CODE=7' -and $safeFallback -notmatch 'raw exception|synthetic-password') 'PHP process failure preserves exit code and suppresses untrusted output'

    @'
param([string]$ScriptName, [string]$Target)
exit 9
'@ | Set-Content -LiteralPath $launcher -Encoding UTF8
    $executeExit = Invoke-UpVanProvisionPhp -ProjectRoot $root -Purpose execute
    Assert-Check ($executeExit -eq 9) 'execute child-process exit code is preserved for existing caller handling'

    Write-Output "PASS: $checks PowerShell child-process diagnostic propagation checks; fake launcher only, no database contacted."
} finally {
    Remove-Item -LiteralPath $root -Recurse -Force -ErrorAction SilentlyContinue
}
