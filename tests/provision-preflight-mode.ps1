$ErrorActionPreference = 'Stop'
$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$sourcePath = Join-Path $projectRoot 'scripts\provision-test-auth-accounts.ps1'
$source = Get-Content -LiteralPath $sourcePath -Raw
$checks = 0
function Assert-Check([bool]$Condition, [string]$Name) {
    if (-not $Condition) { throw "PreflightOnly regression failed: $Name" }
    $script:checks++
    Write-Output "PASS: $Name"
}

$mutexIndex = $source.IndexOf('if ($Execute -and $PreflightOnly)')
$rootIndex = $source.IndexOf("Read-Host 'MySQL root password")
$preflightIndex = $source.IndexOf("-Purpose preflight")
$runtimeIndex = $source.IndexOf('Assert-UpVanProvisioningPhpRuntime -ProjectRoot $projectRoot')
$onlyIndex = $source.IndexOf('if ($PreflightOnly) {', $preflightIndex)
$onlyElseIndex = $source.IndexOf('} else {', $onlyIndex)
$executeIndex = $source.IndexOf("-Purpose execute")
$executeBlockStart = $source.LastIndexOf('} else {', $executeIndex)
$executeBlockEnd = $source.IndexOf("`n    }`n} catch", $executeIndex)
$executeBlock = if ($executeBlockStart -ge 0 -and $executeBlockEnd -gt $executeBlockStart) {
    $source.Substring($executeBlockStart, $executeBlockEnd - $executeBlockStart)
} else { '' }

Assert-Check ($mutexIndex -ge 0 -and $mutexIndex -lt $source.IndexOf('$projectRoot =')) 'Mutual exclusion is checked before project, credential, or database setup'
Assert-Check ($onlyIndex -gt $preflightIndex -and $onlyIndex -lt $executeIndex -and $executeBlock.Length -gt 0) 'PreflightOnly result branch is before the isolated execute branch'
Assert-Check (-not $executeBlock.Contains('PreflightOnly') -and $executeBlock.Contains('Invoke-UpVanProvisionPhp -ProjectRoot $projectRoot -Purpose execute')) 'Execute PHP invocation exists only in the non-PreflightOnly else branch'
Assert-Check ($onlyElseIndex -gt $onlyIndex -and -not $source.Substring($onlyIndex, $onlyElseIndex - $onlyIndex).Contains('RandomNumberGenerator') -and -not $source.Substring($onlyIndex, $onlyElseIndex - $onlyIndex).Contains('Write-UpVanAtomicEnvFile')) 'PreflightOnly branch does not generate credentials or write .env'
Assert-Check ($runtimeIndex -ge 0 -and $runtimeIndex -lt $rootIndex -and $rootIndex -lt $preflightIndex) 'Both modes validate PHP runtime, then use the hidden Root Password prompt before preflight'

# Exercise the mutual-exclusion guard. This must terminate before any prompt or preflight.
$savedErrorPreference = $ErrorActionPreference
$ErrorActionPreference = 'Continue'
$combinedOutput = & powershell.exe -NoProfile -ExecutionPolicy Bypass -File $sourcePath -Execute -PreflightOnly 2>&1 | Out-String
$combinedExit = $LASTEXITCODE
$ErrorActionPreference = $savedErrorPreference
Assert-Check ($combinedExit -ne 0 -and $combinedOutput.Contains('-Execute and -PreflightOnly cannot be used together.') -and -not $combinedOutput.Contains('MySQL root password')) 'Combined switches are rejected without prompting or invoking PHP'

Write-Output "PASS: $checks PreflightOnly isolation checks; no database was contacted."
