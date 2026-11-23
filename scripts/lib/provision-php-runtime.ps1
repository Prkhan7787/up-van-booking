Set-StrictMode -Version Latest

function Get-UpVanProvisionPhpCommand([string]$ProjectRoot, [ValidateSet('preflight','execute')][string]$Purpose) {
    $launcher = Join-Path $ProjectRoot 'scripts/php.ps1'
    $scriptName = if ($Purpose -eq 'preflight') { 'scripts/provision-test-auth-accounts-preflight.php' } else { 'scripts/provision-test-auth-accounts-execute.php' }
    $arguments = @('-NoProfile','-ExecutionPolicy','Bypass','-File',$launcher,$scriptName)
    $arguments += 'up_van_booking_test'
    return [pscustomobject]@{ Executable='powershell.exe'; Arguments=$arguments; PhpScript=$scriptName }
}

function Invoke-UpVanProvisionPhp([string]$ProjectRoot, [ValidateSet('preflight','execute')][string]$Purpose) {
    $command = Get-UpVanProvisionPhpCommand -ProjectRoot $ProjectRoot -Purpose $Purpose
    # Keep child stdout for safe preflight diagnostics; discard stderr because PHP/runtime
    # warnings can contain paths or configuration details. Never forward raw child output.
    $output = & $command.Executable @($command.Arguments) 2>$null | Out-String
    $exitCode = $LASTEXITCODE
    if ($Purpose -eq 'preflight') {
        return [pscustomobject]@{ ExitCode=$exitCode; Output=$output }
    }
    if (-not [string]::IsNullOrWhiteSpace($output)) { Write-Output $output.TrimEnd() }
    return $exitCode
}

function Format-UpVanProvisionPreflightFailure([string]$Output, [int]$ExitCode) {
    $safeSteps = @('CONFIG','GATES','ROOT_PASSWORD_PRESENT','PDO_CONNECT','ROOT_IDENTITY','TARGET_SCHEMA','TARGET_ACCOUNTS')
    foreach ($line in ($Output -split "`r?`n")) {
        try { $result = $line | ConvertFrom-Json -ErrorAction Stop } catch { continue }
        if ($null -eq $result -or $null -eq $result.PSObject.Properties['status'] -or $null -eq $result.PSObject.Properties['step'] -or $null -eq $result.PSObject.Properties['code']) { continue }
        if ($result.status -ne 'FAIL' -or $result.step -notin $safeSteps) { continue }
        if ($result.code -notmatch '^[A-Z0-9_]{1,64}$') { continue }
        $parts = @("Read-only Preflight diagnostic: STEP=$($result.step)", "CODE=$($result.code)")
        if ($result.code -eq 'PDO_ERROR') {
            if ($result.sqlstate -match '^[A-Z0-9]{5}$') { $parts += "SQLSTATE=$($result.sqlstate)" }
            if ($null -ne $result.native_code -and "$($result.native_code)" -match '^\d{1,10}$') { $parts += "NATIVE_CODE=$($result.native_code)" }
        }
        return ($parts -join ' ')
    }
    return "Read-only Preflight diagnostic: STEP=PHP_PROCESS CODE=NO_SAFE_DIAGNOSTIC EXIT_CODE=$ExitCode"
}

function Invoke-UpVanPhpLauncher([string]$ProjectRoot, [string[]]$PhpArguments) {
    $launcher = Join-Path $ProjectRoot 'scripts/php.ps1'
    $output = & powershell.exe -NoProfile -ExecutionPolicy Bypass -File $launcher @PhpArguments 2>$null
    return [pscustomobject]@{ ExitCode=$LASTEXITCODE; Output=($output | Out-String) }
}

function Assert-UpVanProvisioningPhpRuntime(
    [string]$ProjectRoot,
    [scriptblock]$Runner = ${function:Invoke-UpVanPhpLauncher}
) {
    $php = Join-Path $ProjectRoot '.runtime/php84/php.exe'
    $extensionDir = Join-Path $ProjectRoot '.runtime/php84/ext'
    if (-not (Test-Path -LiteralPath $php -PathType Leaf)) { throw 'Project PHP 8.4 runtime is unavailable.' }
    foreach ($dll in @('php_pdo_mysql.dll','php_mbstring.dll','php_openssl.dll')) {
        if (-not (Test-Path -LiteralPath (Join-Path $extensionDir $dll) -PathType Leaf)) {
            throw "Required PHP extension file is missing: $dll."
        }
    }

    $version = & $Runner $ProjectRoot @('-v')
    if ($version.ExitCode -ne 0 -or $version.Output -notmatch '(?m)^PHP 8\.4\.\d+\b') {
        throw 'Project PHP runtime did not report the required PHP 8.4 version.'
    }
    $modules = & $Runner $ProjectRoot @('-m')
    if ($modules.ExitCode -ne 0) { throw 'Project PHP runtime could not enumerate loaded extensions.' }
    foreach ($module in @('PDO','pdo_mysql','mbstring','openssl')) {
        if ($modules.Output -notmatch ("(?im)^" + [regex]::Escape($module) + "\s*$")) {
            throw "Required PHP extension is not loaded: $module."
        }
    }
    return [pscustomobject]@{ Version='8.4'; Extensions=@('PDO','pdo_mysql','mbstring','openssl'); Launcher=(Join-Path $ProjectRoot 'scripts/php.ps1') }
}
