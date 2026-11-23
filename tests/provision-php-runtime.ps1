$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot '..\scripts\lib\provision-php-runtime.ps1')
$root = Join-Path $env:TEMP ('up-van-php-runtime-' + [Guid]::NewGuid().ToString('N'))
$phpDir = Join-Path $root '.runtime/php84'
$extDir = Join-Path $phpDir 'ext'
New-Item -ItemType Directory -Path $extDir -Force | Out-Null
New-Item -ItemType Directory -Path (Join-Path $root 'scripts') -Force | Out-Null
[IO.File]::WriteAllText((Join-Path $phpDir 'php.exe'), '')
[IO.File]::WriteAllText((Join-Path $extDir 'php_pdo_mysql.dll'), '')
[IO.File]::WriteAllText((Join-Path $extDir 'php_mbstring.dll'), '')
[IO.File]::WriteAllText((Join-Path $root 'scripts/php.ps1'), '')
$script:checks = 0
function Assert-Check([bool]$Condition, [string]$Name) {
    if (-not $Condition) { throw "Provisioning PHP runtime regression failed: $Name" }
    $script:checks++
    Write-Output "PASS: $Name"
}

try {
    $command = Get-UpVanProvisionPhpCommand -ProjectRoot $root -Purpose execute
    Assert-Check ($command.Executable -eq 'powershell.exe' -and $command.Arguments -contains '-File' -and $command.Arguments -contains 'scripts/provision-test-auth-accounts-execute.php' -and $command.Arguments -contains 'up_van_booking_test') 'PHP command selects project launcher and file mode with explicit test target'
    Assert-Check (-not ($command.Arguments -contains '-r') -and -not (($command.Arguments -join ' ') -match 'password|secret')) 'PHP command arguments contain no inline code or credential values'
    $fakeLauncher = @'
param([string]$ScriptName, [string]$Target)
if ($ScriptName -ne 'scripts/provision-test-auth-accounts-execute.php' -or $Target -ne 'up_van_booking_test') { exit 9 }
Write-Output 'FAKE_LAUNCHER_ARGUMENTS_OK'
exit 0
'@
    [IO.File]::WriteAllText((Join-Path $root 'scripts/php.ps1'), $fakeLauncher)
    $invocation = @(Invoke-UpVanProvisionPhp -ProjectRoot $root -Purpose execute)
    Assert-Check ($LASTEXITCODE -eq 0 -and $invocation -contains 'FAKE_LAUNCHER_ARGUMENTS_OK') 'Launcher invocation passes only file and explicit target arguments'

    $script:runnerCalls = @()
    $goodRunner = {
        param([string]$ProjectRoot, [string[]]$PhpArguments)
        $script:runnerCalls += ,$PhpArguments
        if ($PhpArguments -contains '-v') { return [pscustomobject]@{ ExitCode=0; Output='PHP 8.4.27 (cli)' } }
        return [pscustomobject]@{ ExitCode=0; Output="[PHP Modules]`nPDO`npdo_mysql`nmbstring`n" }
    }
    $runtime = Assert-UpVanProvisioningPhpRuntime -ProjectRoot $root -Runner $goodRunner
    Assert-Check ($runtime.Version -eq '8.4' -and $runtime.Extensions.Count -eq 3 -and $script:runnerCalls.Count -eq 2) 'Runtime validation requires PHP 8.4 plus PDO, pdo_mysql, and mbstring'

    Remove-Item -LiteralPath (Join-Path $extDir 'php_mbstring.dll') -Force
    $missingDllRejected = $false
    try { [void](Assert-UpVanProvisioningPhpRuntime -ProjectRoot $root -Runner $goodRunner) }
    catch { $missingDllRejected = $_.Exception.Message -match 'php_mbstring\.dll' }
    Assert-Check $missingDllRejected 'Missing extension DLL is rejected before invoking PHP'
    [IO.File]::WriteAllText((Join-Path $extDir 'php_mbstring.dll'), '')

    $missingModuleRunner = {
        param([string]$ProjectRoot, [string[]]$PhpArguments)
        if ($PhpArguments -contains '-v') { return [pscustomobject]@{ ExitCode=0; Output='PHP 8.4.27 (cli)' } }
        return [pscustomobject]@{ ExitCode=0; Output="[PHP Modules]`nPDO`nmbstring`n" }
    }
    $missingModuleRejected = $false
    try { [void](Assert-UpVanProvisioningPhpRuntime -ProjectRoot $root -Runner $missingModuleRunner) }
    catch { $missingModuleRejected = $_.Exception.Message -match 'pdo_mysql' }
    Assert-Check $missingModuleRejected 'Extension file present but module not loaded is rejected'

    $badVersionRunner = {
        param([string]$ProjectRoot, [string[]]$PhpArguments)
        [pscustomobject]@{ ExitCode=0; Output='PHP 8.3.0 (cli)' }
    }
    $wrongVersionRejected = $false
    try { [void](Assert-UpVanProvisioningPhpRuntime -ProjectRoot $root -Runner $badVersionRunner) }
    catch { $wrongVersionRejected = $true }
    Assert-Check $wrongVersionRejected 'Wrong PHP major/minor version is rejected'

    Write-Output "PASS: $checks PHP runtime and command-construction regression checks; no database was contacted."
} finally {
    Remove-Item -LiteralPath $root -Recurse -Force -ErrorAction SilentlyContinue
}
