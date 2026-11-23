#requires -Version 5.1
# Explicit operator action only. No SQL file content is ever read by this script.
param([switch]$PreflightOnly,[switch]$Execute,[switch]$VerifyOnly,[string]$ConfirmTarget='')
Set-StrictMode -Version Latest
$ErrorActionPreference='Stop'
$stage='ARGUMENTS';$aclOperation=$null
trap {
    [ordered]@{status='FAIL';step=$stage;code='ROOT_RECOVERY_STOP';error_type=$_.Exception.GetBaseException().GetType().Name;acl_operation=$aclOperation;automatic_retry=$false} | ConvertTo-Json -Compress | Write-Host
    exit 1
}
if(([int]$PreflightOnly.IsPresent+[int]$Execute.IsPresent+[int]$VerifyOnly.IsPresent) -ne 1) { throw 'Choose exactly one mode' }
if($Execute -and $ConfirmTarget -cne 'mysql-3307-restore') { throw 'Explicit target confirmation required' }
if($PSVersionTable.PSEdition -ne 'Desktop' -or $PSVersionTable.PSVersion.Major -ne 5) { throw 'Windows PowerShell 5.1 required' }
. (Join-Path $PSScriptRoot 'lib\mysql-3307-recovery-acl.ps1')
. (Join-Path $PSScriptRoot 'lib\mysql-3307-startup-safety.ps1')
$restoreData='C:\Users\User\mysql-restore-006-data'
$folder='C:\Users\User\mysql-3307-recovery-80da7a7f45c24543a25a983b20c014da'
$initFile=Join-Path $folder 'root-init.sql'
$attemptFile=Join-Path $folder 'startup-attempt.json'
$errorLog=Join-Path $folder 'startup3307.err'
$server='C:\Program Files\MySQL\MySQL Server 8.4\bin\mysqld.exe'
$client='C:\Program Files\MySQL\MySQL Server 8.4\bin\mysql.exe'
$stage='TARGET_METADATA'
foreach($path in @($server,$client,$restoreData)) {
    $item=Get-Item -LiteralPath $path -Force -ErrorAction Stop
    if($item.Attributes -band [IO.FileAttributes]::ReparsePoint) { throw 'Reparse point rejected' }
}
if([Diagnostics.FileVersionInfo]::GetVersionInfo($server).ProductVersion -notmatch '^8\.4\.9(?:[.\s-]|$)') { throw 'Server executable version rejected' }
$uuidText=[IO.File]::ReadAllText((Join-Path $restoreData 'auto.cnf'))
if([regex]::Match($uuidText,'(?im)^server-uuid\s*=\s*([a-f0-9-]+)\s*$').Groups[1].Value -ne '37e4d222-dda2-11f1-b503-5c2886f057d5') { throw 'Restore UUID mismatch' }
$stage='RECOVERY_DIRECTORY_ACL'
Assert-PrivateRecoveryAcl $folder $true
if(!$VerifyOnly) {
    $stage='STOPPED_CHECK'
    Assert-Restore3307Stopped
    $stage='INIT_FILE_ACL'
    Assert-PrivateRecoveryAcl $initFile $false
    $snapshot=Get-Item -LiteralPath $initFile
    if($snapshot.Length -le 0) { throw 'Empty recovery file' }
    $stage='PREPARED_STATUS'
    $markerPath=Join-Path $folder 'prepared-status.json'
    $marker=[IO.File]::ReadAllText($markerPath) | ConvertFrom-Json
    if($marker.status -ne 'RECOVERY_FILE_PREPARED' -or $marker.target_port -ne 3307 -or
       $marker.datadir -ne $restoreData -or $marker.recovery_file -ne $initFile) { throw 'Preparation marker rejected' }
    $stage='REPLAY_GUARD'
    if(Test-Path -LiteralPath $attemptFile) { throw 'Prior startup attempt exists; manual review required' }
    if(Test-Path -LiteralPath $errorLog) { throw 'Prior startup log exists; manual review required' }
    if($PreflightOnly) {
        [ordered]@{status='PASS';mode='READ_ONLY_PREFLIGHT';port=3307;stopped=$true;acl='PASS';uuid='PASS';prepared_marker='PASS';files_created=$false;server_started=$false} | ConvertTo-Json -Compress | Write-Host
        return
    }
    # CreateNew is also an inter-process replay lock. Preserve it on any startup failure.
    $stage='STARTUP_ATTEMPT_MARKER'
    $handle=[IO.File]::Open($attemptFile,[IO.FileMode]::CreateNew,[IO.FileAccess]::Write,[IO.FileShare]::None)
    $handle.Dispose()
    Set-PrivateRecoveryAcl $attemptFile $false
    [IO.File]::WriteAllText($attemptFile,([ordered]@{port=3307;datadir=$restoreData;attempt_utc=[DateTime]::UtcNow.ToString('o');state='START_REQUESTED_NO_AUTO_RETRY'} | ConvertTo-Json),[Text.UTF8Encoding]::new($false))
    [void](New-Item -ItemType File -Path $errorLog)
    Set-PrivateRecoveryAcl $errorLog $false
    $stage='FINAL_STARTUP_CHECK'
    Assert-Restore3307Stopped
    Assert-PrivateRecoveryAcl $initFile $false
    $current=Get-Item -LiteralPath $initFile
    if($current.Length -ne $snapshot.Length -or $current.LastWriteTimeUtc -ne $snapshot.LastWriteTimeUtc) { throw 'Recovery file metadata changed' }
    $stage='START_MYSQL_3307'
    $serverArgs=Get-Restore3307Arguments $initFile $errorLog
    $launch=Start-Process -FilePath $server -ArgumentList $serverArgs -WindowStyle Hidden -PassThru
    $stage='WAIT_LISTENER'
    $ready=$false;$deadline=[DateTime]::UtcNow.AddSeconds(30)
    while([DateTime]::UtcNow -lt $deadline) {
        $listeners=@(Get-NetTCPConnection -State Listen -ErrorAction Stop | Where-Object LocalPort -eq 3307)
        if($listeners.Count) { [void](Assert-Restore3307Listener $launch.Id);$ready=$true;break }
        $launch.Refresh()
        if($launch.HasExited) { throw 'Startup process exited; inspect safe error codes manually' }
        Start-Sleep -Milliseconds 300
    }
    if(!$ready) { throw 'Startup timed out; do not launch again' }
}
$stage='VERIFY_LISTENER'
[void](Assert-Restore3307Listener)
$stage='ROOT_LOGIN_AND_IDENTITY'
Write-Host 'Enter the NEW 3307 password at the MySQL hidden password prompt.'
$query="SELECT JSON_OBJECT('port',@@port,'uuid',@@server_uuid,'datadir',@@datadir,'version',VERSION(),'account',CURRENT_USER()); SHOW DATABASES;"
$output=@(& $client --no-defaults --no-login-paths --protocol=TCP --host=127.0.0.1 --port=3307 --user=root --password --connect-timeout=5 --batch --raw --skip-column-names "--execute=$query")
if($LASTEXITCODE -ne 0 -or $output.Count -lt 2) { throw 'Root login/identity query failed; no automatic retry' }
$identity=$output[0] | ConvertFrom-Json
$identity | Add-Member -MemberType NoteProperty -Name databases -Value @($output | Select-Object -Skip 1)
$stage='IDENTITY_VALIDATION'
Assert-Restore3307Identity $identity
[ordered]@{status='PASS';code='ROOT_RECOVERY_LOGIN_VERIFIED';port=$identity.port;uuid=$identity.uuid;datadir=$identity.datadir;version=$identity.version;account=$identity.account;databases=$identity.databases} | ConvertTo-Json -Compress | Write-Host
Write-Host 'STOP. Init file remains protected. Cleanup and normal restart are a separate operator step. No Restore or Migration was run.'
