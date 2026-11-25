#Requires -Version 5.1
#Requires -PSEdition Desktop
[CmdletBinding()]
param(
    [Parameter(Mandatory=$true)][string]$BackupDirectory,
    [Parameter(Mandatory=$true)][ValidatePattern('^[a-f0-9]{64}$')][string]$BackupManifestSha256
)
$ErrorActionPreference='Stop'
Set-StrictMode -Version Latest
. (Join-Path $PSScriptRoot 'lib/migration011-apply-credentials.ps1')

$identity=[Security.Principal.WindowsIdentity]::GetCurrent().User
$groups=& whoami.exe /groups 2>$null|Out-String
if($LASTEXITCODE -ne 0 -or $groups -notmatch 'S-1-16-8192'){throw 'MIGRATION_011_NORMAL_WINDOWS_MEDIUM_SESSION_REQUIRED'}
$project=Split-Path -Parent $PSScriptRoot
$runtime=Resolve-Migration011EvaluatorRuntime $project
$powershell=Join-Path $PSHOME 'powershell.exe'
$diagnosticScript=Join-Path $PSScriptRoot 'migration011-provision-user-session.ps1'
$diagnosticArgs=@('-NoProfile','-ExecutionPolicy','Bypass','-File',$diagnosticScript,'-Action','Diagnostics')|
    ForEach-Object{ConvertTo-Migration011WindowsArgument ([string]$_)}
$live=Invoke-Migration011MysqlCaptured -ClientPath $powershell -ClientArgs $diagnosticArgs -Sql ' ' -WorkingDirectory $project
if($live.ExitCode -ne 0){throw 'MIGRATION_011_RECONCILIATION_DIAGNOSTICS_FAILED'}
$jsonLines=@($live.Stdout -split "`r?`n"|Where-Object{$_ -match '^\{'})
if($jsonLines.Count -ne 1){throw 'MIGRATION_011_RECONCILIATION_DIAGNOSTIC_OUTPUT_INVALID'}
$result=$jsonLines[0]|ConvertFrom-Json -ErrorAction Stop
$d=$result.diagnostic
if($result.status -cne 'DIAGNOSTIC_COMPLETE' -or $d.identity -cne 'PASS' -or $d.account_locked -cne 'Y' -or $d.active_apply_sessions -ne 0 -or $d.ledger_001_009 -cne 'PASS' -or $d.migration_010 -cne 'ABSENT' -or $d.ledger_011_checksum -cne 'PASS' -or $d.ledger_011_state -cne 'UNVERIFIED' -or $d.schema_validation -cne 'PASS' -or $d.inventory_validation -cne 'PASS' -or $d.base_table_count -ne 16 -or $d.view_count -ne 1 -or $d.microsoft_identities -cne 'PRESENT'){
    throw 'MIGRATION_011_RECONCILIATION_LIVE_GUARDS_REJECTED'
}
$own=@($d.ledger_011)
if($own.Count -ne 1 -or $own[0].status -cne 'failed' -or $own[0].completed_statements -ne 1 -or $own[0].total_statements -ne 1 -or $own[0].checksum -cne $d.expected_011_checksum -or $own[0].failure_code -notin @('statement_failed','postcondition_failed')){
    throw 'MIGRATION_011_RECONCILIATION_LEDGER_REJECTED'
}
$stateDirectory=Join-Path ([Environment]::GetFolderPath('LocalApplicationData')) 'UPVanBooking\migration011\apply-state'
$pending=Join-Path $stateDirectory 'pending.json'
if(![IO.File]::Exists($pending)){throw 'MIGRATION_011_RECONCILIATION_MARKER_MISSING'}
Assert-Migration011CredentialFileAcl $pending
$marker=ConvertFrom-Json -InputObject ([IO.File]::ReadAllText($pending)) -ErrorAction Stop
if($marker.run_id -cnotmatch '^[a-f0-9]{32}$' -or $marker.phase -cne 'RUNNER_EXITED' -or $marker.child_finished -ne $true -or $marker.automatic_retry -ne $false -or $marker.account -cne 'up_van_migration011_apply@127.0.0.1' -or $marker.target_uuid -cne '77218fbd-d66d-11f1-9a0a-5c2886f057d5'){
    throw 'MIGRATION_011_RECONCILIATION_MARKER_REJECTED'
}
if($marker.child_pid -and $marker.child_started_ticks){
    try{$child=Get-Process -Id ([int]$marker.child_pid) -ErrorAction Stop
        if($child.StartTime.ToUniversalTime().Ticks -eq [long]$marker.child_started_ticks){throw 'MIGRATION_011_RECONCILIATION_CHILD_STILL_RUNNING'}
    }catch [Microsoft.PowerShell.Commands.ProcessCommandException]{}
}
$request=ConvertTo-Json -InputObject @{directory=$BackupDirectory;manifest_sha256=$BackupManifestSha256} -Compress
$evaluator=Join-Path $PSScriptRoot 'migration011-provision-grants-check.php'
$verifyArgs=@('-n',$evaluator,'--verify-011-reconciliation-backup')|ForEach-Object{ConvertTo-Migration011WindowsArgument ([string]$_)}
$backup=Invoke-Migration011MysqlCaptured -ClientPath $runtime -ClientArgs $verifyArgs -Sql $request -WorkingDirectory (Split-Path -Parent $runtime)
if($backup.ExitCode -ne 0){throw 'MIGRATION_011_RECONCILIATION_BACKUP_REJECTED'}
$backupResult=$backup.Stdout.Trim()|ConvertFrom-Json -ErrorAction Stop
if($backupResult.backup_verification -cne 'PASS' -or $backupResult.base_tables -ne 16 -or $backupResult.ledger_state -cne '001-009 applied; 011 failed 1/1; 010 absent'){
    throw 'MIGRATION_011_RECONCILIATION_BACKUP_REJECTED'
}
[pscustomobject]@{
    status='PASS';mode='READ_ONLY_RECONCILIATION_PREFLIGHT';identity='PASS';ledger_001_009='PASS';migration_010='ABSENT'
    ledger_011='failed 1/1';ledger_011_checksum='PASS';schema_validation='PASS';table_inventory='16_BASE_TABLES_AND_CALENDAR_VIEW'
    apply_account_locked='Y';active_apply_sessions=0;recovery_marker='MATCHED_RUNNER_EXITED';backup='PASS'
    backup_directory=$BackupDirectory;backup_manifest_sha256=$BackupManifestSha256;reconciliation_executed=$false;ddl_executed=$false
}|ConvertTo-Json -Compress -Depth 5
