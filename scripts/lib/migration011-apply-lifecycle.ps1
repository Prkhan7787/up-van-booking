function Assert-Migration011ApplyRuntime([string]$ProjectRoot) {
    $runtime=Resolve-Migration011EvaluatorRuntime $ProjectRoot
    $runner=Join-Path $ProjectRoot 'bin/db-migration-011.php'
    if(![IO.File]::Exists($runner)){throw 'MIGRATION_011_RUNNER_MISSING'}
    $arguments=@('-n','-d','extension_dir=ext','-d','extension=pdo_mysql','-d','extension=mbstring','-d','extension=openssl','-d','extension=curl',$runner,'--runtime-check')
    $result=Invoke-Migration011MysqlCaptured -ClientPath $runtime -ClientArgs @($arguments|ForEach-Object{ConvertTo-Migration011WindowsArgument $_}) -Sql ' ' -WorkingDirectory (Split-Path -Parent $runtime)
    if($result.ExitCode -ne 0 -or $result.Stdout.Trim() -cne 'MIGRATION_011_APPLY_RUNTIME=8.4' -or $result.Stderr.Length -ne 0){throw 'MIGRATION_011_APPLY_RUNTIME_REJECTED'}
    return $runtime
}

function Publish-Migration011Journal([string]$Temporary,[string]$Destination) {
    if([IO.File]::Exists($Destination)){
        # PS 5.1 converts $null string arguments to empty strings. Preserve a named
        # previous status file instead of passing an invalid empty backup filename.
        $previous=$Destination+'.previous-'+[guid]::NewGuid().ToString('N')
        [IO.File]::Replace($Temporary,$Destination,$previous)
    }else{[IO.File]::Move($Temporary,$Destination)}
}

function Complete-Migration011ReconciliationMarker([string]$Pending,[string]$ExpectedRunId,[bool]$LedgerVerified,[bool]$SchemaVerified,[bool]$AccountLocked) {
    if(!$LedgerVerified -or !$SchemaVerified -or !$AccountLocked){throw 'MIGRATION_011_RECONCILIATION_MARKER_FINALIZE_GUARD_REJECTED'}
    if($ExpectedRunId -cnotmatch '^[a-f0-9]{32}$' -or ![IO.File]::Exists($Pending)){throw 'MIGRATION_011_RECONCILIATION_MARKER_REJECTED'}
    Assert-Migration011CredentialFileAcl $Pending
    $marker=ConvertFrom-Json -InputObject ([IO.File]::ReadAllText($Pending)) -ErrorAction Stop
    if($marker.run_id -cne $ExpectedRunId -or $marker.phase -cne 'RUNNER_EXITED' -or $marker.child_finished -ne $true -or `
        $marker.automatic_retry -ne $false -or $marker.account -cne 'up_van_migration011_apply@127.0.0.1' -or `
        $marker.target_uuid -cne '77218fbd-d66d-11f1-9a0a-5c2886f057d5'){
        throw 'MIGRATION_011_RECONCILIATION_MARKER_REJECTED'
    }
    $destination=Join-Path (Split-Path -Parent $Pending) ($ExpectedRunId+'.reconciled.json')
    if([IO.File]::Exists($destination)){throw 'MIGRATION_011_RECONCILIATION_MARKER_ARCHIVE_EXISTS'}
    [IO.File]::Move($Pending,$destination)
    return [pscustomobject]@{marker_archived=$true;marker_archive=$destination;run_id=$ExpectedRunId}
}

function Assert-Migration011WrapperApproval([string]$Token) {
    if($Token -cne 'APPLY_MIGRATION_011_TO_UP_VAN_BOOKING_TEST' -or
       [Environment]::GetEnvironmentVariable('TEST_DB_EXECUTION_APPROVED','Process') -cne 'true' -or
       [Environment]::GetEnvironmentVariable('TEST_MIGRATION_011_APPLY_APPROVED','Process') -cne 'true'){
        throw 'MIGRATION_011_APPROVAL_REQUIRED'
    }
}

function Invoke-Migration011ApplyLifecycle {
    param([ValidateSet('Preflight','Apply','Recover')][string]$Action,[Collections.IDictionary]$Operations)
    $result=[ordered]@{status='FAIL';action=$Action;code=$null;failed_stage=$null;exception_type=$null;account_lock_final='NOT_CHECKED';runner_status='NOT_RUN';ledger='UNKNOWN';automatic_retry=$false;recovery_required=$false}
    $stage='INITIAL'
    $mustRelock=$false;$completed=$false
    try{
        if($Action -eq 'Recover'){
            # Recovery deliberately does not depend on PHP, backup freshness or apply approval.
            $mustRelock=$true
            $stage='RECOVERY_IDENTITY'; & $Operations.Identity
            $stage='RECOVERY_EVIDENCE'; & $Operations.Recovery
            $result.recovery_required=$true
        }else{
            $stage='RUNTIME'; & $Operations.Runtime
            $stage='PENDING'; & $Operations.Pending
            if($Action -eq 'Apply'){$stage='APPROVAL'; & $Operations.Approval}
            $stage='BACKUP'; & $Operations.Backup
            $stage='PREFLIGHT'; & $Operations.Preflight
            if($Action -eq 'Preflight'){$result.status='PASS';$result.account_lock_final='Y'}
            else{
                # Recheck expiry and approval immediately before account management.
                $stage='BACKUP_RECHECK'; & $Operations.Backup
                $stage='APPROVAL_RECHECK'; & $Operations.Approval
                $stage='IDENTITY'; & $Operations.Identity
                $stage='JOURNAL_INTENT'; & $Operations.Mark
                # Set intent BEFORE dispatching UNLOCK; an error does not prove no server change.
                $mustRelock=$true
                $stage='UNLOCK'; & $Operations.Unlock
                $stage='RUNNER_DISPATCH';$result.runner_status='START_REQUESTED'
                $applied=& $Operations.Apply
                $result.runner_status=$applied.status;$result.ledger=$applied.ledger
                if($applied.status -cne 'PASS' -or $applied.ledger -cne 'applied 1/1'){throw 'MIGRATION_011_APPLY_UNCONFIRMED'}
                $completed=$true
            }
        }
    }catch{
        $result.failed_stage=$stage;$result.exception_type=$_.Exception.GetBaseException().GetType().FullName
        $match=[regex]::Match($_.Exception.Message,'MIGRATION_011_[A-Z0-9_]+')
        $result.code=if($match.Success){$match.Value}else{'MIGRATION_011_WRAPPER_FAILED'}
        if($mustRelock -or $result.code -eq 'MIGRATION_011_RECOVERY_REQUIRED_NO_RETRY'){$result.recovery_required=$true}
    }finally{
        if($mustRelock){
            try{
                & $Operations.Relock
                $result.account_lock_final='Y'
                if($Action -eq 'Recover' -and !$result.code){$result.status='RECOVERED_LOCK_ONLY'}
            }catch{
                $result.status='CRITICAL';$result.code='MIGRATION_011_RELOCK_OR_RECOVERY_UNVERIFIED'
                $result.account_lock_final='UNKNOWN';$result.recovery_required=$true
            }
            if($completed -and $result.account_lock_final -eq 'Y'){
                try{& $Operations.Complete;$result.status='PASS';$result.recovery_required=$false}
                catch{$result.status='FAIL';$result.code='MIGRATION_011_JOURNAL_FINALIZE_FAILED';$result.recovery_required=$true}
            }
        }
    }
    return [pscustomobject]$result
}
