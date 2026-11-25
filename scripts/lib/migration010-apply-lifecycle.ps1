# Shared Windows ACL, DPAPI and process-quoting helpers come from the existing library.
. (Join-Path $PSScriptRoot 'migration011-apply-credentials.ps1')
. (Join-Path $PSScriptRoot 'migration011-reconciliation-cli.ps1')

function Assert-Migration010OrdinaryPath([string]$Path,[IO.FileAttributes]$Kind) {
    $attributes=[IO.File]::GetAttributes($Path)
    if(($attributes -band [IO.FileAttributes]::ReparsePoint) -or
       (($attributes -band [IO.FileAttributes]::Directory) -ne $Kind)){
        throw 'MIGRATION_010_STORE_PATH_REJECTED'
    }
}

function Read-Migration010ApplyCredential([string]$Directory) {
    $path=Join-Path $Directory 'apply.dpapi'
    $metadata=Join-Path $Directory 'metadata.json'
    if(![IO.File]::Exists($path) -or ![IO.File]::Exists($metadata)){throw 'MIGRATION_010_CREDENTIAL_MISSING'}
    Assert-Migration010OrdinaryPath (Split-Path -Parent $Directory) ([IO.FileAttributes]::Directory)
    Assert-Migration010OrdinaryPath $Directory ([IO.FileAttributes]::Directory)
    Assert-Migration011ExceptionalAuditDirectory $Directory
    foreach($file in @($path,$metadata)){
        Assert-Migration010OrdinaryPath $file ([IO.FileAttributes]0)
        Assert-Migration011CredentialFileAcl $file
        if([IO.FileInfo]::new($file).Length -gt 8192){throw 'MIGRATION_010_CREDENTIAL_FILE_REJECTED'}
    }
    $expected=@{format='upvan-migration010-credential-v1';dpapi_scope='CurrentUser'
        windows_sid=[Security.Principal.WindowsIdentity]::GetCurrent().User.Value
        account='up_van_migration010_apply@127.0.0.1';host='127.0.0.1';port=3306
        database='up_van_booking_test';uuid='77218fbd-d66d-11f1-9a0a-5c2886f057d5';mysql_version='8.4.9';plugin='caching_sha2_password'}
    $metadataLock=$null
    $cipherLock=$null
    try{
        $metadataLock=[IO.File]::Open($metadata,'Open','Read','Read')
        $cipherLock=[IO.File]::Open($path,'Open','Read','Read')
        $packet=ConvertFrom-Json -InputObject ([IO.File]::ReadAllText($metadata,[Text.Encoding]::UTF8)) -ErrorAction Stop
        foreach($key in $expected.Keys){
            $prop=$packet.PSObject.Properties[$key]
            if(!$prop -or $prop.Value -cne $expected[$key]){throw 'MIGRATION_010_CREDENTIAL_METADATA_REJECTED'}
        }
        $pin=$packet.PSObject.Properties['ciphertext_sha256']
        $actual=(Get-FileHash -InputStream $cipherLock -Algorithm SHA256).Hash.ToLowerInvariant()
        if($packet.port -isnot [int] -or !$pin -or $pin.Value -cne $actual){throw 'MIGRATION_010_CREDENTIAL_METADATA_REJECTED'}
        try{return (Read-Migration011DpapiCredential -Path $path)}
        catch{throw 'MIGRATION_010_DPAPI_DECRYPT_FAILED'}
    } finally {
        if($cipherLock){$cipherLock.Dispose()}
        if($metadataLock){$metadataLock.Dispose()}
    }
}

function Write-Migration010JournalRecord([string]$Path,[Collections.IDictionary]$Record) {
    # Flush an immutable record before advancing to the next operation.
    $stream=[IO.File]::Open($Path,'CreateNew','Write','None')
    try{
        $bytes=[Text.UTF8Encoding]::new($false).GetBytes((ConvertTo-Json -InputObject $Record -Compress))
        $stream.Write($bytes,0,$bytes.Length)
        $stream.Flush($true)
    } finally {$stream.Dispose()}
    Assert-Migration011CredentialFileAcl $Path
}

function Invoke-Migration010StdinChild([string]$Executable,[string[]]$Arguments,[string]$WorkingDirectory,
    [Security.SecureString]$Credential,[scriptblock]$BeforeCredential) {
    $info=[Diagnostics.ProcessStartInfo]::new()
    $info.FileName=$Executable
    $info.Arguments=(($Arguments | ForEach-Object {ConvertTo-Migration011WindowsArgument $_}) -join ' ')
    $info.WorkingDirectory=$WorkingDirectory
    $info.UseShellExecute=$false
    $info.CreateNoWindow=$true
    $info.RedirectStandardInput=$true
    $info.RedirectStandardOutput=$true
    $info.RedirectStandardError=$true
    $info.EnvironmentVariables.Remove('MYSQL_PWD')
    $info.EnvironmentVariables.Remove('MIGRATION_010_APPLY_DB_PASSWORD')
    $process=[Diagnostics.Process]::new()
    $process.StartInfo=$info
    $started=$false
    $ptr=[IntPtr]::Zero
    $bytes=$null
    try{
        if(!$process.Start()){throw 'MIGRATION_010_CHILD_START_FAILED'}
        $started=$true
        $stdout=$process.StandardOutput.ReadToEndAsync()
        $stderr=$process.StandardError.ReadToEndAsync()
        try{
            # The PHP child waits for the NUL-terminated packet. A failed or
            # interrupted journal write leaves it with EOF and no credential.
            & $BeforeCredential $process.Id $process.StartTime.ToUniversalTime().Ticks
            if($Credential.Length -lt 12 -or $Credential.Length -gt 255){throw 'MIGRATION_010_CREDENTIAL_FORMAT_REJECTED'}
            $ptr=[Runtime.InteropServices.Marshal]::SecureStringToBSTR($Credential)
            $bytes=[byte[]]::new($Credential.Length+1)
            for($i=0;$i -lt $Credential.Length;$i++){
                $char=[Runtime.InteropServices.Marshal]::ReadInt16($ptr,$i*2)
                if($char -lt 33 -or $char -gt 126){throw 'MIGRATION_010_CREDENTIAL_FORMAT_REJECTED'}
                $bytes[$i]=[byte]$char
            }
            $process.StandardInput.BaseStream.Write($bytes,0,$bytes.Length)
            $process.StandardInput.BaseStream.Flush()
        } finally {
            if($bytes){[Array]::Clear($bytes,0,$bytes.Length)}
            if($ptr -ne [IntPtr]::Zero){[Runtime.InteropServices.Marshal]::ZeroFreeBSTR($ptr)}
            try{$process.StandardInput.Close()}catch{}
            $process.WaitForExit()
        }
        [void]$stderr.Result
        return @{exit_code=$process.ExitCode;stdout=$stdout.Result}
    } finally {
        if($started -and !$process.HasExited){
            try{$process.StandardInput.Close()}catch{}
            $process.WaitForExit()
        }
        $process.Dispose()
    }
}

function ConvertFrom-Migration010RunnerResult([int]$ExitCode,[string]$Output,[string]$ManifestPin) {
    $safe=[ordered]@{status='FAIL';exit_code=$ExitCode;code='MIGRATION_010_RUNNER_UNCONFIRMED';stage=$null;ledger=$null}
    try {
        if($Output.Length -gt 32768){return [pscustomobject]$safe}
        $data=ConvertFrom-Json -InputObject $Output -ErrorAction Stop
        $code=$data.PSObject.Properties['code']
        if($code -and $code.Value -cin @('MIGRATION_010_EXECUTION_FAILED','MIGRATION_010_STDIN_CREDENTIAL_REJECTED','MIGRATION_010_APPLY_REJECTED','MIGRATION_010_LEDGER_DIRTY_OR_CHECKSUM','MIGRATION_010_APPLY_LOCK_UNAVAILABLE')){
            $safe.code=$code.Value
        }
        $stage=$data.PSObject.Properties['execution_stage']
        if(!$stage){$stage=$data.PSObject.Properties['stage']}
        if($stage -and $stage.Value -cin @('LEDGER_STARTED','LOCK_CHECK','DDL','STATEMENT','LEDGER_PROGRESS','POSTCONDITIONS','LEDGER_APPLIED','LOCK_RELEASE','MIGRATION_010_APPLY_IDENTITY','MIGRATION_010_APPLY_GRANTS','MIGRATION_010_APPLY_LIVE_SCHEMA','MIGRATION_010_APPLY_BACKUP')){
            $safe.stage=$stage.Value
        }
        if($ExitCode -eq 0 -and $data.status -ceq 'PASS' -and $data.database -ceq 'up_van_booking_test' -and
           $data.uuid -ceq '77218fbd-d66d-11f1-9a0a-5c2886f057d5' -and $data.version -ceq '010_driver_auth' -and
           $data.ledger -ceq 'applied' -and $data.completed_statements -eq 3 -and $data.total_statements -eq 3 -and
           $data.live_schema_verified -eq $true -and $data.backup.manifest_sha256 -ceq $ManifestPin){
            $safe.status='PASS'
            $safe.code=$null
            $safe.ledger='APPLIED_3_OF_3'
        }
    }catch{}
    return [pscustomobject]$safe
}

function Invoke-Migration010ApplyLifecycle([string]$Action,[Collections.IDictionary]$Operations) {
    $result=[ordered]@{status='BLOCKED';stage='PENDING';account_lock='UNKNOWN';recovery_required=$false;automatic_retry=$false}
    if($Action -eq 'Recover'){
        $result.recovery_required=$true
        try{
            $result.stage='RECOVERY_EVIDENCE'
            & $Operations.VerifyRecovery
            $result.stage='RECOVERY_LOCK'
            & $Operations.Lock
            $result.account_lock='Y'
            $result.stage='RECOVERY_SESSIONS'
            & $Operations.NoRunnerOrSessions
            $result.status='LOCK_VERIFIED_INSPECTION_REQUIRED'
        }catch{$result.status='CRITICAL'}
        return [pscustomobject]$result
    }

    $relock=$false
    $runnerPassed=$false
    try{
        & $Operations.NoPending
        if($Action -eq 'Apply'){
            $result.stage='APPROVAL'
            & $Operations.Approval
        }
        $result.stage='PREFLIGHT'
        & $Operations.Preflight
        $result.account_lock='Y'
        if($Action -ne 'Apply'){
            $result.status='PASS'
            return [pscustomobject]$result
        }
        $result.stage='UNLOCK_INTENT'
        & $Operations.MarkIntent
        $relock=$true
        $result.recovery_required=$true
        $result.account_lock='UNKNOWN'
        $result.stage='UNLOCK'
        & $Operations.Unlock
        $result.stage='RUNNER'
        & $Operations.Run
        $runnerPassed=$true
    } catch {
        if($result.stage -in @('PENDING','UNLOCK_INTENT')){$result.recovery_required=$true}
        if($relock){$result.status='FAIL'}
    } finally {
        if($relock){
            try{& $Operations.Lock;$result.account_lock='Y'}
            catch{$result.status='CRITICAL';$result.cleanup_stage='RELOCK'}
        }
    }
    if($runnerPassed -and $result.account_lock -ceq 'Y'){
        try{
            $result.stage='POSTFLIGHT'
            & $Operations.Postflight
            $result.stage='JOURNAL_FINALIZE'
            & $Operations.Complete
            $result.status='PASS'
            $result.recovery_required=$false
        }catch{$result.status='CRITICAL'}
    }
    return [pscustomobject]$result
}
