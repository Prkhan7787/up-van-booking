#Requires -Version 5.1
$ErrorActionPreference='Stop'
. (Join-Path $PSScriptRoot '../scripts/lib/migration011-apply-credentials.ps1')
$checks=0
function Assert([bool]$Condition,[string]$Message){if(!$Condition){throw "FAIL: $Message"};$script:checks++}
$project=Split-Path -Parent $PSScriptRoot
$runtime=Resolve-Migration011EvaluatorRuntime $project
$fixture=Join-Path $PSScriptRoot 'database/migration011-readonly-preflight-offline.php'
$evaluator=Join-Path $project 'scripts/migration011-provision-grants-check.php'
$args=@('-n',$fixture,'--diagnostic-fixture')|ForEach-Object{ConvertTo-Migration011WindowsArgument $_}
$initial=Invoke-Migration011MysqlCaptured -ClientPath $runtime -ClientArgs $args -Sql ' '
if($initial.ExitCode -ne 0){throw 'FIXTURE_FAILED'}
function Diagnose([string]$Json){
    $args=@('-n',$evaluator,'--diagnose-011')|ForEach-Object{ConvertTo-Migration011WindowsArgument $_}
    $result=Invoke-Migration011MysqlCaptured -ClientPath $runtime -ClientArgs $args -Sql $Json
    if($result.ExitCode -ne 0){throw 'DIAGNOSTIC_FAILED'}
    return $result.Stdout|ConvertFrom-Json
}
$result=Diagnose $initial.Stdout
Assert ($result.status -ceq 'DIAGNOSTIC_COMPLETE' -and $result.migration_state -ceq 'NOT_APPLIED') 'absent table and exact baseline are reported without claiming readiness'
Assert ($result.account_locked -ceq 'Y' -and $result.active_apply_sessions -eq 0 -and $result.ledger_001_009 -ceq 'PASS') 'lock, sessions and baseline are reported independently'
Assert ($result.recovery_required -and !$result.apply_ready) 'read-only diagnostics never clears recovery requirement'
$packet=$initial.Stdout|ConvertFrom-Json
$packet.account[0].account_locked='N';$packet.sessions=@([pscustomobject]@{ID=123;COMMAND='Sleep'})
$result=Diagnose ($packet|ConvertTo-Json -Depth 20 -Compress)
Assert ($result.account_locked -ceq 'N' -and $result.active_apply_sessions -eq 1 -and $result.sessions[0].ID -eq 123) 'unlocked account and lingering session are retained in safe output'
$packet=$initial.Stdout|ConvertFrom-Json
$packet.tables+= [pscustomobject]@{TABLE_NAME='microsoft_identities';TABLE_TYPE='BASE TABLE';ENGINE='InnoDB'}
$result=Diagnose ($packet|ConvertTo-Json -Depth 20 -Compress)
Assert ($result.migration_state -ceq 'PARTIAL_OR_DRIFT_STOP' -and $result.microsoft_identities -ceq 'PRESENT' -and $result.ledger_011_state -ceq 'ABSENT') 'table without ledger is reported as partial or drift, never retry-ready'
$packet=$initial.Stdout|ConvertFrom-Json;$packet.ledger[0].checksum=('0'*64)
$result=Diagnose ($packet|ConvertTo-Json -Depth 20 -Compress)
Assert ($result.ledger_001_009 -ceq 'FAIL' -and $result.migration_state -ceq 'PARTIAL_OR_DRIFT_STOP') 'wrong dependency checksum remains visible and blocks readiness'
$postFixture=Join-Path $PSScriptRoot 'database/migration011-apply-offline.php'
$args=@('-n',$postFixture,'--diagnostic-post-fixture')|ForEach-Object{ConvertTo-Migration011WindowsArgument $_}
$post=Invoke-Migration011MysqlCaptured -ClientPath $runtime -ClientArgs $args -Sql ' '
if($post.ExitCode -ne 0){throw 'POST_FIXTURE_FAILED'}
$result=Diagnose $post.Stdout
Assert ($result.schema_validation -ceq 'PASS' -and $result.ledger_011_checksum -ceq 'PASS' -and $result.ledger_011[0].status -ceq 'failed' -and $result.migration_state -ceq 'PARTIAL_OR_DRIFT_STOP') 'schema validity and matching checksum never adopt a failed ledger as applied'
Assert ($result.inventory_validation -ceq 'PASS' -and $result.base_table_count -eq 16 -and $result.view_count -eq 1) 'reconciliation evidence confirms the exact current 16-table and calendar-view inventory'
Assert ($result.schema_evidence.show_create_table -match '^CREATE TABLE microsoft_identities' -and $result.schema_evidence.columns.Count -eq 7) 'schema-only DDL and complete selected metadata survive diagnostic JSON encoding'
foreach($failure in @('column','default','index','foreign_key','check')){
    $packet=$post.Stdout|ConvertFrom-Json
    switch($failure){
        'column' {$packet.columns[2].IS_NULLABLE='YES';$expected='MIGRATION_011_POST_COLUMNS_REJECTED'}
        'default' {$packet.columns[6].COLUMN_DEFAULT='arbitrary';$expected='MIGRATION_011_POST_DEFAULT_REJECTED'}
        'index' {$packet.indexes[4].NON_UNIQUE=1;$expected='MIGRATION_011_POST_INDEX_REJECTED'}
        'foreign_key' {$packet.foreign_keys[0].DELETE_RULE='CASCADE';$expected='MIGRATION_011_POST_FK_REJECTED'}
        'check' {$packet.checks[0].CHECK_CLAUSE='1';$expected='MIGRATION_011_POST_CHECK_REJECTED'}
    }
    $result=Diagnose ($packet|ConvertTo-Json -Depth 20 -Compress)
    Assert ($result.schema_validation -ceq 'FAIL' -and $result.schema_error_code -ceq $expected) "$failure drift reports exact safe validator error code"
}
$packet=$post.Stdout|ConvertFrom-Json;$packet.inventory=@($packet.inventory|Where-Object{$_.TABLE_NAME -ne 'vehicles'})
$result=Diagnose ($packet|ConvertTo-Json -Depth 24 -Compress)
Assert ($result.inventory_validation -ceq 'FAIL' -and $result.base_table_count -eq 15 -and $result.migration_state -ceq 'PARTIAL_OR_DRIFT_STOP') 'missing current-state base table prevents reconciliation readiness'
Write-Output "PASS: $checks read-only Migration 011 recovery diagnostic checks; fixture data and PHP only."
