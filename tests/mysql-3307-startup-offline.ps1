#requires -Version 5.1
Set-StrictMode -Version Latest
$ErrorActionPreference='Stop'
. (Join-Path $PSScriptRoot '..\scripts\lib\mysql-3307-startup-safety.ps1')
$checks=0
function Check([bool]$Ok,[string]$Label) { if(!$Ok){throw ('FAIL '+$Label)};$script:checks++ }
function Reject([scriptblock]$Action) { try{& $Action;return $false}catch{return $true} }
$arguments=@(Get-Restore3307Arguments 'C:\Private Folder\root-init.sql' 'C:\Private Folder\server.err')
Check ($arguments[0] -eq '--no-defaults' -and $arguments -contains '--persisted-globals-load=OFF' -and $arguments -contains '--port=3307' -and $arguments -contains '--bind-address=127.0.0.1') 'explicit isolated target flags and no persisted overrides'
Check ($arguments -contains '--datadir="C:\Users\User\mysql-restore-006-data"') 'fixed restore datadir'
Check ($arguments -contains '--general-log=OFF' -and $arguments -contains '--slow-query-log=OFF' -and $arguments -contains '--skip-log-bin' -and $arguments -contains '--log-raw=OFF') 'sensitive query logging disabled'
Check (!(($arguments -join ' ') -match '--password|--skip-grant-tables|--initialize|--port=3306')) 'no secret, bypass or primary arguments'
Check ($arguments -contains '--init-file="C:/Private Folder/root-init.sql"') 'paths quoted without secret interpolation'
$script:mockProcesses=@();$script:mockListeners=@();$script:mockOwner=$null
function Get-CimInstance { param($ClassName,$Filter,$ErrorAction) if($Filter -like 'ProcessId=*'){return $script:mockOwner};return $script:mockProcesses }
function Get-NetTCPConnection { param($State,$ErrorAction) return $script:mockListeners }
Assert-Restore3307Stopped
Check $true 'stopped target passes'
$script:mockProcesses=@([pscustomobject]@{CommandLine='mysqld --port=3307'})
Check (Reject {Assert-Restore3307Stopped}) 'existing restore process blocks startup'
$script:mockProcesses=@([pscustomobject]@{CommandLine=$null})
Check (Reject {Assert-Restore3307Stopped}) 'invisible process metadata fails closed'
$script:mockProcesses=@();$script:mockListeners=@([pscustomobject]@{LocalPort=3307;LocalAddress='127.0.0.1';OwningProcess=99})
Check (Reject {Assert-Restore3307Stopped}) 'occupied port blocks startup'
$script:mockOwner=[pscustomobject]@{ExecutablePath='C:\Program Files\MySQL\MySQL Server 8.4\bin\mysqld.exe';CommandLine='mysqld --port=3307 --datadir="C:\Users\User\mysql-restore-006-data"';ParentProcessId=42}
Check ((Assert-Restore3307Listener 42) -eq 99) 'monitor child listener belongs to launch'
Check (Reject {Assert-Restore3307Listener 123}) 'unrelated listener rejected'
$script:mockListeners[0].LocalAddress='0.0.0.0'
Check (Reject {Assert-Restore3307Listener}) 'non-loopback listener rejected'
$identity=[pscustomobject]@{port=3307;uuid='37e4d222-dda2-11f1-b503-5c2886f057d5';datadir='C:\Users\User\mysql-restore-006-data\';version='8.4.9';account='root@localhost';databases=@('mysql','sys','information_schema','performance_schema')}
Assert-Restore3307Identity $identity
Check $true 'expected SQL identity and system databases pass'
foreach($case in @(@{Key='port';Value=3306},@{Key='uuid';Value='77218fbd-d66d-11f1-9a0a-5c2886f057d5'},@{Key='datadir';Value='C:\Users\User\mysql-data'},@{Key='version';Value='8.4.8'},@{Key='account';Value='root@127.0.0.1'},@{Key='databases';Value=@('mysql','up_van_booking_test')})) {
    $saved=$identity.($case.Key);$identity.($case.Key)=$case.Value
    Check (Reject {Assert-Restore3307Identity $identity}) ('wrong SQL identity rejected: '+$case.Key)
    $identity.($case.Key)=$saved
}
$source=[IO.File]::ReadAllText((Join-Path $PSScriptRoot '..\scripts\start-mysql-3307-root-recovery.ps1'))
Check ($source.Contains('[IO.FileMode]::CreateNew') -and $source.Contains('Prior startup attempt exists')) 'exclusive attempt marker prevents replay'
Check ($source.Contains("-cne 'mysql-3307-restore'") -and $source.Contains('Choose exactly one mode')) 'explicit confirmation and mutually exclusive modes'
Check ($source.IndexOf('if($PreflightOnly)') -lt $source.IndexOf('[IO.File]::Open($attemptFile')) 'preflight exits before mutation'
Check ($source.Contains('--user=root --password --connect-timeout=5') -and !$source.Contains('ReadAllText($initFile)') -and !$source.Contains('Get-Content')) 'hidden password prompt and no SQL content read'
Write-Output ('PASS: '+$checks+' startup safety offline checks; mocks only; no MySQL startup/login')
