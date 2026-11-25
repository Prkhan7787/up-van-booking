#Requires -Version 5.1
#Requires -PSEdition Desktop
[CmdletBinding()]
param(
    [Parameter(Mandatory=$true)][string]$BackupDirectory,
    [Parameter(Mandatory=$true)][ValidatePattern('^[a-f0-9]{64}$')][string]$BackupManifestSha256,
    [Parameter(Mandatory=$true)][ValidatePattern('^[a-f0-9]{32}$')][string]$AttemptId
)
$ErrorActionPreference='Stop'
Set-StrictMode -Version Latest
. (Join-Path $PSScriptRoot 'lib/migration011-apply-credentials.ps1')
. (Join-Path $PSScriptRoot 'lib/migration011-apply-lifecycle.ps1')
. (Join-Path $PSScriptRoot 'lib/migration011-reconciliation-cli.ps1')

$result=[ordered]@{status='BLOCKED';mode='READ_ONLY_EXCEPTIONAL_RECOVERY_PREFLIGHT';identity='UNKNOWN';ledger='UNKNOWN';schema='UNKNOWN';account_locked='UNKNOWN';active_apply_sessions='UNKNOWN';recovery_evidence='UNKNOWN';backup='UNKNOWN';runner_exit_status='UNVERIFIED';ddl_executed=$false;ledger_changed=$false;marker_modified=$false;reconciliation_executed=$false;error_code=$null}
try{
    $groups=& whoami.exe /groups 2>$null|Out-String
    if($LASTEXITCODE -ne 0 -or $groups -notmatch 'S-1-16-8192'){throw 'MIGRATION_011_NORMAL_WINDOWS_MEDIUM_SESSION_REQUIRED'}
    $project=Split-Path -Parent $PSScriptRoot
    $runtime=Resolve-Migration011EvaluatorRuntime $project
    $ps=Join-Path $PSHOME 'powershell.exe'
    $diagnosticScript=Join-Path $PSScriptRoot 'migration011-provision-user-session.ps1'
    $diagnosticArgs=@('-NoProfile','-ExecutionPolicy','Bypass','-File',$diagnosticScript,'-Action','Diagnostics')|ForEach-Object{ConvertTo-Migration011WindowsArgument ([string]$_)}
    $live=Invoke-Migration011MysqlCaptured -ClientPath $ps -ClientArgs $diagnosticArgs -Sql ' ' -WorkingDirectory $project
    if($live.ExitCode -ne 0){throw 'MIGRATION_011_EXCEPTIONAL_LIVE_DIAGNOSTICS_FAILED'}
    $jsonLines=@($live.Stdout -split "`r?`n"|Where-Object{$_ -match '^\{'})
    if($jsonLines.Count -ne 1){throw 'MIGRATION_011_EXCEPTIONAL_DIAGNOSTIC_OUTPUT_INVALID'}
    $liveResult=ConvertFrom-Json -InputObject $jsonLines[0] -ErrorAction Stop
    if($liveResult.status -cne 'DIAGNOSTIC_COMPLETE'){throw 'MIGRATION_011_EXCEPTIONAL_LIVE_DIAGNOSTICS_FAILED'}
    [void](Assert-Migration011ReconciliationPreflightPacket $liveResult.diagnostic)

    $stateDirectory=Join-Path ([Environment]::GetFolderPath('LocalApplicationData')) 'UPVanBooking\migration011\apply-state'
    $pendingPath=Join-Path $stateDirectory 'pending.json'
    if(!(Test-Path -LiteralPath $pendingPath -PathType Leaf)){throw 'MIGRATION_011_RECONCILIATION_MARKER_MISSING'}
    Assert-Migration011CredentialFileAcl $pendingPath
    $pendingBytes=[IO.File]::ReadAllBytes($pendingPath);$pendingHash=$null;$pendingSha=[Security.Cryptography.SHA256]::Create()
    try{$pendingDigest=$pendingSha.ComputeHash($pendingBytes);$pendingHash=([BitConverter]::ToString($pendingDigest)).Replace('-','').ToLowerInvariant();$pending=ConvertFrom-Json -InputObject ([Text.Encoding]::UTF8.GetString($pendingBytes)) -ErrorAction Stop}
    finally{$pendingSha.Dispose();[Array]::Clear($pendingBytes,0,$pendingBytes.Length);if($pendingDigest){[Array]::Clear($pendingDigest,0,$pendingDigest.Length)}}
    $temporaryJournals=@(Get-ChildItem -LiteralPath $stateDirectory -Filter 'journal-*.tmp' -File -Force)
    if($temporaryJournals.Count -ne 1){throw 'MIGRATION_011_EXCEPTIONAL_JOURNAL_INVENTORY_REJECTED'}
    $temporaryPath=$temporaryJournals[0].FullName
    Assert-Migration011CredentialFileAcl $temporaryPath
    $temporaryBytes=[IO.File]::ReadAllBytes($temporaryPath);$temporaryHash=$null;$temporarySha=[Security.Cryptography.SHA256]::Create()
    try{$temporaryDigest=$temporarySha.ComputeHash($temporaryBytes);$temporaryHash=([BitConverter]::ToString($temporaryDigest)).Replace('-','').ToLowerInvariant();$temporary=ConvertFrom-Json -InputObject ([Text.Encoding]::UTF8.GetString($temporaryBytes)) -ErrorAction Stop}
    finally{$temporarySha.Dispose();[Array]::Clear($temporaryBytes,0,$temporaryBytes.Length);if($temporaryDigest){[Array]::Clear($temporaryDigest,0,$temporaryDigest.Length)}}
    $evidence=Assert-Migration011ExceptionalRecoveryEvidence $pending $temporary $temporaryJournals.Count
    $processState=Assert-Migration011ExceptionalChildNotRunning $evidence.child_pid $evidence.child_started_ticks
    $auditDirectory=Join-Path $stateDirectory 'exceptional-reconciliation-audit'
    $attemptPaths=New-Migration011ExceptionalAttemptAuditPaths $auditDirectory $pending.run_id $AttemptId
    $auditIntentPath=$attemptPaths.intent
    $auditCompletePath=$attemptPaths.completed
    $legacyIntentPath=Join-Path $auditDirectory ($pending.run_id+'.intent.json')
    $legacyCompletePath=Join-Path $auditDirectory ($pending.run_id+'.completed.json')
    if(Test-Path -LiteralPath $auditDirectory -PathType Container){
        Assert-Migration011ExceptionalAuditDirectory $auditDirectory
    }
    [void](Assert-Migration011ExceptionalAttemptAvailable $attemptPaths)

    $evaluator=Join-Path $PSScriptRoot 'migration011-provision-grants-check.php'
    $backupRequest=ConvertTo-Json -InputObject @{directory=$BackupDirectory;manifest_sha256=$BackupManifestSha256} -Compress
    $backupArgs=@('-n',$evaluator,'--verify-011-reconciliation-backup')|ForEach-Object{ConvertTo-Migration011WindowsArgument ([string]$_)}
    $backupResult=Invoke-Migration011MysqlCaptured -ClientPath $runtime -ClientArgs $backupArgs -Sql $backupRequest -WorkingDirectory (Split-Path -Parent $runtime)
    if($backupResult.ExitCode -ne 0){throw 'MIGRATION_011_EXCEPTIONAL_BACKUP_REJECTED'}
    $backup=$backupResult.Stdout.Trim()|ConvertFrom-Json -ErrorAction Stop
    if($backup.backup_verification -cne 'PASS' -or $backup.base_tables -ne 16 -or $backup.views -ne 1 -or $backup.ledger_state -cne '001-009 applied; 011 failed 1/1; 010 absent'){
        throw 'MIGRATION_011_EXCEPTIONAL_BACKUP_REJECTED'
    }
    # Ensure the exact evidence files inspected above did not change during preflight.
    if((Get-FileHash -LiteralPath $pendingPath -Algorithm SHA256).Hash.ToLowerInvariant() -cne $pendingHash -or
       (Get-FileHash -LiteralPath $temporaryPath -Algorithm SHA256).Hash.ToLowerInvariant() -cne $temporaryHash){
        throw 'MIGRATION_011_EXCEPTIONAL_EVIDENCE_CHANGED'
    }
    $result=[ordered]@{status='PASS';mode='READ_ONLY_EXCEPTIONAL_RECOVERY_PREFLIGHT';identity='PASS';ledger='011 failed 1/1; checksum PASS; 001-009 PASS; 010 ABSENT';schema='PASS';account_locked='Y';active_apply_sessions=0
        recovery_evidence='MATCHED_RUN_ID;RUNNER_EXIT_UNVERIFIED';source_run_id=$evidence.run_id;attempt_id=$AttemptId;legacy_attempt_audit=$(if([IO.File]::Exists($legacyIntentPath)){'PRESENT_UNVERIFIED_PRESERVED'}else{'NOT_PRESENT'});legacy_attempt_completion=$(if([IO.File]::Exists($legacyCompletePath)){'PRESENT'}else{'ABSENT'});child_process_state=$processState;pending_phase=$pending.phase;temporary_phase=$temporary.phase
        pending_sha256=$pendingHash;temporary_journal_sha256=$temporaryHash;backup='PASS';base_tables=16;views=1;backup_expires_at_bangkok=$backup.expires_at_bangkok
        attempt_audit_destination=$(if(Test-Path -LiteralPath $auditDirectory -PathType Container){'EXISTS_ACL_VERIFIED'}else{'WILL_CREATE_RESTRICTED_DIRECTORY'});runner_exit_status='UNVERIFIED';ddl_executed=$false;ledger_changed=$false;marker_modified=$false;reconciliation_executed=$false;ready_for_new_approval=$true}
}catch{
    $code=[regex]::Match($_.Exception.Message,'MIGRATION_011_[A-Z0-9_]+').Value
    $result.error_code=if($code){$code}else{'MIGRATION_011_EXCEPTIONAL_PREFLIGHT_FAILED'}
}
$result|ConvertTo-Json -Compress -Depth 5
if($result.status -ne 'PASS'){exit 1}
