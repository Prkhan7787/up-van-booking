Set-StrictMode -Version Latest

function Get-Restore3307Option([string]$CommandLine,[ValidateSet('port','datadir','bind-address','log-error','init-file')][string]$Name) {
    $pattern='(?i)(?:^|\s)(?:"--'+$Name+'=([^"]*)"|--'+$Name+'=(?:"([^"]*)"|([^\s"]+)))(?=\s|$)'
    $matchesFound=[regex]::Matches($CommandLine,$pattern)
    if($matchesFound.Count -ne 1) { throw 'COMMAND_OPTION_AMBIGUOUS' }
    foreach($index in 1..3) { if($matchesFound[0].Groups[$index].Success) { return $matchesFound[0].Groups[$index].Value } }
    throw 'COMMAND_OPTION_UNREADABLE'
}

function Assert-Restore3307Process($Process) {
    if(!$Process -or $Process.Name -ne 'mysqld.exe' -or !$Process.CreationDate -or
       $Process.ExecutablePath -ne 'C:\Program Files\MySQL\MySQL Server 8.4\bin\mysqld.exe' -or !$Process.CommandLine) { throw 'PROCESS_IDENTITY_REJECTED' }
    if((Get-Restore3307Option $Process.CommandLine 'port') -cne '3307' -or
       (Get-Restore3307Option $Process.CommandLine 'bind-address') -cne '127.0.0.1') { throw 'PROCESS_TARGET_REJECTED' }
    $data=Get-Restore3307Option $Process.CommandLine 'datadir'
    if(![IO.Path]::IsPathRooted($data) -or [IO.Path]::GetFullPath($data).TrimEnd('\','/') -ine 'C:\Users\User\mysql-restore-006-data') { throw 'PROCESS_DATADIR_REJECTED' }
}

function Get-Restore3307ShutdownSnapshot {
    $data='C:\Users\User\mysql-restore-006-data'
    $listeners=@(Get-NetTCPConnection -State Listen -ErrorAction Stop | Where-Object LocalPort -eq 3307)
    if($listeners.Count -ne 1 -or $listeners[0].LocalAddress -ne '127.0.0.1') { throw 'LISTENER_REJECTED' }
    $server=Get-CimInstance Win32_Process -Filter ('ProcessId='+[int]$listeners[0].OwningProcess) -ErrorAction Stop
    Assert-Restore3307Process $server
    $monitor=Get-CimInstance Win32_Process -Filter ('ProcessId='+[int]$server.ParentProcessId) -ErrorAction Stop
    Assert-Restore3307Process $monitor
    if($monitor.ProcessId -eq $server.ProcessId -or $monitor.CreationDate.ToUniversalTime() -gt $server.CreationDate.ToUniversalTime()) { throw 'MONITOR_RELATIONSHIP_REJECTED' }
    if([Diagnostics.FileVersionInfo]::GetVersionInfo($server.ExecutablePath).ProductVersion -notmatch '^8\.4\.9(?:[.\s-]|$)') { throw 'MYSQL_VERSION_REJECTED' }
    if((Get-Item -LiteralPath $data -Force).Attributes -band [IO.FileAttributes]::ReparsePoint) { throw 'DATADIR_REPARSE_REJECTED' }
    $uuidFiles=@(Get-Item -LiteralPath (Join-Path $data 'auto.cnf') -Force)
    if($uuidFiles[0].Attributes -band [IO.FileAttributes]::ReparsePoint) { throw 'UUID_FILE_REPARSE_REJECTED' }
    $uuids=[regex]::Matches([IO.File]::ReadAllText($uuidFiles[0].FullName),'(?im)^server-uuid\s*=\s*([a-f0-9-]+)\s*$')
    if($uuids.Count -ne 1 -or $uuids[0].Groups[1].Value -ne '37e4d222-dda2-11f1-b503-5c2886f057d5') { throw 'RESTORE_UUID_REJECTED' }
    $pidFiles=@(Get-ChildItem -LiteralPath $data -File -Filter '*.pid' -ErrorAction Stop)
    if($pidFiles.Count -ne 1 -or ($pidFiles[0].Attributes -band [IO.FileAttributes]::ReparsePoint)) { throw 'PID_FILE_REJECTED' }
    $filePid=([IO.File]::ReadAllText($pidFiles[0].FullName)).Trim()
    if($filePid -notmatch '^\d+$' -or [long]$filePid -ne [long]$server.ProcessId) { throw 'PID_FILE_MISMATCH' }
    $log=Get-Restore3307Option $server.CommandLine 'log-error'
    if(![IO.Path]::IsPathRooted($log)) { throw 'LOG_PATH_REJECTED' }
    $log=[IO.Path]::GetFullPath($log)
    if($log -notmatch '^C:\\Users\\User\\mysql-3307-recovery-[a-f0-9]{32}\\[^\\/]+\.err$') { throw 'LOG_PATH_REJECTED' }
    if([IO.Path]::GetFullPath((Get-Restore3307Option $monitor.CommandLine 'log-error')) -ine $log) { throw 'MONITOR_LOG_MISMATCH' }
    Assert-PrivateRecoveryAcl (Split-Path -Parent $log) $true
    Assert-PrivateRecoveryAcl $log $false
    $logItem=Get-Item -LiteralPath $log -Force
    return [pscustomobject]@{
        server_pid=[int]$server.ProcessId;monitor_pid=[int]$monitor.ProcessId
        server_created=$server.CreationDate.ToUniversalTime().ToString('o')
        monitor_created=$monitor.CreationDate.ToUniversalTime().ToString('o')
        server_command=$server.CommandLine;monitor_command=$monitor.CommandLine
        uuid=$uuids[0].Groups[1].Value;datadir=$data;event_name=('mysqld'+$monitor.ProcessId+'_shutdown')
        pid_file=$pidFiles[0].FullName;log_path=$log;log_created=$logItem.CreationTimeUtc.ToString('o');log_offset=[long]$logItem.Length
    }
}

function Assert-Restore3307SameSnapshot($Before,$After) {
    foreach($field in @('server_pid','monitor_pid','server_created','monitor_created','server_command','monitor_command','uuid','datadir','event_name','pid_file','log_path','log_created')) {
        if($Before.$field -cne $After.$field) { throw 'TARGET_CHANGED_BEFORE_SIGNAL' }
    }
}

function Open-Restore3307ShutdownEvent([string]$Name) {
    if($Name -cnotmatch '^mysqld[1-9][0-9]*_shutdown$') { throw 'EVENT_NAME_REJECTED' }
    $rights=[Security.AccessControl.EventWaitHandleRights]::Synchronize -bor [Security.AccessControl.EventWaitHandleRights]::Modify
    # Open existing only. Opening Modify permission does not itself signal the event.
    return [Threading.EventWaitHandle]::OpenExisting($Name,$rights)
}

function Test-Restore3307ShutdownLog($Snapshot) {
    $item=Get-Item -LiteralPath $Snapshot.log_path -Force -ErrorAction Stop
    if(($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -or $item.CreationTimeUtc.ToString('o') -cne $Snapshot.log_created -or $item.Length -lt $Snapshot.log_offset) { throw 'SHUTDOWN_LOG_CHANGED' }
    $stream=$null;$reader=$null
    try {
        $stream=[IO.File]::Open($Snapshot.log_path,[IO.FileMode]::Open,[IO.FileAccess]::Read,([IO.FileShare]::ReadWrite -bor [IO.FileShare]::Delete))
        [void]$stream.Seek($Snapshot.log_offset,[IO.SeekOrigin]::Begin)
        if($stream.Length-$Snapshot.log_offset -gt 1048576) { throw 'SHUTDOWN_LOG_LIMIT' }
        $reader=[IO.StreamReader]::new($stream)
        $text=$reader.ReadToEnd()
        return [regex]::IsMatch($text,'(?im)^[^\r\n]*\[MY-010910\][^\r\n]*Shutdown complete')
    } finally { if($reader){$reader.Dispose()}elseif($stream){$stream.Dispose()} }
}

function Wait-Restore3307GracefulShutdown($Snapshot,[int]$TimeoutSeconds=60) {
    $deadline=[DateTime]::UtcNow.AddSeconds($TimeoutSeconds)
    do {
        $all=@(Get-CimInstance Win32_Process -Filter "Name='mysqld.exe'" -ErrorAction Stop)
        if(@($all | Where-Object { !$_.CommandLine }).Count) { throw 'PROCESS_METADATA_UNAVAILABLE' }
        $remaining=@($all | Where-Object {
            $_.ProcessId -in @($Snapshot.server_pid,$Snapshot.monitor_pid) -or
            $_.CommandLine.IndexOf($Snapshot.datadir,[StringComparison]::OrdinalIgnoreCase) -ge 0 -or $_.CommandLine -match '--port(?:=|\s+)3307(?:\s|$)'
        })
        $listeners=@(Get-NetTCPConnection -State Listen -ErrorAction Stop | Where-Object LocalPort -eq 3307)
        if(!$remaining.Count -and !$listeners.Count -and (Test-Restore3307ShutdownLog $Snapshot)) { return }
        if([DateTime]::UtcNow -ge $deadline) { break }
        Start-Sleep -Milliseconds 500
    } while($true)
    throw 'GRACEFUL_SHUTDOWN_NOT_VERIFIED'
}
