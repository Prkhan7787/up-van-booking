Set-StrictMode -Version Latest
$ErrorActionPreference='Stop'
$root=(Resolve-Path (Join-Path $PSScriptRoot '..')).Path
. (Join-Path $root 'scripts/lib/env-file-security.ps1')
. (Join-Path $root 'scripts/lib/recovery-lock.ps1')
$tokens=$null;$errors=$null
$ast=[System.Management.Automation.Language.Parser]::ParseFile((Join-Path $root 'scripts/recover-test-auth-credentials.ps1'),[ref]$tokens,[ref]$errors)
if($errors.Count){throw 'Recovery source syntax failure'}
foreach($name in @('Save-RecoveryState','Move-RecoveryState','Assert-RecoveryPath','ConvertTo-ProtectedPassword','ConvertFrom-ProtectedPassword','Set-RecoveryProcessConfig','Set-RecoveryEnv','Get-EnvValue','Get-TextSha256')){
    $fn=$ast.Find({param($n)$n -is [System.Management.Automation.Language.FunctionDefinitionAst] -and $n.Name -eq $name},$true)
    if($null -eq $fn){throw 'Required function unavailable'}
    Invoke-Expression $fn.Extent.Text
}
$scratch=Join-Path $PSScriptRoot ('.state-save-test-'+[guid]::NewGuid().ToString('N'))
[void][IO.Directory]::CreateDirectory($scratch)
$statePath=Join-Path $scratch 'state.json';$stateTempPath=$statePath+'.tmp'
$managed=@('AUTH_TEST_DB_HOST','AUTH_TEST_DB_PORT','AUTH_TEST_DB_DATABASE','AUTH_TEST_DB_USERNAME','AUTH_TEST_DB_PASSWORD','TEST_PROVISIONER_DB_HOST','TEST_PROVISIONER_DB_PORT','TEST_PROVISIONER_DB_DATABASE','TEST_PROVISIONER_DB_USERNAME','TEST_PROVISIONER_DB_PASSWORD')
$saved=@{};foreach($key in $managed){$saved[$key]=[Environment]::GetEnvironmentVariable($key,'Process')}
$stage='INITIAL_STATE'
try{
    $state=[pscustomobject]@{Status='PREPARED';TransactionId=[guid]::NewGuid().ToString('N');ProtectedAuthPassword=(ConvertTo-ProtectedPassword ('a'*64));ProtectedProvisionerPassword=(ConvertTo-ProtectedPassword ('b'*64))}
    Save-RecoveryState $state
    Write-Output 'PASS: initial dummy DPAPI state saved'
    $stage='PROCESS_CONFIG'
    Set-RecoveryProcessConfig ('a'*64) ('b'*64)
    Write-Output 'PASS: dummy process credential configuration'
    $stage='STATE_REPLACE'
    Move-RecoveryState $state 'ENV_SYNCED'
    $disk=Get-Content -LiteralPath $statePath -Raw|ConvertFrom-Json
    if($disk.Status -ne 'ENV_SYNCED' -or $disk.TransactionId -cne $state.TransactionId -or (ConvertFrom-ProtectedPassword $disk.ProtectedAuthPassword) -cne ('a'*64)){throw 'Dummy state postcondition failed'}
    Write-Output 'PASS: PREPARED to ENV_SYNCED replacement preserves dummy DPAPI/transaction'
    Assert-UpVanEnvAcl -Path $statePath -Mode Localhost -PoolName '' -PoolSidOverride $null
    if(Test-Path -LiteralPath $stateTempPath){throw 'State staging was not consumed'}
    Write-Output 'PASS: state ACL and atomic staging cleanup'

    # Run the actual Execute-body slice against a PREPARED dummy transaction.
    # Database calls are replaced with recording fakes, .env writer must not run.
    $envPath=Join-Path $scratch 'dummy.env'
    $envText=Set-RecoveryEnv 'OTHER_SETTING=dummy' ('a'*64) ('b'*64)
    [IO.File]::WriteAllText($envPath,$envText,[Text.UTF8Encoding]::new($false))
    $state.Status='PREPARED'
    $state | Add-Member PreparedEnvSha256 (Get-TextSha256 $envText)
    $state | Add-Member OriginalEnvSha256 ('0'*64)
    Save-RecoveryState $state
    $transaction=$state.TransactionId;$protectedAuth=$state.ProtectedAuthPassword;$protectedProvisioner=$state.ProtectedProvisionerPassword
    $envHash=(Get-FileHash -LiteralPath $envPath -Algorithm SHA256).Hash
    $calls=[Collections.Generic.List[string]]::new()
    function Invoke-RecoveryPhp([string]$Action,[string]$StateName=''){$calls.Add($Action);return [pscustomobject]@{ExitCode=0;Status='PASS'}}
    function Show-SafeResult($Result){}
    function Remove-OwnedEnvStaging([string]$TransactionId){if($TransactionId -cne $transaction){throw 'Wrong dummy transaction cleanup'}}
    function Write-UpVanAtomicEnvFile {throw 'Resume must not rewrite prepared .env'}
    $source=Get-Content -LiteralPath (Join-Path $root 'scripts/recover-test-auth-credentials.ps1') -Raw
    $start=$source.IndexOf('    $envText = [IO.File]::ReadAllText($envPath)')
    $end=$source.IndexOf('    Assert-UpVanEnvAcl -Path $envPath',$start)
    if($start -lt 0 -or $end -lt $start){throw 'Actual resume block unavailable'}
    $envChanged=$false;$secretBytesAuth=$null;$secretBytesProvisioner=$null
    $lock=Enter-UpVanRecoveryLock (Join-Path $scratch 'dummy.lock')
    try{Invoke-Expression $source.Substring($start,$end-$start)}finally{$lock.Dispose()}
    $disk=Get-Content -LiteralPath $statePath -Raw|ConvertFrom-Json
    if($disk.Status -ne 'COMPLETE' -or $disk.TransactionId -cne $transaction -or $disk.ProtectedAuthPassword -cne $protectedAuth -or $disk.ProtectedProvisionerPassword -cne $protectedProvisioner){throw 'Resume changed dummy transaction credentials'}
    if($envChanged -or $null -ne $secretBytesAuth -or $null -ne $secretBytesProvisioner -or $envHash -cne (Get-FileHash -LiteralPath $envPath -Algorithm SHA256).Hash){throw 'Resume rewrote dummy env or generated new passwords'}
    if(($calls -join ',') -cne 'reconcile-auth,reconcile-provisioner,verify,verify'){throw 'Resume skipped verification'}
    Write-Output 'PASS: actual PREPARED resume reaches COMPLETE using original DPAPI/transaction and no env rewrite or new random credentials'
    Write-Output 'PASS: actual resume executes both account checks and final verification (offline fakes)'
    $held=[IO.File]::Open($statePath,[IO.FileMode]::Open,[IO.FileAccess]::ReadWrite,[IO.FileShare]::None)
    $safeFailure=$false
    try{Save-RecoveryState $state}catch{$safeFailure=$_.Exception.Message -ceq 'STATE_SAVE_FAILED'}finally{$held.Dispose()}
    if(-not $safeFailure){throw 'State save did not return a safe diagnostic'}
    $disk=Get-Content -LiteralPath $statePath -Raw|ConvertFrom-Json
    if($disk.Status -ne 'COMPLETE' -or $disk.TransactionId -cne $transaction){throw 'Failed state save damaged committed state'}
    Write-Output 'PASS: injected state-save failure exposes only safe code and preserves committed state'
}catch{
    Write-Output "FAIL: dummy regression stage=$stage (safe diagnostic only)"
    exit 1
}finally{
    foreach($key in $managed){[Environment]::SetEnvironmentVariable($key,$saved[$key],'Process')}
    $full=[IO.Path]::GetFullPath($scratch)
    if((Split-Path -Parent $full) -cne [IO.Path]::GetFullPath($PSScriptRoot) -or (Split-Path -Leaf $full) -notmatch '^\.state-save-test-[a-f0-9]{32}$'){throw 'Scratch cleanup path rejected'}
    Remove-Item -LiteralPath $full -Recurse -Force
}
