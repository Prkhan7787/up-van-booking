Set-StrictMode -Version Latest

function Get-Restore3307NormalWorkingDirectory {
    return 'C:\Program Files\MySQL\MySQL Server 8.4\bin'
}

function Get-Restore3307NormalArguments([string]$ErrorLog) {
    return @('--no-defaults','--persisted-globals-load=OFF',
        '--basedir="C:\Program Files\MySQL\MySQL Server 8.4"',
        '--datadir="C:\Users\User\mysql-restore-006-data"','--port=3307',
        '--bind-address=127.0.0.1','--mysqlx=OFF','--general-log=OFF',
        '--slow-query-log=OFF','--skip-log-bin','--log-raw=OFF',
        ('--log-error="'+$ErrorLog.Replace('\','/')+'"'))
}

function Wait-Restore3307NormalListener($Process,[System.Collections.IDictionary]$Diagnostic,[int]$TimeoutSeconds=30) {
    $deadline=[DateTime]::UtcNow.AddSeconds($TimeoutSeconds)
    while([DateTime]::UtcNow -lt $deadline) {
        $listeners=@(Get-NetTCPConnection -State Listen -ErrorAction Stop | Where-Object LocalPort -eq 3307)
        if($listeners.Count) {
            $Diagnostic['listener_pid']=Assert-Restore3307Listener $Process.Id
            $Diagnostic['state']='LISTENER_VERIFIED'
            return
        }
        $Process.Refresh()
        if($Process.HasExited) {
            $Process.WaitForExit()
            $Diagnostic['exit_code']=$Process.ExitCode
            $Diagnostic['state']='SERVER_EXITED_BEFORE_LISTENER'
            throw 'SERVER_EXITED_BEFORE_LISTENER'
        }
        Start-Sleep -Milliseconds 300
    }
    $Diagnostic['state']='SERVER_RUNNING_NO_LISTENER'
    $Process.Refresh()
    if($Process.HasExited) {
        $Process.WaitForExit();$Diagnostic['exit_code']=$Process.ExitCode
        $Diagnostic['state']='SERVER_EXITED_BEFORE_LISTENER'
    }
    throw $Diagnostic['state']
}

function Read-Restore3307RootIdentity {
    $client='C:\Program Files\MySQL\MySQL Server 8.4\bin\mysql.exe'
    Write-Host 'Enter the NEW prepared 3307 root password in the hidden MySQL prompt. One attempt only.'
    $query="SELECT JSON_OBJECT('port',@@port,'uuid',@@server_uuid,'datadir',@@datadir,'version',VERSION(),'account',CURRENT_USER()); SHOW DATABASES;"
    $rows=@(& $client --no-defaults --no-login-paths --protocol=TCP --host=127.0.0.1 --port=3307 --user=root --password --connect-timeout=5 --batch --raw --skip-column-names "--execute=$query")
    if($LASTEXITCODE -ne 0 -or $rows.Count -lt 2) { throw 'ROOT_LOGIN_FAILED_OR_UNREACHABLE' }
    $identity=$rows[0] | ConvertFrom-Json
    $identity | Add-Member -MemberType NoteProperty -Name databases -Value @($rows | Select-Object -Skip 1)
    Assert-Restore3307Identity $identity
    return $identity
}
