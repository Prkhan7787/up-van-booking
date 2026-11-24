$ErrorActionPreference='Stop'
$root=Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
$script=Join-Path $root 'scripts/migration-preflight-credential.ps1'
$tokens=$null;$errors=$null
[System.Management.Automation.Language.Parser]::ParseFile($script,[ref]$tokens,[ref]$errors)|Out-Null
if($errors.Count -ne 0){throw 'Migration preflight credential wrapper PowerShell syntax failed'}
$source=Get-Content -LiteralPath $script -Raw
$scriptBytes=[IO.File]::ReadAllBytes($script)
$hasUtf8Bom=$scriptBytes.Length -ge 3 -and $scriptBytes[0] -eq 0xEF -and $scriptBytes[1] -eq 0xBB -and $scriptBytes[2] -eq 0xBF
$checks=[ordered]@{
    'Wrapper is UTF-8 BOM encoded for Windows PowerShell 5.1 Thai paths'=$hasUtf8Bom
    'Only Store/Preflight/Postflight actions are exposed'=$source.Contains("[ValidateSet('Store','Preflight','Postflight')]")
    'Runtime path is explicit, absolute, canonicalized and pinned to the UP Van Booking project runtime'=$source.Contains("PhpRuntimePath='C:\จองรถตู้\up-van-booking\.runtime\php84\php.exe'") -and $source.Contains('[IO.Path]::IsPathRooted($PhpRuntimePath)') -and $source.Contains('Resolve-Path -LiteralPath $PhpRuntimePath') -and $source.Contains('PHP_RUNTIME_PATH_REJECTED') -and !$source.Contains('Get-Command php') -and !$source.Contains('$env:PATH')
    'PHP 8.4 and all required extensions are verified before DPAPI decryption'=$source.IndexOf('PHP_RUNTIME_VERSION_REJECTED') -lt $source.IndexOf('PREFLIGHT_CREDENTIAL_DECRYPT_FAILED') -and $source.Contains('PHP_RUNTIME_EXTENSION_MISSING') -and $source.Contains('pdo_mysql') -and $source.Contains('mbstring') -and $source.Contains('openssl')
    'PHP runtime comes from the primary project while runner comes from this worktree'=$source.Contains('$expectedPhp=Join-Path $approvedProjectRoot ''.runtime\php84\php.exe''') -and $source.Contains('$runner=Join-Path $worktreeRoot ''scripts\driver-auth-migration-preflight.php''')
    'Credential input uses a hidden secure prompt'=$source.Contains('Read-Host -Prompt') -and $source.Contains('-AsSecureString')
    'DPAPI CurrentUser storage uses existing PowerShell protection'=$source.Contains('ConvertFrom-SecureString') -and $source.Contains('ConvertTo-SecureString -String $protected')
    'Credential file is outside the repository'=$source.Contains("Join-Path `$env:LOCALAPPDATA 'UPVanBooking'")
    'Overwriting an existing credential is refused'=$source.Contains('PREFLIGHT_CREDENTIAL_ALREADY_STORED')
    'Only the read-only account and test identity enter child environment'=$source.Contains("MIGRATION_PREFLIGHT_DB_DATABASE']='up_van_booking_test'") -and $source.Contains("MIGRATION_PREFLIGHT_DB_USERNAME']='up_van_migration_preflight'") -and $source.Contains("MIGRATION_PREFLIGHT_DB_PORT']='3306'")
    'Password is passed only through child process environment'=$source.Contains("EnvironmentVariables['MIGRATION_PREFLIGHT_DB_PASSWORD']=$password") -and !$source.Contains('MIGRATION_PREFLIGHT_DB_PASSWORD=')
    'Child process receives only the requested read-only phase'=$source.Contains("`$phase='--'+`$Action.ToLowerInvariant()") -and !$source.Contains('--apply')
    'No environment Gates are changed'=-not ($source -match 'TEST_DB_EXECUTION_APPROVED\s*=')
    'Output redacts the credential before writing'=$source.Contains('$stdout=$stdout.Replace($password,''[REDACTED]'')') -and $source.Contains('$stderr=$stderr.Replace($password,''[REDACTED]'')')
}
foreach($check in $checks.GetEnumerator()){if(!$check.Value){throw "Migration preflight credential wrapper check failed: $($check.Key)"}}
Write-Output "PASS: $($checks.Count) migration preflight credential wrapper static checks; no credential or database was used."
