#requires -Version 5.1
# Version-pinned MySQL shutdown event only. No SQL, process kill, startup or file deletion.
param([switch]$PreflightOnly,[switch]$Execute,[string]$ConfirmTarget='', [ValidateRange(1,300)][int]$TimeoutSeconds=60)
Set-StrictMode -Version Latest
$ErrorActionPreference='Stop'
$stage='ARGUMENTS';$signalSent=$false;$aclOperation=$null;$event=$null
trap {
    $reason=$_.Exception.GetBaseException().Message
    $safeCodes=@('COMMAND_OPTION_AMBIGUOUS','COMMAND_OPTION_UNREADABLE','PROCESS_IDENTITY_REJECTED','PROCESS_TARGET_REJECTED','PROCESS_DATADIR_REJECTED','LISTENER_REJECTED','MONITOR_RELATIONSHIP_REJECTED','MYSQL_VERSION_REJECTED','DATADIR_REPARSE_REJECTED','UUID_FILE_REPARSE_REJECTED','RESTORE_UUID_REJECTED','PID_FILE_REJECTED','PID_FILE_MISMATCH','LOG_PATH_REJECTED','MONITOR_LOG_MISMATCH','TARGET_CHANGED_BEFORE_SIGNAL','EVENT_NAME_REJECTED','SHUTDOWN_LOG_CHANGED','SHUTDOWN_LOG_LIMIT','PROCESS_METADATA_UNAVAILABLE','GRACEFUL_SHUTDOWN_NOT_VERIFIED')
    $code=if($reason -in $safeCodes){$reason}else{'GRACEFUL_SHUTDOWN_STOP'}
    [ordered]@{status='FAIL';step=$stage;code=$code;error_type=$_.Exception.GetBaseException().GetType().Name;signal_sent=$signalSent;automatic_retry=$false;kill_attempted=$false} | ConvertTo-Json -Compress | Write-Host
    exit 1
}
if(([int]$PreflightOnly.IsPresent+[int]$Execute.IsPresent) -ne 1) { throw 'Choose exactly one mode' }
if($Execute -and $ConfirmTarget -cne 'mysql-3307-shutdown') { throw 'Explicit shutdown confirmation required' }
if($PSVersionTable.PSEdition -ne 'Desktop' -or $PSVersionTable.PSVersion.Major -ne 5) { throw 'Windows PowerShell 5.1 required' }
. (Join-Path $PSScriptRoot 'lib\mysql-3307-recovery-acl.ps1')
. (Join-Path $PSScriptRoot 'lib\mysql-3307-shutdown-safety.ps1')
$stage='TARGET_SNAPSHOT'
$snapshot=Get-Restore3307ShutdownSnapshot
try {
    $stage='EVENT_ACCESS_CHECK'
    $event=Open-Restore3307ShutdownEvent $snapshot.event_name
    $stage='REVALIDATE_TARGET'
    $fresh=Get-Restore3307ShutdownSnapshot
    Assert-Restore3307SameSnapshot $snapshot $fresh
    if($PreflightOnly) {
        [ordered]@{status='PASS';mode='READ_ONLY_PREFLIGHT';port=3307;server_pid=$fresh.server_pid;monitor_pid=$fresh.monitor_pid;datadir=$fresh.datadir;uuid=$fresh.uuid;event=$fresh.event_name;event_access='SYNCHRONIZE_AND_MODIFY_OPENED_ONLY';acl='PASS';signal_sent=$false;files_changed=$false} | ConvertTo-Json -Compress | Write-Host
        return
    }
    $stage='FINAL_IDENTITY_CHECK'
    $last=Get-Restore3307ShutdownSnapshot
    Assert-Restore3307SameSnapshot $snapshot $last
    # Compare PID creation times/arguments/UUID/log identity immediately before Set.
    # Use only newly appended shutdown evidence, never an old shutdown-complete line.
    $snapshot=$last
    $stage='SIGNAL_SHUTDOWN'
    $signalSent=$event.Set()
    if(!$signalSent) { throw 'Shutdown signal failed' }
} finally { if($event){$event.Dispose();$event=$null} }
$stage='VERIFY_GRACEFUL_SHUTDOWN'
Wait-Restore3307GracefulShutdown $snapshot $TimeoutSeconds
[ordered]@{status='PASS';code='RESTORE_3307_GRACEFUL_SHUTDOWN_VERIFIED';port=3307;server_pid=$snapshot.server_pid;monitor_pid=$snapshot.monitor_pid;signal_sent=$true;processes_gone=$true;listener_gone=$true;new_shutdown_complete_log=$true;kill_attempted=$false} | ConvertTo-Json -Compress | Write-Host
