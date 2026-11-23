[CmdletBinding()]
param(
    [switch]$PreflightOnly,
    [switch]$DryRun,
    [switch]$Execute,
    [switch]$RootConnectionOnly,
    [string]$ConfirmTarget
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$selectedModes = @([bool]$PreflightOnly,[bool]$DryRun,[bool]$Execute,[bool]$RootConnectionOnly).Where({$_}).Count
if ($selectedModes -ne 1) { throw 'Choose exactly one of -PreflightOnly, -DryRun, -Execute, or -RootConnectionOnly.' }
if ($ConfirmTarget -cne 'up_van_booking_test') { throw 'Explicit test database confirmation is required.' }

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$envPath = Join-Path $projectRoot '.env'
$statePath = Join-Path $projectRoot '.env.auth-recovery-state.json'
$recoveryStageDir = Join-Path $projectRoot '.env.recovery-staging'
$stateTempPath = $statePath + '.tmp'
$phpLauncher = Join-Path $projectRoot 'scripts\php.ps1'
. (Join-Path $PSScriptRoot 'lib\env-file-security.ps1')
. (Join-Path $PSScriptRoot 'lib\recovery-staging.ps1')
. (Join-Path $PSScriptRoot 'lib\recovery-diagnostics.ps1')
. (Join-Path $PSScriptRoot 'lib\recovery-lock.ps1')
. (Join-Path $PSScriptRoot 'lib\git-env-preflight.ps1')
. (Join-Path $PSScriptRoot 'lib\provision-php-runtime.ps1')

function Get-EnvValue([string]$Text,[string]$Key) {
    $matches = [regex]::Matches($Text,"(?m)^[ \t]*$([regex]::Escape($Key))=([^\r\n]*)\r?$")
    if ($matches.Count -gt 1) { throw 'Duplicate recovery configuration; stop and inspect .env.' }
    if ($matches.Count -eq 0) { return $null }
    # Match PHP Env::load(): trim ASCII whitespace around the value, then
    # surrounding quote characters. Do not trim inside a quoted secret.
    return $matches[0].Groups[1].Value.Trim([char[]]@([char]32,[char]9,[char]10,[char]13,[char]0,[char]11)).Trim([char[]]"`"'")
}

function Get-TextSha256([string]$Text) {
    $sha = [Security.Cryptography.SHA256]::Create()
    try {
        $bytes = [Text.UTF8Encoding]::new($false).GetBytes($Text)
        try { return ([BitConverter]::ToString($sha.ComputeHash($bytes))).Replace('-','') }
        finally { [Array]::Clear($bytes,0,$bytes.Length) }
    } finally { $sha.Dispose() }
}

function Set-RecoveryEnv([string]$Text,[string]$AuthPassword,[string]$ProvisionerPassword) {
    $values = [ordered]@{
        AUTH_TEST_DB_DATABASE='up_van_booking_test'; AUTH_TEST_DB_USERNAME='up_van_auth_test'; AUTH_TEST_DB_PASSWORD=$AuthPassword
        TEST_PROVISIONER_DB_DATABASE='up_van_booking_test'; TEST_PROVISIONER_DB_USERNAME='up_van_test_provisioner'; TEST_PROVISIONER_DB_PASSWORD=$ProvisionerPassword
    }
    foreach ($key in $values.Keys) {
        $pattern = "(?m)^[ \t]*$([regex]::Escape($key))=[^\r\n]*(?=\r?$)"
        $count = [regex]::Matches($Text,$pattern).Count
        if ($count -gt 1) { throw 'Duplicate recovery configuration; stop and inspect .env.' }
        $expected = [string]$values[$key]
        if ($key -notlike '*PASSWORD') {
            $current = Get-EnvValue -Text $Text -Key $key
            if ($null -ne $current -and $current -cne $expected) { throw 'Recovery target configuration mismatch.' }
        }
        $line = "$key=$expected"
        if ($count -eq 1) { $Text = ([regex]::new($pattern)).Replace($Text,[System.Text.RegularExpressions.MatchEvaluator]{param($m)$line},1) }
        else { $Text = $Text.TrimEnd("`r","`n") + "`r`n" + $line + "`r`n" }
    }
    return $Text
}

function Set-RecoveryProcessConfig([string]$AuthPassword,[string]$ProvisionerPassword) {
    $values = @{
        AUTH_TEST_DB_HOST='127.0.0.1'; AUTH_TEST_DB_PORT='3306'; AUTH_TEST_DB_DATABASE='up_van_booking_test'; AUTH_TEST_DB_USERNAME='up_van_auth_test'; AUTH_TEST_DB_PASSWORD=$AuthPassword
        TEST_PROVISIONER_DB_HOST='127.0.0.1'; TEST_PROVISIONER_DB_PORT='3306'; TEST_PROVISIONER_DB_DATABASE='up_van_booking_test'; TEST_PROVISIONER_DB_USERNAME='up_van_test_provisioner'; TEST_PROVISIONER_DB_PASSWORD=$ProvisionerPassword
    }
    foreach ($pair in $values.GetEnumerator()) { [Environment]::SetEnvironmentVariable($pair.Key,$pair.Value,'Process') }
}

function ConvertTo-ProtectedPassword([string]$Value) {
    $secure = ConvertTo-SecureString -String $Value -AsPlainText -Force
    try { return ConvertFrom-SecureString -SecureString $secure }
    finally { $secure.Dispose() }
}

function ConvertFrom-ProtectedPassword([string]$Value) {
    $secure = ConvertTo-SecureString -String $Value
    $pointer = [IntPtr]::Zero
    try {
        $pointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure)
        return [Runtime.InteropServices.Marshal]::PtrToStringBSTR($pointer)
    } finally {
        if ($pointer -ne [IntPtr]::Zero) { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($pointer) }
        $secure.Dispose()
    }
}

function Assert-RecoveryPath([string]$Path) {
    if (-not (Test-Path -LiteralPath $Path -PathType Leaf)) { throw 'Recovery state file is unavailable.' }
    if ((Get-Item -LiteralPath $Path).Attributes -band [IO.FileAttributes]::ReparsePoint) { throw 'Recovery state reparse point rejected.' }
    Assert-UpVanEnvAcl -Path $Path -Mode Localhost -PoolName '' -PoolSidOverride $null
}

function Save-RecoveryState($State) {
    try {
    if (Test-Path -LiteralPath $stateTempPath) {
        if ((Get-Item -LiteralPath $stateTempPath).Attributes -band [IO.FileAttributes]::ReparsePoint) { throw 'Recovery staging reparse point rejected.' }
        Assert-UpVanEnvAcl -Path $stateTempPath -Mode Localhost -PoolName '' -PoolSidOverride $null
        Remove-Item -LiteralPath $stateTempPath -Force
    }
    New-Item -ItemType File -Path $stateTempPath -ErrorAction Stop | Out-Null
    Set-UpVanEnvAcl -Path $stateTempPath -Mode Localhost -PoolName '' -PoolSidOverride $null
    $serialized = $State | ConvertTo-Json -Compress
    [IO.File]::WriteAllText($stateTempPath,$serialized,[Text.UTF8Encoding]::new($false))
    Assert-UpVanEnvAcl -Path $stateTempPath -Mode Localhost -PoolName '' -PoolSidOverride $null
    if (Test-Path -LiteralPath $statePath) {
        Assert-RecoveryPath $statePath
        [IO.File]::Replace($stateTempPath,$statePath,[System.Management.Automation.Language.NullString]::Value,$false)
    } else {
        [IO.File]::Move($stateTempPath,$statePath)
    }
    Assert-RecoveryPath $statePath
    } catch { throw 'STATE_SAVE_FAILED' }
}

function Move-RecoveryState($State,[string]$Next) {
    $transitions = @{
        PREPARED=@('ENV_SYNCED'); ENV_SYNCED=@('AUTH_UPDATED'); AUTH_UPDATED=@('PROVISIONER_UPDATED')
        PROVISIONER_UPDATED=@('VERIFIED'); VERIFIED=@('COMPLETE'); COMPLETE=@()
    }
    if ($State.Status -notin $transitions.Keys -or $Next -notin $transitions[$State.Status]) { throw 'Recovery state transition rejected.' }
    $State.Status=$Next
    Save-RecoveryState $State
}

function Remove-OwnedEnvStaging([string]$TransactionId) {
    if (Test-Path -LiteralPath $recoveryStageDir -PathType Container) {
        [void](Remove-UpVanRecoveryStaging -Directory $recoveryStageDir -TransactionId $TransactionId)
    }
}

function Invoke-RecoveryPhp([string]$Action,[string]$StateName='') {
    $approval = if ($Action -like 'reconcile-*') { 'CONFIRM_UP_VAN_BOOKING_TEST_CREDENTIAL_RECOVERY' } else { '' }
    $output = & powershell.exe -NoProfile -ExecutionPolicy Bypass -File $phpLauncher 'scripts/recover-test-auth-credentials-execute.php' $Action 'up_van_booking_test' $StateName $approval 2>$null | Out-String
    $exitCode = $LASTEXITCODE
    return ConvertFrom-UpVanRecoveryDiagnostic -Output $output -ExitCode $exitCode
}

function Show-SafeResult($Result) {
    $fields = @("status=$($Result.Status)","step=$($Result.Step)","code=$($Result.Code)")
    if ($Result.Account -in @('up_van_auth_test','up_van_test_provisioner')) { $fields += "account=$($Result.Account)" }
    if ($Result.SqlState -match '^[A-Z0-9]{5}$') { $fields += "sqlstate=$($Result.SqlState)" }
    if ($null -ne $Result.NativeCode -and "$($Result.NativeCode)" -match '^\d{1,10}$') { $fields += "native_code=$($Result.NativeCode)" }
    Write-Output ($fields -join ' ')
}

if (-not (Test-Path -LiteralPath $envPath -PathType Leaf) -or -not (Test-Path -LiteralPath $phpLauncher -PathType Leaf)) { throw 'Required project configuration or PHP launcher is missing.' }
if ((Get-Item -LiteralPath $envPath).Attributes -band [IO.FileAttributes]::ReparsePoint) { throw '.env reparse points are not accepted.' }
Assert-UpVanEnvAcl -Path $envPath -Mode Localhost -PoolName '' -PoolSidOverride $null
if (-not (Get-Content -LiteralPath (Join-Path $projectRoot '.gitignore') -Raw).Contains('.env.*')) { throw 'Recovery state files are not explicitly ignored by Git.' }
$null = Assert-UpVanProvisioningPhpRuntime -ProjectRoot $projectRoot
if ($RootConnectionOnly) {
    # This branch exits before any recovery state, lock, staging or .env write.
    $previousRoot = [Environment]::GetEnvironmentVariable('UP_VAN_RECOVERY_ROOT_PASSWORD','Process')
    $rootSecure = $null; $rootPointer = [IntPtr]::Zero
    try {
        $rootSecure = Read-Host 'MySQL root password (hidden prompt)' -AsSecureString
        $rootPointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($rootSecure)
        [Environment]::SetEnvironmentVariable('UP_VAN_RECOVERY_ROOT_PASSWORD',[Runtime.InteropServices.Marshal]::PtrToStringBSTR($rootPointer),'Process')
        $result = Invoke-RecoveryPhp 'root-check'
        Show-SafeResult $result
        if ($result.ExitCode -ne 0 -or $result.Status -ne 'PASS') { exit 1 }
        exit 0
    } finally {
        [Environment]::SetEnvironmentVariable('UP_VAN_RECOVERY_ROOT_PASSWORD',$previousRoot,'Process')
        if ($rootPointer -ne [IntPtr]::Zero) { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($rootPointer) }
        if ($null -ne $rootSecure) { $rootSecure.Dispose() }
    }
}
$recoveryLock = Enter-UpVanRecoveryLock -Path (Join-Path $projectRoot '.env.auth-recovery.lock')
try {
$staleEnvStages = @()
if (Test-Path -LiteralPath $recoveryStageDir) {
    if (-not (Test-UpVanRecoveryStagingDirectory -Path $recoveryStageDir)) { throw 'Recovery staging directory ACL is unsafe.' }
    $staleEnvStages = @(Get-ChildItem -LiteralPath $recoveryStageDir -Filter '.env.secure-*' -File -ErrorAction SilentlyContinue)
}
$state = $null
if (Test-Path -LiteralPath $statePath) {
    Assert-RecoveryPath $statePath
    if ((Get-Item -LiteralPath $statePath).Length -gt 16384) { throw 'Recovery state file size is invalid.' }
    $state = Get-Content -LiteralPath $statePath -Raw | ConvertFrom-Json
    if ($state.Version -ne 1 -or $state.Target -cne 'up_van_booking_test' -or $state.AuthAccount -cne 'up_van_auth_test@127.0.0.1' -or $state.ProvisionerAccount -cne 'up_van_test_provisioner@127.0.0.1' -or $state.TransactionId -notmatch '^[a-f0-9]{32}$' -or $state.OriginalEnvSha256 -notmatch '^[A-F0-9]{64}$' -or $state.PreparedEnvSha256 -notmatch '^[A-F0-9]{64}$' -or [string]::IsNullOrWhiteSpace($state.ProtectedAuthPassword) -or [string]::IsNullOrWhiteSpace($state.ProtectedProvisionerPassword)) { throw 'Recovery state target validation failed.' }
    if ($state.Status -notin @('PREPARED','ENV_SYNCED','AUTH_UPDATED','PROVISIONER_UPDATED','VERIFIED','COMPLETE')) { throw 'Recovery state status is invalid; preserve it and inspect before retry.' }
} elseif (Test-Path -LiteralPath $stateTempPath) {
    throw 'Encrypted recovery state staging exists without a committed state; preserve it and inspect before retry.'
}
if ($Execute -and $null -ne $state -and (Test-Path -LiteralPath $stateTempPath)) {
    if ((Get-Item -LiteralPath $stateTempPath).Attributes -band [IO.FileAttributes]::ReparsePoint) { throw 'Recovery state staging reparse point rejected.' }
    Assert-UpVanEnvAcl -Path $stateTempPath -Mode Localhost -PoolName '' -PoolSidOverride $null
    Remove-Item -LiteralPath $stateTempPath -Force
}
if ($staleEnvStages.Count -gt 0 -and $null -eq $state) { throw 'Unowned .env staging files exist; stop and inspect them before recovery.' }
if ($null -ne $state) {
    $ownedPrefix = @('.env.secure-' + $state.TransactionId + '.tmp','.env.secure-backup-' + $state.TransactionId + '.tmp','.env.secure-recovery-' + $state.TransactionId + '.tmp')
    foreach ($file in $staleEnvStages) {
        if ($file.Name -notin $ownedPrefix) { throw 'Unowned .env staging files exist; stop and inspect them before recovery.' }
    }
}

if ($PreflightOnly -or $DryRun) {
    $rootSecure = Read-Host 'MySQL root password (hidden prompt)' -AsSecureString
    $rootPointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($rootSecure)
    try { [Environment]::SetEnvironmentVariable('UP_VAN_RECOVERY_ROOT_PASSWORD',[Runtime.InteropServices.Marshal]::PtrToStringBSTR($rootPointer),'Process') }
    finally { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($rootPointer); $rootSecure.Dispose() }
    try {
        $result = Invoke-RecoveryPhp 'preflight'
        Show-SafeResult $result
        if ($result.ExitCode -ne 0 -or $result.Status -ne 'PASS') { exit 1 }
        if ($DryRun) {
            $decision = if ($null -eq $state) { 'START_NEW_WITH_TWO_EXISTING_ACCOUNTS' } elseif ($state.Status -eq 'COMPLETE') { 'VERIFY_AND_STOP_NO_PASSWORD_CHANGE' } else { 'RESUME_STORED_CREDENTIAL_SET' }
            Write-Output "DRY_RUN plan=$decision target=up_van_booking_test changes=none"
        }
        exit 0
    } finally { [Environment]::SetEnvironmentVariable('UP_VAN_RECOVERY_ROOT_PASSWORD',$null,'Process') }
}

# Execute mode is deliberately separate and must be invoked explicitly with the exact target.
$lockPath = Join-Path $projectRoot '.env.auth-recovery.lock'
$lock = $null
$secretBytesAuth = $null
$secretBytesProvisioner = $null
$authPassword = $null
$provisionerPassword = $null
$rootPassword = $null
$rootSecure = $null
$rootPointer = [IntPtr]::Zero
$envChanged = $false
$managedEnvNames = @('UP_VAN_RECOVERY_ROOT_PASSWORD','AUTH_TEST_DB_PASSWORD','TEST_PROVISIONER_DB_PASSWORD','AUTH_TEST_DB_HOST','AUTH_TEST_DB_PORT','AUTH_TEST_DB_DATABASE','AUTH_TEST_DB_USERNAME','TEST_PROVISIONER_DB_HOST','TEST_PROVISIONER_DB_PORT','TEST_PROVISIONER_DB_DATABASE','TEST_PROVISIONER_DB_USERNAME')
$priorProcessEnv = @{}
foreach ($name in $managedEnvNames) { $priorProcessEnv[$name] = [Environment]::GetEnvironmentVariable($name,'Process') }
try {
    if (-not (Test-UpVanEnvAcl -Path $lockPath -Mode Localhost -PoolName '' -PoolSidOverride $null)) { Set-UpVanEnvAcl -Path $lockPath -Mode Localhost -PoolName '' -PoolSidOverride $null }
    $rootSecure = Read-Host 'MySQL root password (hidden prompt)' -AsSecureString
    $rootPointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($rootSecure)
    $rootPassword = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($rootPointer)
    [Environment]::SetEnvironmentVariable('UP_VAN_RECOVERY_ROOT_PASSWORD',$rootPassword,'Process')
    $preflight = Invoke-RecoveryPhp 'preflight'
    Show-SafeResult $preflight
    if ($preflight.ExitCode -ne 0 -or $preflight.Status -ne 'PASS') { throw 'RECOVERY_PREFLIGHT_FAILED' }

    $envText = [IO.File]::ReadAllText($envPath)
    if ($null -eq $state) {
        foreach ($key in @('AUTH_TEST_DB_PASSWORD','TEST_PROVISIONER_DB_PASSWORD')) {
            if (-not [string]::IsNullOrEmpty((Get-EnvValue $envText $key))) { throw 'CREDENTIALS_WITHOUT_RECOVERY_STATE' }
        }
        $secretBytesAuth = New-Object byte[] 32; $secretBytesProvisioner = New-Object byte[] 32
        $rng = [Security.Cryptography.RandomNumberGenerator]::Create()
        try {
            $rng.GetBytes($secretBytesAuth); $rng.GetBytes($secretBytesProvisioner)
            $authPassword = [BitConverter]::ToString($secretBytesAuth).Replace('-','').ToLowerInvariant()
            $provisionerPassword = [BitConverter]::ToString($secretBytesProvisioner).Replace('-','').ToLowerInvariant()
        } finally { $rng.Dispose() }
        $updatedEnv = Set-RecoveryEnv $envText $authPassword $provisionerPassword
        $transactionId = [Guid]::NewGuid().ToString('N')
        $state = [pscustomobject]@{
            Version=1; Target='up_van_booking_test'; AuthAccount='up_van_auth_test@127.0.0.1'; ProvisionerAccount='up_van_test_provisioner@127.0.0.1'
            TransactionId=$transactionId; Status='PREPARED'; OriginalEnvSha256=(Get-FileHash -LiteralPath $envPath -Algorithm SHA256).Hash
            PreparedEnvSha256=(Get-TextSha256 $updatedEnv)
            ProtectedAuthPassword=(ConvertTo-ProtectedPassword $authPassword); ProtectedProvisionerPassword=(ConvertTo-ProtectedPassword $provisionerPassword)
        }
        Save-RecoveryState $state
    } else {
        $authPassword = ConvertFrom-ProtectedPassword ([string]$state.ProtectedAuthPassword)
        $provisionerPassword = ConvertFrom-ProtectedPassword ([string]$state.ProtectedProvisionerPassword)
        $updatedEnv = Set-RecoveryEnv $envText $authPassword $provisionerPassword
        $currentHash = (Get-FileHash -LiteralPath $envPath -Algorithm SHA256).Hash
        if ($state.Status -eq 'COMPLETE') {
            if ($currentHash -cne $state.PreparedEnvSha256) { throw 'COMPLETED_STATE_ENV_MISMATCH' }
            Set-RecoveryProcessConfig $authPassword $provisionerPassword
            $verified = Invoke-RecoveryPhp 'verify' 'COMPLETE'; Show-SafeResult $verified
            if ($verified.ExitCode -ne 0 -or $verified.Status -ne 'PASS') { throw 'COMPLETED_STATE_VERIFICATION_FAILED' }
            Remove-OwnedEnvStaging $state.TransactionId
            Write-Output 'Recovery state is already COMPLETE; no Password was changed.'
            exit 0
        }
        if ($currentHash -cne $state.PreparedEnvSha256 -and $currentHash -cne $state.OriginalEnvSha256) { throw 'RECOVERY_ENV_STATE_AMBIGUOUS' }
        if ($currentHash -ceq $state.PreparedEnvSha256) { $updatedEnv = $envText }
    }

    $currentEnvHash = (Get-FileHash -LiteralPath $envPath -Algorithm SHA256).Hash
    if ($currentEnvHash -cne $state.PreparedEnvSha256) {
        Ensure-UpVanRecoveryStagingDirectory -Path $recoveryStageDir
        Remove-OwnedEnvStaging $state.TransactionId
        $sddl = Get-UpVanAclFingerprint -Path $envPath
        Write-UpVanAtomicEnvFile -Path $envPath -Text $updatedEnv -Mode Localhost -PoolName '' -ExpectedOriginalSddl $sddl -StagingId $state.TransactionId -StagingDirectory $recoveryStageDir
        $envChanged = $true
    } else { Remove-OwnedEnvStaging $state.TransactionId }
    Set-RecoveryProcessConfig $authPassword $provisionerPassword
    if ($state.Status -eq 'PREPARED') { Move-RecoveryState $state 'ENV_SYNCED' }

    if ($state.Status -eq 'ENV_SYNCED') {
        $r=Invoke-RecoveryPhp 'reconcile-auth' 'ENV_SYNCED'; Show-SafeResult $r
        if ($r.ExitCode -ne 0 -or $r.Status -ne 'PASS') { throw 'AUTH_ACCOUNT_RECOVERY_FAILED' }
        Move-RecoveryState $state 'AUTH_UPDATED'
    }
    if ($state.Status -eq 'AUTH_UPDATED') {
        $r=Invoke-RecoveryPhp 'reconcile-provisioner' 'AUTH_UPDATED'; Show-SafeResult $r
        if ($r.ExitCode -ne 0 -or $r.Status -ne 'PASS') { throw 'PROVISIONER_ACCOUNT_RECOVERY_FAILED' }
        Move-RecoveryState $state 'PROVISIONER_UPDATED'
    }
    if ($state.Status -eq 'PROVISIONER_UPDATED') {
        $r=Invoke-RecoveryPhp 'verify' 'PROVISIONER_UPDATED'; Show-SafeResult $r
        if ($r.ExitCode -ne 0 -or $r.Status -ne 'PASS') { throw 'FINAL_RECOVERY_VERIFICATION_FAILED' }
        Move-RecoveryState $state 'VERIFIED'
    }
    if ($state.Status -eq 'VERIFIED') {
        $r=Invoke-RecoveryPhp 'verify' 'VERIFIED'; Show-SafeResult $r
        if ($r.ExitCode -ne 0 -or $r.Status -ne 'PASS') { throw 'FINAL_RECOVERY_VERIFICATION_FAILED' }
        Move-RecoveryState $state 'COMPLETE'
    }
    Assert-UpVanEnvAcl -Path $envPath -Mode Localhost -PoolName '' -PoolSidOverride $null
    Remove-OwnedEnvStaging $state.TransactionId
    Write-Output 'Recovery COMPLETE; both account connections and GrantVerifier passed. No grants were changed.'
} catch {
    $safeMessage = $_.Exception.Message
    $allowed = @('STATE_SAVE_FAILED','RECOVERY_PREFLIGHT_FAILED','CREDENTIALS_WITHOUT_RECOVERY_STATE','COMPLETED_STATE_ENV_MISMATCH','COMPLETED_STATE_VERIFICATION_FAILED','RECOVERY_ENV_STATE_AMBIGUOUS','AUTH_ACCOUNT_RECOVERY_FAILED','PROVISIONER_ACCOUNT_RECOVERY_FAILED','FINAL_RECOVERY_VERIFICATION_FAILED')
    if ($safeMessage -notin $allowed) { $safeMessage='SAFE_RECOVERY_STOP' }
    if ($safeMessage -eq 'STATE_SAVE_FAILED') { Write-Output 'status=FAIL step=STATE_SAVE code=STATE_SAVE_FAILED' }
    Write-Error "$safeMessage; recovery state was preserved where created. Do not run the original Provisioning utility."
    exit 1
} finally {
    foreach ($name in $managedEnvNames) { [Environment]::SetEnvironmentVariable($name,$priorProcessEnv[$name],'Process') }
    if ($null -ne $secretBytesAuth) { [Array]::Clear($secretBytesAuth,0,$secretBytesAuth.Length) }
    if ($null -ne $secretBytesProvisioner) { [Array]::Clear($secretBytesProvisioner,0,$secretBytesProvisioner.Length) }
    $authPassword=$provisionerPassword=$rootPassword=$updatedEnv=$envText=$null
    if ($rootPointer -ne [IntPtr]::Zero) { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($rootPointer) }
    if ($null -ne $rootSecure) { $rootSecure.Dispose() }
    $priorProcessEnv=$null
}

} finally { $recoveryLock.Dispose() }
