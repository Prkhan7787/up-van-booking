#Requires -Version 5.1
param([string]$Mode,[string]$AttemptId,[string]$ApprovalToken,[string]$BackupDirectory,
    [string]$BackupManifestSha256,[switch]$SqlProtocol)
$ErrorActionPreference='Stop'
if($SqlProtocol){
    $transaction=$false
    $affected=-1
    while($null -ne ($line=[Console]::ReadLine())){
        switch -Regex -CaseSensitive ($line){
            '^START TRANSACTION;$' { $transaction=$true; break }
            '^UPDATE schema_migrations SET .+;$' {
                if(!$transaction){exit 1}
                $affected=1
                break
            }
            '^SELECT ROW_COUNT\(\);$' { [Console]::WriteLine($affected);$affected=-1;break }
            "^SELECT '(UPVAN011_EXEC_OK|UPVAN011_RESPONSE_[0-9]+)';$" {
                [Console]::WriteLine($Matches[1]);$affected=-1;break
            }
            default { exit 1 }
        }
        [Console]::Out.Flush()
    }
    exit 0
}
$Scenario=$BackupDirectory
if($Scenario -notin @('StructuredFailure','NoOutput','BeforeTransaction','DuringTransaction','AuditWriteFailure','CommitUnknown')){throw 'MOCK_SCENARIO_REJECTED'}
switch($Scenario){
    'NoOutput' { [Console]::Error.WriteLine('SECRET_RAW_ERROR_MUST_NOT_ESCAPE'); exit 1 }
    'StructuredFailure' { $result=@{status='FAIL';error_code='MIGRATION_011_MOCK_FAILURE';failed_stage='PRECONDITIONS';exception_type='MockPreconditionException';transaction_state='NOT_STARTED';commit_state='NOT_COMMITTED';audit_write_status='FAILURE_WRITTEN';attempt_id='e4163e43ea9e40e2bba9bfc7606fe542';source_run_id='569a48a5f6404c95a5f0771de3e04dc4'} }
    'BeforeTransaction' { $result=@{status='FAIL';failed_stage='DBA_SESSION';exception_type='MockConnectionException';transaction_state='NOT_STARTED';commit_state='NOT_COMMITTED';audit_write_status='FAILURE_WRITTEN'} }
    'DuringTransaction' { $result=@{status='FAIL';failed_stage='LEDGER_UPDATE';exception_type='MockSqlException';transaction_state='ROLLED_BACK';commit_state='NOT_COMMITTED';audit_write_status='FAILURE_WRITTEN'} }
    'AuditWriteFailure' { $result=@{status='FAIL';failed_stage='PRECONDITIONS';exception_type='MockAuditException';transaction_state='NOT_STARTED';commit_state='NOT_COMMITTED';audit_write_status='FAILURE_WRITE_FAILED'} }
    'CommitUnknown' { $result=@{status='CRITICAL';failed_stage='COMMIT';exception_type='MockCommitException';transaction_state='ACTIVE';commit_state='UNKNOWN';audit_write_status='FAILURE_WRITTEN'} }
}
if($Scenario -ne 'NoOutput'){
    Write-Output (ConvertTo-Json -InputObject $result -Compress)
    [Console]::Error.WriteLine('SECRET_RAW_ERROR_MUST_NOT_ESCAPE')
    exit 1
}
