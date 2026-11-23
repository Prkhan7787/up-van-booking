#requires -Version 5.1
Set-StrictMode -Version Latest
$ErrorActionPreference='Stop'
. (Join-Path $PSScriptRoot '..\scripts\lib\mysql-3307-normal-startup.ps1')
. (Join-Path $PSScriptRoot '..\scripts\lib\mysql-3307-startup-safety.ps1')
$count=0
function Check([bool]$Ok,[string]$Name) {if(!$Ok){throw ('FAIL '+$Name)};$script:count++}
function Reject([scriptblock]$Action) {try{& $Action;return $false}catch{return $true}}
$arguments=@(Get-Restore3307NormalArguments 'C:\Private Folder\normal.err')
Check ($arguments[0] -eq '--no-defaults' -and $arguments -contains '--persisted-globals-load=OFF') 'ignore config and persisted overrides'
Check ($arguments -contains '--port=3307' -and $arguments -contains '--bind-address=127.0.0.1') 'fixed isolated listener'
Check ($arguments -contains '--basedir="C:\Program Files\MySQL\MySQL Server 8.4"' -and $arguments -contains '--datadir="C:\Users\User\mysql-restore-006-data"') 'correct quoted basedir/datadir'
Check (!(($arguments -join ' ') -match 'init-file|skip-grant-tables|initialize|port=3306|password=')) 'no recovery options or secrets'
Check ($arguments -contains '--log-error="C:/Private Folder/normal.err"') 'quoted ASCII error path'
Check ((Get-Restore3307NormalWorkingDirectory) -ceq 'C:\Program Files\MySQL\MySQL Server 8.4\bin') 'explicit non-Thai working directory'
$script:listeners=@();$script:ownerPid=88;$script:rejectListener=$false
function Get-NetTCPConnection {param($State,$ErrorAction) return $script:listeners}
function Assert-Restore3307Listener {param($LaunchPid) if($script:rejectListener){throw 'UNRELATED_LISTENER_PROCESS'};if($LaunchPid -ne 77){throw 'BAD_LAUNCH_PID'};return $script:ownerPid}
function Start-Sleep {param($Milliseconds)}
$process=[pscustomobject]@{Id=77;HasExited=$true;ExitCode=23}
$process | Add-Member -MemberType ScriptMethod -Name Refresh -Value {}
$process | Add-Member -MemberType ScriptMethod -Name WaitForExit -Value {}
$diag=[ordered]@{state='NEW';listener_pid=$null;exit_code=$null}
Check (Reject {Wait-Restore3307NormalListener $process $diag}) 'early process exit stops'
Check ($diag.exit_code -eq 23 -and $diag.state -eq 'SERVER_EXITED_BEFORE_LISTENER') 'exit code recorded'
$process.HasExited=$false;$diag.exit_code=$null
Check (Reject {Wait-Restore3307NormalListener $process $diag 0}) 'timeout stops without relaunch'
Check ($diag.state -eq 'SERVER_RUNNING_NO_LISTENER' -and $null -eq $diag.exit_code) 'running process has unknown exit code'
$script:listeners=@([pscustomobject]@{LocalPort=3307})
Wait-Restore3307NormalListener $process $diag
Check ($diag.listener_pid -eq 88 -and $diag.state -eq 'LISTENER_VERIFIED') 'verified listener recorded'
$script:rejectListener=$true
Check (Reject {Wait-Restore3307NormalListener $process $diag}) 'unrelated process listener stops'
$scriptPath=Join-Path $PSScriptRoot '..\scripts\start-mysql-3307-normal.ps1'
$source=[IO.File]::ReadAllText($scriptPath)
$tokens=$null;$errors=$null;$ast=[System.Management.Automation.Language.Parser]::ParseFile($scriptPath,[ref]$tokens,[ref]$errors)
Check ($errors.Count -eq 0) 'PowerShell 5.1 syntax'
$commands=@($ast.FindAll({param($n) $n -is [System.Management.Automation.Language.CommandAst]},$true))
$launches=@($commands | Where-Object {$_.GetCommandName() -eq 'Start-Process'})
Check ($launches.Count -eq 1 -and $launches[0].Extent.Text.Contains('-WorkingDirectory $workingDirectory') -and $launches[0].Extent.Text.Contains('-WindowStyle Hidden')) 'single hidden launch with explicit directory'
Check (@($commands | Where-Object {$_.GetCommandName() -in @('Remove-Item','Stop-Process','Stop-Service','Restart-Service')}).Count -eq 0) 'no deletion or automatic server stop'
Check (!$source.Contains('startup-attempt.json') -and !$source.Contains('root-init.sql') -and !$source.Contains('Get-Restore3307Arguments ')) 'normal mode does not access recovery artifacts/arguments'
Check ($source.Contains('[IO.FileMode]::CreateNew') -and $source.Contains("-cne 'mysql-3307-normal'")) 'confirmation and exclusive normal-start lock'
Check ($source.IndexOf('if($PreflightOnly)') -lt $source.IndexOf('[IO.File]::Open($lockPath')) 'preflight returns before writes'
Check ($source.Contains("root_login']='FAILED_OR_IDENTITY_REJECTED'") -and $source.Contains("'ROOT_LOGIN_FAILED_OR_UNREACHABLE'")) 'failed root login reported without recovery retry'
$helper=[IO.File]::ReadAllText((Join-Path $PSScriptRoot '..\scripts\lib\mysql-3307-normal-startup.ps1'))
Check ($helper.Contains('--host=127.0.0.1 --port=3307 --user=root --password') -and $helper.Contains('SHOW DATABASES;')) 'one hidden-password read-only identity check'
Write-Output ('PASS: '+$count+' normal startup offline checks; no MySQL process, login or file preparation executed')
