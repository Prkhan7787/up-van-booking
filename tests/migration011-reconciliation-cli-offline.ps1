#Requires -Version 5.1
$ErrorActionPreference='Stop'
. (Join-Path $PSScriptRoot '../scripts/lib/migration011-reconciliation-cli.ps1')
$checks=0
function Assert([bool]$Condition,[string]$Message){if(!$Condition){throw "FAIL: $Message"};$script:checks++}
$expected='b381d5b7e093398d072dfceb337e1f7b355a204459b4bc723a680cd40f595664'
$args=New-Migration011DbaClientArguments
Assert ($args -contains '--login-path=upvan3306-backup' -and $args -contains '--database=up_van_booking_test' -and $args -contains '--skip-reconnect') 'uses the approved DBA login path and fixed test target'
Assert (!(@($args|Where-Object{$_ -match '(?i)password|pwd|secret'}).Count)) 'client arguments contain no credential material'
$response=ConvertFrom-Migration011MysqlResponse '[{"TABLE_NAME":"microsoft_identities"}]' 'UPVAN011_RESPONSE_000001' 'UPVAN011_RESPONSE_000001'
Assert ($response -match 'microsoft_identities') 'accepts a single-row JSON response followed by its sentinel'
$badResponse=$false;try{ConvertFrom-Migration011MysqlResponse 'row1' 'row2' 'UPVAN011_RESPONSE_000002'}catch{$badResponse=$_.Exception.Message -ceq 'MIGRATION_011_CLI_OUTPUT_PROTOCOL_REJECTED'}
Assert $badResponse 'rejects extra row or output-format changes instead of consuming an ambiguous result'
$badSentinel=$false;try{ConvertFrom-Migration011MysqlResponse 'row' 'UPVAN011_RESPONSE_000001' 'UPVAN011_RESPONSE_000002'}catch{$badSentinel=$_.Exception.Message -ceq 'MIGRATION_011_CLI_OUTPUT_PROTOCOL_REJECTED'}
Assert $badSentinel 'rejects a delayed or mismatched CLI sentinel'
$identity="3306`t77218fbd-d66d-11f1-9a0a-5c2886f057d5`tup_van_booking_test`t8.4.9`troot@localhost"
Assert (ConvertFrom-Migration011CliIdentity $identity) 'accepts only the pinned database identity'
$identityRejected=$false;try{ConvertFrom-Migration011CliIdentity ($identity -replace '3306','3307')}catch{$identityRejected=$_.Exception.Message -ceq 'MIGRATION_011_RECONCILIATION_IDENTITY_REJECTED'}
Assert $identityRejected 'rejects a different MySQL port'
$sql=New-Migration011LedgerReconciliationUpdateSql '011_microsoft_identities' $expected
Assert ($sql -match '^UPDATE schema_migrations ' -and $sql -notmatch '(?i)\b(ALTER|CREATE|DROP|DELETE)\b' -and $sql -match "status='failed'.*completed_statements=1.*total_statements=1") 'update is constrained to the single eligible ledger row and is ledger-only'
$badPlan=$false;try{New-Migration011LedgerReconciliationUpdateSql '010_driver_auth' $expected}catch{$badPlan=$_.Exception.Message -ceq 'MIGRATION_011_RECONCILIATION_PLAN_REJECTED'}
Assert $badPlan 'rejects any migration version except 011'

$queryPlan=[ordered]@{}
foreach($name in @('tables','columns','indexes','foreign_keys','checks','triggers','ledger','inventory','account','sessions')){
    $queryPlan[$name]="SELECT '$name'"
}
$decodedPlan=ConvertFrom-Migration011DiagnosticQueries (ConvertTo-Json -InputObject $queryPlan -Compress)
Assert ($decodedPlan -is [Collections.IDictionary] -and $decodedPlan.Count -eq 10) 'PHP query plan JSON is converted to the protocol dictionary type'
$diagnosticTransport=[pscustomobject]@{}
$diagnosticTransport|Add-Member ScriptMethod Query {
    param([string]$sql)
    if($sql -ceq "SELECT 'sessions'"){return '[]'}
    if($sql -ceq "SELECT 'ledger'"){return '[{"version":"001"},{"version":"011"}]'}
    return '[{"locked":"Y"}]'
}
$payload=Get-Migration011DiagnosticPayload $decodedPlan $diagnosticTransport
Assert ($payload.Contains('"sessions":[]') -and $payload.Contains('"account":[{"locked":"Y"}]') -and $payload.Contains('"ledger":[{"version":"001"},{"version":"011"}]')) 'validator receives JSON arrays for zero, one and many diagnostic rows'

function New-Packet([string]$State='UNVERIFIED'){
    $ownState=if($State -eq 'APPLIED_CHECKSUM_PASS'){'applied'}else{'failed'}
    $failure=if($ownState -eq 'failed'){'statement_failed'}else{$null}
    return [pscustomobject]@{status='DIAGNOSTIC_COMPLETE';identity='PASS';account_locked='Y';active_apply_sessions=0
        ledger_001_009='PASS';migration_010='ABSENT';ledger_011_checksum='PASS';ledger_011_state=$State;inventory_validation='PASS';base_table_count=16;view_count=1
        schema_validation='PASS';microsoft_identities='PRESENT';migration_state=$(if($State -eq 'APPLIED_CHECKSUM_PASS'){'APPLIED_VERIFIED'}else{'PARTIAL_OR_DRIFT_STOP'})
        expected_011_checksum=$expected;ledger_011=@([pscustomobject]@{status=$ownState;completed_statements=1;total_statements=1;checksum=$expected;failure_code=$failure})}
}
function Invoke-Mock([string]$Failure=''){
    $state=@{failure=$Failure;calls=[Collections.Generic.List[string]]::new();diagnosticCount=0;updated=$false;committed=$false;inTransaction=$false;released=$false}
    $transport=[pscustomobject]@{State=$state}
    $transport|Add-Member ScriptMethod Query {
        param([string]$query)
        $s=$this.State
        if($query -like 'SELECT CONCAT(@@port*'){$s.calls.Add('IDENTITY');return $identity}
        if($query -like "SELECT GET_LOCK(*"){$s.calls.Add('GET_LOCK');if($s.failure -eq 'LockUnavailable'){return '0'};return '1'}
        if($query -like 'SELECT version,checksum,status*'){$s.calls.Add('LOCK_LEDGER');if($s.failure -eq 'LedgerMismatch'){return "011_microsoft_identities`t$('0'*64)`tfailed`t1`t1`tstatement_failed"};return "011_microsoft_identities`t$expected`tfailed`t1`t1`tstatement_failed"}
        if($query -like "SELECT RELEASE_LOCK(*"){$s.calls.Add('RELEASE_LOCK');$s.released=$true;return '1'}
        throw 'MOCK_UNEXPECTED_QUERY'
    }.GetNewClosure()
    $transport|Add-Member ScriptMethod Execute {
        param([string]$sql)
        $s=$this.State
        if($sql -ceq 'START TRANSACTION'){$s.calls.Add('BEGIN');if($s.failure -eq 'TransactionFailure'){throw 'MOCK_BEGIN_FAILED'};if($s.failure -ne 'TransactionNotStarted'){$s.inTransaction=$true};return}
        if($sql -ceq 'SAVEPOINT upvan011_reconciliation'){$s.calls.Add('SAVEPOINT');return}
        if($sql -ceq 'RELEASE SAVEPOINT upvan011_reconciliation'){$s.calls.Add('RELEASE_SAVEPOINT');if(!$s.inTransaction){throw 'MOCK_NO_SAVEPOINT'};return}
        if($sql -ceq 'COMMIT AND NO CHAIN NO RELEASE'){$s.calls.Add('COMMIT');if($s.failure -eq 'CommitUnknown'){throw 'MOCK_COMMIT_REPLY_LOST'};$s.committed=$true;$s.inTransaction=$false;return}
        if($sql -ceq 'ROLLBACK AND NO CHAIN NO RELEASE'){$s.calls.Add('ROLLBACK');$s.inTransaction=$false;return}
        throw 'MOCK_UNEXPECTED_EXECUTE'
    }.GetNewClosure()
    $transport|Add-Member ScriptMethod ExecuteAffectedRows {
        param([string]$sql)
        if($sql -notlike 'UPDATE schema_migrations*'){throw 'MOCK_UNEXPECTED_DML'}
        $this.State.calls.Add('UPDATE_AND_ROW_COUNT')
        $this.State.updated=$true
        if($this.State.failure -eq 'RowCountMismatch'){return '0'}
        return '1'
    }
    $transport|Add-Member ScriptMethod Dispose {}
    $getDiagnostic={param($queries,$session)$state.diagnosticCount++
        if($state.failure -eq 'PreGuardFailure'){return (New-Packet 'FAIL')}
        if($state.failure -eq 'InventoryFailure'){$packet=New-Packet 'UNVERIFIED';$packet.inventory_validation='FAIL';return $packet}
        if($state.diagnosticCount -ge 3){if($state.failure -eq 'PostGuardFailure' -and $state.diagnosticCount -eq 3){return (New-Packet 'UNVERIFIED')};if($state.failure -eq 'PostCommitGuardFailure' -and $state.diagnosticCount -ge 4){return (New-Packet 'UNVERIFIED')};return (New-Packet 'APPLIED_CHECKSUM_PASS')}
        return (New-Packet 'UNVERIFIED')
    }.GetNewClosure()
    $backup={if($state.failure -eq 'BackupFailure'){throw 'MOCK_BACKUP_FAIL'}}.GetNewClosure()
    $marker={
        if($state.failure -eq 'MarkerFailure' -or ($state.failure -eq 'PostCommitMarkerFailure' -and $state.committed)){
            throw 'MOCK_MARKER_FAIL'
        }
    }.GetNewClosure()
    $queries=[ordered]@{ledger='SELECT ledger'}
    $executionState=@{}
    try{
        $result=Invoke-Migration011LedgerReconciliationProtocol -Transport $transport -DiagnosticQueries $queries -GetDiagnostic $getDiagnostic `
            -ExpectedVersion '011_microsoft_identities' -ExpectedChecksum $expected -BackupVerify $backup -RecoveryMarkerVerify $marker -ExecutionState $executionState
        return @{result=$result;state=$state;error=$null}
    }catch{return @{result=$null;state=$state;error=$_.Exception.Message;summary=(Get-Migration011ReconciliationFailureSummary $_ $executionState)}}
}
$success=Invoke-Mock
Assert ($success.result.status -ceq 'PASS' -and !$success.result.ddl_executed -and $success.state.committed -and $success.state.released) ("success commits the ledger-only transaction and releases lock; error=$($success.error), calls=$($success.state.calls -join ',')")
Assert (($success.state.calls -join ',') -ceq 'IDENTITY,GET_LOCK,BEGIN,LOCK_LEDGER,SAVEPOINT,RELEASE_SAVEPOINT,UPDATE_AND_ROW_COUNT,COMMIT,RELEASE_LOCK') 'single-session protocol checks locks and reads affected rows before commit'
Assert ($success.state.calls -notcontains 'DDL') 'no DDL is executed by the protocol'
foreach($failure in @('LockUnavailable','LedgerMismatch','TransactionFailure','TransactionNotStarted','PreGuardFailure','InventoryFailure','BackupFailure','MarkerFailure','PostGuardFailure','RowCountMismatch')){
    $case=Invoke-Mock $failure
    Assert ($null -eq $case.result -and $case.error) "$failure fails closed"
    Assert (!$case.state.committed -and (!$case.state.updated -or $failure -in @('PostGuardFailure','RowCountMismatch'))) "$failure never commits a rejected reconciliation"
    if($case.state.calls -contains 'BEGIN'){Assert ($case.state.calls -contains 'ROLLBACK') "$failure attempts rollback when transaction start was sent"}
    if($case.state.calls -contains 'GET_LOCK' -and $case.state.calls -notcontains 'RELEASE_LOCK' -and $failure -ne 'LockUnavailable'){throw "FAIL: $failure did not release advisory lock"}
    if($case.state.calls -contains 'GET_LOCK' -and $failure -ne 'LockUnavailable'){Assert ($case.state.calls -contains 'RELEASE_LOCK') "$failure releases the advisory lock"}
}
$unknown=Invoke-Mock 'CommitUnknown'
Assert ($unknown.error -ceq 'MIGRATION_011_RECONCILIATION_COMMIT_OUTCOME_UNKNOWN' -and $unknown.state.calls -notcontains 'RELEASE_LOCK' -and $unknown.state.calls -notcontains 'ROLLBACK') 'lost COMMIT response is explicitly ambiguous and leaves lock cleanup to closing the session'
Assert ($unknown.summary.commit_state -ceq 'UNKNOWN' -and $unknown.summary.transaction_state -ceq 'COMMIT_UNCONFIRMED' -and $unknown.summary.failed_stage -ceq 'COMMIT') 'unknown commit does not report a rollback'
$postCommit=Invoke-Mock 'PostCommitGuardFailure'
Assert ($postCommit.error -ceq 'MIGRATION_011_RECONCILIATION_COMMITTED_POSTFLIGHT_FAILED' -and $postCommit.state.committed) 'failed postflight after commit is explicitly critical and cannot be mistaken for rollback'
$postMarker=Invoke-Mock 'PostCommitMarkerFailure'
Assert ($postMarker.summary.failed_stage -ceq 'POSTFLIGHT_MARKER' -and $postMarker.summary.commit_state -ceq 'CONFIRMED' -and $null -eq $postMarker.result) 'changed marker after commit precludes PASS and preserves confirmed commit'
$parser=$null;$tokens=$null
[Management.Automation.Language.Parser]::ParseFile((Join-Path $PSScriptRoot '../scripts/lib/migration011-reconciliation-cli.ps1'),[ref]$tokens,[ref]$parser)|Out-Null
Assert ($parser.Count -eq 0) 'library parses with the installed Windows PowerShell 5.1 parser'
$productionScripts=@('../scripts/migration011-ledger-reconcile.ps1','../scripts/migration011-ledger-reconciliation-preflight.ps1')
foreach($relative in $productionScripts){$tokens=$null;$parser=$null;$parsePath=Join-Path $PSScriptRoot $relative;[Management.Automation.Language.Parser]::ParseFile($parsePath,[ref]$tokens,[ref]$parser)|Out-Null;Assert ($parser.Count -eq 0) "$(Split-Path -Leaf $relative) parses with Windows PowerShell 5.1 (errors=$($parser.Count))"}
Write-Output "PASS: $checks Migration 011 DBA reconciliation CLI offline checks; no MySQL connection made."
