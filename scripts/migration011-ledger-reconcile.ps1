#Requires -Version 5.1
#Requires -PSEdition Desktop
[CmdletBinding()]
param([Parameter(Mandatory=$true)][string]$BackupDirectory,
    [Parameter(Mandatory=$true)][ValidatePattern('^[a-f0-9]{64}$')][string]$BackupManifestSha256,
    [Parameter(Mandatory=$true)][string]$ApprovalToken,
    [ValidateSet('Standard','Exceptional')][string]$Mode='Standard',
    [string]$AttemptId='')
$ErrorActionPreference='Stop'
Set-StrictMode -Version Latest

$project=Split-Path -Parent $PSScriptRoot
$mysql=Join-Path $env:ProgramFiles 'MySQL\MySQL Server 8.4\bin\mysql.exe'
$evaluator=Join-Path $PSScriptRoot 'migration011-provision-grants-check.php'
$result=[ordered]@{status='BLOCKED';mode=$(if($Mode -eq 'Exceptional'){'EXCEPTIONAL_LEDGER_ONLY'}else{'LEDGER_ONLY'});migration='011_microsoft_identities';ledger='UNKNOWN';schema='UNKNOWN';account_locked='UNKNOWN';ddl_executed=$false;automatic_retry=$false;recovery_required=$true;error_code=$null}
$session=$null;$mutex=$null;$mutexHeld=$false;$committed=$false;$marker=$null;$markerHash=$null;$markerSha256=$null;$markerPath=$null;$temporaryJournalPath=$null;$temporaryJournalHash=$null;$temporaryJournalSha256=$null;$exceptionalEvidence=$null;$childProcessEvidence=$null;$auditDirectory=$null;$auditIntentPath=$null;$auditCompletePath=$null;$auditFailurePath=$null;$wrapperStage='SESSION_CHECK';$executionState=@{stage='DB_IDENTITY';transaction_state='NOT_STARTED';update_sent=$false;commit_attempted=$false;commit_confirmed=$false;child_exit_code=$null};$failureSummary=$null;$auditWriteStatus='NOT_ATTEMPTED'
$intentWritten=$false
try{
    $wrapperStage='WRAPPER_BOOTSTRAP'
    . (Join-Path $PSScriptRoot 'lib/migration011-apply-credentials.ps1')
    . (Join-Path $PSScriptRoot 'lib/migration011-reconciliation-cli.ps1')
    $wrapperStage='SESSION_CHECK'
    $groups=& whoami.exe /groups 2>$null|Out-String
    if($LASTEXITCODE -ne 0 -or $groups -notmatch 'S-1-16-8192'){throw 'MIGRATION_011_NORMAL_WINDOWS_MEDIUM_SESSION_REQUIRED'}
    $wrapperStage='APPROVAL'
    if($Mode -eq 'Standard'){
        if($ApprovalToken -cne 'RECONCILE_MIGRATION_011_LEDGER_ONLY' -or [Environment]::GetEnvironmentVariable('TEST_DB_EXECUTION_APPROVED','Process') -cne 'true' -or [Environment]::GetEnvironmentVariable('TEST_MIGRATION_011_LEDGER_RECONCILIATION_APPROVED','Process') -cne 'true'){
            throw 'MIGRATION_011_RECONCILIATION_APPROVAL_REQUIRED'
        }
    }else{
        [void](Assert-Migration011ExceptionalApproval $ApprovalToken $AttemptId)
    }
    $wrapperStage='MUTEX'
    $sid=[Security.Principal.WindowsIdentity]::GetCurrent().User.Value
    $mutex=[Threading.Mutex]::new($false,('Local\UPVanBooking-Migration011-Reconcile-'+$sid))
    try{$mutexHeld=$mutex.WaitOne(0)}catch [Threading.AbandonedMutexException]{$mutexHeld=$true}
    if(!$mutexHeld){throw 'MIGRATION_011_RECONCILIATION_WRAPPER_ALREADY_RUNNING'}
    $wrapperStage='RUNTIME'
    $runtime=Assert-Migration011ApplyRuntime $project
    if(![IO.File]::Exists($mysql)){throw 'MIGRATION_011_MYSQL_CLIENT_MISSING'}
    $wrapperStage='MYSQL_CLIENT'
    $clientVersion=& $mysql --no-defaults --version 2>$null
    if($LASTEXITCODE -ne 0 -or $clientVersion -notmatch '\b8\.4\.9\b'){throw 'MIGRATION_011_MYSQL_CLIENT_VERSION_REJECTED'}
    $wrapperStage='PLAN'
    $planOutput=@(& $runtime -n (Join-Path $PSScriptRoot 'migration010-backup-check.php') --expected-microsoft-011 2>$null)
    if($LASTEXITCODE -ne 0 -or $planOutput.Count -ne 1){throw 'MIGRATION_011_RECONCILIATION_PLAN_REJECTED'}
    $plan=@($planOutput[0] -split "`t")
    if($plan.Count -ne 7 -or $plan[0] -cne 'LEDGER' -or $plan[3] -cne 'applied' -or $plan[4] -cne '1' -or $plan[5] -cne '1'){
        throw 'MIGRATION_011_RECONCILIATION_PLAN_REJECTED'
    }
    $expectedVersion=$plan[1];$expectedChecksum=$plan[2]
    $wrapperStage='BACKUP_GUARD'
    $backupRequest=ConvertTo-Json -InputObject @{directory=$BackupDirectory;manifest_sha256=$BackupManifestSha256} -Compress
    $backupArgs=@('-n',$evaluator,'--verify-011-reconciliation-backup')|ForEach-Object{ConvertTo-Migration011WindowsArgument ([string]$_)}
    $backupCheck=Invoke-Migration011MysqlCaptured -ClientPath $runtime -ClientArgs $backupArgs -Sql $backupRequest -WorkingDirectory (Split-Path -Parent $runtime)
    if($backupCheck.ExitCode -ne 0){throw 'MIGRATION_011_RECONCILIATION_BACKUP_REJECTED'}
    $backupResult=$backupCheck.Stdout.Trim()|ConvertFrom-Json -ErrorAction Stop
    if($backupResult.backup_verification -cne 'PASS' -or $backupResult.base_tables -ne 16 -or $backupResult.views -ne 1 -or $backupResult.ledger_state -cne '001-009 applied; 011 failed 1/1; 010 absent'){
        throw 'MIGRATION_011_RECONCILIATION_BACKUP_REJECTED'
    }
    $stateDirectory=Join-Path ([Environment]::GetFolderPath('LocalApplicationData')) 'UPVanBooking\migration011\apply-state'
    $wrapperStage='RECOVERY_EVIDENCE'
    $markerPath=Join-Path $stateDirectory 'pending.json'
    if(![IO.File]::Exists($markerPath)){throw 'MIGRATION_011_RECONCILIATION_MARKER_MISSING'}
    Assert-Migration011CredentialFileAcl $markerPath
    $markerBytes=[IO.File]::ReadAllBytes($markerPath);$sha=[Security.Cryptography.SHA256]::Create()
    try{$markerDigest=$sha.ComputeHash($markerBytes);$markerHash=[Convert]::ToBase64String($markerDigest);$markerSha256=([BitConverter]::ToString($markerDigest)).Replace('-','').ToLowerInvariant();$markerJson=[Text.Encoding]::UTF8.GetString($markerBytes)}finally{$sha.Dispose();[Array]::Clear($markerBytes,0,$markerBytes.Length);if($markerDigest){[Array]::Clear($markerDigest,0,$markerDigest.Length)}}
    $marker=ConvertFrom-Json -InputObject $markerJson -ErrorAction Stop
    if($Mode -eq 'Standard'){
        if($marker.run_id -cnotmatch '^[a-f0-9]{32}$' -or $marker.phase -cne 'RUNNER_EXITED' -or $marker.child_finished -ne $true -or $marker.automatic_retry -ne $false -or $marker.account -cne 'up_van_migration011_apply@127.0.0.1' -or $marker.target_uuid -cne '77218fbd-d66d-11f1-9a0a-5c2886f057d5' -or !$marker.child_pid -or !$marker.child_started_ticks){throw 'MIGRATION_011_RECONCILIATION_MARKER_REJECTED'}
        try{$child=Get-Process -Id ([int]$marker.child_pid) -ErrorAction Stop
            if($child.StartTime.ToUniversalTime().Ticks -eq [long]$marker.child_started_ticks){throw 'MIGRATION_011_RECONCILIATION_CHILD_STILL_RUNNING'}
        }catch [Microsoft.PowerShell.Commands.ProcessCommandException]{}
    }else{
        $temporaryJournals=@(Get-ChildItem -LiteralPath $stateDirectory -Filter 'journal-*.tmp' -File -Force)
        if($temporaryJournals.Count -ne 1){throw 'MIGRATION_011_EXCEPTIONAL_JOURNAL_INVENTORY_REJECTED'}
        $temporaryJournalPath=$temporaryJournals[0].FullName
        Assert-Migration011CredentialFileAcl $temporaryJournalPath
        $temporaryBytes=[IO.File]::ReadAllBytes($temporaryJournalPath);$temporarySha=[Security.Cryptography.SHA256]::Create()
        try{$temporaryDigest=$temporarySha.ComputeHash($temporaryBytes);$temporaryJournalHash=[Convert]::ToBase64String($temporaryDigest);$temporaryJournalSha256=([BitConverter]::ToString($temporaryDigest)).Replace('-','').ToLowerInvariant();$temporaryJson=[Text.Encoding]::UTF8.GetString($temporaryBytes)}finally{$temporarySha.Dispose();[Array]::Clear($temporaryBytes,0,$temporaryBytes.Length);if($temporaryDigest){[Array]::Clear($temporaryDigest,0,$temporaryDigest.Length)}}
        $temporary=ConvertFrom-Json -InputObject $temporaryJson -ErrorAction Stop
        $exceptionalEvidence=Assert-Migration011ExceptionalRecoveryEvidence $marker $temporary $temporaryJournals.Count
        $childProcessEvidence=Assert-Migration011ExceptionalChildNotRunning $exceptionalEvidence.child_pid $exceptionalEvidence.child_started_ticks
        $auditDirectory=Join-Path $stateDirectory 'exceptional-reconciliation-audit'
        if(!(Test-Path -LiteralPath $auditDirectory -PathType Container)){New-Migration011PrivateDirectory $auditDirectory}
        Assert-Migration011ExceptionalAuditDirectory $auditDirectory
        $attemptPaths=New-Migration011ExceptionalAttemptAuditPaths $auditDirectory $marker.run_id $AttemptId
        $auditIntentPath=$attemptPaths.intent
        $auditCompletePath=$attemptPaths.completed
        [void](Assert-Migration011ExceptionalAttemptAvailable $attemptPaths)
        $wrapperStage='AUDIT_INTENT'
        Write-Migration011ExceptionalAuditRecord $auditIntentPath ([ordered]@{record='EXCEPTIONAL_LEDGER_RECOVERY_INTENT';recorded_utc=[DateTime]::UtcNow.ToString('o');source_run_id=$marker.run_id;attempt_id=$AttemptId;prior_legacy_attempt_intent='PRESERVED_UNVERIFIED';runner_exit_status='UNVERIFIED';child_process_state=$childProcessEvidence;child_pid=$exceptionalEvidence.child_pid;child_started_ticks=$exceptionalEvidence.child_started_ticks;pending_updated_utc=$exceptionalEvidence.pending_updated_utc;temporary_updated_utc=$exceptionalEvidence.temporary_updated_utc;pending_sha256=$markerSha256;temporary_journal_sha256=$temporaryJournalSha256;recovery_basis='LIVE_DATABASE_GUARDS_AND_SCHEMA_POSTCONDITIONS';pending_phase=$marker.phase;temporary_phase=$temporary.phase;ddl_executed=$false;ledger_changed_at_record_time=$false;marker_modified=$false})
        $intentWritten=$true
        $auditWriteStatus='INTENT_WRITTEN'
    }
    $wrapperStage='QUERY_PLAN'
    $queryJson=@(& $runtime -n $evaluator --diagnostic-queries 2>$null)
    if($LASTEXITCODE -ne 0){throw 'MIGRATION_011_RECONCILIATION_QUERY_PLAN_REJECTED'}
    $diagnosticQueries=ConvertFrom-Migration011DiagnosticQueries ($queryJson -join "`n")
    $wrapperStage='DBA_SESSION'
    $clientArgs=New-Migration011DbaClientArguments
    $session=New-Migration011MysqlLineSession $mysql $clientArgs
    $getDiagnostic={
        param($queries,$transport)
        $payload=Get-Migration011DiagnosticPayload $queries $transport
        $args=@('-n',$evaluator,'--diagnose-011')|ForEach-Object{ConvertTo-Migration011WindowsArgument ([string]$_)}
        $assessed=Invoke-Migration011MysqlCaptured -ClientPath $runtime -ClientArgs $args -Sql $payload -WorkingDirectory (Split-Path -Parent $runtime)
        if($assessed.ExitCode -ne 0){throw 'MIGRATION_011_RECONCILIATION_VALIDATOR_FAILED'}
        $diagnostic=($assessed.Stdout -split "`r?`n"|Where-Object{$_ -match '^\{'})
        if(@($diagnostic).Count -ne 1){throw 'MIGRATION_011_RECONCILIATION_VALIDATOR_OUTPUT_REJECTED'}
        return ($diagnostic|ConvertFrom-Json -ErrorAction Stop)
    }.GetNewClosure()
    $verifyBackup={
        $check=Invoke-Migration011MysqlCaptured -ClientPath $runtime -ClientArgs $backupArgs -Sql $backupRequest -WorkingDirectory (Split-Path -Parent $runtime)
        if($check.ExitCode -ne 0){throw 'MIGRATION_011_RECONCILIATION_BACKUP_REJECTED'}
        $value=$check.Stdout.Trim()|ConvertFrom-Json -ErrorAction Stop
        if($value.backup_verification -cne 'PASS' -or $value.base_tables -ne 16 -or $value.views -ne 1 -or $value.ledger_state -cne '001-009 applied; 011 failed 1/1; 010 absent'){throw 'MIGRATION_011_RECONCILIATION_BACKUP_REJECTED'}
    }.GetNewClosure()
    $verifyMarker={
        if(!(Test-Path -LiteralPath $markerPath -PathType Leaf)){throw 'MIGRATION_011_RECONCILIATION_MARKER_CHANGED'}
        $bytes=[IO.File]::ReadAllBytes($markerPath);$sha=[Security.Cryptography.SHA256]::Create()
        try{$current=[Convert]::ToBase64String($sha.ComputeHash($bytes))}finally{$sha.Dispose();[Array]::Clear($bytes,0,$bytes.Length)}
        if($current -cne $markerHash){throw 'MIGRATION_011_RECONCILIATION_MARKER_CHANGED'}
        if($Mode -eq 'Exceptional'){
            if(!(Test-Path -LiteralPath $temporaryJournalPath -PathType Leaf)){throw 'MIGRATION_011_EXCEPTIONAL_JOURNAL_CHANGED'}
            $tempBytes=[IO.File]::ReadAllBytes($temporaryJournalPath);$tempSha=[Security.Cryptography.SHA256]::Create()
            try{$tempCurrent=[Convert]::ToBase64String($tempSha.ComputeHash($tempBytes))}finally{$tempSha.Dispose();[Array]::Clear($tempBytes,0,$tempBytes.Length)}
            if($tempCurrent -cne $temporaryJournalHash){throw 'MIGRATION_011_EXCEPTIONAL_JOURNAL_CHANGED'}
        }
    }.GetNewClosure()
    $wrapperStage='RECONCILIATION_PROTOCOL'
    $protocol=Invoke-Migration011LedgerReconciliationProtocol -Transport $session -DiagnosticQueries $diagnosticQueries `
        -GetDiagnostic $getDiagnostic -ExpectedVersion $expectedVersion -ExpectedChecksum $expectedChecksum `
        -BackupVerify $verifyBackup -RecoveryMarkerVerify $verifyMarker -ExecutionState $executionState
    $committed=$true
    if($Mode -eq 'Standard'){
        $archived=Complete-Migration011ReconciliationMarker -Pending $markerPath -ExpectedRunId $marker.run_id `
            -LedgerVerified $true -SchemaVerified $true -AccountLocked $true
        $result=[ordered]@{status='PASS';mode='LEDGER_ONLY';migration=$expectedVersion;ledger='applied 1/1';checksum=$expectedChecksum
            schema='PASS';account_locked='Y';recovery_marker='ARCHIVED';recovery_required=$false;ddl_executed=$false;automatic_retry=$false}
    }else{
        $wrapperStage='AUDIT_COMPLETE'
        Write-Migration011ExceptionalAuditRecord $auditCompletePath ([ordered]@{record='EXCEPTIONAL_LEDGER_RECOVERY_COMPLETE';recorded_utc=[DateTime]::UtcNow.ToString('o');source_run_id=$marker.run_id;attempt_id=$AttemptId;runner_exit_status='UNVERIFIED';child_process_state=$childProcessEvidence;pending_sha256=$markerSha256;temporary_journal_sha256=$temporaryJournalSha256;recovery_basis='LIVE_DATABASE_GUARDS_AND_SCHEMA_POSTCONDITIONS';ledger='applied 1/1';checksum=$expectedChecksum;schema='PASS';account_locked='Y';ddl_executed=$false;ledger_changed=$true;marker_modified=$false;temporary_journal_modified=$false})
        $auditWriteStatus='COMPLETE_WRITTEN'
        $result=[ordered]@{status='PASS';mode='EXCEPTIONAL_LEDGER_ONLY';migration=$expectedVersion;ledger='applied 1/1';checksum=$expectedChecksum
            schema='PASS';account_locked='Y';runner_exit_status='UNVERIFIED';source_run_id=$marker.run_id;attempt_id=$AttemptId;recovery_marker='PRESERVED';audit_record=$auditCompletePath;recovery_required=$true;ddl_executed=$false;automatic_retry=$false
            failed_stage=$null;exception_type=$null;transaction_state='COMMITTED';commit_state='CONFIRMED';audit_write_status=$auditWriteStatus}
    }
}catch{
    if($wrapperStage -eq 'RECONCILIATION_PROTOCOL'){$failureSummary=Get-Migration011ReconciliationFailureSummary $_ $executionState}
    else{
        $safeErrorCodeMatch=[regex]::Match([string]$_.Exception.Message,'MIGRATION_011_[A-Z0-9_]+')
        $safeErrorCode=if($safeErrorCodeMatch.Success){$safeErrorCodeMatch.Value}else{'MIGRATION_011_RECONCILIATION_FAILED'}
        $failureCommitState=if($committed -or $executionState.commit_confirmed){'CONFIRMED'}elseif($executionState.commit_attempted){'UNKNOWN'}else{'NOT_COMMITTED'}
        $failureTransactionState=if($executionState.transaction_state){[string]$executionState.transaction_state}else{'NOT_STARTED'}
        if($wrapperStage -eq 'AUDIT_COMPLETE'){$auditWriteStatus='COMPLETE_WRITE_FAILED'}
        if($wrapperStage -eq 'AUDIT_INTENT'){$auditWriteStatus='INTENT_WRITE_FAILED'}
        $failureSummary=[pscustomobject]@{error_code=$safeErrorCode;failed_stage=$wrapperStage;exception_type=$_.Exception.GetBaseException().GetType().FullName;transaction_state=$failureTransactionState;ledger_update_sent=[bool]$executionState.update_sent;commit_state=$failureCommitState;raw_error_recorded=$false}
    }
    $code=$failureSummary.error_code
    $critical=$committed -or $failureSummary.commit_state -in @('CONFIRMED','UNKNOWN') -or $code -match 'COMMITTED|COMMIT_OUTCOME_UNKNOWN|MARKER_FINALIZE|MARKER_ARCHIVE'
    if($intentWritten){
        try{
            $auditFailurePath=Join-Path $auditDirectory ($attemptPaths.failurePrefix+[guid]::NewGuid().ToString('N')+'.json')
            $failureAudit=[ordered]@{record='EXCEPTIONAL_LEDGER_RECOVERY_FAILURE';recorded_utc=[DateTime]::UtcNow.ToString('o');source_run_id=$marker.run_id;attempt_id=$AttemptId;runner_exit_status='UNVERIFIED';failed_stage=$failureSummary.failed_stage;exception_type=$failureSummary.exception_type;error_code=$code;transaction_state=$failureSummary.transaction_state;ledger_update_sent=$failureSummary.ledger_update_sent;commit_state=$failureSummary.commit_state;ddl_executed=$false;marker_modified=$false;raw_error_recorded=$false}
            $auditWriteStatus=Write-Migration011ExceptionalFailureAudit $auditFailurePath $failureAudit ($auditWriteStatus -eq 'COMPLETE_WRITE_FAILED')
        }catch{$auditWriteStatus=if($auditWriteStatus -eq 'COMPLETE_WRITE_FAILED'){'COMPLETE_FAILED_FAILURE_WRITE_FAILED'}else{'FAILURE_WRITE_FAILED'}}
    }
    $ledgerState=if($failureSummary.commit_state -in @('CONFIRMED','UNKNOWN')){'VERIFY_REQUIRED'}elseif($failureSummary.transaction_state -eq 'ROLLBACK_UNCONFIRMED'){'UNKNOWN'}else{'UNCHANGED'}
    $result=[ordered]@{status=$(if($critical){'CRITICAL'}else{'FAIL'});mode=$(if($Mode -eq 'Exceptional'){'EXCEPTIONAL_LEDGER_ONLY'}else{'LEDGER_ONLY'});migration='011_microsoft_identities'
        ledger=$ledgerState;schema='VERIFY_REQUIRED';account_locked='UNKNOWN';runner_exit_status=$(if($Mode -eq 'Exceptional'){'UNVERIFIED'}else{$null});
        failed_stage=$failureSummary.failed_stage;exception_type=$failureSummary.exception_type;transaction_state=$failureSummary.transaction_state;ledger_update_sent=$failureSummary.ledger_update_sent;commit_state=$failureSummary.commit_state;child_exit_code=$executionState.child_exit_code;
        recovery_marker='RETAINED_OR_VERIFY_REQUIRED';audit_failure=$auditWriteStatus;audit_write_status=$auditWriteStatus;audit_record=$auditFailurePath;attempt_id=$AttemptId;source_run_id=$(if($marker){$marker.run_id}else{$null});recovery_required=$true;ddl_executed=$false;automatic_retry=$false;error_code=$code}
}finally{
    if($session){try{$session.Dispose()}catch{}}
    if($mutex){
        try{if($mutexHeld){$mutex.ReleaseMutex()}}catch{}
        try{$mutex.Dispose()}catch{}
    }
}
Write-Output (ConvertTo-Json -InputObject $result -Compress -Depth 5)
if($result.status -ne 'PASS'){exit 1}
