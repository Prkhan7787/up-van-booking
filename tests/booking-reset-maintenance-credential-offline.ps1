$ErrorActionPreference='Stop'
$root=Split-Path -Parent $PSScriptRoot
$helper=Join-Path $root 'scripts/booking-reset-maintenance-credential.ps1'
$wrapper=Join-Path $root 'scripts/booking-reset-maintenance-account-preflight.ps1'
foreach($file in @($helper,$wrapper)){
    $tokens=$null;$errors=$null
    [System.Management.Automation.Language.Parser]::ParseFile($file,[ref]$tokens,[ref]$errors)|Out-Null
    if($errors.Count -ne 0){throw "PowerShell parser rejected $file"}
}
. $helper
$testSecret='offline-dpapi-test-only'
$secure=ConvertTo-SecureString -String $testSecret -AsPlainText -Force
$cipher=$secure | ConvertFrom-SecureString
$tempPath=[IO.Path]::GetTempFileName()
try {
    [IO.File]::WriteAllText($tempPath,$cipher,[Text.Encoding]::ASCII)
    $actual=Read-MaintenanceDpapiCredential -Path $tempPath
    if($actual -cne $testSecret){throw 'Current-user DPAPI round trip failed'}
} finally {Remove-Item -LiteralPath $tempPath -ErrorAction SilentlyContinue}
$missingRejected=$false
try {$null=Read-MaintenanceDpapiCredential -Path ($tempPath+'.missing')}catch {$missingRejected=$_.Exception.Message -eq 'MAINTENANCE_DPAPI_FILE_MISSING'}
if(!$missingRejected){throw 'Missing DPAPI file was accepted'}
$source=Get-Content -LiteralPath $wrapper -Raw
if(!$source.Contains("UPVAN_MAINTENANCE_PREFLIGHT_WRAPPER='1'") -or !$source.Contains('RESET_MAINTENANCE_DB_PASSWORD=$password') -or !$source.Contains('$psi.EnvironmentVariables[$key]') -or !$source.Contains("$([char]36)psi.EnvironmentVariables['RESET_MAINTENANCE_DB_PASSWORD']=''")){throw 'Dedicated child credential environment or cleanup missing'}
if($source -match '(?m)^\s*(?:Write-Host|Write-Output)\s+.*(?:password|secret|cipher)'){throw 'Wrapper may print credentials'}
Write-Output 'PASS: maintenance DPAPI wrapper syntax and offline credential round trip; no MySQL connection made.'
