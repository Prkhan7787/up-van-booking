#Requires -Version 5.1
#Requires -PSEdition Desktop
[CmdletBinding()]
param([ValidateSet('Provision','VerifyExisting','ReadOnlyPreflight','Diagnostics')][string]$Action='Provision')
$ErrorActionPreference='Stop'
Set-StrictMode -Version Latest
. (Join-Path $PSScriptRoot 'lib/migration011-apply-credentials.ps1')

$account='up_van_migration011_apply@127.0.0.1'
$proposalHash='2ebee4bea4560acf210bed537d32b4778843eed38ad67db0be2436d7f9682336'
$project=Split-Path -Parent $PSScriptRoot
$provisionScript=Join-Path $PSScriptRoot 'migration011-apply-account-provision.ps1'
$resultRoot=Join-Path (Join-Path ([Environment]::GetFolderPath('LocalApplicationData')) 'UPVanBooking') 'migration011\provision-results'
$runId=[guid]::NewGuid().ToString('N')
$resultPath=Join-Path $resultRoot ("provision-{0}.json" -f $runId)
$identity=[Security.Principal.WindowsIdentity]::GetCurrent().User
$groupText=& whoami.exe /groups 2>$null | Out-String
$medium=$LASTEXITCODE -eq 0 -and $groupText -match 'S-1-16-8192'
$result=[ordered]@{
    run_id=$runId
    completed_at_utc=[DateTime]::UtcNow.ToString('o')
    target='127.0.0.1:3306/up_van_booking_test'
    account=$account
    status='BLOCKED'
    identity='UNKNOWN'
    account_state='UNKNOWN'
    grants='UNKNOWN'
    dpapi_credential='UNKNOWN'
    account_locked='UNKNOWN'
    readonly_preflight='NOT_TESTED'
    ledger='NOT_TESTED'
    schema_preconditions='NOT_TESTED'
    inventory='NOT_TESTED'
    backup_tooling='NOT_TESTED'
    backup_created=$false
    apply_ready=$false
    diagnostic=$null
    mysql_error_code=$null
    error_code=$null
    exit_code=$null
}

function Write-ProvisionResult {
    if(!(Test-Path -LiteralPath $resultRoot -PathType Container)){
        $inheritance=[Security.AccessControl.InheritanceFlags]'ContainerInherit,ObjectInherit'
        $acl=[Security.AccessControl.DirectorySecurity]::new();$acl.SetAccessRuleProtection($true,$false);$acl.SetOwner($identity)
        foreach($sid in @($identity,[Security.Principal.SecurityIdentifier]::new('S-1-5-18'))){
            $acl.AddAccessRule([Security.AccessControl.FileSystemAccessRule]::new($sid,[Security.AccessControl.FileSystemRights]::FullControl,$inheritance,[Security.AccessControl.PropagationFlags]::None,[Security.AccessControl.AccessControlType]::Allow))
        }
        [void][IO.Directory]::CreateDirectory($resultRoot,$acl)
    }
    $dir=[IO.DirectoryInfo]::new($resultRoot);$dirAcl=$dir.GetAccessControl();$rules=$dirAcl.GetAccessRules($true,$true,[Security.Principal.SecurityIdentifier]);$allowed=@{}
    foreach($rule in $rules){
        if($rule.AccessControlType -ne [Security.AccessControl.AccessControlType]::Allow -or ($rule.FileSystemRights -band [Security.AccessControl.FileSystemRights]::FullControl) -ne [Security.AccessControl.FileSystemRights]::FullControl){throw 'MIGRATION_011_RESULT_DIRECTORY_ACL_REJECTED'}
        $allowed[$rule.IdentityReference.Value]=$true
    }
    if(!$dirAcl.AreAccessRulesProtected -or $rules.Count -ne 2 -or !$dirAcl.GetOwner([Security.Principal.SecurityIdentifier]).Equals($identity) -or !$allowed.ContainsKey($identity.Value) -or !$allowed.ContainsKey('S-1-5-18')){throw 'MIGRATION_011_RESULT_DIRECTORY_ACL_REJECTED'}
    $json=ConvertTo-Json -InputObject $result -Compress -Depth 20
    $bytes=[Text.UTF8Encoding]::new($false).GetBytes($json)
    $stream=$null
    try{$stream=[IO.File]::Open($resultPath,[IO.FileMode]::CreateNew,[IO.FileAccess]::Write,[IO.FileShare]::None);$stream.Write($bytes,0,$bytes.Length);$stream.Flush($true)}
    finally{if($stream){$stream.Dispose()};[Array]::Clear($bytes,0,$bytes.Length)}
    Assert-Migration011CredentialFileAcl $resultPath
}

if(!$medium){$result.error_code='MIGRATION_011_NORMAL_WINDOWS_MEDIUM_SESSION_REQUIRED';$result.exit_code=2}
else{
    $psi=$null;$process=$null;$stdout=$null;$stderr=$null
    try{
        $powershell=Join-Path $PSHOME 'powershell.exe'
        $args=@('-NoProfile','-ExecutionPolicy','Bypass','-File',$provisionScript,'-Action',$Action,'-ApprovedSha256',$proposalHash)
        $psi=[Diagnostics.ProcessStartInfo]::new();$psi.FileName=$powershell;$psi.Arguments=(($args|ForEach-Object{ConvertTo-Migration011WindowsArgument ([string]$_)}) -join ' ')
        $psi.UseShellExecute=$false;$psi.CreateNoWindow=$true;$psi.RedirectStandardOutput=$true;$psi.RedirectStandardError=$true
        $psi.WorkingDirectory=$project
        $sessionEnvironment=@{USERPROFILE=$env:USERPROFILE;APPDATA=$env:APPDATA;LOCALAPPDATA=$env:LOCALAPPDATA}
        Set-Migration011ProvisionChildEnvironment -StartInfo $psi -SessionEnvironment $sessionEnvironment
        if($Action -ne 'Provision'){$psi.EnvironmentVariables.Remove('UPVAN_MIGRATION011_PROVISION_APPROVED')}
        $process=[Diagnostics.Process]::new();$process.StartInfo=$psi
        if(!$process.Start()){throw 'MIGRATION_011_PROVISION_CHILD_START_FAILED'}
        $outTask=$process.StandardOutput.ReadToEndAsync();$errTask=$process.StandardError.ReadToEndAsync();$process.WaitForExit()
        $stdout=$outTask.Result;$stderr=$errTask.Result;$result.exit_code=$process.ExitCode
        if($Action -eq 'Diagnostics' -and $process.ExitCode -eq 0){
            $jsonLines=@($stdout -split "`r?`n"|Where-Object{$_ -match '^\{'})
            if($jsonLines.Count -ne 1){throw 'MIGRATION_011_DIAGNOSTIC_OUTPUT_INVALID'}
            $result.diagnostic=$jsonLines[0]|ConvertFrom-Json -ErrorAction Stop
            if($result.diagnostic.status -cne 'DIAGNOSTIC_COMPLETE'){throw 'MIGRATION_011_DIAGNOSTIC_OUTPUT_INVALID'}
            $result.status='DIAGNOSTIC_COMPLETE'
            $result.account_locked=$result.diagnostic.account_locked
            $result.ledger=$result.diagnostic.ledger_001_009
            if($result.account_locked -in @('Y','N')){$result.account_state='EXISTS'}
        }
        if($stdout -match '(?m)^IDENTITY=PASS\r?$'){$result.identity='PASS'}
        if($stdout -match '(?m)^ACCOUNT_STATE=(CREATED|EXISTS)\r?$'){$result.account_state=$Matches[1]}
        if($stdout -match '(?m)^ACCOUNT_LOCK=Y\r?$'){$result.account_locked='Y'}
        if($stdout -match '(?m)^GRANTS=PASS\r?$'){$result.grants='PASS'}
        if($stdout -match '(?m)^DPAPI_CREDENTIAL=READY\r?$'){$result.dpapi_credential='READY'}
        if($stdout -match '(?m)^READ_ONLY_PREFLIGHT=PASS\r?$'){$result.readonly_preflight='PASS'}
        if($stdout -match '(?m)^LEDGER=PASS\r?$'){$result.ledger='PASS'}
        if($stdout -match '(?m)^SCHEMA_PRECONDITIONS=PASS\r?$'){$result.schema_preconditions='PASS'}
        if($stdout -match '(?m)^INVENTORY=15_BASE_TABLES_AND_CALENDAR_VIEW\r?$'){$result.inventory='15_BASE_TABLES_AND_CALENDAR_VIEW'}
        if($stdout -match '(?m)^BACKUP_TOOLING=READY\r?$'){$result.backup_tooling='READY'}
        if($process.ExitCode -eq 0 -and $stdout -match '(?m)^(PROVISION|VERIFICATION)=PASS account=up_van_migration011_apply@127\.0\.0\.1 grants=PASS account_locked=Y credential=DPAPI_CURRENTUSER\b'){
            $result.status='PASS'
        }elseif($Action -ne 'Diagnostics' -or $result.status -ne 'DIAGNOSTIC_COMPLETE'){
            $result.status='FAIL'
            $codeMatch=[regex]::Match(($stdout+"`n"+$stderr),'(?<code>MIGRATION_011_[A-Z0-9_]+)')
            if($codeMatch.Success){$result.error_code=$codeMatch.Groups['code'].Value}
            $mysqlMatch=[regex]::Match(($stdout+"`n"+$stderr),'mysql_code=(?<code>[0-9]{3,5}) sqlstate=(?<state>[0-9A-Z]{5})')
            if($mysqlMatch.Success){$result.mysql_error_code=$mysqlMatch.Groups['code'].Value;$result.error_code='MYSQL_QUERY_FAILED'}
            if($result.error_code -eq 'MIGRATION_011_ACCOUNT_ALREADY_EXISTS_STOP_NO_RETRY'){$result.account_state='EXISTS'}
            if($result.error_code -eq 'MIGRATION_011_DBA_IDENTITY_REJECTED'){$result.identity='FAIL'}
            if($result.error_code -eq 'MIGRATION_011_ACCOUNT_ALREADY_EXISTS_STOP_NO_RETRY' -or $result.error_code -eq 'MIGRATION_011_PROVISION_APPROVAL_REQUIRED'){$result.status='BLOCKED'}
        }
    }catch{
        $result.status='FAIL';$result.error_code=([regex]::Match($_.Exception.Message,'MIGRATION_011_[A-Z0-9_]+').Value)
        if(!$result.error_code){$result.error_code='MIGRATION_011_LAUNCHER_FAILED'}
        if($null -eq $result.exit_code){$result.exit_code=1}
    }finally{
        if($process){$process.Dispose()};$stdout=$null;$stderr=$null
    }
}

try{Write-ProvisionResult}catch{
    $result.status='CRITICAL';$result.error_code='MIGRATION_011_RESULT_WRITE_FAILED'
    Write-Output 'PROVISION_RESULT=CRITICAL RESULT_FILE=UNAVAILABLE; inspect MySQL account read-only; do not retry'
    exit 3
}
Write-Output ('RESULT_FILE='+$resultPath)
Write-Output (ConvertTo-Json -InputObject $result -Compress -Depth 20)
if($result.status -notin @('PASS','DIAGNOSTIC_COMPLETE')){exit 1}
exit 0
