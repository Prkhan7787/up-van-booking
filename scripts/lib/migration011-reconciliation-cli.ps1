# MySQL CLI transport for the single-session 011 ledger reconciliation path.
function New-Migration011DbaClientArguments {
    param([string]$LoginPath='upvan3306-backup')
    if($LoginPath -cne 'upvan3306-backup'){throw 'MIGRATION_011_RECONCILIATION_LOGIN_PATH_REJECTED'}
    return @('--no-defaults',"--login-path=$LoginPath",'--protocol=TCP','--host=127.0.0.1','--port=3306',
        '--database=up_van_booking_test','--connect-timeout=5','--skip-reconnect','--batch','--raw','--skip-column-names','--unbuffered')
}

function ConvertFrom-Migration011MysqlResponse([string]$Value,[string]$End,[string]$ExpectedSentinel) {
    if($End -cne $ExpectedSentinel -or $Value -ceq $ExpectedSentinel){throw 'MIGRATION_011_CLI_OUTPUT_PROTOCOL_REJECTED'}
    return $Value
}

function Read-Migration011MysqlLine($Session) {
    $task=$Session.Process.StandardOutput.ReadLineAsync()
    if(!$task.Wait(30000)){throw 'MIGRATION_011_CLI_RESPONSE_TIMEOUT'}
    if($null -eq $task.Result){throw 'MIGRATION_011_CLI_SESSION_CLOSED'}
    return [string]$task.Result
}

function Invoke-Migration011MysqlLineQuery($Session,[string[]]$Statements) {
    $Session.Counter++
    $sentinel='UPVAN011_RESPONSE_'+$Session.Counter.ToString('D6')
    foreach($statement in $Statements){
        $Session.Process.StandardInput.WriteLine($statement.Trim().TrimEnd(';')+';')
    }
    $Session.Process.StandardInput.WriteLine("SELECT '$sentinel';")
    $Session.Process.StandardInput.Flush()
    $value=Read-Migration011MysqlLine $Session
    if($value -ceq $sentinel){throw 'MIGRATION_011_CLI_OUTPUT_PROTOCOL_REJECTED'}
    $end=Read-Migration011MysqlLine $Session
    return ConvertFrom-Migration011MysqlResponse $value $end $sentinel
}

function New-Migration011MysqlLineSession([string]$Mysql,[string[]]$ClientArgs) {
    $psi=[Diagnostics.ProcessStartInfo]::new()
    $psi.FileName=$Mysql
    $psi.Arguments=(($ClientArgs|ForEach-Object{ConvertTo-Migration011WindowsArgument ([string]$_)}) -join ' ')
    $psi.UseShellExecute=$false
    $psi.CreateNoWindow=$true
    $psi.RedirectStandardInput=$true
    $psi.RedirectStandardOutput=$true
    $psi.RedirectStandardError=$true
    $process=[Diagnostics.Process]::new()
    $process.StartInfo=$psi
    try {
        if(!$process.Start()){throw 'MIGRATION_011_CLI_START_FAILED'}
        $stderrTask=$process.StandardError.ReadToEndAsync()
    } catch {
        $process.Dispose()
        throw
    }
    $session=[pscustomobject]@{Process=$process;Counter=0;StderrTask=$stderrTask;ExitCode=$null}
    $session|Add-Member ScriptMethod Query {
        param([string]$Sql)
        Invoke-Migration011MysqlLineQuery $this @($Sql)
    }
    $session|Add-Member ScriptMethod Execute {
        param([string]$Sql)
        $value=Invoke-Migration011MysqlLineQuery $this @($Sql,"SELECT 'UPVAN011_EXEC_OK'")
        if($value -cne 'UPVAN011_EXEC_OK'){throw 'MIGRATION_011_CLI_EXECUTION_REJECTED'}
    }
    $session|Add-Member ScriptMethod ExecuteAffectedRows {
        param([string]$Sql)
        Invoke-Migration011MysqlLineQuery $this @($Sql,'SELECT ROW_COUNT()')
    }
    $session|Add-Member ScriptMethod Dispose {
        try {
            try{$this.Process.StandardInput.Close()}catch{}
            if(!$this.Process.WaitForExit(5000)){
                $this.Process.Kill()
                if(!$this.Process.WaitForExit(5000)){throw 'MIGRATION_011_CLI_SHUTDOWN_UNCONFIRMED'}
            }
            $this.ExitCode=$this.Process.ExitCode
            [void]$this.StderrTask.Result
        } finally {
            $this.Process.Dispose()
        }
    }
    return $session
}

function ConvertFrom-Migration011CliIdentity([string]$Output) {
    $parts=@(([string]$Output).Trim() -split "`t",5)
    if($parts.Count -ne 5 -or $parts[0] -cne '3306' -or $parts[1] -cne '77218fbd-d66d-11f1-9a0a-5c2886f057d5' -or $parts[2] -cne 'up_van_booking_test' -or $parts[3] -cne '8.4.9' -or $parts[4] -cne 'root@localhost'){
        throw 'MIGRATION_011_RECONCILIATION_IDENTITY_REJECTED'
    }
    return $true
}

function ConvertFrom-Migration011CliLedgerRow([string]$Output,[string]$ExpectedVersion,[string]$ExpectedChecksum) {
    $parts=@(([string]$Output).Trim() -split "`t",6)
    if($parts.Count -ne 6 -or $parts[0] -cne $ExpectedVersion -or $parts[1] -cne $ExpectedChecksum -or $parts[2] -cne 'failed' -or $parts[3] -cne '1' -or $parts[4] -cne '1' -or $parts[5] -notin @('statement_failed','postcondition_failed')){
        throw 'MIGRATION_011_RECONCILIATION_LEDGER_ROW_REJECTED'
    }
    return [pscustomobject]@{version=$parts[0];checksum=$parts[1];status=$parts[2];completed_statements=1;total_statements=1;failure_code=$parts[5]}
}

function ConvertFrom-Migration011DiagnosticQueries([string]$Json) {
    $decoded=ConvertFrom-Json -InputObject $Json -ErrorAction Stop
    if($decoded -isnot [pscustomobject]){throw 'MIGRATION_011_RECONCILIATION_QUERY_PLAN_REJECTED'}
    $queries=[ordered]@{}
    foreach($property in $decoded.PSObject.Properties){
        if($property.Value -isnot [string] -or $property.Value -cnotmatch '^SELECT '){
            throw 'MIGRATION_011_RECONCILIATION_QUERY_PLAN_REJECTED'
        }
        $queries[$property.Name]=$property.Value
    }
    foreach($required in @('tables','columns','indexes','foreign_keys','checks','triggers','ledger','inventory','account','sessions')){
        if(!$queries.Contains($required)){throw 'MIGRATION_011_RECONCILIATION_QUERY_PLAN_REJECTED'}
    }
    return $queries
}

function Get-Migration011DiagnosticPayload([Collections.IDictionary]$Queries,$Transport) {
    $packet=[ordered]@{}
    foreach($query in $Queries.GetEnumerator()){
        $raw=$Transport.Query($query.Value)
        if($raw -notmatch '^\s*\[.*\]\s*$'){throw 'MIGRATION_011_RECONCILIATION_DIAGNOSTIC_ROWS_REJECTED'}
        $rows=ConvertFrom-Json -InputObject $raw -ErrorAction Stop
        $packet[$query.Key]=@()
        if($null -ne $rows){$packet[$query.Key]=@($rows)}
    }
    return (ConvertTo-Json -InputObject $packet -Depth 24 -Compress)
}

function Assert-Migration011ReconciliationPreflightPacket($Diagnostic) {
    $own=@($Diagnostic.ledger_011)
    if($Diagnostic.status -cne 'DIAGNOSTIC_COMPLETE' -or $Diagnostic.identity -cne 'PASS' -or $Diagnostic.account_locked -cne 'Y' -or $Diagnostic.active_apply_sessions -ne 0 -or $Diagnostic.ledger_001_009 -cne 'PASS' -or $Diagnostic.migration_010 -cne 'ABSENT' -or $Diagnostic.ledger_011_checksum -cne 'PASS' -or $Diagnostic.ledger_011_state -cne 'UNVERIFIED' -or $Diagnostic.schema_validation -cne 'PASS' -or $Diagnostic.inventory_validation -cne 'PASS' -or $Diagnostic.base_table_count -ne 16 -or $Diagnostic.view_count -ne 1 -or $Diagnostic.microsoft_identities -cne 'PRESENT' -or $own.Count -ne 1 -or $own[0].status -cne 'failed' -or $own[0].completed_statements -ne 1 -or $own[0].total_statements -ne 1 -or $own[0].checksum -cne $Diagnostic.expected_011_checksum -or $own[0].failure_code -notin @('statement_failed','postcondition_failed')){
        throw 'MIGRATION_011_RECONCILIATION_LIVE_GUARDS_REJECTED'
    }
    return $true
}

function Assert-Migration011ReconciliationPostflightPacket($Diagnostic) {
    if($Diagnostic.status -cne 'DIAGNOSTIC_COMPLETE' -or $Diagnostic.identity -cne 'PASS' -or $Diagnostic.account_locked -cne 'Y' -or $Diagnostic.active_apply_sessions -ne 0 -or $Diagnostic.ledger_001_009 -cne 'PASS' -or $Diagnostic.migration_010 -cne 'ABSENT' -or $Diagnostic.ledger_011_checksum -cne 'PASS' -or $Diagnostic.ledger_011_state -cne 'APPLIED_CHECKSUM_PASS' -or $Diagnostic.schema_validation -cne 'PASS' -or $Diagnostic.inventory_validation -cne 'PASS' -or $Diagnostic.base_table_count -ne 16 -or $Diagnostic.view_count -ne 1 -or $Diagnostic.microsoft_identities -cne 'PRESENT' -or $Diagnostic.migration_state -cne 'APPLIED_VERIFIED'){
        throw 'MIGRATION_011_RECONCILIATION_POSTFLIGHT_REJECTED'
    }
    return $true
}

function Assert-Migration011ExceptionalRecoveryEvidence($Pending,$Temporary,[int]$TemporaryCount) {
    # This is a separate evidence class. It never makes an incomplete journal
    # acceptable to the standard RUNNER_EXITED marker validator.
    if($TemporaryCount -ne 1 -or $null -eq $Pending -or $null -eq $Temporary -or
       $Pending.run_id -cnotmatch '^[a-f0-9]{32}$' -or $Temporary.run_id -cne $Pending.run_id -or
       $Pending.phase -cne 'UNLOCK_INTENT' -or $Pending.child_finished -ne $false -or
       $Temporary.phase -cne 'RUNNER_STARTED' -or $Temporary.child_finished -ne $false -or
       $Pending.automatic_retry -ne $false -or $Temporary.automatic_retry -ne $false -or
       $Pending.account -cne 'up_van_migration011_apply@127.0.0.1' -or
       $Temporary.account -cne 'up_van_migration011_apply@127.0.0.1' -or
       $Pending.target_uuid -cne '77218fbd-d66d-11f1-9a0a-5c2886f057d5' -or
       $Temporary.target_uuid -cne '77218fbd-d66d-11f1-9a0a-5c2886f057d5' -or
       !($Temporary.child_pid -is [ValueType]) -or [int]$Temporary.child_pid -lt 1 -or
       !($Temporary.child_started_ticks -is [ValueType]) -or [long]$Temporary.child_started_ticks -lt 1) {
        throw 'MIGRATION_011_EXCEPTIONAL_EVIDENCE_REJECTED'
    }
    try{
        $pendingTime=[DateTimeOffset]::ParseExact([string]$Pending.updated_utc,'o',[Globalization.CultureInfo]::InvariantCulture,[Globalization.DateTimeStyles]::RoundtripKind)
        $temporaryTime=[DateTimeOffset]::ParseExact([string]$Temporary.updated_utc,'o',[Globalization.CultureInfo]::InvariantCulture,[Globalization.DateTimeStyles]::RoundtripKind)
    }catch{throw 'MIGRATION_011_EXCEPTIONAL_EVIDENCE_REJECTED'}
    $childTicks=[long]$Temporary.child_started_ticks
    if($temporaryTime -le $pendingTime -or $pendingTime.UtcDateTime.Ticks -gt $childTicks -or $childTicks -gt $temporaryTime.UtcDateTime.Ticks -or
       ($temporaryTime-$pendingTime).TotalMinutes -gt 5){throw 'MIGRATION_011_EXCEPTIONAL_EVIDENCE_REJECTED'}
    return [pscustomobject]@{run_id=$Pending.run_id;runner_exit_status='UNVERIFIED';journal_state='UNLOCK_INTENT_AND_RUNNER_STARTED';
        pending_updated_utc=$pendingTime.UtcDateTime.ToString('o');temporary_updated_utc=$temporaryTime.UtcDateTime.ToString('o');
        child_pid=[int]$Temporary.child_pid;child_started_ticks=$childTicks}
}

function Assert-Migration011ExceptionalChildNotRunning([int]$ProcessId,[long]$StartedTicks) {
    $process=$null
    try{$process=[Diagnostics.Process]::GetProcessById($ProcessId)}
    catch [ArgumentException]{return 'PROCESS_NOT_RUNNING'}
    try{
        try{$actualTicks=$process.StartTime.ToUniversalTime().Ticks}
        catch{throw 'MIGRATION_011_EXCEPTIONAL_PROCESS_STATE_UNKNOWN'}
        if($actualTicks -eq $StartedTicks){throw 'MIGRATION_011_EXCEPTIONAL_CHILD_STILL_RUNNING'}
        return 'PID_REUSED_FOR_DIFFERENT_PROCESS'
    }finally{if($process){$process.Dispose()}}
}

function New-Migration011ExceptionalAttemptAuditPaths([string]$AuditDirectory,[string]$SourceRunId,[string]$AttemptId) {
    if($SourceRunId -cnotmatch '^[a-f0-9]{32}$' -or $AttemptId -cnotmatch '^[a-f0-9]{32}$' -or $AttemptId -ceq $SourceRunId){
        throw 'MIGRATION_011_EXCEPTIONAL_ATTEMPT_ID_REJECTED'
    }
    $prefix=$SourceRunId+'.'+$AttemptId
    return [pscustomobject]@{intent=(Join-Path $AuditDirectory ($prefix+'.intent.json'));completed=(Join-Path $AuditDirectory ($prefix+'.completed.json'));failurePrefix=($prefix+'.failed-')}
}

function Assert-Migration011ExceptionalAttemptAvailable($Paths) {
    if($null -eq $Paths -or [string]::IsNullOrWhiteSpace([string]$Paths.intent) -or [string]::IsNullOrWhiteSpace([string]$Paths.completed)){
        throw 'MIGRATION_011_EXCEPTIONAL_ATTEMPT_PATHS_REJECTED'
    }
    # A persisted intent consumes an attempt even if the prior process stopped
    # before recording completion. Never infer permission to replay from absence
    # of a completion record or from an unverified commit outcome.
    if([IO.File]::Exists([string]$Paths.intent) -or [IO.File]::Exists([string]$Paths.completed)){
        throw 'MIGRATION_011_EXCEPTIONAL_AUDIT_REPLAY_REJECTED'
    }
    return $true
}

function Assert-Migration011ExceptionalApproval([string]$ApprovalToken,[string]$AttemptId) {
    if($AttemptId -cnotmatch '^[a-f0-9]{32}$' -or $ApprovalToken -cne ('APPROVE_MIGRATION_011_EXCEPTIONAL_ATTEMPT_'+$AttemptId) -or
       [Environment]::GetEnvironmentVariable('TEST_DB_EXECUTION_APPROVED','Process') -cne 'true' -or
       [Environment]::GetEnvironmentVariable('TEST_MIGRATION_011_EXCEPTIONAL_LEDGER_RECOVERY_APPROVED','Process') -cne 'true' -or
       [Environment]::GetEnvironmentVariable('TEST_MIGRATION_011_EXCEPTIONAL_RECOVERY_ATTEMPT_APPROVED','Process') -cne 'true'){
        throw 'MIGRATION_011_EXCEPTIONAL_APPROVAL_REQUIRED'
    }
    return $true
}

function ConvertFrom-Migration011RunnerChildOutput([int]$ExitCode,[string]$Stdout,[string]$FallbackStage='RUNNER_PROCESS') {
    # Never return raw stdout/stderr: a child can emit arbitrary diagnostic text.
    # The reconciliation runner's compact JSON contract is the only accepted source.
    $lines=@(([string]$Stdout -split "`r?`n") | Where-Object { $_ -match '^\s*\{.*\}\s*$' })
    $child=$null
    if($lines.Count -eq 1){try{$child=ConvertFrom-Json -InputObject $lines[0] -ErrorAction Stop}catch{}}
    if($null -eq $child){
        return [pscustomobject]@{status='CRITICAL';error_code='MIGRATION_011_CHILD_RESULT_UNAVAILABLE';failed_stage='CHILD_PROCESS_OUTPUT';
            exception_type='StructuredRunnerResultMissing';transaction_state='UNKNOWN';commit_state='UNKNOWN';child_exit_code=$ExitCode;
            audit_write_status='UNKNOWN';raw_error_recorded=$false;automatic_retry=$false}
    }
    # Select-Object supplies null for optional fields on Windows PowerShell
    # 5.1 even when the launcher enables StrictMode.
    $child=$child | Select-Object status,failed_stage,exception_type,error_code,audit_write_status,audit_failure,transaction_state,commit_state,mode,attempt_id,source_run_id,ledger
    $stage=if($child.failed_stage -and [string]$child.failed_stage -match '^[A-Z0-9_]{1,64}$'){[string]$child.failed_stage}else{$FallbackStage}
    $exceptionType=if($child.exception_type -and [string]$child.exception_type -match '^[A-Za-z0-9_.+`]{1,160}$'){[string]$child.exception_type}else{'StructuredFailureCategoryMissing'}
    $errorCode=if($child.error_code -and [string]$child.error_code -match '^MIGRATION_011_[A-Z0-9_]{1,96}$'){[string]$child.error_code}else{$null}
    $status=[string]$child.status
    if($ExitCode -ne 0 -and $status -eq 'PASS'){$status='CRITICAL'}
    if($status -notin @('PASS','FAIL','BLOCKED','CRITICAL')){$status='CRITICAL'}
    $auditStatus=if($child.audit_write_status){[string]$child.audit_write_status}elseif($child.audit_failure){[string]$child.audit_failure}elseif($status -eq 'PASS'){'WRITTEN'}else{'UNKNOWN'}
    if($auditStatus -notin @('WRITTEN','WRITE_FAILED','NOT_WRITTEN','UNKNOWN','NOT_ATTEMPTED','INTENT_WRITTEN','INTENT_WRITE_FAILED','COMPLETE_WRITTEN','COMPLETE_WRITE_FAILED','FAILURE_WRITTEN','FAILURE_WRITE_FAILED','COMPLETE_FAILED_FAILURE_WRITTEN','COMPLETE_FAILED_FAILURE_WRITE_FAILED')){$auditStatus='UNKNOWN'}
    return [pscustomobject]@{status=$status;error_code=$errorCode;failed_stage=$stage;exception_type=$exceptionType;
        transaction_state=$(if($child.transaction_state -in @('NOT_STARTED','START_UNCONFIRMED','ACTIVE','ROLLED_BACK','ROLLBACK_UNCONFIRMED','COMMIT_UNCONFIRMED','COMMITTED')){[string]$child.transaction_state}else{'UNKNOWN'});
        commit_state=$(if($child.commit_state -in @('NOT_COMMITTED','UNKNOWN','CONFIRMED')){[string]$child.commit_state}else{'UNKNOWN'});
        child_exit_code=$ExitCode;audit_write_status=$auditStatus;raw_error_recorded=$false;automatic_retry=$false;
        mode=$(if($child.mode -in @('EXCEPTIONAL_LEDGER_ONLY','LEDGER_ONLY')){[string]$child.mode}else{$null});
        attempt_id=$(if($child.attempt_id -match '^[a-f0-9]{32}$'){[string]$child.attempt_id}else{$null});
        source_run_id=$(if($child.source_run_id -match '^[a-f0-9]{32}$'){[string]$child.source_run_id}else{$null});
        ledger=$(if($child.ledger -in @('applied 1/1','UNCHANGED','UNKNOWN','VERIFY_REQUIRED')){[string]$child.ledger}else{'UNKNOWN'})}
}

function Invoke-Migration011CapturedChildProcess {
    param([Parameter(Mandatory=$true)][string]$Executable,[Parameter(Mandatory=$true)][AllowEmptyCollection()][string[]]$Arguments,
        [string]$WorkingDirectory='', [string]$FallbackStage='RUNNER_PROCESS')
    $quoted=@($Arguments | ForEach-Object { ConvertTo-Migration011WindowsArgument ([string]$_) })
    $psi=[Diagnostics.ProcessStartInfo]::new();$psi.FileName=$Executable;$psi.Arguments=($quoted -join ' ')
    if($WorkingDirectory){$psi.WorkingDirectory=$WorkingDirectory}
    $psi.UseShellExecute=$false;$psi.CreateNoWindow=$true;$psi.RedirectStandardOutput=$true;$psi.RedirectStandardError=$true
    $process=[Diagnostics.Process]::new();$process.StartInfo=$psi
    try{
        if(!$process.Start()){
            return [pscustomobject]@{status='CRITICAL';error_code='MIGRATION_011_CHILD_START_FAILED';failed_stage='CHILD_START';exception_type='ProcessStartFailed';transaction_state='NOT_STARTED';commit_state='NOT_COMMITTED';child_exit_code=-1;audit_write_status='NOT_WRITTEN';raw_error_recorded=$false;automatic_retry=$false}
        }
        $stdoutTask=$process.StandardOutput.ReadToEndAsync();$stderrTask=$process.StandardError.ReadToEndAsync()
        $process.WaitForExit();$stdout=$stdoutTask.Result;[void]$stderrTask.Result
        return (ConvertFrom-Migration011RunnerChildOutput $process.ExitCode $stdout $FallbackStage)
    }catch{
        $captureExit=-1
        if($process){try{if($process.HasExited){$captureExit=$process.ExitCode}}catch{}}
        return [pscustomobject]@{status='CRITICAL';error_code='MIGRATION_011_CHILD_CAPTURE_FAILED';failed_stage='CHILD_CAPTURE';exception_type='ChildCaptureFailed';transaction_state='UNKNOWN';commit_state='UNKNOWN';child_exit_code=$captureExit;audit_write_status='UNKNOWN';raw_error_recorded=$false;automatic_retry=$false}
    }finally{$process.Dispose()}
}

function Invoke-Migration011ReconciliationRunnerProcess {
    param([Parameter(Mandatory=$true)][string]$RunnerPath,[Parameter(Mandatory=$true)][string]$AttemptId,
        [Parameter(Mandatory=$true)][string]$ApprovalToken,[Parameter(Mandatory=$true)][string]$BackupDirectory,
        [Parameter(Mandatory=$true)][string]$BackupManifestSha256,[string]$PowerShellPath=(Join-Path $PSHOME 'powershell.exe'),
        [string]$WorkingDirectory=(Split-Path -Parent $RunnerPath))
    $args=@('-NoProfile','-NonInteractive','-ExecutionPolicy','Bypass','-File',$RunnerPath,'-Mode','Exceptional','-AttemptId',$AttemptId,
        '-ApprovalToken',$ApprovalToken,'-BackupDirectory',$BackupDirectory,'-BackupManifestSha256',$BackupManifestSha256)
    return (Invoke-Migration011CapturedChildProcess -Executable $PowerShellPath -Arguments $args -WorkingDirectory $WorkingDirectory -FallbackStage 'RECONCILIATION_RUNNER')
}

function Write-Migration011ExceptionalAuditRecord([string]$Path,$Record) {
    $bytes=[Text.UTF8Encoding]::new($false).GetBytes((ConvertTo-Json -InputObject $Record -Compress -Depth 6))
    $stream=$null
    try{
        $stream=[IO.File]::Open($Path,[IO.FileMode]::CreateNew,[IO.FileAccess]::Write,[IO.FileShare]::None)
        $stream.Write($bytes,0,$bytes.Length);$stream.Flush($true);$stream.Dispose();$stream=$null
        Assert-Migration011CredentialFileAcl $Path
    }catch{throw 'MIGRATION_011_EXCEPTIONAL_AUDIT_WRITE_FAILED'}
    finally{if($stream){$stream.Dispose()};[Array]::Clear($bytes,0,$bytes.Length)}
}

function Write-Migration011ExceptionalFailureAudit([string]$Path,$Record,[bool]$CompletionWriteFailed) {
    try {
        Write-Migration011ExceptionalAuditRecord $Path $Record
        if($CompletionWriteFailed){return 'COMPLETE_FAILED_FAILURE_WRITTEN'}
        return 'FAILURE_WRITTEN'
    } catch {
        if($CompletionWriteFailed){return 'COMPLETE_FAILED_FAILURE_WRITE_FAILED'}
        return 'FAILURE_WRITE_FAILED'
    }
}

function Assert-Migration011ExceptionalAuditDirectory([string]$Path) {
    if(!(Test-Path -LiteralPath $Path -PathType Container)){throw 'MIGRATION_011_EXCEPTIONAL_AUDIT_DIRECTORY_MISSING'}
    $sid=[Security.Principal.WindowsIdentity]::GetCurrent().User
    $acl=[IO.DirectoryInfo]::new($Path).GetAccessControl()
    $rules=$acl.GetAccessRules($true,$true,[Security.Principal.SecurityIdentifier]);$allowed=@{}
    foreach($rule in $rules){
        if($rule.AccessControlType -ne [Security.AccessControl.AccessControlType]::Allow -or
           ($rule.FileSystemRights -band [Security.AccessControl.FileSystemRights]::FullControl) -ne [Security.AccessControl.FileSystemRights]::FullControl){
            throw 'MIGRATION_011_EXCEPTIONAL_AUDIT_ACL_REJECTED'
        }
        $allowed[$rule.IdentityReference.Value]=$true
    }
    if(!$acl.AreAccessRulesProtected -or $rules.Count -ne 2 -or
       !$acl.GetOwner([Security.Principal.SecurityIdentifier]).Equals($sid) -or
       !$allowed.ContainsKey($sid.Value) -or !$allowed.ContainsKey('S-1-5-18')){
        throw 'MIGRATION_011_EXCEPTIONAL_AUDIT_ACL_REJECTED'
    }
}

function New-Migration011LedgerReconciliationUpdateSql([string]$Version,[string]$Checksum) {
    if($Version -cne '011_microsoft_identities' -or $Checksum -cnotmatch '^[a-f0-9]{64}$'){
        throw 'MIGRATION_011_RECONCILIATION_PLAN_REJECTED'
    }
    return "UPDATE schema_migrations SET status='applied',finished_at=UTC_TIMESTAMP(6),failure_code=NULL WHERE version='$Version' AND checksum='$Checksum' AND status='failed' AND completed_statements=1 AND total_statements=1 AND failure_code IN ('statement_failed','postcondition_failed')"
}

function Invoke-Migration011LedgerReconciliationProtocol {
    param([Parameter(Mandatory=$true)]$Transport,[Parameter(Mandatory=$true)][Collections.IDictionary]$DiagnosticQueries,
        [Parameter(Mandatory=$true)][scriptblock]$GetDiagnostic,[Parameter(Mandatory=$true)][string]$ExpectedVersion,
        [Parameter(Mandatory=$true)][string]$ExpectedChecksum,[Parameter(Mandatory=$true)][scriptblock]$BackupVerify,
        [Parameter(Mandatory=$true)][scriptblock]$RecoveryMarkerVerify,[Collections.IDictionary]$ExecutionState=@{})
    $lockHeld=$false;$inTransaction=$false;$committed=$false;$released=$false
    $ExecutionState.failed_stage=$null
    $ExecutionState.stage='IDENTITY';$ExecutionState.transaction_state='NOT_STARTED';$ExecutionState.update_sent=$false;$ExecutionState.commit_attempted=$false;$ExecutionState.commit_confirmed=$false
    try{
        [void](ConvertFrom-Migration011CliIdentity $Transport.Query('SELECT CONCAT(@@port,CHAR(9),@@server_uuid,CHAR(9),DATABASE(),CHAR(9),VERSION(),CHAR(9),CURRENT_USER())'))
        $ExecutionState.stage='MIGRATION_LOCK'
        if(([string]$Transport.Query("SELECT GET_LOCK('up_van_booking_test:migrations',0)")).Trim() -cne '1'){
            throw 'MIGRATION_011_RECONCILIATION_LOCK_UNAVAILABLE'
        }
        $lockHeld=$true
        $ExecutionState.stage='START_TRANSACTION'
        $ExecutionState.transaction_state='START_UNCONFIRMED'
        $inTransaction=$true
        $Transport.Execute('START TRANSACTION')
        $ExecutionState.transaction_state='ACTIVE'
        $ExecutionState.stage='LEDGER_ROW_LOCK'
        $locked=ConvertFrom-Migration011CliLedgerRow ($Transport.Query("SELECT version,checksum,status,completed_statements,total_statements,failure_code FROM schema_migrations WHERE version='$ExpectedVersion' FOR UPDATE")) $ExpectedVersion $ExpectedChecksum
        $ExecutionState.stage='BACKUP_GUARD'
        & $BackupVerify
        $ExecutionState.stage='MARKER_GUARD'
        & $RecoveryMarkerVerify
        $ExecutionState.stage='PRECONDITIONS'
        $before=& $GetDiagnostic $DiagnosticQueries $Transport
        [void](Assert-Migration011ReconciliationPreflightPacket $before)
        # Repeat the full Validator read while the ledger row and advisory lock are held.
        $ExecutionState.stage='PRECONDITIONS_RECHECK'
        $rechecked=& $GetDiagnostic $DiagnosticQueries $Transport
        [void](Assert-Migration011ReconciliationPreflightPacket $rechecked)
        $ExecutionState.stage='BACKUP_GUARD_RECHECK'
        & $BackupVerify
        $ExecutionState.stage='MARKER_GUARD_RECHECK'
        & $RecoveryMarkerVerify
        $ExecutionState.stage='TRANSACTION_GUARD'
        # MySQL 8.4 has no @@session.in_transaction. Releasing a named
        # savepoint proves the transaction is still active before the write.
        $Transport.Execute('SAVEPOINT upvan011_reconciliation')
        $Transport.Execute('RELEASE SAVEPOINT upvan011_reconciliation')
        $ExecutionState.stage='LEDGER_UPDATE'
        $update=New-Migration011LedgerReconciliationUpdateSql $ExpectedVersion $ExpectedChecksum
        $ExecutionState.update_sent=$true
        if(([string]$Transport.ExecuteAffectedRows($update)).Trim() -cne '1'){
            throw 'MIGRATION_011_RECONCILIATION_UPDATE_UNCONFIRMED'
        }
        $ExecutionState.stage='POSTCONDITIONS_PRECOMMIT'
        $after=& $GetDiagnostic $DiagnosticQueries $Transport
        [void](Assert-Migration011ReconciliationPostflightPacket $after)
        # A lost response after sending COMMIT is ambiguous: MySQL may already
        # have committed. Preserve the recovery marker and force live verification.
        try{
            $ExecutionState.stage='COMMIT'
            $ExecutionState.commit_attempted=$true
            $ExecutionState.transaction_state='COMMIT_UNCONFIRMED'
            $Transport.Execute('COMMIT AND NO CHAIN NO RELEASE')
        }catch{throw 'MIGRATION_011_RECONCILIATION_COMMIT_OUTCOME_UNKNOWN'}
        $inTransaction=$false;$committed=$true;$ExecutionState.transaction_state='COMMITTED';$ExecutionState.commit_confirmed=$true
        $ExecutionState.stage='POSTFLIGHT'
        try{$postCommit=& $GetDiagnostic $DiagnosticQueries $Transport;[void](Assert-Migration011ReconciliationPostflightPacket $postCommit)}
        catch{throw 'MIGRATION_011_RECONCILIATION_COMMITTED_POSTFLIGHT_FAILED'}
        $ExecutionState.stage='POSTFLIGHT_MARKER'
        & $RecoveryMarkerVerify
    }catch{
        if(!$ExecutionState.failed_stage){$ExecutionState.failed_stage=[string]$ExecutionState.stage}
        if($inTransaction -and !$ExecutionState.commit_attempted){
            $ExecutionState.stage='ROLLBACK'
            try{$Transport.Execute('ROLLBACK AND NO CHAIN NO RELEASE');$inTransaction=$false;$ExecutionState.transaction_state='ROLLED_BACK'}
            catch{$ExecutionState.transaction_state='ROLLBACK_UNCONFIRMED'}
        }
        throw
    }finally{
        if($lockHeld -and !$inTransaction){
            $ExecutionState.stage='LOCK_RELEASE'
            try{$released=([string]$Transport.Query("SELECT RELEASE_LOCK('up_van_booking_test:migrations')")).Trim() -ceq '1'}catch{}
        }
        if($Transport -is [IDisposable]){try{$Transport.Dispose()}catch{}}
    }
    if(!$committed){throw 'MIGRATION_011_RECONCILIATION_COMMIT_UNVERIFIED'}
    if(!$released){throw 'MIGRATION_011_RECONCILIATION_COMMITTED_LOCK_RELEASE_UNVERIFIED'}
    $ExecutionState.stage='COMPLETE'
    return [pscustomobject]@{status='PASS';mode='LEDGER_ONLY';ledger='applied 1/1';checksum=$ExpectedChecksum;
        schema_postconditions='PASS';account_locked='Y';ddl_executed=$false;automatic_retry=$false;migration_lock_released=$true}
}

function Get-Migration011ReconciliationFailureSummary([System.Management.Automation.ErrorRecord]$ErrorRecord,[Collections.IDictionary]$ExecutionState) {
    $base=$ErrorRecord.Exception.GetBaseException()
    $match=[regex]::Match([string]$ErrorRecord.Exception.Message,'MIGRATION_011_[A-Z0-9_]+')
    $code=if($match.Success){$match.Value}else{'MIGRATION_011_RECONCILIATION_FAILED'}
    $commitState=if($ExecutionState.commit_confirmed){'CONFIRMED'}elseif($ExecutionState.commit_attempted){'UNKNOWN'}else{'NOT_COMMITTED'}
    $failedStage=if($ExecutionState.failed_stage){[string]$ExecutionState.failed_stage}else{[string]$ExecutionState.stage}
    return [pscustomobject]@{error_code=$code;failed_stage=$failedStage;exception_type=$base.GetType().FullName;
        transaction_state=[string]$ExecutionState.transaction_state;ledger_update_sent=[bool]$ExecutionState.update_sent;commit_state=$commitState;raw_error_recorded=$false}
}
