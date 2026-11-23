[CmdletBinding()]
param(
    [ValidateSet('Localhost', 'IIS')]
    [string]$Mode = 'Localhost',
    [switch]$Execute,
    [switch]$PreflightOnly,
    [string]$ConfirmTarget,
    [string]$IisAppPoolName
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
if ($Execute -and $PreflightOnly) {
    throw '-Execute and -PreflightOnly cannot be used together.'
}

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$envPath = Join-Path $projectRoot '.env'
$phpLauncher = Join-Path $projectRoot 'scripts\php.ps1'
. (Join-Path $PSScriptRoot 'lib\env-file-security.ps1')
. (Join-Path $PSScriptRoot 'lib\git-env-preflight.ps1')
. (Join-Path $PSScriptRoot 'lib\provision-php-runtime.ps1')

if (-not $Execute -and -not $PreflightOnly) {
    Write-Output "PREPARATION ONLY ($Mode). No MySQL connection or file change was made."
    if ($Mode -eq 'Localhost') {
        Write-Output 'Read-only preflight: .\scripts\provision-test-auth-accounts.ps1 -Mode Localhost -PreflightOnly'
        Write-Output 'To execute after approval: .\scripts\provision-test-auth-accounts.ps1 -Mode Localhost -Execute -ConfirmTarget up_van_booking_test'
    } else {
        Write-Output "Read-only preflight: .\scripts\provision-test-auth-accounts.ps1 -Mode IIS -PreflightOnly -IisAppPoolName <pool-name>"
        Write-Output "To execute after approval: .\scripts\provision-test-auth-accounts.ps1 -Mode IIS -Execute -ConfirmTarget up_van_booking_test -IisAppPoolName <pool-name>"
    }
    exit 0
}
if ($Execute -and $ConfirmTarget -cne 'up_van_booking_test') { throw 'Explicit test database confirmation is required.' }
if ($Mode -eq 'IIS' -and ([string]::IsNullOrWhiteSpace($IisAppPoolName) -or $IisAppPoolName -notmatch '^[A-Za-z0-9_.-]{1,64}$')) {
    throw 'Provide the exact IIS application pool name so .env access can be limited to that pool.'
}
if ($Mode -eq 'Localhost' -and -not [string]::IsNullOrWhiteSpace($IisAppPoolName)) {
    throw 'Do not pass an IIS application pool name in Localhost mode.'
}
if (-not (Test-Path -LiteralPath $envPath -PathType Leaf)) { throw 'Project .env file is required; no file was created.' }
if (-not (Test-Path -LiteralPath $phpLauncher -PathType Leaf)) { throw 'Project PHP launcher is unavailable.' }
if ((Get-Item -LiteralPath $envPath).Attributes -band [IO.FileAttributes]::ReparsePoint) { throw '.env reparse points are not accepted.' }

$ignoreText = Get-Content -LiteralPath (Join-Path $projectRoot '.gitignore') -Raw
if ($ignoreText -notmatch '(?m)^\.env\s*$') { throw '.gitignore does not explicitly ignore .env.' }
 $gitStatus = Get-UpVanEnvGitTrackingStatus -ProjectRoot $projectRoot -GitPath ''
switch ($gitStatus.Status) {
    'tracked' { throw '.env is tracked by Git; refusing to write credentials.' }
    'untracked' { Write-Output 'Git tracking: .env is not tracked in the detected repository.' }
    'not_verifiable_no_repository' { Write-Output 'Git tracking: NOT VERIFIABLE; project is not inside a Git repository. The .gitignore rule is present.' }
    'not_verifiable_git_unavailable' { Write-Output 'Git tracking: NOT VERIFIABLE; Git is unavailable and no repository marker was found. The .gitignore rule is present.' }
    default { throw 'Git tracking status is unknown; refusing credential-file changes.' }
}

Assert-UpVanEnvAcl -Path $envPath -Mode $Mode -PoolName $IisAppPoolName -PoolSidOverride $null
$originalEnvSddl = Get-UpVanAclFingerprint -Path $envPath
$originalEnv = $null
$rootSecure = $null
$rootBstr = [IntPtr]::Zero
$rootPassword = $null
$authPassword = $null
$provisionerPassword = $null
$authBytes = $null
$provisionerBytes = $null
$rng = $null
$envWasChanged = $false
$databaseProvisioned = $false
$locationPushed = $false

function Set-EnvAccountConfig([string]$Text, [string]$AuthValue, [string]$ProvisionerValue) {
    $values = [ordered]@{
        AUTH_TEST_DB_DATABASE = 'up_van_booking_test'
        AUTH_TEST_DB_USERNAME = 'up_van_auth_test'
        AUTH_TEST_DB_PASSWORD = $AuthValue
        TEST_PROVISIONER_DB_DATABASE = 'up_van_booking_test'
        TEST_PROVISIONER_DB_USERNAME = 'up_van_test_provisioner'
        TEST_PROVISIONER_DB_PASSWORD = $ProvisionerValue
    }
    foreach ($key in $values.Keys) {
        $count = [regex]::Matches($Text, "(?m)^$key=").Count
        if ($count -gt 1) { throw "Duplicate $key configuration; refusing to edit .env." }
        $expected = [string]$values[$key]
        $existing = [regex]::Match($Text, "(?m)^$key=(.*)$")
        if ($count -eq 1 -and $key -notlike '*PASSWORD' -and $existing.Groups[1].Value -cne $expected) {
            throw "Unexpected $key value; refusing to retarget credentials."
        }
        $line = "$key=$expected"
        if ($count -eq 1) { $Text = ([regex]::new("(?m)^$key=.*$")).Replace($Text, [System.Text.RegularExpressions.MatchEvaluator]{ param($m) $line }, 1) }
        else { $Text = $Text.TrimEnd("`r", "`n") + "`r`n" + $line + "`r`n" }
    }
    return $Text
}

$phpRuntime = Assert-UpVanProvisioningPhpRuntime -ProjectRoot $projectRoot
Write-Output "PHP Runtime: PHP $($phpRuntime.Version); PDO MySQL and mbstring loaded."

try {
    Push-Location -LiteralPath $projectRoot
    $locationPushed = $true
    $rootSecure = Read-Host 'MySQL root password (hidden prompt)' -AsSecureString
    $rootBstr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($rootSecure)
    $rootPassword = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($rootBstr)
    [Environment]::SetEnvironmentVariable('UP_VAN_PROVISION_ROOT_PASSWORD', $rootPassword, 'Process')

    $preflightResult = Invoke-UpVanProvisionPhp -ProjectRoot $projectRoot -Purpose preflight
    if ($preflightResult.ExitCode -ne 0) {
        Write-Output (Format-UpVanProvisionPreflightFailure -Output $preflightResult.Output -ExitCode $preflightResult.ExitCode)
        throw 'Read-only Preflight failed; no credentials or accounts were changed.'
    }

    if ($PreflightOnly) {
        Write-Output 'Read-only Preflight: PASS. No account creation or .env update was attempted.'
    } else {
        $originalEnv = [IO.File]::ReadAllText($envPath)
        $rng = [Security.Cryptography.RandomNumberGenerator]::Create()
        $authBytes = New-Object byte[] 32
        $provisionerBytes = New-Object byte[] 32
        $rng.GetBytes($authBytes)
        $rng.GetBytes($provisionerBytes)
        $authPassword = [BitConverter]::ToString($authBytes).Replace('-', '').ToLowerInvariant()
        $provisionerPassword = [BitConverter]::ToString($provisionerBytes).Replace('-', '').ToLowerInvariant()
        if ($authPassword.Length -ne 64 -or $provisionerPassword.Length -ne 64) { throw 'Secure password generation failed.' }

        [Environment]::SetEnvironmentVariable('AUTH_TEST_DB_PASSWORD', $authPassword, 'Process')
        [Environment]::SetEnvironmentVariable('TEST_PROVISIONER_DB_PASSWORD', $provisionerPassword, 'Process')
        $updatedEnv = Set-EnvAccountConfig -Text $originalEnv -AuthValue $authPassword -ProvisionerValue $provisionerPassword
        Write-UpVanAtomicEnvFile -Path $envPath -Text $updatedEnv -Mode $Mode -PoolName $IisAppPoolName -ExpectedOriginalSddl $originalEnvSddl
        $envWasChanged = $true

        $provisionExit = Invoke-UpVanProvisionPhp -ProjectRoot $projectRoot -Purpose execute
        if ($provisionExit -ne 0) {
            Write-UpVanAtomicEnvFile -Path $envPath -Text $originalEnv -Mode $Mode -PoolName $IisAppPoolName -ExpectedOriginalSddl $originalEnvSddl
            if ((Get-UpVanAclFingerprint -Path $envPath) -cne $originalEnvSddl) { throw 'Original .env ACL restoration could not be verified.' }
            $envWasChanged = $false
            throw 'Provisioning did not complete. Environment values were restored; inspect account state before retry.'
        }
        $databaseProvisioned = $true
        Write-Output 'Accounts created and Grants verified for up_van_booking_test. Passwords were stored in the ACL-restricted .env and were not displayed.'
    }
} catch {
    if ($envWasChanged -and -not $databaseProvisioned) {
        try {
            Write-UpVanAtomicEnvFile -Path $envPath -Text $originalEnv -Mode $Mode -PoolName $IisAppPoolName -ExpectedOriginalSddl $originalEnvSddl
            if ((Get-UpVanAclFingerprint -Path $envPath) -cne $originalEnvSddl) { throw 'Original .env ACL restoration could not be verified.' }
            $envWasChanged = $false
        }
        catch { Write-Error 'Credential file restoration failed. Stop and secure .env before continuing.' }
    }
    throw $_.Exception.Message
} finally {
    foreach ($name in @('UP_VAN_PROVISION_ROOT_PASSWORD', 'AUTH_TEST_DB_PASSWORD', 'TEST_PROVISIONER_DB_PASSWORD')) {
        [Environment]::SetEnvironmentVariable($name, $null, 'Process')
    }
    if ($null -ne $authBytes) { [Array]::Clear($authBytes, 0, $authBytes.Length) }
    if ($null -ne $provisionerBytes) { [Array]::Clear($provisionerBytes, 0, $provisionerBytes.Length) }
    if ($null -ne $rng) { $rng.Dispose() }
    if ($null -ne $rootSecure) { $rootSecure.Dispose() }
    if ($rootBstr -ne [IntPtr]::Zero) { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($rootBstr) }
    $rootPassword = $authPassword = $provisionerPassword = $updatedEnv = $originalEnv = $null
    if ($locationPushed) { Pop-Location }
}
