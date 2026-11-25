#Requires -Version 5.1
#Requires -PSEdition Desktop
[CmdletBinding()]
param(
    [ValidateSet('Inspect','Preflight','Apply','Recover')][string]$Action='Inspect',
    [string]$BackupDirectory='',
    [string]$BackupManifestSha256='',
    [string]$ApprovalToken=''
)
$ErrorActionPreference='Stop'
Set-StrictMode -Version Latest

$project=Split-Path -Parent $PSScriptRoot
$mysql=Join-Path $env:ProgramFiles 'MySQL\MySQL Server 8.4\bin\mysql.exe'
$credentialDirectory=Join-Path ([Environment]::GetFolderPath('LocalApplicationData')) 'UPVanBooking\migration010'
$stateDirectory=Join-Path $credentialDirectory 'apply-state'
$pending=Join-Path $stateDirectory 'pending'
$credential=$null
$runtime=$null
$readiness=$null
$verifiedBackup=$null
$mutex=$null
$held=$false
$runnerExit=$null
$runnerCode=$null
$runnerStage=$null
$runnerLedger=$null
$runId=$null
$accountDb=$null
$account='up_van_migration010_apply@127.0.0.1'
$uuid='77218fbd-d66d-11f1-9a0a-5c2886f057d5'
$database='up_van_booking_test'
$accountPredicate="BINARY User='up_van_migration010_apply' AND BINARY Host='127.0.0.1'"
$identityPredicate="@@port=3306 AND @@server_uuid='$uuid' AND DATABASE()='$database' AND VERSION()='8.4.9' AND CURRENT_USER()='root@localhost'"
$identityQuery='SELECT CONCAT(@@port,CHAR(9),@@server_uuid,CHAR(9),DATABASE(),CHAR(9),VERSION(),CHAR(9),CURRENT_USER())'
$evalScript=Join-Path $PSScriptRoot 'migration010-apply-preflight.php'
$runnerScript=Join-Path $project 'bin/db-migration-010.php'
$phpFlags=@('-n','-d','extension_dir=ext','-d','extension=pdo_mysql','-d','extension=mbstring',
    '-d','display_errors=0','-d','display_startup_errors=0','-d','log_errors=0','-d','zend.exception_ignore_args=1')

function Invoke-Dba010([string]$Sql) {
    # Guard and operation run on the same pinned connection. Batch mode
    # stops at the first SQL error and reconnect is disabled.
    $guard="DO IF($identityPredicate,0,JSON_EXTRACT(CONCAT('UPVAN010_DBA_IDENTITY_REJECTED_',@@server_uuid),'$'));"
    $r=Invoke-Migration011MysqlCaptured -ClientPath $mysql -ClientArgs $clientArgs -Sql ($guard+[Environment]::NewLine+$Sql)
    if($r.ExitCode -ne 0 -or $r.InputWriteFailed){throw 'MIGRATION_010_DBA_REJECTED'}
    return $r.Stdout.TrimEnd([char[]]"`r`n")
}

function Get-Json010([string]$Mode,[string]$InputJson=' ') {
    $arguments=@($phpFlags)+@($evalScript,$Mode)
    $quoted=$arguments | ForEach-Object {ConvertTo-Migration011WindowsArgument $_}
    $check=Invoke-Migration011MysqlCaptured -ClientPath $runtime -ClientArgs $quoted -Sql $InputJson -WorkingDirectory (Split-Path -Parent $runtime)
    if($check.ExitCode -ne 0 -or $check.InputWriteFailed -or $check.Stderr.Length -ne 0){throw 'MIGRATION_010_VALIDATOR_REJECTED'}
    return (ConvertFrom-Json -InputObject $check.Stdout -ErrorAction Stop)
}

function Get-DbReadiness010([string]$Mode) {
    $plan=Get-Json010 '--queries'
    $queries=[ordered]@{}
    foreach($entry in $plan.PSObject.Properties){
        if($entry.Value -isnot [string] -or $entry.Value -cnotmatch '^SELECT '){throw 'MIGRATION_010_QUERY_PLAN_REJECTED'}
        $queries[$entry.Name]=$entry.Value
    }
    $db=New-Migration011MysqlLineSession $mysql $clientArgs
    try {
        [void](ConvertFrom-Migration011CliIdentity ($db.Query($identityQuery)))
        $text=Get-Migration011DiagnosticPayload $queries $db
    } finally {$db.Dispose()}
    if($db.ExitCode -ne 0){throw 'MIGRATION_010_DBA_REJECTED'}
    $packet=ConvertFrom-Json -InputObject $text -ErrorAction Stop
    $grants=@((Invoke-Dba010 "SHOW GRANTS FOR 'up_van_migration010_apply'@'127.0.0.1';") -split '\r?\n')
    $packet | Add-Member -NotePropertyName grants -NotePropertyValue $grants
    $result=Get-Json010 $Mode (ConvertTo-Json -InputObject $packet -Depth 16 -Compress)
    if($result.status -cne 'PASS' -or $result.account_lock -cne 'Y'){throw 'MIGRATION_010_LIVE_GUARDS_REJECTED'}
    return $result
}

function Assert-Apply010Approval {
    if($ApprovalToken -cne 'APPLY_MIGRATION_010_TO_UP_VAN_BOOKING_TEST' -or
       [Environment]::GetEnvironmentVariable('TEST_DB_EXECUTION_APPROVED','Process') -cne 'true' -or
       [Environment]::GetEnvironmentVariable('TEST_MIGRATION_010_APPLY_APPROVED','Process') -cne 'true'){
        throw 'MIGRATION_010_APPLY_APPROVAL_REQUIRED'
    }
}

function Get-Backup010 {
    if([string]::IsNullOrWhiteSpace($BackupDirectory) -or $BackupManifestSha256 -cnotmatch '^[a-f0-9]{64}$'){throw 'MIGRATION_010_BACKUP_PIN_REQUIRED'}
    $packet=ConvertTo-Json -InputObject @{directory=$BackupDirectory;manifest_sha256=$BackupManifestSha256} -Compress
    $verified=Get-Json010 '--verify-backup' $packet
    if($verified.status -cne 'PASS' -or $verified.manifest_sha256 -cne $BackupManifestSha256 -or
       $verified.ledger_state -cne $readiness.backup_ledger_state){throw 'MIGRATION_010_BACKUP_LIVE_BRANCH_MISMATCH'}
    return $verified
}

function Get-Account010GuardSession {
    if($script:accountDb){return $script:accountDb}
    $db=New-Migration011MysqlLineSession $mysql $clientArgs
    try {
        [void](ConvertFrom-Migration011CliIdentity ($db.Query($identityQuery)))
        if($db.Query("SELECT GET_LOCK('up_van_booking_test:010-account',0)") -cne '1'){
            throw 'MIGRATION_010_ACCOUNT_GUARD_BUSY'
        }
        # Keep this connection and lock until relock/exit. If the wrapper is
        # killed mid-UNLOCK, recovery cannot overtake its in-flight statement.
        $script:accountDb=$db
        return $db
    } catch {
        try{$db.Dispose()}catch{}
        throw
    }
}

function Lock-Apply010Account {
    $db=Get-Account010GuardSession
    [void](ConvertFrom-Migration011CliIdentity ($db.Query($identityQuery)))
    $guard="DO IF(IS_USED_LOCK('up_van_booking_test:010-account')=CONNECTION_ID(),0,JSON_EXTRACT(CONCAT('UPVAN010_LOCK_REFUSED_',@@server_uuid),'$'));"
    $response=$db.Query("$guard ALTER USER 'up_van_migration010_apply'@'127.0.0.1' ACCOUNT LOCK; SELECT account_locked FROM mysql.user WHERE $accountPredicate;")
    # A second DBA connection confirms the persisted state.
    if($response -cne 'Y' -or (Invoke-Dba010 "SELECT account_locked FROM mysql.user WHERE $accountPredicate;") -cne 'Y'){
        throw 'MIGRATION_010_ACCOUNT_RELOCK_UNVERIFIED'
    }
}

function Read-Apply010Journal([string]$Name) {
    $file=Join-Path $pending $Name
    Assert-Migration010OrdinaryPath $file ([IO.FileAttributes]0)
    Assert-Migration011CredentialFileAcl $file
    if([IO.FileInfo]::new($file).Length -gt 8192){throw 'MIGRATION_010_JOURNAL_REJECTED'}
    $record=ConvertFrom-Json -InputObject ([IO.File]::ReadAllText($file,[Text.Encoding]::UTF8)) -ErrorAction Stop
    if($record.run_id -cne $script:runId -and $Name -cne 'intent.json'){throw 'MIGRATION_010_JOURNAL_REJECTED'}
    return $record
}

try {
    . (Join-Path $PSScriptRoot 'lib/migration010-apply-lifecycle.ps1')
    $groups=& whoami.exe /groups 2>$null | Out-String
    if($LASTEXITCODE -ne 0 -or $groups -notmatch '\bS-1-16-8192\b'){throw 'MIGRATION_010_MEDIUM_REQUIRED'}
    $sid=[Security.Principal.WindowsIdentity]::GetCurrent().User.Value
    $clientArgs=@(New-Migration011DbaClientArguments)+@('--ssl-mode=REQUIRED')
    $mutex=[Threading.Mutex]::new($false,('Local\UPVanBooking-Migration010-Apply-'+$sid))
    try{$held=$mutex.WaitOne(0)}catch [Threading.AbandonedMutexException]{$held=$true}
    if(!$held){throw 'MIGRATION_010_WRAPPER_ALREADY_RUNNING'}

    $operations=@{
        NoPending={
            if(Test-Path -LiteralPath $pending){throw 'MIGRATION_010_RECOVERY_REQUIRED_NO_RETRY'}
        }
        Approval={Assert-Apply010Approval}
        Preflight={
            $script:credential=Read-Migration010ApplyCredential $credentialDirectory
            $script:runtime=Resolve-Migration011EvaluatorRuntime $project
            $runtimeArgs=@($phpFlags)+@($runnerScript,'--runtime-check')
            $quoted=$runtimeArgs|ForEach-Object{ConvertTo-Migration011WindowsArgument $_}
            $check=Invoke-Migration011MysqlCaptured -ClientPath $runtime -ClientArgs $quoted -Sql ' ' -WorkingDirectory (Split-Path -Parent $runtime)
            if($check.ExitCode -ne 0 -or $check.InputWriteFailed -or $check.Stdout.Trim() -cne 'MIGRATION_010_APPLY_RUNTIME=8.4' -or $check.Stderr.Length -ne 0){
                throw 'MIGRATION_010_RUNTIME_REJECTED'
            }
            $script:readiness=Get-DbReadiness010 '--preflight'
            if($Action -ne 'Inspect'){$script:verifiedBackup=Get-Backup010}
        }
        MarkIntent={
            if(![IO.Directory]::Exists($stateDirectory)){New-Migration011PrivateDirectory $stateDirectory}
            Assert-Migration010OrdinaryPath $stateDirectory ([IO.FileAttributes]::Directory)
            Assert-Migration011ExceptionalAuditDirectory $stateDirectory
            New-Migration011PrivateDirectory $pending
            Assert-Migration011ExceptionalAuditDirectory $pending
            $script:runId=[guid]::NewGuid().ToString('N')
            Write-Migration010JournalRecord (Join-Path $pending 'intent.json') ([ordered]@{
                format='upvan-migration010-apply-intent-v1';run_id=$runId;phase='UNLOCK_INTENT';account=$account;uuid=$uuid;database=$database
                windows_sid=$sid;created_utc=[DateTimeOffset]::UtcNow.ToString('o');backup_manifest_sha256=$BackupManifestSha256
            })
        }
        Unlock={
            Assert-Apply010Approval
            $db=Get-Account010GuardSession
            $script:verifiedBackup=Get-Backup010
            $expires=[DateTimeOffset]::Parse($verifiedBackup.expires_at_utc).ToUnixTimeSeconds()
            $condition="$identityPredicate AND IS_USED_LOCK('up_van_booking_test:010-account')=CONNECTION_ID() AND UNIX_TIMESTAMP()<$expires AND (SELECT COUNT(*) FROM mysql.user WHERE $accountPredicate AND account_locked='Y' AND plugin='caching_sha2_password' AND password_expired='N')=1 AND NOT EXISTS(SELECT 1 FROM information_schema.PROCESSLIST WHERE USER='up_van_migration010_apply') AND IS_FREE_LOCK('up_van_booking_test:migrations')=1 AND NOT EXISTS(SELECT 1 FROM schema_migrations WHERE LEFT(version,3)='010')"
            $sql="DO IF($condition,0,JSON_EXTRACT(CONCAT('UPVAN010_UNLOCK_REFUSED_',@@server_uuid),'$')); ALTER USER 'up_van_migration010_apply'@'127.0.0.1' ACCOUNT UNLOCK; SELECT account_locked FROM mysql.user WHERE $accountPredicate;"
            if($db.Query($sql) -cne 'N'){throw 'MIGRATION_010_ACCOUNT_UNLOCK_UNVERIFIED'}
        }
        Run={
            $args010=@($phpFlags)+@($runnerScript,'--apply','--confirm=up_van_booking_test',"--backup-dir=$BackupDirectory","--backup-manifest-sha256=$BackupManifestSha256")
            $capture=Invoke-Migration010StdinChild $runtime $args010 (Split-Path -Parent $runtime) $credential {
                param($childPid,$startedTicks)
                Write-Migration010JournalRecord (Join-Path $pending 'runner.json') ([ordered]@{
                    run_id=$runId;phase='RUNNER_STARTED';child_pid=$childPid;child_started_ticks=$startedTicks
                })
            }
            try {
                $response=ConvertFrom-Migration010RunnerResult $capture.exit_code $capture.stdout $BackupManifestSha256
                $script:runnerExit=$response.exit_code
                $script:runnerCode=$response.code
                $script:runnerStage=$response.stage
                $script:runnerLedger=$response.ledger
                Write-Migration010JournalRecord (Join-Path $pending 'finished.json') ([ordered]@{run_id=$runId;phase='RUNNER_EXITED';exit_code=$runnerExit})
                if($response.status -cne 'PASS'){throw 'MIGRATION_010_RUNNER_UNCONFIRMED'}
            } finally {$capture.stdout=$null}
        }
        Lock={Lock-Apply010Account}
        Postflight={
            $script:readiness=Get-DbReadiness010 '--postflight'
        }
        Complete={
            Write-Migration010JournalRecord (Join-Path $pending 'complete.json') ([ordered]@{
                run_id=$runId;phase='COMPLETE_LOCK_VERIFIED';account_lock='Y';ledger='APPLIED_3_OF_3'
            })
            [IO.Directory]::Move($pending,(Join-Path $stateDirectory ('complete-'+$runId)))
        }
        VerifyRecovery={
            if($ApprovalToken -cne 'LOCK_MIGRATION_010_APPLY_ACCOUNT'){throw 'MIGRATION_010_RECOVERY_APPROVAL_REQUIRED'}
            foreach($folder in @($credentialDirectory,$stateDirectory,$pending)){
                Assert-Migration010OrdinaryPath $folder ([IO.FileAttributes]::Directory)
                Assert-Migration011ExceptionalAuditDirectory $folder
            }
            $record=Read-Apply010Journal 'intent.json'
            if($record.format -cne 'upvan-migration010-apply-intent-v1' -or $record.run_id -cnotmatch '^[a-f0-9]{32}$' -or
               $record.phase -cne 'UNLOCK_INTENT' -or $record.account -cne $account -or $record.uuid -cne $uuid -or
               $record.database -cne $database -or $record.windows_sid -cne $sid){
                throw 'MIGRATION_010_JOURNAL_REJECTED'
            }
            $script:runId=$record.run_id
        }
        NoRunnerOrSessions={
            if((Invoke-Dba010 "SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE USER='up_van_migration010_apply';") -cne '0'){
                throw 'MIGRATION_010_RECOVERY_ACTIVE_APPLY_SESSIONS'
            }
            if([IO.File]::Exists((Join-Path $pending 'runner.json'))){
                $record=Read-Apply010Journal 'runner.json'
                if($record.phase -cne 'RUNNER_STARTED' -or [int]$record.child_pid -lt 1 -or [long]$record.child_started_ticks -lt 1){throw 'MIGRATION_010_JOURNAL_REJECTED'}
                # ACCOUNT LOCK blocks new logins. It does not kill an existing session.
                [void](Assert-Migration011ExceptionalChildNotRunning ([int]$record.child_pid) ([long]$record.child_started_ticks))
            }
        }
    }
    $result=Invoke-Migration010ApplyLifecycle $Action $operations
} catch {
    $result=[pscustomobject]@{status='BLOCKED';stage='BOOTSTRAP';account_lock='UNKNOWN';recovery_required=$false;automatic_retry=$false}
} finally {
    if($credential){$credential.Dispose()}
    if($accountDb){try{$accountDb.Dispose()}catch{}}
    if($mutex){
        try{if($held){$mutex.ReleaseMutex()}}catch{}
        try{$mutex.Dispose()}catch{}
    }
}

$result | Add-Member -NotePropertyName action -NotePropertyValue $Action
$result | Add-Member -NotePropertyName credential -NotePropertyValue $(if($credential){'DPAPI_VERIFIED'}else{'NOT_VERIFIED'})
$result | Add-Member -NotePropertyName readiness -NotePropertyValue $readiness
$result | Add-Member -NotePropertyName backup -NotePropertyValue $verifiedBackup
$result | Add-Member -NotePropertyName runner -NotePropertyValue @{exit_code=$runnerExit;code=$runnerCode;stage=$runnerStage;ledger=$runnerLedger}
$result | Add-Member -NotePropertyName journal_run_id -NotePropertyValue $runId
$result | ConvertTo-Json -Compress -Depth 8
if($result.status -notin @('PASS','LOCK_VERIFIED_INSPECTION_REQUIRED')){exit 1}
