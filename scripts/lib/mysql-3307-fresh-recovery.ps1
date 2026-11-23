Set-StrictMode -Version Latest

function Get-Restore3307FreshConfig {
    $folder='C:\Users\User\mysql-3307-recovery-2597af28af234664ad619cbdda6136ad'
    return [pscustomobject]@{
        Folder=$folder;InitFile=(Join-Path $folder 'root-init.sql')
        Prepared=(Join-Path $folder 'prepared-status.json')
        Attempt=(Join-Path $folder 'startup-attempt.json')
        Diagnostic=(Join-Path $folder 'startup-diagnostic.json')
        ErrorLog=(Join-Path $folder 'startup.err')
        Datadir='C:\Users\User\mysql-restore-006-data'
        Uuid='37e4d222-dda2-11f1-b503-5c2886f057d5'
        WorkingDirectory='C:\Program Files\MySQL\MySQL Server 8.4\bin'
    }
}

function Assert-Restore3307FreshMarker($Marker,$Config) {
    $pinned=Get-Restore3307FreshConfig
    foreach($field in @('Folder','InitFile','Prepared','Attempt','Diagnostic','ErrorLog','Datadir','Uuid','WorkingDirectory')) {
        if($Config.$field -cne $pinned.$field) { throw 'FRESH_CONFIG_REJECTED' }
    }
    if($Marker.status -cne 'RECOVERY_FILE_PREPARED' -or $Marker.target_port -ne 3307 -or
       $Marker.datadir -cne $Config.Datadir -or $Marker.recovery_file -cne $Config.InitFile -or
       $Marker.acl -cne 'CURRENT_USER_AND_SYSTEM_ONLY') { throw 'FRESH_PREPARED_MARKER_REJECTED' }
}

function Assert-Restore3307FreshArtifactsUnused($Config) {
    foreach($path in @($Config.Attempt,$Config.Diagnostic,$Config.ErrorLog)) {
        if(Test-Path -LiteralPath $path) { throw 'FRESH_ATTEMPT_ALREADY_EXISTS' }
    }
}
