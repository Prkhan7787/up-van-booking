#Requires -Version 5.1
#Requires -PSEdition Desktop
[CmdletBinding()]
param(
    [Parameter(Mandatory=$true)][string]$BackupDirectory,
    [Parameter(Mandatory=$true)][ValidatePattern('^[a-f0-9]{64}$')][string]$BackupManifestSha256,
    [Parameter(Mandatory=$true)][ValidatePattern('^[a-f0-9]{32}$')][string]$AttemptId,
    [Parameter(Mandatory=$true)][string]$ApprovalToken
)
$ErrorActionPreference='Stop'
Set-StrictMode -Version Latest
$result=$null
$stage='LAUNCHER_BOOTSTRAP'
try{
    . (Join-Path $PSScriptRoot 'lib/migration011-apply-credentials.ps1')
    . (Join-Path $PSScriptRoot 'lib/migration011-reconciliation-cli.ps1')
    $stage='LAUNCHER'
    $groups=& whoami.exe /groups 2>$null|Out-String
    if($LASTEXITCODE -ne 0 -or $groups -notmatch 'S-1-16-8192'){throw 'MIGRATION_011_NORMAL_WINDOWS_MEDIUM_SESSION_REQUIRED'}
    [void](Assert-Migration011ExceptionalApproval $ApprovalToken $AttemptId)
    $runner=Join-Path $PSScriptRoot 'migration011-ledger-reconcile.ps1'
    if(![IO.File]::Exists($runner)){throw 'MIGRATION_011_RECONCILIATION_RUNNER_MISSING'}
    $result=Invoke-Migration011ReconciliationRunnerProcess -RunnerPath $runner -AttemptId $AttemptId -ApprovalToken $ApprovalToken `
        -BackupDirectory $BackupDirectory -BackupManifestSha256 $BackupManifestSha256
}catch{
    $codeMatch=[regex]::Match([string]$_.Exception.Message,'MIGRATION_011_[A-Z0-9_]+')
    $result=[pscustomobject]@{status='BLOCKED';error_code=$(if($codeMatch.Success){$codeMatch.Value}else{'MIGRATION_011_LAUNCHER_FAILED'});
        failed_stage=$stage;exception_type=$_.Exception.GetBaseException().GetType().Name;transaction_state='NOT_STARTED';commit_state='NOT_COMMITTED';
        child_exit_code=-1;audit_write_status='NOT_ATTEMPTED';raw_error_recorded=$false;automatic_retry=$false}
}
Write-Output (ConvertTo-Json -InputObject $result -Compress -Depth 5)
if($result.status -ne 'PASS'){exit 1}
