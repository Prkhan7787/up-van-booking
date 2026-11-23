#requires -Version 5.1
# Pinned NEW recovery attempt only. The init SQL content is never read or printed.
param([switch]$PreflightOnly,[switch]$Execute,[string]$ConfirmTarget='')
Set-StrictMode -Version Latest
$ErrorActionPreference='Stop'
$stage='ARGUMENTS';$aclOperation=$null;$diag=$null;$cfg=$null;$launch=$null;$startRequested=$false
trap {
    $baseError=$_.Exception.GetBaseException()
    $errorType=$baseError.GetType().Name
    $nativeCode=if($baseError -is [ComponentModel.Win32Exception]){$baseError.NativeErrorCode}else{$null}
    $reason=$baseError.Message
    $allowed=@('FRESH_CONFIG_REJECTED','FRESH_PREPARED_MARKER_REJECTED','FRESH_ATTEMPT_ALREADY_EXISTS','SERVER_EXITED_BEFORE_LISTENER','SERVER_RUNNING_NO_LISTENER','ROOT_LOGIN_FAILED_OR_UNREACHABLE','RESTORE_IDENTITY_REJECTED','RESTORE_USER_DATABASE_EXISTS','RESTORE_LISTENER_REJECTED','RESTORE_LISTENER_PROCESS_REJECTED','UNRELATED_LISTENER_PROCESS','RESTORE_PROCESS_EXISTS','RESTORE_LISTENER_EXISTS','PROCESS_METADATA_UNAVAILABLE')
    $code=if($reason -in $allowed){$reason}else{'FRESH_RECOVERY_STOP'}
    if($stage -eq 'ROOT_LOGIN' -and $code -eq 'FRESH_RECOVERY_STOP') { $code='ROOT_LOGIN_FAILED_OR_UNREACHABLE' }
    $saved=$false
    if($null -ne $diag) {
        try {if($null -ne $launch){$launch.Refresh();if($launch.HasExited){$launch.WaitForExit();$diag['exit_code']=$launch.ExitCode}}} catch { }
        $diag['status']='FAIL';$diag['failed_step']=$stage;$diag['code']=$code;$diag['error_type']=$errorType;$diag['native_error_code']=$nativeCode
        if($stage -eq 'ROOT_LOGIN'){$diag['root_login']='FAILED_OR_IDENTITY_REJECTED'}
        try {[IO.File]::WriteAllText($cfg.Diagnostic,($diag | ConvertTo-Json -Depth 4),[Text.UTF8Encoding]::new($false));$saved=$true} catch { }
    }
    [ordered]@{status='FAIL';step=$stage;code=$code;error_type=$errorType;native_error_code=$nativeCode;acl_operation=$aclOperation;start_requested=$startRequested;diagnostic_saved=$saved;automatic_retry=$false} | ConvertTo-Json -Compress | Write-Host
    exit 1
}
if(([int]$PreflightOnly.IsPresent+[int]$Execute.IsPresent) -ne 1) { throw 'Choose exactly one mode' }
if($Execute -and $ConfirmTarget -cne 'mysql-3307-fresh-recovery') { throw 'Explicit fresh password reset confirmation required' }
if($PSVersionTable.PSEdition -ne 'Desktop' -or $PSVersionTable.PSVersion.Major -ne 5) { throw 'Windows PowerShell 5.1 required' }
. (Join-Path $PSScriptRoot 'lib\mysql-3307-recovery-acl.ps1')
. (Join-Path $PSScriptRoot 'lib\mysql-3307-startup-safety.ps1')
. (Join-Path $PSScriptRoot 'lib\mysql-3307-normal-startup.ps1')
. (Join-Path $PSScriptRoot 'lib\mysql-3307-fresh-recovery.ps1')
$cfg=Get-Restore3307FreshConfig
$workingDirectory=$cfg.WorkingDirectory
$server=Join-Path $workingDirectory 'mysqld.exe'
$stage='TARGET_METADATA'
foreach($path in @($workingDirectory,$server,(Join-Path $workingDirectory 'mysql.exe'),$cfg.Datadir)) {
    $item=Get-Item -LiteralPath $path -Force -ErrorAction Stop
    if($item.Attributes -band [IO.FileAttributes]::ReparsePoint) { throw 'Target reparse point rejected' }
}
if($workingDirectory -match '[^\x00-\x7F]' -or [Diagnostics.FileVersionInfo]::GetVersionInfo($server).ProductVersion -notmatch '^8\.4\.9(?:[.\s-]|$)') { throw 'Working directory/version rejected' }
$uuidPath=Join-Path $cfg.Datadir 'auto.cnf'
if((Get-Item -LiteralPath $uuidPath -Force).Attributes -band [IO.FileAttributes]::ReparsePoint) { throw 'UUID file reparse point rejected' }
$uuids=[regex]::Matches([IO.File]::ReadAllText($uuidPath),'(?im)^server-uuid\s*=\s*([a-f0-9-]+)\s*$')
if($uuids.Count -ne 1 -or $uuids[0].Groups[1].Value -cne $cfg.Uuid -or $cfg.Uuid -eq '77218fbd-d66d-11f1-9a0a-5c2886f057d5') { throw 'Restore UUID rejected' }
$stage='STOPPED_CHECK'
Assert-Restore3307Stopped
$stage='FRESH_ACL'
Assert-PrivateRecoveryAcl $cfg.Folder $true
Assert-PrivateRecoveryAcl $cfg.InitFile $false
$snapshot=Get-Item -LiteralPath $cfg.InitFile -Force
if($snapshot.Length -le 0) { throw 'Fresh file empty' }
$stage='PREPARED_MARKER'
if((Get-Item -LiteralPath $cfg.Prepared -Force).Attributes -band [IO.FileAttributes]::ReparsePoint) { throw 'Prepared marker reparse point rejected' }
$prepared=[IO.File]::ReadAllText($cfg.Prepared) | ConvertFrom-Json
Assert-Restore3307FreshMarker $prepared $cfg
$stage='REPLAY_GUARD'
Assert-Restore3307FreshArtifactsUnused $cfg
if($PreflightOnly) {
    [ordered]@{status='PASS';mode='READ_ONLY_PREFLIGHT';port=3307;stopped=$true;uuid=$cfg.Uuid;working_directory=$workingDirectory;recovery_file=$cfg.InitFile;acl='PASS';prepared_marker='PASS';unused_attempt='PASS';files_created=$false;server_started=$false} | ConvertTo-Json -Compress | Write-Host
    return
}
$stage='NEW_ATTEMPT_CLAIM'
$claim=[IO.File]::Open($cfg.Attempt,[IO.FileMode]::CreateNew,[IO.FileAccess]::Write,[IO.FileShare]::None)
$claim.Dispose()
Set-PrivateRecoveryAcl $cfg.Attempt $false
[IO.File]::WriteAllText($cfg.Attempt,([ordered]@{mode='FRESH_RECOVERY';port=3307;datadir=$cfg.Datadir;recovery_file=$cfg.InitFile;working_directory=$workingDirectory;attempt_utc=[DateTime]::UtcNow.ToString('o');state='START_REQUESTED_NO_AUTO_RETRY'} | ConvertTo-Json),[Text.UTF8Encoding]::new($false))
$stage='NEW_DIAGNOSTIC_FILES'
foreach($path in @($cfg.Diagnostic,$cfg.ErrorLog)) {
    [void](New-Item -ItemType File -Path $path)
    Set-PrivateRecoveryAcl $path $false
}
$diag=[ordered]@{status='PENDING';mode='FRESH_RECOVERY';port=3307;uuid=$cfg.Uuid;datadir=$cfg.Datadir;working_directory=$workingDirectory;launch_pid=$null;listener_pid=$null;exit_code=$null;state='PREPARED';root_login='NOT_ATTEMPTED';server_error_log=$cfg.ErrorLog;automatic_retry=$false}
function Save-FreshRecoveryDiagnostic { [IO.File]::WriteAllText($cfg.Diagnostic,($diag | ConvertTo-Json -Depth 4),[Text.UTF8Encoding]::new($false)) }
Save-FreshRecoveryDiagnostic
$stage='FINAL_STARTUP_CHECK'
Assert-Restore3307Stopped
Assert-PrivateRecoveryAcl $cfg.Folder $true
Assert-PrivateRecoveryAcl $cfg.InitFile $false
$current=Get-Item -LiteralPath $cfg.InitFile -Force
if($current.Length -ne $snapshot.Length -or $current.LastWriteTimeUtc -ne $snapshot.LastWriteTimeUtc -or $current.CreationTimeUtc -ne $snapshot.CreationTimeUtc) { throw 'Fresh file metadata changed' }
$stage='START_FRESH_RECOVERY'
$arguments=Get-Restore3307Arguments $cfg.InitFile $cfg.ErrorLog
$startRequested=$true
$launch=Start-Process -FilePath $server -ArgumentList $arguments -WorkingDirectory $workingDirectory -WindowStyle Hidden -PassThru
$diag['launch_pid']=$launch.Id;$diag['state']='PROCESS_LAUNCHED'
Save-FreshRecoveryDiagnostic
$stage='WAIT_LISTENER'
Wait-Restore3307NormalListener $launch $diag
Save-FreshRecoveryDiagnostic
$stage='ROOT_LOGIN'
$diag['root_login']='PROMPTING'
Save-FreshRecoveryDiagnostic
$identity=Read-Restore3307RootIdentity
$diag['status']='PASS';$diag['root_login']='PASS';$diag['state']='ROOT_LOGIN_VERIFIED'
Save-FreshRecoveryDiagnostic
[ordered]@{status='PASS';code='FRESH_RECOVERY_ROOT_LOGIN_VERIFIED';port=$identity.port;uuid=$identity.uuid;datadir=$identity.datadir;version=$identity.version;account=$identity.account;databases=$identity.databases;launch_pid=$diag['launch_pid'];listener_pid=$diag['listener_pid'];exit_code=$diag['exit_code'];diagnostic_file=$cfg.Diagnostic} | ConvertTo-Json -Compress | Write-Host
Write-Host 'STOP. No cleanup, normal restart, Restore or Migration follows automatically. Preserve both attempts; review fresh init-file cleanup separately.'
