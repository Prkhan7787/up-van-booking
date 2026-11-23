Set-StrictMode -Version Latest

function Assert-Restore3307RecoverySuccess($Proof,$Config) {
    $pinned=Get-Restore3307FreshConfig
    foreach($field in @('Folder','InitFile','Prepared','Attempt','Diagnostic','Datadir','Uuid')) {
        if($Config.$field -cne $pinned.$field) { throw 'POST_RECOVERY_PROOF_REJECTED' }
    }
    foreach($name in @('status','mode','port','uuid','datadir','state','root_login','launch_pid','listener_pid')) {
        if($Proof.PSObject.Properties.Name -notcontains $name) { throw 'POST_RECOVERY_PROOF_REJECTED' }
    }
    if($Proof.status -cne 'PASS' -or $Proof.mode -cne 'FRESH_RECOVERY' -or $Proof.port -ne 3307 -or
       $Proof.uuid -cne $Config.Uuid -or $Proof.datadir -cne $Config.Datadir -or
       $Proof.state -cne 'ROOT_LOGIN_VERIFIED' -or $Proof.root_login -cne 'PASS' -or
       $Proof.launch_pid -le 0 -or $Proof.listener_pid -le 0) { throw 'POST_RECOVERY_PROOF_REJECTED' }
}

function Read-Restore3307RecoverySuccess($Config) {
    Assert-PrivateRecoveryAcl $Config.Folder $true
    Assert-PrivateRecoveryAcl $Config.Diagnostic $false
    Assert-PrivateRecoveryAcl $Config.Attempt $false
    $proof=[IO.File]::ReadAllText($Config.Diagnostic) | ConvertFrom-Json
    Assert-Restore3307RecoverySuccess $proof $Config
    return $proof
}

function Assert-Restore3307CleanupTarget($Proof,$Snapshot,$Config) {
    Assert-Restore3307RecoverySuccess $Proof $Config
    if($Snapshot.server_pid -ne $Proof.listener_pid -or $Snapshot.monitor_pid -ne $Proof.launch_pid -or
       $Snapshot.uuid -ne $Config.Uuid -or $Snapshot.datadir -ne $Config.Datadir) { throw 'CLEANUP_INSTANCE_CHANGED' }
    $saved=(Get-Item -LiteralPath $Config.Diagnostic).LastWriteTimeUtc
    if([DateTime]::Parse($Snapshot.server_created).ToUniversalTime() -gt $saved -or
       [DateTime]::Parse($Snapshot.monitor_created).ToUniversalTime() -gt $saved) { throw 'CLEANUP_PROCESS_NEWER_THAN_PROOF' }
    foreach($command in @($Snapshot.server_command,$Snapshot.monitor_command)) {
        $file=Get-Restore3307Option $command 'init-file'
        if(![IO.Path]::IsPathRooted($file) -or [IO.Path]::GetFullPath($file) -ine $Config.InitFile) { throw 'CLEANUP_INIT_PATH_REJECTED' }
    }
}

function Get-Restore3307AuditMetadata($Config) {
    return @(foreach($path in @($Config.Prepared,$Config.Attempt,$Config.Diagnostic)) {
        $item=Get-Item -LiteralPath $path -Force -ErrorAction Stop
        if($item.PSIsContainer -or ($item.Attributes -band [IO.FileAttributes]::ReparsePoint)) { throw 'AUDIT_ARTIFACT_REJECTED' }
        [pscustomobject]@{path=$item.FullName;length=$item.Length;created=$item.CreationTimeUtc.ToString('o');modified=$item.LastWriteTimeUtc.ToString('o')}
    })
}

function Assert-Restore3307AuditUnchanged($Before,$After) {
    if(($Before | ConvertTo-Json -Compress) -cne ($After | ConvertTo-Json -Compress)) { throw 'AUDIT_ARTIFACT_CHANGED' }
}

function Assert-Restore3307PostNormalReady($Config) {
    [void](Read-Restore3307RecoverySuccess $Config)
    if(Test-Path -LiteralPath $Config.InitFile) { throw 'POST_RECOVERY_INIT_FILE_REMAINS' }
}

function Get-Restore3307StoppedCleanupSnapshot($Proof,$Config) {
    Assert-Restore3307RecoverySuccess $Proof $Config
    Assert-Restore3307Stopped
    foreach($path in @($Config.Datadir,(Join-Path $Config.Datadir 'auto.cnf'))) {
        if((Get-Item -LiteralPath $path -Force -ErrorAction Stop).Attributes -band [IO.FileAttributes]::ReparsePoint) { throw 'STOPPED_METADATA_REJECTED' }
    }
    $matchesFound=[regex]::Matches([IO.File]::ReadAllText((Join-Path $Config.Datadir 'auto.cnf')),'(?im)^server-uuid\s*=\s*([a-f0-9-]+)\s*$')
    if($matchesFound.Count -ne 1 -or $matchesFound[0].Groups[1].Value -cne $Config.Uuid) { throw 'RESTORE_UUID_REJECTED' }
    $pidFiles=@(Get-ChildItem -LiteralPath $Config.Datadir -File -Filter '*.pid' -ErrorAction Stop)
    if($pidFiles.Count -gt 1) { throw 'PID_FILE_REJECTED' }
    $pidValue=$null
    if($pidFiles.Count -eq 1) {
        if($pidFiles[0].Attributes -band [IO.FileAttributes]::ReparsePoint) { throw 'PID_FILE_REJECTED' }
        $pidValue=[IO.File]::ReadAllText($pidFiles[0].FullName).Trim()
        if($pidValue -cne [string]$Proof.listener_pid) { throw 'PID_FILE_MISMATCH' }
    }
    return [pscustomobject]@{server_pid=$null;monitor_pid=$null;uuid=$Config.Uuid;datadir=$Config.Datadir;stopped=$true;pid_file_count=$pidFiles.Count;stale_pid=$pidValue}
}

function Assert-Restore3307NormalCommandLines($Snapshot) {
    foreach($line in @($Snapshot.server_command,$Snapshot.monitor_command)) {
        if($line -match '(?i)(?:^|\s)"?--init-file(?:=|\s|"|$)') { throw 'POST_RECOVERY_INIT_OPTION_PRESENT' }
    }
}
