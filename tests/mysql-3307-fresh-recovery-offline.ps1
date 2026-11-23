#requires -Version 5.1
Set-StrictMode -Version Latest
$ErrorActionPreference='Stop'
. (Join-Path $PSScriptRoot '..\scripts\lib\mysql-3307-fresh-recovery.ps1')
. (Join-Path $PSScriptRoot '..\scripts\lib\mysql-3307-startup-safety.ps1')
$checks=0
function Check([bool]$Ok,[string]$Name){if(!$Ok){throw ('FAIL '+$Name)};$script:checks++}
function Reject([scriptblock]$Action){try{& $Action;return $false}catch{return $true}}
$cfg=Get-Restore3307FreshConfig
$expected='C:\Users\User\mysql-3307-recovery-2597af28af234664ad619cbdda6136ad\root-init.sql'
Check ($cfg.InitFile -ceq $expected) 'exact fresh file pin'
Check ($cfg.Attempt -ceq (Join-Path $cfg.Folder 'startup-attempt.json')) 'fresh marker in same fresh directory'
$marker=[pscustomobject]@{status='RECOVERY_FILE_PREPARED';target_port=3307;datadir=$cfg.Datadir;recovery_file=$cfg.InitFile;acl='CURRENT_USER_AND_SYSTEM_ONLY'}
Assert-Restore3307FreshMarker $marker $cfg
Check $true 'valid preparation accepted'
foreach($field in @('Folder','InitFile','Prepared','Attempt','Diagnostic','ErrorLog','Datadir','Uuid','WorkingDirectory')) {
    $wrong=$cfg.PSObject.Copy();$wrong.$field='UNAPPROVED_TARGET'
    Check (Reject {Assert-Restore3307FreshMarker $marker $wrong}) ('config override rejected: '+$field)
}
foreach($case in @(@{Key='status';Value='FAILED'},@{Key='target_port';Value=3306},@{Key='datadir';Value='C:\Users\User\mysql-data'},@{Key='recovery_file';Value='C:\Users\User\mysql-3307-recovery-80da7a7f45c24543a25a983b20c014da\root-init.sql'},@{Key='acl';Value='UNVERIFIED'})) {
    $wrong=$marker.PSObject.Copy();$wrong.($case.Key)=$case.Value
    Check (Reject {Assert-Restore3307FreshMarker $wrong $cfg}) ('prepared marker mismatch rejected: '+$case.Key)
}
$script:existing=@{}
function Test-Path {param($LiteralPath) return $script:existing.ContainsKey($LiteralPath)}
Assert-Restore3307FreshArtifactsUnused $cfg
Check $true 'unused fresh attempt accepted'
foreach($path in @($cfg.Attempt,$cfg.Diagnostic,$cfg.ErrorLog)) {
    $script:existing[$path]=$true
    Check (Reject {Assert-Restore3307FreshArtifactsUnused $cfg}) 'any prior fresh startup artifact blocks retry'
    [void]$script:existing.Remove($path)
}
$arguments=@(Get-Restore3307Arguments $cfg.InitFile $cfg.ErrorLog)
Check ($arguments -contains ('--init-file="'+$cfg.InitFile.Replace('\','/')+'"')) 'only pinned fresh init file supplied'
Check ($arguments[0] -eq '--no-defaults' -and $arguments -contains '--port=3307' -and $arguments -contains '--bind-address=127.0.0.1') 'explicit restore target flags'
Check (!(($arguments -join ' ') -match '80da7a7f|--password|--initialize|--skip-grant-tables|--port=3306')) 'no legacy/reinitialization/bypass/primary arguments'
$path=Join-Path $PSScriptRoot '..\scripts\start-mysql-3307-fresh-recovery.ps1'
$source=[IO.File]::ReadAllText($path)
$tokens=$null;$issues=$null;$ast=[System.Management.Automation.Language.Parser]::ParseFile($path,[ref]$tokens,[ref]$issues)
Check ($issues.Count -eq 0) 'main syntax'
$commands=@($ast.FindAll({param($n) $n -is [System.Management.Automation.Language.CommandAst]},$true))
$starts=@($commands | Where-Object {$_.GetCommandName() -eq 'Start-Process'})
Check ($starts.Count -eq 1 -and $starts[0].Extent.Text.Contains('-WorkingDirectory $workingDirectory') -and $starts[0].Extent.Text.Contains('-WindowStyle Hidden')) 'single explicit ASCII-directory launch'
Check ($source.Contains("-cne 'mysql-3307-fresh-recovery'") -and $source.Contains('Choose exactly one mode')) 'explicit new approval and exclusive modes'
Check ($source.IndexOf('if($PreflightOnly)') -lt $source.IndexOf('[IO.File]::Open($cfg.Attempt')) 'read-only mode returns before mutation'
Check ($source.Contains('[IO.FileMode]::CreateNew') -and $source.Contains('Assert-Restore3307FreshArtifactsUnused')) 'exclusive marker and fail-closed replay guard'
Check (@($commands | Where-Object {$_.GetCommandName() -in @('Remove-Item','Stop-Process','Stop-Service','Get-Content')}).Count -eq 0) 'no deletion, kill or SQL content read'
$reads=@($ast.FindAll({param($n) $n -is [System.Management.Automation.Language.InvokeMemberExpressionAst] -and $n.Member.Extent.Text -eq 'ReadAllText'},$true))
Check ($reads.Count -eq 2 -and !$source.Contains('ReadAllText($cfg.InitFile)')) 'only UUID and nonsecret prepared marker read'
Check ($source.Contains("diag['launch_pid']") -and $source.Contains("diag['exit_code']") -and $source.Contains('native_error_code')) 'PID/exit/native error diagnostics present'
Check ($source.Contains('Read-Restore3307RootIdentity') -and $source.Contains('Wait-Restore3307NormalListener')) 'shared hidden login and process/exit verification used'
Write-Output ('PASS: '+$checks+' fresh recovery offline checks; no SQL read, Windows process launch, login or file creation')
