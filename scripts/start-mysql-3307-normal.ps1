#requires -Version 5.1
# Operator normal startup only. Never executes any password-change SQL.
param([switch]$PreflightOnly,[switch]$Execute,[switch]$PostRecovery,[string]$ConfirmTarget='')
Set-StrictMode -Version Latest
$ErrorActionPreference='Stop'
$stage='ARGUMENTS';$aclOperation=$null;$diagnostic=$null;$diagnosticPath=$null;$launch=$null
trap {
    $failureType=$_.Exception.GetBaseException().GetType().Name
    $reason=$_.Exception.GetBaseException().Message
    $allowed=@('SERVER_EXITED_BEFORE_LISTENER','SERVER_RUNNING_NO_LISTENER','ROOT_LOGIN_FAILED_OR_UNREACHABLE','RESTORE_IDENTITY_REJECTED','RESTORE_USER_DATABASE_EXISTS','RESTORE_LISTENER_REJECTED','RESTORE_LISTENER_PROCESS_REJECTED','UNRELATED_LISTENER_PROCESS','RESTORE_PROCESS_EXISTS','RESTORE_LISTENER_EXISTS','PROCESS_METADATA_UNAVAILABLE','POST_RECOVERY_PROOF_REJECTED','POST_RECOVERY_INIT_FILE_REMAINS','POST_RECOVERY_INIT_OPTION_PRESENT')
    $code=if($reason -in $allowed){$reason}else{'NORMAL_STARTUP_STOP'}
    if($stage -eq 'ROOT_LOGIN' -and $code -eq 'NORMAL_STARTUP_STOP') { $code='ROOT_LOGIN_FAILED_OR_UNREACHABLE' }
    $saved=$false
    if($null -ne $diagnostic -and $diagnosticPath) {
        try {
            if($null -ne $launch) {
                $launch.Refresh()
                if($launch.HasExited){$launch.WaitForExit();$diagnostic['exit_code']=$launch.ExitCode}
            }
        } catch { }
        $diagnostic['status']='FAIL';$diagnostic['failed_step']=$stage;$diagnostic['code']=$code;$diagnostic['error_type']=$failureType
        if($stage -eq 'ROOT_LOGIN') { $diagnostic['root_login']='FAILED_OR_IDENTITY_REJECTED' }
        try {[IO.File]::WriteAllText($diagnosticPath,($diagnostic | ConvertTo-Json -Depth 4),[Text.UTF8Encoding]::new($false));$saved=$true} catch { }
    }
    [ordered]@{status='FAIL';step=$stage;code=$code;error_type=$failureType;diagnostic_saved=$saved;diagnostic_file=$diagnosticPath;automatic_retry=$false;password_changed_by_script=$false} | ConvertTo-Json -Compress | Write-Host
    exit 1
}
if(([int]$PreflightOnly.IsPresent+[int]$Execute.IsPresent) -ne 1) { throw 'Choose one mode' }
if($Execute -and $ConfirmTarget -cne 'mysql-3307-normal') { throw 'Explicit normal-start confirmation required' }
if($PSVersionTable.PSEdition -ne 'Desktop' -or $PSVersionTable.PSVersion.Major -ne 5) { throw 'Windows PowerShell 5.1 required' }
. (Join-Path $PSScriptRoot 'lib\mysql-3307-recovery-acl.ps1')
. (Join-Path $PSScriptRoot 'lib\mysql-3307-startup-safety.ps1')
. (Join-Path $PSScriptRoot 'lib\mysql-3307-normal-startup.ps1')
. (Join-Path $PSScriptRoot 'lib\mysql-3307-fresh-recovery.ps1')
. (Join-Path $PSScriptRoot 'lib\mysql-3307-post-recovery.ps1')
. (Join-Path $PSScriptRoot 'lib\mysql-3307-shutdown-safety.ps1')
$restoreData='C:\Users\User\mysql-restore-006-data'
$folder='C:\Users\User\mysql-3307-recovery-80da7a7f45c24543a25a983b20c014da'
if($PostRecovery) { $postConfig=Get-Restore3307FreshConfig;$folder=$postConfig.Folder }
$workingDirectory=Get-Restore3307NormalWorkingDirectory
$server=Join-Path $workingDirectory 'mysqld.exe'
$lockPath=Join-Path $folder 'normal-startup.lock'
$stage='TARGET_METADATA'
foreach($path in @($workingDirectory,$server,$restoreData)) {
    $item=Get-Item -LiteralPath $path -Force -ErrorAction Stop
    if($item.Attributes -band [IO.FileAttributes]::ReparsePoint) { throw 'Reparse point rejected' }
}
if($workingDirectory -match '[^\x00-\x7F]') { throw 'Non-ASCII working directory rejected' }
if([Diagnostics.FileVersionInfo]::GetVersionInfo($server).ProductVersion -notmatch '^8\.4\.9(?:[.\s-]|$)') { throw 'Unexpected server version' }
$restoreUuid=[regex]::Match([IO.File]::ReadAllText((Join-Path $restoreData 'auto.cnf')),'(?im)^server-uuid\s*=\s*([a-f0-9-]+)\s*$').Groups[1].Value
# Read only UUID metadata from Primary; never connect to, launch or stop it.
$primaryUuid=if($PostRecovery){'77218fbd-d66d-11f1-9a0a-5c2886f057d5'}else{[regex]::Match([IO.File]::ReadAllText('C:\Users\User\mysql-data\auto.cnf'),'(?im)^server-uuid\s*=\s*([a-f0-9-]+)\s*$').Groups[1].Value}
if($restoreUuid -ne '37e4d222-dda2-11f1-b503-5c2886f057d5' -or !$primaryUuid -or $restoreUuid -eq $primaryUuid) { throw 'UUID isolation rejected' }
$stage='DIRECTORY_ACL'
Assert-PrivateRecoveryAcl $folder $true
if($PostRecovery) {
    $stage='POST_RECOVERY_GATE'
    Assert-Restore3307PostNormalReady $postConfig
}
$stage='STOPPED_CHECK'
Assert-Restore3307Stopped
$stage='NORMAL_START_LOCK'
if(Test-Path -LiteralPath $lockPath) { throw 'Normal startup already attempted; review diagnostics before another start' }
if($PreflightOnly) {
    [ordered]@{status='PASS';mode='READ_ONLY_PREFLIGHT';port=3307;working_directory=$workingDirectory;uuid=$restoreUuid;different_from_primary=$true;stopped=$true;files_created=$false;server_started=$false} | ConvertTo-Json -Compress | Write-Host
    return
}
$stage='EXCLUSIVE_NORMAL_START_LOCK'
$handle=[IO.File]::Open($lockPath,[IO.FileMode]::CreateNew,[IO.FileAccess]::Write,[IO.FileShare]::None)
$handle.Dispose()
Set-PrivateRecoveryAcl $lockPath $false
$id=[guid]::NewGuid().ToString('N')
$diagnosticPath=Join-Path $folder ('normal-startup-'+$id+'.json')
$errorLog=Join-Path $folder ('normal-startup-'+$id+'.err')
$stage='NORMAL_DIAGNOSTIC_FILES'
foreach($path in @($diagnosticPath,$errorLog)) {
    [void](New-Item -ItemType File -Path $path)
    Set-PrivateRecoveryAcl $path $false
}
$diagnostic=[ordered]@{status='PENDING';mode='NORMAL';port=3307;datadir=$restoreData;uuid=$restoreUuid;working_directory=$workingDirectory;started_utc=[DateTime]::UtcNow.ToString('o');launch_pid=$null;listener_pid=$null;exit_code=$null;state='PREPARED';root_login='NOT_ATTEMPTED';server_error_log=$errorLog;automatic_retry=$false}
function Save-NormalStartupDiagnostic {
    [IO.File]::WriteAllText($diagnosticPath,($diagnostic | ConvertTo-Json -Depth 4),[Text.UTF8Encoding]::new($false))
}
Save-NormalStartupDiagnostic
$stage='FINAL_STOPPED_CHECK'
Assert-Restore3307Stopped
$stage='NORMAL_START'
$serverArgs=Get-Restore3307NormalArguments $errorLog
$launch=Start-Process -FilePath $server -ArgumentList $serverArgs -WorkingDirectory $workingDirectory -WindowStyle Hidden -PassThru
$diagnostic['launch_pid']=$launch.Id;$diagnostic['state']='PROCESS_LAUNCHED'
Save-NormalStartupDiagnostic
$stage='WAIT_LISTENER'
Wait-Restore3307NormalListener $launch $diagnostic
Save-NormalStartupDiagnostic
if($PostRecovery) {
    $stage='VERIFY_NORMAL_ARGUMENTS'
    Assert-Restore3307NormalCommandLines (Get-Restore3307ShutdownSnapshot)
}
$stage='ROOT_LOGIN'
$diagnostic['root_login']='PROMPTING'
Save-NormalStartupDiagnostic
$identity=Read-Restore3307RootIdentity
$diagnostic['status']='PASS';$diagnostic['state']='ROOT_LOGIN_VERIFIED';$diagnostic['root_login']='PASS'
Save-NormalStartupDiagnostic
[ordered]@{status='PASS';code='NORMAL_STARTUP_ROOT_LOGIN_VERIFIED';port=$identity.port;uuid=$identity.uuid;datadir=$identity.datadir;version=$identity.version;account=$identity.account;databases=$identity.databases;launch_pid=$diagnostic['launch_pid'];listener_pid=$diagnostic['listener_pid'];diagnostic_file=$diagnosticPath;exit_code=$null} | ConvertTo-Json -Compress | Write-Host
Write-Host 'STOP. Original recovery marker/file remain untouched. No password change, restore or migration was executed by this script.'
