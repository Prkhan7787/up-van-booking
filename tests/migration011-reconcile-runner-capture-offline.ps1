#Requires -Version 5.1
#Requires -PSEdition Desktop
param([switch]$MediumLauncherFixture)
$ErrorActionPreference='Stop'
Set-StrictMode -Version Latest
. (Join-Path $PSScriptRoot '../scripts/lib/migration011-apply-credentials.ps1')
. (Join-Path $PSScriptRoot '../scripts/lib/migration011-reconciliation-cli.ps1')
$checks=0
function Assert([bool]$Condition,[string]$Message){if(!$Condition){throw "FAIL: $Message"};$script:checks++}
if($PSVersionTable.PSVersion.Major -ne 5){throw 'TEST_REQUIRES_WINDOWS_POWERSHELL_5_1'}
$powershell=Join-Path $PSHOME 'powershell.exe'
$fixture=Join-Path $PSScriptRoot 'fixtures/migration011-reconcile-runner-mock.ps1'
function Invoke-Fixture([string]$Scenario){
    return (Invoke-Migration011ReconciliationRunnerProcess -RunnerPath $fixture -AttemptId 'e4163e43ea9e40e2bba9bfc7606fe542' `
        -ApprovalToken 'APPROVE_MIGRATION_011_EXCEPTIONAL_ATTEMPT_e4163e43ea9e40e2bba9bfc7606fe542' `
        -BackupDirectory $Scenario -BackupManifestSha256 ('a'*64) -PowerShellPath $powershell -WorkingDirectory $PSScriptRoot)
}

$structured=Invoke-Fixture 'StructuredFailure'
Assert ($structured.status -ceq 'FAIL' -and $structured.child_exit_code -eq 1) 'nonzero child exit keeps structured status and exact child exit code'
Assert ($structured.failed_stage -ceq 'PRECONDITIONS' -and $structured.exception_type -ceq 'MockPreconditionException') 'wrapper preserves structured failed stage and exception type'
Assert ($structured.transaction_state -ceq 'NOT_STARTED' -and $structured.commit_state -ceq 'NOT_COMMITTED' -and $structured.audit_write_status -ceq 'FAILURE_WRITTEN') 'wrapper preserves transaction, commit and audit status'
Assert ($structured.attempt_id -ceq 'e4163e43ea9e40e2bba9bfc7606fe542' -and $structured.source_run_id -ceq '569a48a5f6404c95a5f0771de3e04dc4') 'wrapper preserves attempt/source correlation'
Assert (($structured|ConvertTo-Json -Compress) -notmatch 'SECRET_RAW_ERROR_MUST_NOT_ESCAPE') 'stderr/raw output is never surfaced in structured result'

$noOutput=Invoke-Fixture 'NoOutput'
Assert ($noOutput.status -ceq 'CRITICAL' -and $noOutput.child_exit_code -eq 1) 'exit 1 without structured output is reported as critical with exit code'
Assert ($noOutput.failed_stage -ceq 'CHILD_PROCESS_OUTPUT' -and $noOutput.transaction_state -ceq 'UNKNOWN' -and $noOutput.commit_state -ceq 'UNKNOWN') 'missing output forces explicit stage and unknown transaction/commit states'
Assert ($noOutput.audit_write_status -ceq 'UNKNOWN' -and ($noOutput|ConvertTo-Json -Compress) -notmatch 'SECRET_RAW_ERROR_MUST_NOT_ESCAPE') 'missing output reports audit status unknown without leaking stderr'

$before=Invoke-Fixture 'BeforeTransaction'
Assert ($before.failed_stage -ceq 'DBA_SESSION' -and $before.transaction_state -ceq 'NOT_STARTED' -and $before.commit_state -ceq 'NOT_COMMITTED') 'pre-transaction exception survives the child process boundary'
$during=Invoke-Fixture 'DuringTransaction'
Assert ($during.failed_stage -ceq 'LEDGER_UPDATE' -and $during.transaction_state -ceq 'ROLLED_BACK' -and $during.commit_state -ceq 'NOT_COMMITTED') 'in-transaction exception survives with rollback state'
$audit=Invoke-Fixture 'AuditWriteFailure'
Assert ($audit.audit_write_status -ceq 'FAILURE_WRITE_FAILED' -and $audit.failed_stage -ceq 'PRECONDITIONS') 'audit writer failure is explicit instead of hidden by generic failure'
$commit=Invoke-Fixture 'CommitUnknown'
Assert ($commit.status -ceq 'CRITICAL' -and $commit.commit_state -ceq 'UNKNOWN' -and $commit.failed_stage -ceq 'COMMIT') 'unknown commit remains critical across wrapper boundary'

$inconsistent=ConvertFrom-Migration011RunnerChildOutput 1 '{"status":"PASS","transaction_state":"COMMITTED","commit_state":"CONFIRMED"}'
Assert ($inconsistent.status -ceq 'CRITICAL' -and $inconsistent.child_exit_code -eq 1 -and $inconsistent.commit_state -ceq 'CONFIRMED') 'PASS payload paired with exit 1 is promoted to critical inconsistency'
$launcher=Get-Content -LiteralPath (Join-Path $PSScriptRoot '../scripts/migration011-ledger-reconcile-launcher.ps1') -Raw
Assert ($launcher.Contains('Invoke-Migration011ReconciliationRunnerProcess') -and $launcher.Contains('ConvertTo-Json') -and !$launcher.Contains('StandardError.ReadToEnd().Result')) 'dedicated launcher emits normalized JSON and does not surface raw stderr'
$tokens=$null;$errors=$null
[Management.Automation.Language.Parser]::ParseFile((Join-Path $PSScriptRoot '../scripts/migration011-ledger-reconcile-launcher.ps1'),[ref]$tokens,[ref]$errors)|Out-Null
Assert ($errors.Count -eq 0) 'launcher parses under Windows PowerShell 5.1'
$tokens=$null;$errors=$null
[Management.Automation.Language.Parser]::ParseFile($fixture,[ref]$tokens,[ref]$errors)|Out-Null
Assert ($errors.Count -eq 0) 'mock child fixture parses under Windows PowerShell 5.1'

$sqlArguments=@('-NoProfile','-NonInteractive','-ExecutionPolicy','Bypass','-File',$fixture,'-SqlProtocol')
$wire=New-Migration011MysqlLineSession $powershell $sqlArguments
try {
    $wire.Execute('START TRANSACTION')
    $rowCount=$wire.ExecuteAffectedRows("UPDATE schema_migrations SET status='fixture' WHERE version='fixture'")
    Assert ($rowCount -ceq '1') 'actual line transport delimits BEGIN and captures affected rows before issuing the sentinel'
} finally {$wire.Dispose()}
Assert ($wire.ExitCode -eq 0) 'line fixture consumed only the expected SQL statements'

if($MediumLauncherFixture){
    $groupText=& whoami.exe /groups | Out-String
    Assert ($LASTEXITCODE -eq 0 -and $groupText -match '\bS-1-16-8192\b') 'launcher fixture runs under Windows MEDIUM'
    # The real launcher is copied into a disposable directory with only a mock
    # sibling runner. Its local library copy accepts a fixture-only token.
    $fixtureRoot=Join-Path ([IO.Path]::GetTempPath()) ('upvan011-launcher-fixture-'+[guid]::NewGuid().ToString('N'))
    $fixtureLib=Join-Path $fixtureRoot 'lib'
    $fixtureLauncher=Join-Path $fixtureRoot 'migration011-ledger-reconcile-launcher.ps1'
    $fixtureRunner=Join-Path $fixtureRoot 'migration011-ledger-reconcile.ps1'
    $fixtureCli=Join-Path $fixtureLib 'migration011-reconciliation-cli.ps1'
    $fixtureCredentials=Join-Path $fixtureLib 'migration011-apply-credentials.ps1'
    $fixtureLifecycle=Join-Path $fixtureLib 'migration011-apply-lifecycle.ps1'
    $audit=Join-Path $fixtureRoot 'audit'
    $intent=Join-Path $audit 'fixture.intent.json'
    $completion=Join-Path $audit 'fixture.completed.json'
    $failureAudit=Join-Path $audit 'fixture.failed.json'
    try {
        [void][IO.Directory]::CreateDirectory($fixtureLib)
        [IO.File]::WriteAllText($fixtureLauncher,$launcher)
        Copy-Item -LiteralPath $fixture -Destination $fixtureRunner
        Copy-Item -LiteralPath (Join-Path $PSScriptRoot '../scripts/lib/migration011-apply-credentials.ps1') -Destination $fixtureCredentials
        $library=[IO.File]::ReadAllText((Join-Path $PSScriptRoot '../scripts/lib/migration011-reconciliation-cli.ps1'))
        $approvalStub=@'
function Assert-Migration011ExceptionalApproval([string]$ApprovalToken,[string]$AttemptId) {
    if($ApprovalToken -cne 'offline-fixture-only' -or $AttemptId -cne ('0'*32)){
        throw 'FIXTURE_APPROVAL_REJECTED'
    }
    return $true
}
'@
        [IO.File]::WriteAllText($fixtureCli,$library+[Environment]::NewLine+$approvalStub)
        $launchArgs=@('-NoProfile','-NonInteractive','-ExecutionPolicy','Bypass','-File',$fixtureLauncher,
            '-BackupDirectory','StructuredFailure','-BackupManifestSha256',('a'*64),'-AttemptId',('0'*32),'-ApprovalToken','offline-fixture-only')
        $bootstrap=Invoke-Migration011CapturedChildProcess -Executable $powershell -Arguments $launchArgs
        Assert ($bootstrap.status -ceq 'BLOCKED' -and $bootstrap.failed_stage -ceq 'LAUNCHER_BOOTSTRAP') 'missing launcher dependency still emits a structured failure'
        Copy-Item -LiteralPath (Join-Path $PSScriptRoot '../scripts/lib/migration011-apply-lifecycle.ps1') -Destination $fixtureLifecycle
        $throughLauncher=Invoke-Migration011CapturedChildProcess -Executable $powershell -Arguments $launchArgs
        Assert ($throughLauncher.status -ceq 'FAIL' -and $throughLauncher.child_exit_code -eq 1) "launcher exits 1 and emits a structured failure: $($throughLauncher | ConvertTo-Json -Compress)"
        Assert ($throughLauncher.failed_stage -ceq 'PRECONDITIONS' -and $throughLauncher.exception_type -ceq 'MockPreconditionException') 'launcher preserves failure stage and exception type under StrictMode'
        Assert ($throughLauncher.transaction_state -ceq 'NOT_STARTED' -and $throughLauncher.commit_state -ceq 'NOT_COMMITTED' -and $throughLauncher.audit_write_status -ceq 'FAILURE_WRITTEN') 'launcher preserves transaction, commit and audit fields'

        New-Migration011PrivateDirectory $audit
        Assert-Migration011ExceptionalAuditDirectory $audit
        Assert ($true) 'fixture audit directory ACL meets the production checks'
        Write-Migration011ExceptionalAuditRecord $intent @{record='INTENT_FIXTURE'}
        [IO.File]::WriteAllText($completion,'partial completion fixture')
        $intentHash=(Get-FileHash -LiteralPath $intent -Algorithm SHA256).Hash
        $record=@{record='FAILURE_FIXTURE';failed_stage='AUDIT_COMPLETE';commit_state='CONFIRMED'}
        $auditStatus=Write-Migration011ExceptionalFailureAudit $failureAudit $record $true
        Assert ($auditStatus -ceq 'COMPLETE_FAILED_FAILURE_WRITTEN' -and [IO.File]::Exists($failureAudit)) 'completion file existence does not prevent writing a separate failure audit'
        $duplicate=Write-Migration011ExceptionalFailureAudit $failureAudit $record $true
        Assert ($duplicate -ceq 'COMPLETE_FAILED_FAILURE_WRITE_FAILED') 'failure audit write rejection is reported'
        Assert ((Get-FileHash -LiteralPath $intent -Algorithm SHA256).Hash -ceq $intentHash -and [IO.File]::ReadAllText($completion) -ceq 'partial completion fixture') 'audit fallback preserves preexisting evidence'
    } finally {
        if([IO.Directory]::Exists($audit)){
            foreach($auditFile in @($intent,$completion,$failureAudit)){[IO.File]::Delete($auditFile)}
            [IO.Directory]::Delete($audit)
        }
        if([IO.Directory]::Exists($fixtureLib)){
            foreach($fixtureFile in @($fixtureCli,$fixtureCredentials,$fixtureLifecycle)){[IO.File]::Delete($fixtureFile)}
        }
        foreach($fixtureFile in @($fixtureRunner,$fixtureLauncher)){[IO.File]::Delete($fixtureFile)}
        if([IO.Directory]::Exists($fixtureLib)){[IO.Directory]::Delete($fixtureLib)}
        if([IO.Directory]::Exists($fixtureRoot)){[IO.Directory]::Delete($fixtureRoot)}
    }
}

Write-Output "PASS: $checks Migration 011 runner capture offline checks; mock child processes only; no MySQL connection made."
