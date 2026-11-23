#requires -Version 5.1
Set-StrictMode -Version Latest
$ErrorActionPreference='Stop'
. (Join-Path $PSScriptRoot '..\scripts\lib\mysql-3307-shutdown-safety.ps1')
$checks=0
function Check([bool]$Ok,[string]$Label){if(!$Ok){throw ('FAIL '+$Label)};$script:checks++}
function Reject([scriptblock]$Action){try{& $Action;return $false}catch{return $true}}
$cmd='"mysqld.exe" --datadir="C:\Users\User\mysql-restore-006-data" --port=3307 --bind-address=127.0.0.1 --log-error="C:/Users/User/mysql-3307-recovery-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa/log.err"'
Check ((Get-Restore3307Option $cmd 'datadir') -eq 'C:\Users\User\mysql-restore-006-data') 'quoted option value parsed'
Check ((Get-Restore3307Option 'mysqld "--datadir=C:\Users\User\mysql-restore-006-data"' 'datadir') -eq 'C:\Users\User\mysql-restore-006-data') 'whole-token quotes parsed'
Check (Reject {Get-Restore3307Option ($cmd+' --port=3306') 'port'}) 'duplicate options rejected'
$process=[pscustomobject]@{Name='mysqld.exe';CreationDate=[DateTime]::UtcNow;ExecutablePath='C:\Program Files\MySQL\MySQL Server 8.4\bin\mysqld.exe';CommandLine=$cmd}
Assert-Restore3307Process $process
Check $true 'restore process accepted'
$process.CommandLine=$cmd.Replace('--port=3307','--port=3306')
Check (Reject {Assert-Restore3307Process $process}) 'primary port rejected'
$process.CommandLine=$cmd.Replace('mysql-restore-006-data','mysql-data')
Check (Reject {Assert-Restore3307Process $process}) 'primary datadir rejected'
$process.CommandLine=$null
Check (Reject {Assert-Restore3307Process $process}) 'invisible command line fails closed'
$base=[pscustomobject]@{server_pid=222;monitor_pid=111;server_created='server-start';monitor_created='monitor-start';server_command=$cmd;monitor_command=$cmd;uuid='restore-uuid';datadir='restore-data';event_name='mysqld111_shutdown';pid_file='pid';log_path='log';log_created='created';log_offset=0}
$same=$base.PSObject.Copy();$same.log_offset=500
Assert-Restore3307SameSnapshot $base $same
Check $true 'normal log growth does not change process identity'
foreach($field in @('server_pid','monitor_pid','server_created','monitor_created','server_command','uuid','datadir','event_name','log_path','log_created')) {
    $changed=$base.PSObject.Copy();$changed.$field='DIFFERENT'
    Check (Reject {Assert-Restore3307SameSnapshot $base $changed}) ('changed identity blocks signal: '+$field)
}
$scriptPath=Join-Path $PSScriptRoot '..\scripts\stop-mysql-3307-gracefully.ps1'
$tokens=$null;$errors=$null;$ast=[System.Management.Automation.Language.Parser]::ParseFile($scriptPath,[ref]$tokens,[ref]$errors)
Check ($errors.Count -eq 0) 'main script syntax'
$tries=@($ast.FindAll({param($n) $n -is [System.Management.Automation.Language.TryStatementAst] -and $n.Body.Extent.Text.Contains('$event=Open-Restore3307ShutdownEvent')},$true))
Check ($tries.Count -eq 1) 'single guarded signal block'
$signalBlock=[scriptblock]::Create($tries[0].Extent.Text)
$script:fakeEvent=[pscustomobject]@{Signals=0;Disposed=$false}
$script:fakeEvent | Add-Member -MemberType ScriptMethod -Name Set -Value {$this.Signals++;return $true}
$script:fakeEvent | Add-Member -MemberType ScriptMethod -Name Dispose -Value {$this.Disposed=$true}
$script:snapshotCalls=0;$script:changeAt=0
function Open-Restore3307ShutdownEvent {param($Name) return $script:fakeEvent}
function Get-Restore3307ShutdownSnapshot {
    $script:snapshotCalls++
    $result=$base.PSObject.Copy()
    if($script:changeAt -gt 0 -and $script:snapshotCalls -ge $script:changeAt){$result.server_created='PID_REUSED'}
    return $result
}
$PreflightOnly=$true;$snapshot=$base;$event=$null;$signalSent=$false
& $signalBlock
Check ($script:fakeEvent.Signals -eq 0 -and $script:fakeEvent.Disposed) 'preflight opens/disposes mock handle without signaling'
$PreflightOnly=$false;$script:changeAt=2;$script:snapshotCalls=0;$script:fakeEvent.Disposed=$false
Check (Reject {& $signalBlock}) 'identity changed at last recheck rejects signal'
Check ($script:fakeEvent.Signals -eq 0 -and $script:fakeEvent.Disposed) 'changed identity cannot signal'
$script:changeAt=0;$script:snapshotCalls=0
& $signalBlock
Check ($script:fakeEvent.Signals -eq 1) 'exact stable identity signals once in mock only'

$fixture=Join-Path $PSScriptRoot ('shutdown-log-fixture-'+[guid]::NewGuid().ToString('N')+'.err')
try {
    [IO.File]::WriteAllText($fixture,'[System] [MY-010910] [Server] Shutdown complete'+[Environment]::NewLine)
    $item=Get-Item -LiteralPath $fixture
    $logSnapshot=[pscustomobject]@{log_path=$fixture;log_created=$item.CreationTimeUtc.ToString('o');log_offset=$item.Length}
    Check (!(Test-Restore3307ShutdownLog $logSnapshot)) 'old shutdown line cannot prove new shutdown'
    [IO.File]::AppendAllText($fixture,'[System] [MY-010910] [Server] Shutdown complete'+[Environment]::NewLine)
    Check (Test-Restore3307ShutdownLog $logSnapshot) 'new appended shutdown completion recognized'
    [IO.File]::WriteAllText($fixture,'')
    Check (Reject {Test-Restore3307ShutdownLog $logSnapshot}) 'truncated log fails closed'
} finally {if(Test-Path -LiteralPath $fixture){Remove-Item -LiteralPath $fixture -Force}}
$script:remaining=@();$script:listeners=@();$script:complete=$true
function Get-CimInstance {param($ClassName,$Filter,$ErrorAction) return $script:remaining}
function Get-NetTCPConnection {param($State,$ErrorAction) return $script:listeners}
function Test-Restore3307ShutdownLog {param($Snapshot) return $script:complete}
Wait-Restore3307GracefulShutdown $base 0
Check $true 'gone processes/listener and fresh log required for success'
$script:complete=$false
Check (Reject {Wait-Restore3307GracefulShutdown $base 0}) 'gone processes without completion log are not verified'
$script:complete=$true;$script:listeners=@([pscustomobject]@{LocalPort=3307})
Check (Reject {Wait-Restore3307GracefulShutdown $base 0}) 'remaining listener times out without kill'
$source=[IO.File]::ReadAllText($scriptPath)
$commands=@($ast.FindAll({param($n) $n -is [System.Management.Automation.Language.CommandAst]},$true))
Check (@($commands | Where-Object {$_.GetCommandName() -in @('Start-Process','Stop-Process','Stop-Service','Remove-Item','mysql.exe','mysqld.exe')}).Count -eq 0) 'no startup/kill/delete/SQL actions'
Check ($source.Contains("-cne 'mysql-3307-shutdown'") -and $source.Contains('Choose exactly one mode')) 'explicit mode and target approval required'
Check (!$source.Contains('17392') -and !$source.Contains('11516')) 'no stale hardcoded PIDs'
Write-Output ('PASS: '+$checks+' shutdown offline checks; mock event only; no Windows event was signaled')
