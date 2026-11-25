#Requires -Version 5.1
#Requires -PSEdition Desktop
[CmdletBinding()]
param(
    [ValidateSet('Preflight','Apply','Recover')][string]$Action='Preflight',
    [string]$BackupDirectory='', [string]$BackupManifestSha256='', [string]$ApprovalToken=''
)
$ErrorActionPreference='Stop'
Set-StrictMode -Version Latest
. (Join-Path $PSScriptRoot 'lib/migration011-apply-credentials.ps1')
$project=Split-Path -Parent $PSScriptRoot
$mysql=Join-Path $env:ProgramFiles 'MySQL\MySQL Server 8.4\bin\mysql.exe'
$evaluator=Join-Path $PSScriptRoot 'migration011-provision-grants-check.php'
$account="'up_van_migration011_apply'@'127.0.0.1'"
$stateDirectory=Join-Path ([Environment]::GetFolderPath('LocalApplicationData')) 'UPVanBooking\migration011\apply-state'
$pending=Join-Path $stateDirectory 'pending.json'
$credential=$null;$runtime=$null;$journal=$null;$mutex=$null;$mutexHeld=$false
$recoverySql="ALTER USER $account ACCOUNT LOCK; SELECT account_locked FROM mysql.user WHERE User='up_van_migration011_apply' AND Host='127.0.0.1';"

function Invoke-Apply011Dba([string]$Sql){
    if(![IO.File]::Exists($mysql)){throw 'MIGRATION_011_MYSQL_CLIENT_MISSING'}
    $version=& $mysql --no-defaults --version 2>$null
    if($LASTEXITCODE -ne 0 -or $version -notmatch '\b8\.4\.9\b'){throw 'MIGRATION_011_MYSQL_CLIENT_VERSION_REJECTED'}
    $arguments=@('--no-defaults','--login-path=upvan3306-backup','--protocol=TCP','--host=127.0.0.1','--port=3306','--database=up_van_booking_test','--connect-timeout=5','--skip-reconnect','--batch','--raw','--skip-column-names')
    # Identity guard and account management share the SAME DBA connection.
    $guard="SET @upvan011_guard=IF(@@port=3306 AND @@server_uuid='77218fbd-d66d-11f1-9a0a-5c2886f057d5' AND DATABASE()='up_van_booking_test' AND VERSION()='8.4.9' AND CURRENT_USER()='root@localhost','DO 0','SELECT * FROM __migration011_identity_rejected'); PREPARE upvan011_guard_stmt FROM @upvan011_guard; EXECUTE upvan011_guard_stmt; DEALLOCATE PREPARE upvan011_guard_stmt;"
    $response=Invoke-Migration011MysqlCaptured -ClientPath $mysql -ClientArgs $arguments -Sql ($guard+"`n"+$Sql)
    if($response.ExitCode -ne 0 -or $response.InputWriteFailed){throw ('MIGRATION_011_DBA_FAILED '+(Get-Migration011MysqlErrorCode $response.Stderr))}
    return $response.Stdout.Trim()
}

function Write-Apply011Journal {
    $script:journal.updated_utc=[DateTime]::UtcNow.ToString('o')
    $bytes=[Text.UTF8Encoding]::new($false).GetBytes((ConvertTo-Json -InputObject $script:journal -Compress))
    $temporary=Join-Path $stateDirectory ('journal-'+[guid]::NewGuid().ToString('N')+'.tmp')
    $stream=$null
    try{
        $stream=[IO.File]::Open($temporary,[IO.FileMode]::CreateNew,[IO.FileAccess]::Write,[IO.FileShare]::None)
        $stream.Write($bytes,0,$bytes.Length);$stream.Flush($true);$stream.Dispose();$stream=$null
        Assert-Migration011CredentialFileAcl $temporary
        Publish-Migration011Journal -Temporary $temporary -Destination $pending
    }finally{if($stream){$stream.Dispose()};[Array]::Clear($bytes,0,$bytes.Length)}
}

try{
    $groups=& whoami.exe /groups 2>$null | Out-String
    if($groups -notmatch 'S-1-16-8192'){throw 'MIGRATION_011_NORMAL_WINDOWS_MEDIUM_SESSION_REQUIRED'}
    $sid=[Security.Principal.WindowsIdentity]::GetCurrent().User.Value
    $mutex=[Threading.Mutex]::new($false,('Local\UPVanBooking-Migration011-'+$sid))
    try{$mutexHeld=$mutex.WaitOne(0)}catch [Threading.AbandonedMutexException]{$mutexHeld=$true}
    if(!$mutexHeld){throw 'MIGRATION_011_WRAPPER_ALREADY_RUNNING'}
    $operations=@{
        Runtime={ $script:runtime=Assert-Migration011ApplyRuntime $project }
        Pending={ if([IO.File]::Exists($pending)){throw 'MIGRATION_011_RECOVERY_REQUIRED_NO_RETRY'} }
        Approval={ Assert-Migration011WrapperApproval $ApprovalToken }
        Backup={
            if($BackupManifestSha256 -cnotmatch '^[a-f0-9]{64}$'){throw 'MIGRATION_011_BACKUP_PIN_REQUIRED'}
            $request=ConvertTo-Json -InputObject @{directory=$BackupDirectory;manifest_sha256=$BackupManifestSha256} -Compress
            $arguments=@('-n',$evaluator,'--verify-011-backup')|ForEach-Object{ConvertTo-Migration011WindowsArgument $_}
            $check=Invoke-Migration011MysqlCaptured -ClientPath $runtime -ClientArgs $arguments -Sql $request
            if($check.ExitCode -ne 0){throw 'MIGRATION_011_BACKUP_REJECTED'}
            $backupCheck=$check.Stdout|ConvertFrom-Json -ErrorAction Stop
            if($backupCheck.backup_verification -cne 'PASS'){throw 'MIGRATION_011_BACKUP_REJECTED'}
        }
        Preflight={
            $ps=Join-Path $PSHOME 'powershell.exe'
            $arguments=@('-NoProfile','-ExecutionPolicy','Bypass','-File',(Join-Path $PSScriptRoot 'migration011-apply-account-provision.ps1'),'-Action','ReadOnlyPreflight')|ForEach-Object{ConvertTo-Migration011WindowsArgument $_}
            $check=Invoke-Migration011MysqlCaptured -ClientPath $ps -ClientArgs $arguments -Sql ' '
            if($check.ExitCode -ne 0 -or $check.Stdout -notmatch '(?m)^READ_ONLY_PREFLIGHT=PASS\r?$'){throw 'MIGRATION_011_PREFLIGHT_REJECTED'}
            Assert-Migration011CredentialFileAcl (Get-Migration011CredentialPath)
            $script:credential=Read-Migration011DpapiCredential
        }
        Identity={
            $identity=Invoke-Apply011Dba 'SELECT CONCAT(@@port,CHAR(9),@@server_uuid,CHAR(9),DATABASE(),CHAR(9),VERSION(),CHAR(9),CURRENT_USER());'
            if(!(Test-Migration011DbaIdentityOutput $identity)){throw 'MIGRATION_011_DBA_IDENTITY_REJECTED'}
        }
        Mark={
            if(![IO.Directory]::Exists($stateDirectory)){New-Migration011PrivateDirectory $stateDirectory}
            if([IO.File]::Exists($pending)){throw 'MIGRATION_011_RECOVERY_REQUIRED_NO_RETRY'}
            $script:journal=[ordered]@{run_id=[guid]::NewGuid().ToString('N');phase='UNLOCK_INTENT';updated_utc='';account='up_van_migration011_apply@127.0.0.1';target_uuid='77218fbd-d66d-11f1-9a0a-5c2886f057d5';child_pid=$null;child_started_ticks=$null;child_finished=$false;automatic_retry=$false}
            Write-Apply011Journal
        }
        Unlock={
            $assertLocked="SET @upvan011_account=IF((SELECT COUNT(*) FROM mysql.user WHERE User='up_van_migration011_apply' AND Host='127.0.0.1' AND account_locked='Y')=1,'DO 0','SELECT * FROM __migration011_account_rejected'); PREPARE upvan011_account_stmt FROM @upvan011_account; EXECUTE upvan011_account_stmt; DEALLOCATE PREPARE upvan011_account_stmt;"
            $state=Invoke-Apply011Dba ($assertLocked+" ALTER USER $account ACCOUNT UNLOCK; SELECT account_locked FROM mysql.user WHERE User='up_van_migration011_apply' AND Host='127.0.0.1';")
            if($state -cne 'N'){throw 'MIGRATION_011_UNLOCK_UNVERIFIED'}
        }
        Apply={
            $arguments=@('--apply','--confirm=up_van_booking_test',"--backup-dir=$BackupDirectory","--backup-manifest-sha256=$BackupManifestSha256")
            $child=Invoke-Migration011RunnerChild -Password $credential -RunnerArguments $arguments -OnStarted {
                param($childPid,$ticks)
                $script:journal.child_pid=$childPid;$script:journal.child_started_ticks=$ticks;$script:journal.phase='RUNNER_STARTED';Write-Apply011Journal
            }
            $script:journal.child_finished=$true;$script:journal.phase='RUNNER_EXITED';Write-Apply011Journal
            try{
                $data=([string]$child.Stdout).Trim()|ConvertFrom-Json -ErrorAction Stop
                if($child.ExitCode -ne 0 -or $data.status -cne 'PASS' -or $data.ledger -cne 'applied 1/1' -or $data.schema_postconditions -cne 'PASS' -or !$data.migration_lock_released){throw 'MIGRATION_011_RUNNER_FAILED_NO_RETRY'}
                return [pscustomobject]@{status='PASS';ledger='applied 1/1'}
            }finally{$child.Stdout=$null;$child.Stderr=$null}
        }
        Relock={
            $state=Invoke-Apply011Dba $recoverySql
            if($state -cne 'Y'){throw 'MIGRATION_011_ACCOUNT_LOCK_FINAL_UNVERIFIED'}
        }
        Complete={
            $script:journal.phase='COMPLETE_LOCK_VERIFIED';Write-Apply011Journal
            [IO.File]::Move($pending,(Join-Path $stateDirectory ($script:journal.run_id+'.json')))
        }
        Recovery={
            # Preserve crash evidence. ACCOUNT LOCK does not terminate existing sessions.
            if([IO.File]::Exists($pending)){
                Assert-Migration011CredentialFileAcl $pending
                $script:journal=ConvertFrom-Json -InputObject ([IO.File]::ReadAllText($pending))
            }
        }
    }
    $result=Invoke-Migration011ApplyLifecycle -Action $Action -Operations $operations
    $result|Add-Member -NotePropertyName recovery_marker -NotePropertyValue $pending
}catch{
    $code=[regex]::Match($_.Exception.Message,'MIGRATION_011_[A-Z0-9_]+').Value
    if(!$code){$code='MIGRATION_011_WRAPPER_FAILED'}
    $result=[pscustomobject]@{status='FAIL';code=$code;account_lock_final='NOT_CHECKED';automatic_retry=$false;recovery_required=$false}
}finally{
    if($credential){$credential.Dispose()}
    if($mutexHeld){$mutex.ReleaseMutex()};if($mutex){$mutex.Dispose()}
}
Write-Output (ConvertTo-Json -InputObject $result -Compress -Depth 5)
if($result.status -eq 'CRITICAL'){
    Write-Output ('CRITICAL: account lock is unverified. DBA recovery SQL (after verifying target identity): '+$recoverySql)
}
if($result.recovery_required){Write-Output 'RECOVERY_REQUIRED: do not retry or resume; inspect pending journal, running PHP/MySQL sessions and ledger. ACCOUNT LOCK does not terminate existing sessions.'}
if($result.status -notin @('PASS','RECOVERED_LOCK_ONLY')){exit 1}
exit 0
