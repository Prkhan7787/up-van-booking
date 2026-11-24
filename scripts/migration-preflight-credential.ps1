[CmdletBinding()]
param(
    [Parameter(Mandatory=$true)]
    [ValidateSet('Store','Preflight','Postflight')]
    [string]$Action,
    [string]$PhpRuntimePath='C:\จองรถตู้\up-van-booking\.runtime\php84\php.exe',
    [string]$SchemaSnapshotDirectory=''
)

$ErrorActionPreference='Stop'
if($env:OS -ne 'Windows_NT'){throw 'WINDOWS_DPAPI_REQUIRED'}
$approvedProjectRoot='C:\จองรถตู้\up-van-booking'
$worktreeRoot=Split-Path -Parent $PSScriptRoot
$credentialDirectory=Join-Path $env:LOCALAPPDATA 'UPVanBooking'
$credentialPath=Join-Path $credentialDirectory 'migration-preflight.dpapi'

if($Action -eq 'Store'){
    if(Test-Path -LiteralPath $credentialPath){throw 'PREFLIGHT_CREDENTIAL_ALREADY_STORED'}
    $secure=Read-Host -Prompt 'Enter password for up_van_migration_preflight@127.0.0.1' -AsSecureString
    if($secure.Length -lt 1){$secure.Dispose();throw 'PREFLIGHT_CREDENTIAL_EMPTY'}
    $temporaryPath=$null
    try{
        $protected=ConvertFrom-SecureString -SecureString $secure -ErrorAction Stop
        if(!(Test-Path -LiteralPath $credentialDirectory -PathType Container)){
            New-Item -ItemType Directory -Path $credentialDirectory -Force | Out-Null
        }
        $temporaryPath=Join-Path $credentialDirectory ('.migration-preflight-'+[Guid]::NewGuid().ToString('N')+'.tmp')
        [IO.File]::WriteAllText($temporaryPath,$protected,[Text.Encoding]::ASCII)
        [IO.File]::Move($temporaryPath,$credentialPath)
        Write-Output 'PREFLIGHT_CREDENTIAL_STORED=TRUE'
    }catch{
        throw 'PREFLIGHT_CREDENTIAL_STORE_FAILED'
    }finally{
        $protected=$null
        $secure.Dispose()
        if($temporaryPath -and (Test-Path -LiteralPath $temporaryPath)){
            Remove-Item -LiteralPath $temporaryPath -Force -ErrorAction SilentlyContinue
        }
    }
    return
}

if(!(Test-Path -LiteralPath $credentialPath -PathType Leaf)){throw 'PREFLIGHT_CREDENTIAL_MISSING_RUN_STORE_FIRST'}
$expectedPhp=Join-Path $approvedProjectRoot '.runtime\php84\php.exe'
if(-not [IO.Path]::IsPathRooted($PhpRuntimePath)){throw 'PHP_RUNTIME_PATH_MUST_BE_ABSOLUTE'}
try{
    $php=(Resolve-Path -LiteralPath $PhpRuntimePath -ErrorAction Stop).ProviderPath
    $expectedPhp=(Resolve-Path -LiteralPath $expectedPhp -ErrorAction Stop).ProviderPath
}catch{throw 'PROJECT_PHP_RUNTIME_MISSING'}
$php=[IO.Path]::GetFullPath($php)
$expectedPhp=[IO.Path]::GetFullPath($expectedPhp)
$extensionDirectory=Join-Path (Split-Path -Parent $php) 'ext'
$runner=Join-Path $worktreeRoot 'scripts\driver-auth-migration-preflight.php'
if(![string]::Equals($php,$expectedPhp,[StringComparison]::OrdinalIgnoreCase)){throw 'PHP_RUNTIME_PATH_REJECTED'}
if(!(Test-Path -LiteralPath $php -PathType Leaf) -or !(Test-Path -LiteralPath $extensionDirectory -PathType Container)){throw 'PROJECT_PHP_RUNTIME_MISSING'}
if(!(Test-Path -LiteralPath $runner -PathType Leaf)){throw 'PREFLIGHT_RUNNER_MISSING'}

Push-Location -LiteralPath $approvedProjectRoot
try{
    $versionOutput=& $php -n -d extension_dir=.runtime/php84/ext -d extension=pdo_mysql -d extension=mbstring -d extension=openssl --version 2>&1 | Out-String
    if($LASTEXITCODE -ne 0 -or $versionOutput -notmatch '(?m)^PHP 8\.4\.\d+'){throw 'PHP_RUNTIME_VERSION_REJECTED'}
    $moduleOutput=& $php -n -d extension_dir=.runtime/php84/ext -d extension=pdo_mysql -d extension=mbstring -d extension=openssl --modules 2>&1
    if($LASTEXITCODE -ne 0){throw 'PHP_RUNTIME_EXTENSION_CHECK_FAILED'}
    $modules=@($moduleOutput | ForEach-Object {([string]$_).Trim()} | Where-Object {$_ -ne ''})
    foreach($module in @('pdo_mysql','mbstring','openssl')){
        if($modules -notcontains $module){throw 'PHP_RUNTIME_EXTENSION_MISSING'}
    }
}finally{Pop-Location}
Write-Output 'PHP_RUNTIME=8.4; EXTENSIONS=pdo_mysql,mbstring,openssl'

$secure=$null;$password=$null;$pointer=[IntPtr]::Zero;$process=$null;$started=$false
$psi=New-Object System.Diagnostics.ProcessStartInfo
try{
    try{
        $protected=[IO.File]::ReadAllText($credentialPath,[Text.Encoding]::ASCII).Trim()
        if([string]::IsNullOrWhiteSpace($protected)){throw 'PREFLIGHT_CREDENTIAL_EMPTY'}
        $secure=ConvertTo-SecureString -String $protected -ErrorAction Stop
    }catch{throw 'PREFLIGHT_CREDENTIAL_DECRYPT_FAILED'}
    $pointer=[Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure)
    $password=[Runtime.InteropServices.Marshal]::PtrToStringBSTR($pointer)
    if([string]::IsNullOrEmpty($password)){throw 'PREFLIGHT_CREDENTIAL_DECRYPT_FAILED'}

    $phase='--'+$Action.ToLowerInvariant()
    $psi.FileName=$php
    $psi.Arguments="-n -d extension_dir=.runtime/php84/ext -d extension=pdo_mysql -d extension=mbstring -d extension=openssl `"$runner`" $phase"
    $psi.WorkingDirectory=$approvedProjectRoot
    $psi.UseShellExecute=$false
    $psi.CreateNoWindow=$true
    $psi.RedirectStandardOutput=$true
    $psi.RedirectStandardError=$true
    $psi.EnvironmentVariables['MIGRATION_PREFLIGHT_DB_HOST']='127.0.0.1'
    $psi.EnvironmentVariables['MIGRATION_PREFLIGHT_DB_PORT']='3306'
    $psi.EnvironmentVariables['MIGRATION_PREFLIGHT_DB_DATABASE']='up_van_booking_test'
    $psi.EnvironmentVariables['MIGRATION_PREFLIGHT_DB_USERNAME']='up_van_migration_preflight'
    $psi.EnvironmentVariables['MIGRATION_PREFLIGHT_DB_PASSWORD']=$password
    $psi.EnvironmentVariables['MIGRATION_PREFLIGHT_SCHEMA_SNAPSHOT']=$SchemaSnapshotDirectory

    $process=New-Object System.Diagnostics.Process
    $process.StartInfo=$psi
    if(!$process.Start()){throw 'PREFLIGHT_PHP_START_FAILED'}
    $started=$true
    $psi.EnvironmentVariables['MIGRATION_PREFLIGHT_DB_PASSWORD']=''
    $stdoutTask=$process.StandardOutput.ReadToEndAsync()
    $stderrTask=$process.StandardError.ReadToEndAsync()
    $process.WaitForExit()
    $exitCode=$process.ExitCode
    $stdout=$stdoutTask.Result
    $stderr=$stderrTask.Result
    $stdout=$stdout.Replace($password,'[REDACTED]')
    $stderr=$stderr.Replace($password,'[REDACTED]')
    $password=$null
    if($pointer -ne [IntPtr]::Zero){[Runtime.InteropServices.Marshal]::ZeroFreeBSTR($pointer);$pointer=[IntPtr]::Zero}
    if($secure){$secure.Dispose();$secure=$null}

    foreach($line in @($stdout -split "`r?`n" | Where-Object {$_ -ne ''})){
        try{$null=ConvertFrom-Json -InputObject $line -ErrorAction Stop}catch{throw 'PREFLIGHT_OUTPUT_INVALID'}
        Write-Output $line
    }
    foreach($line in @($stderr -split "`r?`n" | Where-Object {$_ -ne ''})){Write-Output "PHP_STDERR=$line"}
    if($exitCode -ne 0){throw "PREFLIGHT_RUNNER_FAILED_EXIT_$exitCode"}
}finally{
    $password=$null
    if($pointer -ne [IntPtr]::Zero){[Runtime.InteropServices.Marshal]::ZeroFreeBSTR($pointer)}
    if($secure){$secure.Dispose()}
    if($psi){$psi.EnvironmentVariables['MIGRATION_PREFLIGHT_DB_PASSWORD']=''}
    if($process){$process.Dispose()}
}
