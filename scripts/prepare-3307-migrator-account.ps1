#requires -Version 5.1
param([switch]$PreflightOnly,[switch]$Execute,[switch]$Postflight,[string]$ConfirmTarget='')
Set-StrictMode -Version Latest
$ErrorActionPreference='Stop'
$stage='ARGUMENTS';$proc=$null;$secure=$null;$ptr=[IntPtr]::Zero;$plain=$null;$payload=$null
$diagnosticRevision='SCHEMA006_V3'
function Send-Restore006RootInput([Diagnostics.Process]$Child,[string]$Json) {
    $bytes=[Text.UTF8Encoding]::new($false).GetBytes($Json)
    try {
        $Child.StandardInput.BaseStream.Write($bytes,0,$bytes.Length)
        $Child.StandardInput.BaseStream.Flush()
        $Child.StandardInput.BaseStream.Close()
    } finally { [Array]::Clear($bytes,0,$bytes.Length) }
}
try {
    if(([int]$PreflightOnly.IsPresent+[int]$Execute.IsPresent+[int]$Postflight.IsPresent) -ne 1) {throw 'MODE'}
    if($ConfirmTarget -cne 'mysql-3307-37e4d222-dda2-11f1-b503-5c2886f057d5') {throw 'TARGET'}
    $mode=if($Execute){'execute'}elseif($Postflight){'postflight'}else{'preflight'}
    $root=(Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..')).Path
    $php=Join-Path $root '.runtime\php84\php.exe'
    $ext=Join-Path $root '.runtime\php84\ext'
    $script=Join-Path $PSScriptRoot 'prepare-3307-migrator-account.php'
    $stage='RUNTIME'
    foreach($file in @($php,(Join-Path $ext 'php_pdo_mysql.dll'),(Join-Path $ext 'php_mbstring.dll'),$script)) {if(!(Test-Path -LiteralPath $file -PathType Leaf)){throw 'RUNTIME'}}
    . (Join-Path $PSScriptRoot 'lib\provision-php-runtime.ps1')
    [void](Assert-UpVanProvisioningPhpRuntime -ProjectRoot $root)
    $stage='ROOT_HIDDEN_PROMPT'
    $secure=Read-Host 'Root password for Restore MySQL 3307 only' -AsSecureString
    if($secure.Length -eq 0){throw 'EMPTY'}
    $stage='ROOT_PASSWORD_ENCODING'
    $ptr=[Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure)
    $plain=[Runtime.InteropServices.Marshal]::PtrToStringBSTR($ptr)
    $stage='STDIN_SERIALIZATION'
    $payload=@{root_password=$plain} | ConvertTo-Json -Compress
    $stage='PHP_PROCESS'
    $info=New-Object Diagnostics.ProcessStartInfo
    $info.FileName=$php
    # Match scripts/php.ps1: ASCII relative extension path under the pinned cwd.
    $info.Arguments='-n -d display_errors=0 -d log_errors=0 -d extension_dir=.runtime/php84/ext -d extension=pdo_mysql -d extension=mbstring "'+$script+'" '+$mode+' '+$ConfirmTarget
    $info.WorkingDirectory=$root;$info.UseShellExecute=$false;$info.CreateNoWindow=$true
    $info.RedirectStandardInput=$true;$info.RedirectStandardOutput=$true;$info.RedirectStandardError=$true
    $info.StandardOutputEncoding=[Text.UTF8Encoding]::new($false)
    $proc=New-Object Diagnostics.Process;$proc.StartInfo=$info
    if(!$proc.Start()){throw 'PROCESS'}
    $outTask=$proc.StandardOutput.ReadToEndAsync();$errTask=$proc.StandardError.ReadToEndAsync()
    Send-Restore006RootInput $proc $payload
    $proc.WaitForExit();$output=$outTask.Result;[void]$errTask.Result
    $stage='SAFE_RESULT'
    $result=$output | ConvertFrom-Json -ErrorAction Stop
    foreach($key in @('status','step','code')) {if(!$result.PSObject.Properties[$key] -or $result.$key -notmatch '^[A-Z0-9_]{1,64}$'){throw 'SCHEMA'}}
    $stage='SOURCE_VERSION'
    if(!$result.PSObject.Properties['diagnostic_revision'] -or $result.diagnostic_revision -cne $diagnosticRevision){throw 'REVISION'}
    # Never forward raw PHP output/stderr; forward only bounded non-secret fields.
    $safe=[ordered]@{status=$result.status;step=$result.step;code=$result.code;php_exit_code=$proc.ExitCode;diagnostic_revision=$diagnosticRevision}
    foreach($key in @('creation_state','sqlstate','failure_type')) {if($result.PSObject.Properties[$key] -and $result.$key -match '^[A-Z0-9_]{1,64}$'){$safe[$key]=$result.$key}}
    foreach($key in @('schema_check','schema_field')) {if($result.PSObject.Properties[$key] -and $result.$key -match '^[A-Za-z0-9_.]{1,128}$'){$safe[$key]=$result.$key}}
    foreach($key in @('error_type','error_file')) {if($result.PSObject.Properties[$key] -and $result.$key -match '^[A-Za-z0-9_.]{1,96}$'){$safe[$key]=$result.$key}}
    if($result.PSObject.Properties['error_line'] -and "$($result.error_line)" -match '^\d{1,8}$'){$safe['error_line']=$result.error_line}
    foreach($key in @('grants_completed','native_code')) {if($result.PSObject.Properties[$key] -and "$($result.$key)" -match '^\d{1,10}$'){$safe[$key]=$result.$key}}
    foreach($key in @('credential_verified','migration_applied','automatic_retry')) {if($result.PSObject.Properties[$key] -and $result.$key -is [bool]){$safe[$key]=$result.$key}}
    $safe | ConvertTo-Json -Compress | Write-Host
    if($proc.ExitCode -ne 0 -or $result.status -ne 'PASS'){exit 1}
} catch {
    $code=if($stage -eq 'RUNTIME'){'PHP_RUNTIME_UNAVAILABLE'}elseif($stage -eq 'SOURCE_VERSION'){'PHP_SOURCE_REVISION_MISMATCH'}elseif($stage -eq 'ROOT_HIDDEN_PROMPT'){if($null -ne $secure -and $secure.Length -eq 0){'ROOT_PASSWORD_EMPTY'}else{'ROOT_PROMPT_FAILED'}}elseif($stage -eq 'ROOT_PASSWORD_ENCODING'){'ROOT_PASSWORD_ENCODING_FAILED'}elseif($stage -eq 'STDIN_SERIALIZATION'){'STDIN_SERIALIZATION_FAILED'}elseif($stage -eq 'PHP_PROCESS' -or $stage -eq 'SAFE_RESULT'){'PHP_PROCESS_OR_OUTPUT_FAILURE'}else{'SAFE_ACCOUNT_PREPARATION_STOP'}
    [ordered]@{status='FAIL';step=$stage;code=$code;diagnostic_revision=$diagnosticRevision;error_type=$_.Exception.GetBaseException().GetType().Name;automatic_retry=$false} | ConvertTo-Json -Compress | Write-Host
    exit 1
} finally {
    if($ptr -ne [IntPtr]::Zero){[Runtime.InteropServices.Marshal]::ZeroFreeBSTR($ptr)}
    if($null -ne $secure){$secure.Dispose()}
    $plain=$null;$payload=$null
    if($null -ne $proc){$proc.Dispose()}
}
