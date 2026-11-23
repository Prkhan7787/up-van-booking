Set-StrictMode -Version Latest

function Get-Restore3307Processes {
    $all=@(Get-CimInstance Win32_Process -Filter "Name='mysqld.exe'" -ErrorAction Stop)
    if(@($all | Where-Object { -not $_.CommandLine }).Count) { throw 'PROCESS_METADATA_UNAVAILABLE' }
    return @($all | Where-Object { $_.CommandLine.Contains('C:\Users\User\mysql-restore-006-data') -or $_.CommandLine -match '--port(?:=|\s+)3307(?:\s|$)' })
}

function Assert-Restore3307Stopped {
    if(@(Get-Restore3307Processes).Count) { throw 'RESTORE_PROCESS_EXISTS' }
    if(@(Get-NetTCPConnection -State Listen -ErrorAction Stop | Where-Object LocalPort -eq 3307).Count) { throw 'RESTORE_LISTENER_EXISTS' }
}

function Get-Restore3307Arguments([string]$InitFile,[string]$ErrorLog) {
    return @('--no-defaults','--persisted-globals-load=OFF','--basedir="C:\Program Files\MySQL\MySQL Server 8.4"',
        '--datadir="C:\Users\User\mysql-restore-006-data"','--port=3307',
        '--bind-address=127.0.0.1','--mysqlx=OFF','--general-log=OFF',
        '--slow-query-log=OFF','--skip-log-bin','--log-raw=OFF',
        ('--log-error="'+$ErrorLog.Replace('\','/')+'"'),
        ('--init-file="'+$InitFile.Replace('\','/')+'"'))
}

function Assert-Restore3307Listener([int]$LaunchPid=0) {
    $listeners=@(Get-NetTCPConnection -State Listen -ErrorAction Stop | Where-Object LocalPort -eq 3307)
    if($listeners.Count -ne 1 -or $listeners[0].LocalAddress -ne '127.0.0.1') { throw 'RESTORE_LISTENER_REJECTED' }
    $ownerPid=[int]$listeners[0].OwningProcess
    $owner=Get-CimInstance Win32_Process -Filter ("ProcessId="+$ownerPid) -ErrorAction Stop
    if(!$owner -or $owner.ExecutablePath -ne 'C:\Program Files\MySQL\MySQL Server 8.4\bin\mysqld.exe' -or
       $owner.CommandLine -notmatch '--port(?:=|\s+)3307(?:\s|$)' -or
       $owner.CommandLine -notmatch '--datadir="?C:\\Users\\User\\mysql-restore-006-data"?(?:\s|$)') { throw 'RESTORE_LISTENER_PROCESS_REJECTED' }
    if($LaunchPid -gt 0 -and $ownerPid -ne $LaunchPid -and $owner.ParentProcessId -ne $LaunchPid) { throw 'UNRELATED_LISTENER_PROCESS' }
    return $ownerPid
}

function Assert-Restore3307Identity($Identity) {
    if([int]$Identity.port -ne 3307 -or $Identity.uuid -ne '37e4d222-dda2-11f1-b503-5c2886f057d5' -or
       $Identity.uuid -eq '77218fbd-d66d-11f1-9a0a-5c2886f057d5' -or
       [IO.Path]::GetFullPath([string]$Identity.datadir).TrimEnd('\','/') -ne 'C:\Users\User\mysql-restore-006-data' -or
       [string]$Identity.version -notmatch '^8\.4\.9(?:[.\s-]|$)' -or $Identity.account -ne 'root@localhost') { throw 'RESTORE_IDENTITY_REJECTED' }
    foreach($database in @($Identity.databases)) {
        if($database -notin @('mysql','information_schema','performance_schema','sys')) { throw 'RESTORE_USER_DATABASE_EXISTS' }
    }
}
