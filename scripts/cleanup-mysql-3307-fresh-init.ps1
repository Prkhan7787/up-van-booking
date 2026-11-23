#requires -Version 5.1
# Removes only the successful pinned attempt's init file, never reads its contents.
param([switch]$PreflightOnly,[switch]$Execute,[switch]$StoppedInstance,[string]$ConfirmTarget='')
Set-StrictMode -Version Latest
$ErrorActionPreference='Stop'
$stage='ARGUMENTS';$aclOperation=$null;$deleted=$false
trap {
    $reason=$_.Exception.GetBaseException().Message
    $safe=@('POST_RECOVERY_PROOF_REJECTED','CLEANUP_INSTANCE_CHANGED','CLEANUP_PROCESS_NEWER_THAN_PROOF','CLEANUP_INIT_PATH_REJECTED','AUDIT_ARTIFACT_REJECTED','AUDIT_ARTIFACT_CHANGED','RESTORE_PROCESS_EXISTS','RESTORE_LISTENER_EXISTS','RESTORE_UUID_REJECTED','PID_FILE_REJECTED','PID_FILE_MISMATCH','STOPPED_METADATA_REJECTED','LISTENER_REJECTED','PROCESS_METADATA_UNAVAILABLE')
    $code=if($reason -in $safe){$reason}else{'INIT_CLEANUP_STOP'}
    [ordered]@{status='FAIL';step=$stage;code=$code;error_type=$_.Exception.GetBaseException().GetType().Name;file_deleted=$deleted;automatic_retry=$false} | ConvertTo-Json -Compress | Write-Host
    exit 1
}
if(([int]$PreflightOnly.IsPresent+[int]$Execute.IsPresent) -ne 1) { throw 'Choose exactly one mode' }
if($Execute -and $ConfirmTarget -cne 'mysql-3307-fresh-init-cleanup') { throw 'Explicit cleanup approval required' }
if($PSVersionTable.PSEdition -ne 'Desktop' -or $PSVersionTable.PSVersion.Major -ne 5) { throw 'Windows PowerShell 5.1 required' }
. (Join-Path $PSScriptRoot 'lib\mysql-3307-recovery-acl.ps1')
. (Join-Path $PSScriptRoot 'lib\mysql-3307-fresh-recovery.ps1')
. (Join-Path $PSScriptRoot 'lib\mysql-3307-shutdown-safety.ps1')
. (Join-Path $PSScriptRoot 'lib\mysql-3307-startup-safety.ps1')
. (Join-Path $PSScriptRoot 'lib\mysql-3307-post-recovery.ps1')
$cfg=Get-Restore3307FreshConfig
$stage='SUCCESS_PROOF'
$proof=Read-Restore3307RecoverySuccess $cfg
$audit=Get-Restore3307AuditMetadata $cfg
$stage='CURRENT_INSTANCE'
if($StoppedInstance) { $snapshot=Get-Restore3307StoppedCleanupSnapshot $proof $cfg }
else { $snapshot=Get-Restore3307ShutdownSnapshot;Assert-Restore3307CleanupTarget $proof $snapshot $cfg }
$stage='INIT_FILE_ACL'
Assert-PrivateRecoveryAcl $cfg.InitFile $false
$file=Get-Item -LiteralPath $cfg.InitFile -Force
if($file.Length -le 0) { throw 'Empty cleanup target' }
if($PreflightOnly) {
    [ordered]@{status='PASS';mode='READ_ONLY_PREFLIGHT';port=3307;server_pid=$snapshot.server_pid;monitor_pid=$snapshot.monitor_pid;uuid=$snapshot.uuid;cleanup_file=$cfg.InitFile;success_proof='PASS';acl='PASS';audit_preserved=$true;file_deleted=$false} | ConvertTo-Json -Compress | Write-Host
    return
}
$stage='FINAL_RECHECK'
if($StoppedInstance) {
    $again=Get-Restore3307StoppedCleanupSnapshot (Read-Restore3307RecoverySuccess $cfg) $cfg
    if(($snapshot | ConvertTo-Json -Compress) -cne ($again | ConvertTo-Json -Compress)) { throw 'CLEANUP_INSTANCE_CHANGED' }
} else {
    $again=Get-Restore3307ShutdownSnapshot
    Assert-Restore3307SameSnapshot $snapshot $again
    Assert-Restore3307CleanupTarget (Read-Restore3307RecoverySuccess $cfg) $again $cfg
}
Assert-PrivateRecoveryAcl $cfg.InitFile $false
$current=Get-Item -LiteralPath $cfg.InitFile -Force
if($current.Length -ne $file.Length -or $current.CreationTimeUtc -ne $file.CreationTimeUtc -or $current.LastWriteTimeUtc -ne $file.LastWriteTimeUtc) { throw 'Cleanup target metadata changed' }
Assert-Restore3307AuditUnchanged $audit (Get-Restore3307AuditMetadata $cfg)
$stage='DELETE_SUCCESSFUL_INIT_FILE'
Remove-Item -LiteralPath $cfg.InitFile -Force -ErrorAction Stop
$deleted=$true
if(Test-Path -LiteralPath $cfg.InitFile) { throw 'Init file still exists' }
$stage='VERIFY_AUDIT_PRESERVED'
Assert-Restore3307AuditUnchanged $audit (Get-Restore3307AuditMetadata $cfg)
[ordered]@{status='PASS';code='SUCCESSFUL_INIT_FILE_REMOVED';port=3307;file_deleted=$true;audit_preserved=$true;server_stopped=$StoppedInstance.IsPresent} | ConvertTo-Json -Compress | Write-Host
Write-Host 'STOP. Proceed to separately approved graceful shutdown and normal restart. Do not issue RESTART while the old init-file option remains configured.'
