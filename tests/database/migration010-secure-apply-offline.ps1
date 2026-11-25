#Requires -Version 5.1
#Requires -PSEdition Desktop
$ErrorActionPreference='Stop'
Set-StrictMode -Version Latest
. (Join-Path $PSScriptRoot '../../scripts/lib/migration010-apply-lifecycle.ps1')

$checks=0
function Assert([bool]$Pass,[string]$Description){
    if(!$Pass){throw "FAIL: $Description"}
    $script:checks++
}

function Test-Lifecycle([string]$Action,[string[]]$Failures=@()) {
    $trace=[Collections.Generic.List[string]]::new()
    $ops=@{}
    foreach($operation in @('NoPending','Approval','Preflight','MarkIntent','Unlock','Run','Lock','Postflight','Complete','VerifyRecovery','NoRunnerOrSessions')){
        $ops[$operation]={
            $trace.Add($operation)
            if($Failures -ccontains $operation){throw 'mock-only-private-diagnostic'}
        }.GetNewClosure()
    }
    $r=Invoke-Migration010ApplyLifecycle $Action $ops
    return @{result=$r;trace=($trace -join ',')}
}

foreach($mode in @('Inspect','Preflight')){
    $r=Test-Lifecycle $mode
    Assert ($r.result.status -ceq 'PASS' -and $r.trace -ceq 'NoPending,Preflight') "$mode never opens account management"
}
$r=Test-Lifecycle 'Apply' @('Approval')
Assert ($r.trace -ceq 'NoPending,Approval' -and $r.result.status -ceq 'BLOCKED') 'closed gates stop before preflight/unlock'
$r=Test-Lifecycle 'Apply' @('Preflight')
Assert ($r.trace -ceq 'NoPending,Approval,Preflight' -and $r.result.status -ceq 'BLOCKED') 'backup or credential failures stop before journal/unlock'
$r=Test-Lifecycle 'Apply' @('MarkIntent')
Assert ($r.trace -notmatch 'Unlock,|,Lock' -and $r.result.recovery_required) 'failed intent cannot unlock and requires inspection'
$r=Test-Lifecycle 'Apply' @('Unlock')
Assert ($r.trace -ceq 'NoPending,Approval,Preflight,MarkIntent,Unlock,Lock' -and $r.result.account_lock -ceq 'Y' -and $r.result.recovery_required) 'ambiguous unlock always attempts relock'
$r=Test-Lifecycle 'Apply' @('Run')
Assert ($r.trace -ceq 'NoPending,Approval,Preflight,MarkIntent,Unlock,Run,Lock' -and $r.result.status -ceq 'FAIL' -and $r.result.recovery_required) 'runner failure relocks and preserves pending marker'
$r=Test-Lifecycle 'Apply' @('Run','Lock')
Assert ($r.result.status -ceq 'CRITICAL' -and $r.result.account_lock -ceq 'UNKNOWN' -and $r.result.cleanup_stage -ceq 'RELOCK') 'failed relock after runner failure remains critical'
Assert (($r.result | ConvertTo-Json) -notmatch 'mock-only-private-diagnostic') 'raw exceptions are omitted'
$r=Test-Lifecycle 'Apply' @('Postflight')
Assert ($r.result.status -ceq 'CRITICAL' -and $r.result.account_lock -ceq 'Y' -and $r.trace -notmatch 'Complete') 'postflight failure retains the verified lock and pending marker'
$r=Test-Lifecycle 'Apply'
Assert ($r.trace -ceq 'NoPending,Approval,Preflight,MarkIntent,Unlock,Run,Lock,Postflight,Complete' -and $r.result.status -ceq 'PASS' -and !$r.result.recovery_required) 'completion requires live relock and postflight'
$r=Test-Lifecycle 'Apply' @('NoPending')
Assert ($r.trace -ceq 'NoPending' -and $r.result.recovery_required) 'pending marker after a killed process blocks a new apply'
$r=Test-Lifecycle 'Recover' @('VerifyRecovery')
Assert ($r.trace -ceq 'VerifyRecovery' -and $r.result.status -ceq 'CRITICAL') 'invalid or missing recovery evidence blocks account management'
$r=Test-Lifecycle 'Recover' @('NoRunnerOrSessions')
Assert ($r.trace -ceq 'VerifyRecovery,Lock,NoRunnerOrSessions' -and $r.result.status -ceq 'CRITICAL' -and $r.result.account_lock -ceq 'Y') 'recovery relocks first and reports a surviving child or session'
$r=Test-Lifecycle 'Recover'
Assert ($r.trace -ceq 'VerifyRecovery,Lock,NoRunnerOrSessions' -and $r.result.status -ceq 'LOCK_VERIFIED_INSPECTION_REQUIRED' -and $r.result.recovery_required) 'lock-only recovery preserves journal and never runs migration'

$root=Join-Path ([IO.Path]::GetTempPath()) ('upvan010-offline-'+[guid]::NewGuid().ToString('N'))
$credential=$null
try {
    New-Migration011PrivateDirectory $root
    $file=Join-Path $root 'apply.dpapi'
    $metaPath=Join-Path $root 'metadata.json'
    $intent=Join-Path $root 'intent.json'
    $rejected=$false
    try{Read-Migration010ApplyCredential $root | Out-Null}catch{$rejected=$_.Exception.Message -ceq 'MIGRATION_010_CREDENTIAL_MISSING'}
    Assert $rejected 'missing dedicated DPAPI file is rejected'
    $dummy='offline-010-only!secret'
    [void](Save-Migration011DpapiCredential -Path $file -Password $dummy)
    $metadata=@{format='upvan-migration010-credential-v1';dpapi_scope='CurrentUser';windows_sid=[Security.Principal.WindowsIdentity]::GetCurrent().User.Value
        account='up_van_migration010_apply@127.0.0.1';host='127.0.0.1';port=3306;database='up_van_booking_test'
        uuid='77218fbd-d66d-11f1-9a0a-5c2886f057d5';mysql_version='8.4.9';plugin='caching_sha2_password'
        ciphertext_sha256=(Get-FileHash -LiteralPath $file -Algorithm SHA256).Hash.ToLowerInvariant()}
    Write-Migration010JournalRecord $metaPath $metadata
    $credential=Read-Migration010ApplyCredential $root
    Assert ($credential -is [Security.SecureString] -and $credential.Length -eq $dummy.Length) 'bound DPAPI credential decrypts under the current Windows user'
    foreach($field in @('account','windows_sid','uuid','host','database','ciphertext_sha256')){
        $changed=$metadata.Clone()
        $changed[$field]='not-the-approved-value'
        [IO.File]::WriteAllText($metaPath,(ConvertTo-Json -InputObject $changed -Compress))
        $rejected=$false
        try{Read-Migration010ApplyCredential $root | Out-Null}catch{$rejected=$_.Exception.Message -ceq 'MIGRATION_010_CREDENTIAL_METADATA_REJECTED'}
        Assert $rejected "credential metadata mismatch is blocked: $field"
    }
    Write-Migration010JournalRecord $intent @{phase='UNLOCK_INTENT'}
    $duplicate=$false
    try{Write-Migration010JournalRecord $intent @{phase='OVERWRITE'}}catch{$duplicate=$true}
    Assert ($duplicate -and (Get-Content -LiteralPath $intent -Raw).Contains('UNLOCK_INTENT')) 'journal intent is create-only'

    $repo=Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
    $php=Resolve-Migration011EvaluatorRuntime $repo
    $program=@'
$raw=stream_get_contents(STDIN);
$argvText=implode(" ",$argv);
if (getenv("MIGRATION_010_APPLY_DB_PASSWORD")!==false || str_contains($argvText,"offline-010-only!secret")) exit(2);
if ($raw!=="offline-010-only!secret\0") exit(3);
fwrite(STDERR,"offline-010-only!secret");
echo "PIPE_FRAME_OK";
'@
    # The mock program itself is a fixture file. Its test value isn't in the launched arguments.
    $fixturePath=Join-Path $root 'pipe-fixture.php'
    [IO.File]::WriteAllText($fixturePath,('<?php '+$program),[Text.UTF8Encoding]::new($false))
    $childJournal=Join-Path $root 'child.json'
    $before={param($id,$ticks) Write-Migration010JournalRecord $childJournal @{id=$id;ticks=$ticks}}.GetNewClosure()
    $child=Invoke-Migration010StdinChild $php @('-n',$fixturePath) (Split-Path -Parent $php) $credential $before
    Assert ($child.exit_code -eq 0 -and $child.stdout -ceq 'PIPE_FRAME_OK' -and [IO.File]::Exists($childJournal)) 'real PHP child reads the NUL-framed secret from stdin after PID journaling'
    Assert (!(($child | ConvertTo-Json -Compress).Contains($dummy))) 'child stderr is withheld'
    $emptyPath=Join-Path $root 'pipe-eof.php'
    [IO.File]::WriteAllText($emptyPath,'<?php exit(stream_get_contents(STDIN)===""?0:33);',[Text.UTF8Encoding]::new($false))
    $sentinel=Join-Path $root 'must-stay-empty.txt'
    # Check EOF directly via a harmless child side effect, even though the launcher throws.
    [IO.File]::WriteAllText($emptyPath,'<?php file_put_contents($argv[1],strlen(stream_get_contents(STDIN)));',[Text.UTF8Encoding]::new($false))
    $refused=$false
    try{$null=Invoke-Migration010StdinChild $php @('-n',$emptyPath,$sentinel) (Split-Path -Parent $php) $credential {throw 'synthetic journal failure'}}catch{$refused=$true}
    Assert ($refused -and [IO.File]::ReadAllText($sentinel) -ceq '0') 'journal failure prevents any secret from reaching child stdin'
    $malicious=ConvertTo-Json -InputObject @{status='FAIL';code=$dummy;raw_detail=$dummy;execution_stage='DDL'} -Compress
    $errorPacket=ConvertFrom-Migration010RunnerResult 1 $malicious ('a'*64)
    Assert ($errorPacket.status -ceq 'FAIL' -and $errorPacket.stage -ceq 'DDL' -and !(($errorPacket | ConvertTo-Json).Contains($dummy))) 'runner result keeps an allowlisted stage and discards private values'
} finally {
    if($credential){$credential.Dispose()}
    if([IO.Directory]::Exists($root)){
        foreach($file in [IO.Directory]::GetFiles($root)){[IO.File]::Delete($file)}
        [IO.Directory]::Delete($root)
    }
}
"PASS: $checks Migration 010 secure apply offline checks; fixtures and mock process only."
