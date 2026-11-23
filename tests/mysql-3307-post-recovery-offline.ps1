#requires -Version 5.1
Set-StrictMode -Version Latest
$ErrorActionPreference='Stop'
. (Join-Path $PSScriptRoot '..\scripts\lib\mysql-3307-fresh-recovery.ps1')
. (Join-Path $PSScriptRoot '..\scripts\lib\mysql-3307-shutdown-safety.ps1')
. (Join-Path $PSScriptRoot '..\scripts\lib\mysql-3307-post-recovery.ps1')
$count=0
function Check([bool]$Ok,[string]$Name) {if(!$Ok){throw ('FAIL '+$Name)};$script:count++}
function Reject([scriptblock]$Action) {try{& $Action;return $false}catch{return $true}}
$cfg=Get-Restore3307FreshConfig
$proof=[pscustomobject]@{status='PASS';mode='FRESH_RECOVERY';port=3307;uuid=$cfg.Uuid;datadir=$cfg.Datadir;state='ROOT_LOGIN_VERIFIED';root_login='PASS';launch_pid=23104;listener_pid=14200}
Assert-Restore3307RecoverySuccess $proof $cfg
Check $true 'successful pinned proof accepted'
foreach($field in @('status','mode','port','uuid','datadir','state','root_login','launch_pid','listener_pid')) {
    $copy=$proof | ConvertTo-Json | ConvertFrom-Json
    $copy.PSObject.Properties.Remove($field)
    Check (Reject {Assert-Restore3307RecoverySuccess $copy $cfg}) ('missing proof field '+$field)
}
$bad=$cfg | ConvertTo-Json | ConvertFrom-Json;$bad.InitFile='C:\Other\root-init.sql'
Check (Reject {Assert-Restore3307RecoverySuccess $proof $bad}) 'different cleanup path rejected'
$badProof=$proof | ConvertTo-Json | ConvertFrom-Json;$badProof.root_login='FAIL'
Check (Reject {Assert-Restore3307RecoverySuccess $badProof $cfg}) 'failed login proof rejected'
# Metadata mock only: no SQL file is opened, changed or deleted by this test.
function Get-Item {param($LiteralPath) [pscustomobject]@{LastWriteTimeUtc=[datetime]'2026-11-11T10:00:00Z'}}
$cmd='mysqld.exe --init-file="'+$cfg.InitFile.Replace('\','/')+'"'
$snap=[pscustomobject]@{server_pid=14200;monitor_pid=23104;uuid=$cfg.Uuid;datadir=$cfg.Datadir;server_created='2026-11-11T09:24:32Z';monitor_created='2026-11-11T09:24:31Z';server_command=$cmd;monitor_command=$cmd}
Assert-Restore3307CleanupTarget $proof $snap $cfg
Check $true 'matching active successful instance accepted'
$snap.server_pid=999
Check (Reject {Assert-Restore3307CleanupTarget $proof $snap $cfg}) 'changed process rejected'
$snap.server_pid=14200;$snap.server_created='2026-11-12T09:00:00Z'
Check (Reject {Assert-Restore3307CleanupTarget $proof $snap $cfg}) 'PID reuse after proof rejected'
$snap.server_created='2026-11-11T09:24:32Z';$snap.server_command='mysqld.exe --init-file=C:\Other\root-init.sql'
Check (Reject {Assert-Restore3307CleanupTarget $proof $snap $cfg}) 'wrong active init file rejected'
$snap.server_command='mysqld.exe --port=3307';$snap.monitor_command=$snap.server_command
Assert-Restore3307NormalCommandLines $snap
Check $true 'normal actual process options accepted'
foreach($option in @('--init-file=C:\Private\file.sql','"--init-file=C:\Private\file.sql"','--init-file "C:\Private\file.sql"')) {
    $snap.server_command='mysqld.exe '+$option
    Check (Reject {Assert-Restore3307NormalCommandLines $snap}) 'recovery option in actual process rejected'
}
$metadata=@([pscustomobject]@{path='marker';length=5;modified='unchanged'})
Assert-Restore3307AuditUnchanged $metadata $metadata
Check $true 'unchanged audit accepted'
Check (Reject {Assert-Restore3307AuditUnchanged $metadata @([pscustomobject]@{path='marker';length=6;modified='changed'})}) 'changed audit rejected'
function Read-Restore3307RecoverySuccess {param($Config) return $proof}
$script:initRemains=$true
function Test-Path {param($LiteralPath) return $script:initRemains}
Check (Reject {Assert-Restore3307PostNormalReady $cfg}) 'normal startup blocked until init file removed'
$script:initRemains=$false
Assert-Restore3307PostNormalReady $cfg
Check $true 'normal startup gate permits removed init file'
$cleanupPath=Join-Path $PSScriptRoot '..\scripts\cleanup-mysql-3307-fresh-init.ps1'
$source=[IO.File]::ReadAllText($cleanupPath)
$tokens=$null;$errors=$null
$ast=[Management.Automation.Language.Parser]::ParseFile($cleanupPath,[ref]$tokens,[ref]$errors)
Check ($errors.Count -eq 0) 'cleanup Windows PowerShell syntax'
$commands=@($ast.FindAll({param($n) $n -is [Management.Automation.Language.CommandAst]},$true))
$removes=@($commands | Where-Object {$_.GetCommandName() -eq 'Remove-Item'})
Check ($removes.Count -eq 1 -and $removes[0].Extent.Text.Contains('-LiteralPath $cfg.InitFile') -and !$removes[0].Extent.Text.Contains('-Recurse')) 'one exact nonrecursive init deletion only'
Check (@($commands | Where-Object {$_.GetCommandName() -in @('Start-Process','Stop-Process','Stop-Service','Restart-Service','Set-Content','Set-Acl')}).Count -eq 0) 'cleanup has no startup stop SQL or ACL modifications'
Check ($source.IndexOf('if($PreflightOnly)') -lt $source.IndexOf('Remove-Item')) 'preflight returns before deletion'
Check (!$source.Contains('ReadAllText') -and !$source.Contains('Get-Content')) 'cleanup never reads SQL contents'
Check ($source.Contains('Assert-Restore3307SameSnapshot') -and $source.Contains('-cne ''mysql-3307-fresh-init-cleanup''')) 'immediate identity recheck and explicit confirmation'
$normal=[IO.File]::ReadAllText((Join-Path $PSScriptRoot '..\scripts\start-mysql-3307-normal.ps1'))
Check ($normal.Contains('if($PostRecovery) { $postConfig=Get-Restore3307FreshConfig;$folder=$postConfig.Folder }')) 'post recovery normal startup uses fresh folder instead of old lock'
Check ($normal.IndexOf('Assert-Restore3307PostNormalReady') -lt $normal.IndexOf('[IO.File]::Open($lockPath')) 'post recovery gate precedes startup writes'
Check ($normal.Contains('Assert-Restore3307NormalCommandLines (Get-Restore3307ShutdownSnapshot)')) 'actual normal process checked before root login'
Remove-Item Function:\Get-Item
# Isolated dummy metadata files only. Mock the pinned proof and process inspector;
# their production checks have already been exercised separately above.
function Assert-Restore3307RecoverySuccess {param($Proof,$Config)}
$script:running=$false
function Assert-Restore3307Stopped {if($script:running){throw 'RESTORE_PROCESS_EXISTS'}}
$fixture=Join-Path $env:TEMP ('mysql3307-offline-'+[guid]::NewGuid().ToString('N'))
[void][IO.Directory]::CreateDirectory($fixture)
$fake=[pscustomobject]@{Datadir=$fixture;Uuid=$cfg.Uuid}
$auto=Join-Path $fixture 'auto.cnf';$pidFile=Join-Path $fixture 'test.pid'
try {
    [IO.File]::WriteAllText($auto,('[auto]'+[Environment]::NewLine+'server-uuid='+$cfg.Uuid))
    $stopped=Get-Restore3307StoppedCleanupSnapshot $proof $fake
    Check ($stopped.stopped -and $stopped.pid_file_count -eq 0) 'stopped instance without PID file accepted'
    [IO.File]::WriteAllText($pidFile,'14200')
    $stopped=Get-Restore3307StoppedCleanupSnapshot $proof $fake
    Check ($stopped.stale_pid -eq '14200') 'stale successful PID metadata accepted without deleting it'
    [IO.File]::WriteAllText($pidFile,'999')
    Check (Reject {Get-Restore3307StoppedCleanupSnapshot $proof $fake}) 'unrelated stale PID rejected'
    [IO.File]::WriteAllText($pidFile,'14200')
    [IO.File]::WriteAllText($auto,'server-uuid=wrong')
    Check (Reject {Get-Restore3307StoppedCleanupSnapshot $proof $fake}) 'wrong stopped UUID rejected'
    $script:running=$true
    Check (Reject {Get-Restore3307StoppedCleanupSnapshot $proof $fake}) 'stopped cleanup rejects new process before metadata access'
} finally {
    foreach($dummy in @($auto,$pidFile)){if([IO.File]::Exists($dummy)){[IO.File]::Delete($dummy)}}
    [IO.Directory]::Delete($fixture)
}
Write-Output ('PASS: '+$count+' post-recovery offline checks; no server operation or recovery file access executed')
