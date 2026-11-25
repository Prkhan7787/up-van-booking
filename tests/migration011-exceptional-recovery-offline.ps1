#Requires -Version 5.1
$ErrorActionPreference='Stop'
. (Join-Path $PSScriptRoot '../scripts/lib/migration011-apply-credentials.ps1')
. (Join-Path $PSScriptRoot '../scripts/lib/migration011-reconciliation-cli.ps1')
$checks=0
function Assert([bool]$Condition,[string]$Label){if(!$Condition){throw "FAIL: $Label"};$script:checks++}

$run='569a48a5f6404c95a5f0771de3e04dc4'
$attempt='a71d63a3c9844a99b7875f09d91b407e'
$pending=[pscustomobject]@{run_id=$run;phase='UNLOCK_INTENT';updated_utc='2026-11-25T03:07:10.7630393Z';child_finished=$false;automatic_retry=$false;account='up_van_migration011_apply@127.0.0.1';target_uuid='77218fbd-d66d-11f1-9a0a-5c2886f057d5'}
$temporary=[pscustomobject]@{run_id=$run;phase='RUNNER_STARTED';updated_utc='2026-11-25T03:07:10.9561549Z';child_finished=$false;automatic_retry=$false;account='up_van_migration011_apply@127.0.0.1';target_uuid='77218fbd-d66d-11f1-9a0a-5c2886f057d5';child_pid=8832;child_started_ticks=[long]639311728309442570}
$evidence=Assert-Migration011ExceptionalRecoveryEvidence $pending $temporary 1
Assert ($evidence.run_id -ceq $run -and $evidence.runner_exit_status -ceq 'UNVERIFIED') 'matching incomplete journals are classified as unverified, never as runner success'
Assert ($evidence.child_pid -eq 8832 -and $evidence.child_started_ticks -eq [long]639311728309442570) 'child identity is retained for process-state verification'
$pendingSnapshot=ConvertTo-Json -InputObject $pending -Compress;$temporarySnapshot=ConvertTo-Json -InputObject $temporary -Compress
[void](Assert-Migration011ExceptionalRecoveryEvidence $pending $temporary 1)
Assert ((ConvertTo-Json -InputObject $pending -Compress) -ceq $pendingSnapshot -and (ConvertTo-Json -InputObject $temporary -Compress) -ceq $temporarySnapshot) 'evidence validation leaves original journal objects unchanged'

$auditRoot=Join-Path ([IO.Path]::GetTempPath()) ('migration011-attempt-paths-'+[guid]::NewGuid().ToString('N'))
try{
    $paths=New-Migration011ExceptionalAttemptAuditPaths $auditRoot $run $attempt
    Assert ($paths.intent -ceq (Join-Path $auditRoot ($run+'.'+$attempt+'.intent.json')) -and $paths.completed -ceq (Join-Path $auditRoot ($run+'.'+$attempt+'.completed.json'))) 'new attempt audit identity is namespaced by source run and fresh attempt ID'
    Assert (Assert-Migration011ExceptionalAttemptAvailable $paths) 'fresh attempt has a new create-only audit namespace'
    [IO.Directory]::CreateDirectory($auditRoot)|Out-Null
    $legacyIntent=Join-Path $auditRoot ($run+'.intent.json')
    [IO.File]::WriteAllText($legacyIntent,'{"runner_exit_status":"UNVERIFIED"}')
    Assert (Assert-Migration011ExceptionalAttemptAvailable $paths) 'legacy intent remains independent evidence and does not consume the new attempt namespace'
    [IO.Directory]::CreateDirectory($auditRoot)|Out-Null
    [IO.File]::WriteAllText($paths.intent,'{"attempt_id":"'+$attempt+'"}')
    $replayRejected=$false;try{[void](Assert-Migration011ExceptionalAttemptAvailable $paths)}catch{$replayRejected=$_.Exception.Message -ceq 'MIGRATION_011_EXCEPTIONAL_AUDIT_REPLAY_REJECTED'}
    Assert $replayRejected 'persisted intent consumes attempt even if no completion exists; same attempt cannot replay'
    $sameIdRejected=$false;try{[void](New-Migration011ExceptionalAttemptAuditPaths $auditRoot $run $run)}catch{$sameIdRejected=$_.Exception.Message -ceq 'MIGRATION_011_EXCEPTIONAL_ATTEMPT_ID_REJECTED'}
    Assert $sameIdRejected 'attempt ID must differ from the source recovery run ID'
    $badIdRejected=$false;try{[void](New-Migration011ExceptionalAttemptAuditPaths $auditRoot $run 'not-an-id')}catch{$badIdRejected=$_.Exception.Message -ceq 'MIGRATION_011_EXCEPTIONAL_ATTEMPT_ID_REJECTED'}
    Assert $badIdRejected 'malformed attempt ID is rejected'
}finally{
    if(Test-Path -LiteralPath $auditRoot){Remove-Item -LiteralPath $auditRoot -Recurse -Force}
}

try{throw [InvalidOperationException]::new('synthetic raw detail must not be returned')}catch{$syntheticError=$_}
$failure=Get-Migration011ReconciliationFailureSummary $syntheticError @{stage='PRECONDITIONS_RECHECK';transaction_state='ROLLED_BACK';update_sent=$false;commit_attempted=$false;commit_confirmed=$false;failed_stage='PRECONDITIONS_RECHECK'}
Assert ($failure.error_code -ceq 'MIGRATION_011_RECONCILIATION_FAILED' -and $failure.failed_stage -ceq 'PRECONDITIONS_RECHECK' -and $failure.exception_type -ceq 'System.InvalidOperationException') 'generic failures identify the stage and exception type safely'
Assert ($failure.commit_state -ceq 'NOT_COMMITTED' -and $failure.raw_error_recorded -eq $false -and ($failure|ConvertTo-Json -Compress) -notmatch 'synthetic raw detail') 'failure diagnostics omit raw error text and report non-commit state'
$commitUnknown=Get-Migration011ReconciliationFailureSummary $syntheticError @{stage='COMMIT';transaction_state='ACTIVE';update_sent=$true;commit_attempted=$true;commit_confirmed=$false;failed_stage='COMMIT'}
Assert ($commitUnknown.commit_state -ceq 'UNKNOWN') 'commit attempt without confirmation is classified as critical-unknown'
$commitConfirmed=Get-Migration011ReconciliationFailureSummary $syntheticError @{stage='POSTFLIGHT';transaction_state='COMMITTED';update_sent=$true;commit_attempted=$true;commit_confirmed=$true;failed_stage='POSTFLIGHT'}
Assert ($commitConfirmed.commit_state -ceq 'CONFIRMED') 'confirmed commit remains critical if later verification fails'

$cases=@(
    @{name='run-id-mismatch';mutate={param($p,$t)$t.run_id='aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'}},
    @{name='pending-phase-mismatch';mutate={param($p,$t)$p.phase='RUNNER_EXITED'}},
    @{name='temporary-phase-mismatch';mutate={param($p,$t)$t.phase='RUNNER_EXITED'}},
    @{name='child-finished-claimed';mutate={param($p,$t)$t.child_finished=$true}},
    @{name='automatic-retry';mutate={param($p,$t)$t.automatic_retry=$true}},
    @{name='wrong-account';mutate={param($p,$t)$t.account='other@127.0.0.1'}},
    @{name='wrong-target';mutate={param($p,$t)$t.target_uuid='00000000-0000-0000-0000-000000000000'}},
    @{name='missing-pid';mutate={param($p,$t)$t.child_pid=$null}},
    @{name='missing-start-ticks';mutate={param($p,$t)$t.child_started_ticks=$null}},
    @{name='invalid-timestamp';mutate={param($p,$t)$t.updated_utc='not-a-timestamp'}},
    @{name='child-start-outside-journal-times';mutate={param($p,$t)$t.updated_utc='2026-11-25T03:07:10.9000000Z'}}
)
foreach($case in $cases){
    $p=$pending.PSObject.Copy();$t=$temporary.PSObject.Copy();& $case.mutate $p $t
    $rejected=$false;try{[void](Assert-Migration011ExceptionalRecoveryEvidence $p $t 1)}catch{$rejected=$_.Exception.Message -ceq 'MIGRATION_011_EXCEPTIONAL_EVIDENCE_REJECTED'}
    Assert $rejected ("rejects "+$case.name)
}
$badCount=$false;try{[void](Assert-Migration011ExceptionalRecoveryEvidence $pending $temporary 2)}catch{$badCount=$_.Exception.Message -ceq 'MIGRATION_011_EXCEPTIONAL_EVIDENCE_REJECTED'}
Assert $badCount 'rejects ambiguous temporary journal inventory'
$badJson=$false;try{[void](Assert-Migration011ExceptionalRecoveryEvidence $pending $null 1)}catch{$badJson=$_.Exception.Message -ceq 'MIGRATION_011_EXCEPTIONAL_EVIDENCE_REJECTED'}
Assert $badJson 'rejects missing temporary journal'

$stopped=Assert-Migration011ExceptionalChildNotRunning 2147483000 ([long]1)
Assert ($stopped -ceq 'PROCESS_NOT_RUNNING') 'accepts only a confirmed absent child PID'
$current=[Diagnostics.Process]::GetCurrentProcess();try{$currentTicks=$current.StartTime.ToUniversalTime().Ticks;$sameRejected=$false;try{[void](Assert-Migration011ExceptionalChildNotRunning $current.Id $currentTicks)}catch{$sameRejected=$_.Exception.Message -ceq 'MIGRATION_011_EXCEPTIONAL_CHILD_STILL_RUNNING'}}finally{$current.Dispose()}
Assert $sameRejected 'rejects a matching live child process identity'

$wrapper=Get-Content -LiteralPath (Join-Path $PSScriptRoot '../scripts/migration011-ledger-reconcile.ps1') -Raw
$readOnlyPreflight=Get-Content -LiteralPath (Join-Path $PSScriptRoot '../scripts/migration011-exceptional-recovery-preflight.ps1') -Raw
$cliLibrary=Get-Content -LiteralPath (Join-Path $PSScriptRoot '../scripts/lib/migration011-reconciliation-cli.ps1') -Raw
Assert ($wrapper.Contains('$marker.phase -cne ''RUNNER_EXITED''') -and $wrapper.Contains('$marker.child_finished -ne $true')) 'standard marker guard remains strict'
Assert ($wrapper.Contains('Assert-Migration011ExceptionalApproval') -and $cliLibrary.Contains('TEST_MIGRATION_011_EXCEPTIONAL_LEDGER_RECOVERY_APPROVED') -and $cliLibrary.Contains('TEST_MIGRATION_011_EXCEPTIONAL_RECOVERY_ATTEMPT_APPROVED') -and $cliLibrary.Contains('APPROVE_MIGRATION_011_EXCEPTIONAL_ATTEMPT_')) 'exceptional path requires new attempt-bound token and dedicated process gates'
Assert ($wrapper.Contains('New-Migration011ExceptionalAttemptAuditPaths') -and $wrapper.Contains('source_run_id=$marker.run_id;attempt_id=$AttemptId') -and $wrapper.Contains('prior_legacy_attempt_intent=''PRESERVED_UNVERIFIED''')) 'new attempt audit links back to old run while preserving prior unverified evidence'
Assert ($wrapper.Contains("runner_exit_status='UNVERIFIED'") -and $wrapper.Contains("recovery_marker='PRESERVED'")) 'exceptional audit does not claim runner completion and retains marker'
Assert ($wrapper.Contains('Write-Migration011ExceptionalAuditRecord $auditIntentPath') -and $wrapper.Contains('Write-Migration011ExceptionalAuditRecord $auditCompletePath')) 'exceptional intent and completion evidence are separate records'
Assert ($wrapper.Contains('pending_sha256=$markerSha256') -and $wrapper.Contains('temporary_journal_sha256=$temporaryJournalSha256')) 'audit records bind both immutable journal snapshots by hash'
Assert ($wrapper.Contains('Get-Migration011ReconciliationFailureSummary') -and $wrapper.Contains('failed_stage=$failureSummary.failed_stage') -and $wrapper.Contains('exception_type=$failureSummary.exception_type')) 'wrapper failure JSON preserves safe stage and exception metadata'
Assert ($wrapper.Contains("record='EXCEPTIONAL_LEDGER_RECOVERY_FAILURE'") -and $wrapper.Contains('commit_state=$failureSummary.commit_state')) 'exceptional failures write create-only audit evidence with commit state'
Assert ($wrapper.Contains('Invoke-Migration011LedgerReconciliationProtocol') -and $wrapper.Contains('-BackupVerify $verifyBackup') -and $wrapper.Contains('-RecoveryMarkerVerify $verifyMarker')) 'exceptional execution reuses the full guarded DB protocol'
Assert ($readOnlyPreflight.Contains("'-Action','Diagnostics'") -and $readOnlyPreflight.Contains('ready_for_new_approval=$true') -and $readOnlyPreflight.Contains('Assert-Migration011ExceptionalAttemptAvailable')) 'new-attempt dry run uses read-only DB diagnostics and rejects consumed attempt IDs'
Assert ($readOnlyPreflight.Contains('[string]$AttemptId') -and $readOnlyPreflight.Contains('attempt_id=$AttemptId') -and $readOnlyPreflight.Contains('legacy_attempt_audit=')) 'read-only preflight binds and reports the fresh attempt separately from legacy audit evidence'
Assert (!$readOnlyPreflight.Contains('Invoke-Migration011LedgerReconciliationProtocol') -and !$readOnlyPreflight.Contains('Complete-Migration011ReconciliationMarker') -and $readOnlyPreflight -notmatch '(?i)\b(UPDATE\s+schema_migrations|DELETE\s+FROM|ALTER\s+TABLE|CREATE\s+TABLE|DROP\s+TABLE)\b') 'exceptional dry run cannot mutate schema, ledger, or marker'

$gateNames=@('TEST_DB_EXECUTION_APPROVED','TEST_MIGRATION_011_EXCEPTIONAL_LEDGER_RECOVERY_APPROVED','TEST_MIGRATION_011_EXCEPTIONAL_RECOVERY_ATTEMPT_APPROVED');$oldGates=@{}
foreach($name in $gateNames){$oldGates[$name]=[Environment]::GetEnvironmentVariable($name,'Process');[Environment]::SetEnvironmentVariable($name,$null,'Process')}
try{
    $missingGate=$false;try{[void](Assert-Migration011ExceptionalApproval ('APPROVE_MIGRATION_011_EXCEPTIONAL_ATTEMPT_'+$attempt) $attempt)}catch{$missingGate=$_.Exception.Message -ceq 'MIGRATION_011_EXCEPTIONAL_APPROVAL_REQUIRED'}
    Assert $missingGate 'exceptional recovery fails closed without all process gates'
    foreach($name in $gateNames){[Environment]::SetEnvironmentVariable($name,'true','Process')}
    $wrongToken=$false;try{[void](Assert-Migration011ExceptionalApproval 'RECONCILE_MIGRATION_011_LEDGER_ONLY' $attempt)}catch{$wrongToken=$_.Exception.Message -ceq 'MIGRATION_011_EXCEPTIONAL_APPROVAL_REQUIRED'}
    Assert $wrongToken 'exceptional recovery rejects the standard or wrong approval token'
    $wrongAttempt=$false;try{[void](Assert-Migration011ExceptionalApproval ('APPROVE_MIGRATION_011_EXCEPTIONAL_ATTEMPT_'+$run) $attempt)}catch{$wrongAttempt=$_.Exception.Message -ceq 'MIGRATION_011_EXCEPTIONAL_APPROVAL_REQUIRED'}
    Assert $wrongAttempt 'exceptional approval token cannot be replayed against a different attempt ID'
    Assert (Assert-Migration011ExceptionalApproval ('APPROVE_MIGRATION_011_EXCEPTIONAL_ATTEMPT_'+$attempt) $attempt) 'exceptional recovery accepts only the attempt-bound token and all three gates'
}finally{foreach($name in $gateNames){[Environment]::SetEnvironmentVariable($name,$oldGates[$name],'Process')}}

$root=Join-Path ([IO.Path]::GetTempPath()) ('migration011-exceptional-audit-'+[guid]::NewGuid().ToString('N'))
$auditPath=$null
try{
    New-Migration011PrivateDirectory $root
    $auditPath=Join-Path $root 'fixture.intent.json'
    Write-Migration011ExceptionalAuditRecord $auditPath ([ordered]@{record='EXCEPTIONAL_LEDGER_RECOVERY_INTENT';runner_exit_status='UNVERIFIED';run_id=$run;ledger_changed=$false;marker_modified=$false})
    $read=Get-Content -LiteralPath $auditPath -Raw -Encoding UTF8|ConvertFrom-Json
    Assert ($read.runner_exit_status -ceq 'UNVERIFIED' -and $read.ledger_changed -eq $false -and $read.marker_modified -eq $false) 'writes sanitized audit record under restricted ACL'
    $duplicateRejected=$false;try{Write-Migration011ExceptionalAuditRecord $auditPath ([ordered]@{record='DUPLICATE'})}catch{$duplicateRejected=$_.Exception.Message -ceq 'MIGRATION_011_EXCEPTIONAL_AUDIT_WRITE_FAILED'}
    Assert $duplicateRejected 'audit record is create-only and rejects overwrite/replay'
}finally{
    if(Test-Path -LiteralPath $root){
        $resolved=[IO.Path]::GetFullPath($root);$tempRoot=[IO.Path]::GetFullPath([IO.Path]::GetTempPath())
        if(!$resolved.StartsWith($tempRoot,[StringComparison]::OrdinalIgnoreCase) -or [IO.Path]::GetFileName($resolved) -notlike 'migration011-exceptional-audit-*'){throw 'OFFLINE_TEST_TEMP_PATH_REJECTED'}
        Remove-Item -LiteralPath $resolved -Recurse -Force
    }
}

$productionScripts=@('../scripts/migration011-ledger-reconcile.ps1','../scripts/migration011-exceptional-recovery-preflight.ps1')
foreach($relative in $productionScripts){$tokens=$null;$parseErrors=$null;[Management.Automation.Language.Parser]::ParseFile((Join-Path $PSScriptRoot $relative),[ref]$tokens,[ref]$parseErrors)|Out-Null;Assert ($parseErrors.Count -eq 0) ("$(Split-Path -Leaf $relative) remains valid PowerShell syntax")}
Write-Output "PASS: $checks Migration 011 exceptional recovery offline checks; no MySQL connection made."
