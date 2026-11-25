#Requires -Version 5.1
$ErrorActionPreference='Stop'
. (Join-Path $PSScriptRoot '../scripts/lib/migration011-apply-credentials.ps1')
$checks=0
function Assert([bool]$Ok,[string]$Message){if(!$Ok){throw "FAIL: $Message"};$script:checks++}
function Run-Scenario([string]$Failure='', [string]$Action='Apply'){
    $fixture=@{failure=$Failure;calls=[Collections.Generic.List[string]]::new();pending=$false}
    $ops=@{}
    foreach($name in @('Runtime','Pending','Approval','Backup','Preflight','Identity','Mark','Unlock','Apply','Relock','Complete','Recovery')){
        $operation=$name
        $ops[$operation]={
            $fixture.calls.Add($operation)
            if($operation -eq 'Pending' -and $fixture.failure -eq 'Crash'){throw 'MIGRATION_011_RECOVERY_REQUIRED_NO_RETRY'}
            if($operation -eq 'Preflight' -and $fixture.failure -eq 'AlreadyApplied'){throw 'MIGRATION_011_DEPENDENCY_SET_REJECTED'}
            if($operation -eq 'Backup' -and $fixture.failure -eq 'ExpiredAfterPreflight' -and @($fixture.calls|Where-Object{$_ -eq 'Backup'}).Count -eq 2){throw 'MIGRATION_011_BACKUP_STALE_OR_STATE_REJECTED'}
            if($operation -eq $fixture.failure){throw ('MIGRATION_011_MOCK_'+$operation.ToUpperInvariant())}
            if($operation -eq 'Mark'){$fixture.pending=$true}
            if($operation -eq 'Complete'){$fixture.pending=$false}
            if($operation -eq 'Apply'){return [pscustomobject]@{status='PASS';ledger='applied 1/1'}}
        }.GetNewClosure()
    }
    $result=Invoke-Migration011ApplyLifecycle -Action $Action -Operations $ops
    $script:calls=$fixture.calls;$script:pendingFixture=$fixture.pending
    return $result
}
$r=Run-Scenario
Assert ($r.status -ceq 'PASS' -and $r.account_lock_final -ceq 'Y' -and $r.ledger -ceq 'applied 1/1') 'complete apply and verified relock required for PASS'
Assert (($calls -join ',') -ceq 'Runtime,Pending,Approval,Backup,Preflight,Backup,Approval,Identity,Mark,Unlock,Apply,Relock,Complete') 'guards repeat immediately before unlock; cleanup follows apply'
Assert (!$pendingFixture) 'only fully confirmed success archives pending intent'
foreach($failure in @('Runtime','Approval','Backup','Preflight','Identity')){
    $r=Run-Scenario $failure
    Assert ($r.status -ceq 'FAIL' -and !$calls.Contains('Unlock') -and !$calls.Contains('Apply')) "$failure failure prevents unlock and execution"
}
$r=Run-Scenario 'Unlock'
Assert ($r.status -ceq 'FAIL' -and $calls.Contains('Relock') -and !$calls.Contains('Apply') -and $r.account_lock_final -ceq 'Y') 'unconfirmed unlock always attempts and verifies relock'
$r=Run-Scenario 'Apply'
Assert ($r.status -ceq 'FAIL' -and $r.account_lock_final -ceq 'Y' -and $pendingFixture -and $r.recovery_required) 'apply exception locks account and retains recovery evidence'
Assert ($r.failed_stage -ceq 'RUNNER_DISPATCH' -and $r.runner_status -ceq 'START_REQUESTED' -and $r.exception_type) 'dispatch exception never misreports NOT_RUN and retains safe exception type'
$r=Run-Scenario 'Relock'
Assert ($r.status -ceq 'CRITICAL' -and $r.account_lock_final -ceq 'UNKNOWN' -and $pendingFixture -and !$calls.Contains('Complete')) 'relock failure overrides successful DDL outcome'
$r=Run-Scenario '' 'Preflight'
Assert ($r.status -ceq 'PASS' -and !$calls.Contains('Unlock') -and !$calls.Contains('Apply') -and !$calls.Contains('Mark')) 'preflight never unlocks or dispatches runner'
$r=Run-Scenario 'Crash'
Assert ($r.code -ceq 'MIGRATION_011_RECOVERY_REQUIRED_NO_RETRY' -and !$calls.Contains('Unlock')) 'pending crash evidence blocks later apply'
$r=Run-Scenario '' 'Recover'
Assert ($r.status -ceq 'RECOVERED_LOCK_ONLY' -and $r.account_lock_final -ceq 'Y' -and ($calls -join ',') -ceq 'Identity,Recovery,Relock') 'crash recovery only checks identity and relocks; never executes migration'
Assert $r.recovery_required 'recovery does not authorize resume or clear pending migration state'
$r=Run-Scenario 'Relock' 'Recover'
Assert ($r.status -ceq 'CRITICAL') 'failed recovery is critical'
$r=Run-Scenario 'AlreadyApplied'
Assert (!$calls.Contains('Unlock') -and !$calls.Contains('Apply')) 'already-applied ledger prevents double apply'
$r=Run-Scenario 'ExpiredAfterPreflight'
Assert ($r.code -ceq 'MIGRATION_011_BACKUP_STALE_OR_STATE_REJECTED' -and !$calls.Contains('Unlock')) 'backup expiring during preflight prevents unlock'
$r=Run-Scenario 'Complete'
Assert ($r.status -ceq 'FAIL' -and $r.account_lock_final -ceq 'Y' -and $r.recovery_required) 'journal finalization failure preserves verified lock fact and never reports complete success'
$before1=$env:TEST_DB_EXECUTION_APPROVED;$before2=$env:TEST_MIGRATION_011_APPLY_APPROVED
try{
    # Do not set either gate to true, even in this offline process.
    Remove-Item Env:\TEST_DB_EXECUTION_APPROVED -ErrorAction SilentlyContinue
    Remove-Item Env:\TEST_MIGRATION_011_APPLY_APPROVED -ErrorAction SilentlyContinue
    $rejected=$false;try{Assert-Migration011WrapperApproval 'APPLY_MIGRATION_011_TO_UP_VAN_BOOKING_TEST'}catch{$rejected=$_.Exception.Message -ceq 'MIGRATION_011_APPROVAL_REQUIRED'}
    Assert $rejected 'real approval checker rejects absent gates without opening them'
}finally{
    if($null -ne $before1){$env:TEST_DB_EXECUTION_APPROVED=$before1}
    if($null -ne $before2){$env:TEST_MIGRATION_011_APPLY_APPROVED=$before2}
}
$runtime=Assert-Migration011ApplyRuntime (Split-Path -Parent $PSScriptRoot)
Assert ([IO.File]::Exists($runtime)) 'actual project PHP 8.4, extensions, runner and pinned SQL load without MySQL'
$directory=Join-Path ([IO.Path]::GetTempPath()) ('upvan011-journal-fixture-'+[guid]::NewGuid().ToString('N'))
[void][IO.Directory]::CreateDirectory($directory)
try{
    $pending=Join-Path $directory 'pending.json';$temporary=Join-Path $directory 'next.json'
    [IO.File]::WriteAllText($temporary,'intent')
    Publish-Migration011Journal $temporary $pending
    Assert ([IO.File]::ReadAllText($pending) -ceq 'intent') 'first journal publication creates intent'
    [IO.File]::WriteAllText($temporary,'runner-started')
    Publish-Migration011Journal $temporary $pending
    Assert ([IO.File]::ReadAllText($pending) -ceq 'runner-started') 'PS 5.1 replacement uses valid backup path and publishes runner state'
    $previous=@([IO.Directory]::GetFiles($directory,'pending.json.previous-*'))
    Assert ($previous.Count -eq 1 -and [IO.File]::ReadAllText($previous[0]) -ceq 'intent') 'atomic replacement preserves previous journal evidence'
    $secure=ConvertTo-SecureString 'offline-dummy-password-only' -AsPlainText -Force
    try{
        $rejected=$false
        try{$null=Invoke-Migration011RunnerChild -Password $secure -RunnerArguments @() -Mock -OnStarted {throw 'synthetic journal failure'}}
        catch{$rejected=$_.Exception.Message -ceq 'MIGRATION_011_RUNNER_JOURNAL_FAILED_NO_RETRY'}
        Assert $rejected 'failed journal callback waits for mock child exit and reports a specific no-retry code'
    }finally{$secure.Dispose()}
}finally{
    foreach($file in [IO.Directory]::GetFiles($directory)){[IO.File]::Delete($file)}
    [IO.Directory]::Delete($directory)
}
$markerDirectory=Join-Path ([IO.Path]::GetTempPath()) ('upvan011-marker-fixture-'+[guid]::NewGuid().ToString('N'))
New-Migration011PrivateDirectory $markerDirectory
try{
    $runId=[guid]::NewGuid().ToString('N');$pending=Join-Path $markerDirectory 'pending.json'
    $marker=[ordered]@{run_id=$runId;phase='RUNNER_EXITED';child_pid=12345;child_started_ticks=639311728309442570;child_finished=$true
        automatic_retry=$false;account='up_van_migration011_apply@127.0.0.1';target_uuid='77218fbd-d66d-11f1-9a0a-5c2886f057d5'}
    [IO.File]::WriteAllText($pending,(ConvertTo-Json $marker -Compress),[Text.UTF8Encoding]::new($false));Assert-Migration011CredentialFileAcl $pending
    $rejected=$false;try{Complete-Migration011ReconciliationMarker $pending $runId $false $true $true|Out-Null}catch{$rejected=$_.Exception.Message -ceq 'MIGRATION_011_RECONCILIATION_MARKER_FINALIZE_GUARD_REJECTED'}
    Assert ($rejected -and [IO.File]::Exists($pending)) 'marker cannot archive unless ledger, schema and lock have all been verified'
    $archive=Join-Path $markerDirectory ($runId+'.reconciled.json');[IO.File]::WriteAllText($archive,'reserved')
    $rejected=$false;try{Complete-Migration011ReconciliationMarker $pending $runId $true $true $true|Out-Null}catch{$rejected=$_.Exception.Message -ceq 'MIGRATION_011_RECONCILIATION_MARKER_ARCHIVE_EXISTS'}
    Assert ($rejected -and [IO.File]::Exists($pending)) 'archive collision preserves pending recovery evidence'
    [IO.File]::Delete($archive)
    $final=Complete-Migration011ReconciliationMarker $pending $runId $true $true $true
    Assert ($final.marker_archived -and [IO.File]::Exists($final.marker_archive) -and ![IO.File]::Exists($pending)) 'verified reconciliation atomically archives only its matching recovery marker'
}finally{
    foreach($file in [IO.Directory]::GetFiles($markerDirectory)){[IO.File]::Delete($file)}
    [IO.Directory]::Delete($markerDirectory)
}
Write-Output "PASS: $checks Migration 011 apply lifecycle offline checks; mocks only, no account management or migration executed."
