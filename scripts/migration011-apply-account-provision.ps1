#Requires -Version 5.1
#Requires -PSEdition Desktop
[CmdletBinding()]
param(
    [ValidateSet('Preflight','Provision','VerifyExisting','ReadOnlyPreflight','Diagnostics')][string]$Action='Preflight',
    [string]$ApprovedSha256=''
)
$ErrorActionPreference='Stop'
Set-StrictMode -Version Latest
. (Join-Path $PSScriptRoot 'lib/migration011-apply-credentials.ps1')

$expectedHash='2ebee4bea4560acf210bed537d32b4778843eed38ad67db0be2436d7f9682336'
$account='up_van_migration011_apply';$hostName='127.0.0.1';$database='up_van_booking_test';$uuid='77218fbd-d66d-11f1-9a0a-5c2886f057d5'
$mysqlBins=@((Join-Path $env:ProgramFiles 'MySQL\MySQL Server 8.4\bin'),(Join-Path $env:ProgramFiles 'MySQL\MySQL Server 8.0\bin'))
$mysql=$mysqlBins|ForEach-Object{Join-Path $_ 'mysql.exe'}|Where-Object{Test-Path -LiteralPath $_ -PathType Leaf}|Select-Object -First 1
$editor=$mysqlBins|ForEach-Object{Join-Path $_ 'mysql_config_editor.exe'}|Where-Object{Test-Path -LiteralPath $_ -PathType Leaf}|Select-Object -First 1
$proposal=Join-Path (Split-Path -Parent $PSScriptRoot) 'database/proposals/011_migration_apply_grants.proposed.sql'
$phpEvaluator=Join-Path $PSScriptRoot 'migration011-provision-grants-check.php'

$sid=[Security.Principal.WindowsIdentity]::GetCurrent().User
$groups=& whoami.exe /groups 2>$null | Out-String
if($groups -notmatch 'S-1-16-8192'){throw 'MIGRATION_011_NORMAL_WINDOWS_MEDIUM_SESSION_REQUIRED'}
# Resolve and execute evaluator dependency checks before any MySQL connection or mutation.
$php=Resolve-Migration011EvaluatorRuntime -ProjectRoot (Split-Path -Parent $PSScriptRoot)
Invoke-Migration011GrantEvaluator -Runtime $php -Evaluator $phpEvaluator -RuntimeCheck
if(!$mysql -or !$editor){throw 'MIGRATION_011_MYSQL_CLIENT_MISSING'}
$clientVersion=& $mysql --no-defaults --version 2>$null
if($LASTEXITCODE -ne 0 -or $clientVersion -notmatch '\b8\.4\.9\b'){throw 'MIGRATION_011_MYSQL_CLIENT_VERSION_REJECTED'}
$proposalBytes=[IO.File]::ReadAllBytes($proposal);$hasher=[Security.Cryptography.SHA256]::Create()
try{$actualHash=([BitConverter]::ToString($hasher.ComputeHash($proposalBytes))).Replace('-','').ToLowerInvariant()}finally{$hasher.Dispose()}
if($actualHash -cne $expectedHash){throw 'MIGRATION_011_PROPOSAL_HASH_REJECTED'}
$editorOutput=& $editor print --all 2>$null | Out-String;$editorExit=$LASTEXITCODE
$loginPathStatus=Get-Migration011LoginPathStatus -ExitCode $editorExit -Output $editorOutput
$editorOutput=$null
if($loginPathStatus -eq 'MISSING'){throw 'MIGRATION_011_DBA_LOGIN_PATH_MISSING'}
if($loginPathStatus -ne 'PRESENT'){throw 'MIGRATION_011_DBA_LOGIN_PATH_INVALID'}
$clientArgs=@('--no-defaults','--login-path=upvan3306-backup','--protocol=TCP','--host=127.0.0.1','--port=3306','--database=up_van_booking_test','--connect-timeout=5','--skip-reconnect','--batch','--raw','--skip-column-names')

function Invoke-011Sql([string]$Sql){
    $result=Invoke-Migration011MysqlCaptured -ClientPath $mysql -ClientArgs $clientArgs -Sql $Sql
    if($result.ExitCode -ne 0 -or $result.InputWriteFailed){$code=Get-Migration011MysqlErrorCode $result.Stderr;$result.Stdout=$null;$result.Stderr=$null;throw "MIGRATION_011_DBA_QUERY_FAILED $code"}
    return $result.Stdout
}
function Assert-011Identity {
    $rows=Invoke-011Sql 'SELECT CONCAT(@@port,CHAR(9),@@server_uuid,CHAR(9),DATABASE(),CHAR(9),VERSION(),CHAR(9),CURRENT_USER())'
    if(!(Test-Migration011DbaIdentityOutput -Output $rows -ExpectedUuid $uuid)){throw 'MIGRATION_011_DBA_IDENTITY_REJECTED'}
}
function Assert-011AccountMissing {
    $row=Invoke-011Sql "SELECT COUNT(*) FROM mysql.user WHERE User='$account' AND Host='$hostName'"
    if(([string]$row).Trim() -cne '0'){throw 'MIGRATION_011_ACCOUNT_ALREADY_EXISTS_STOP_NO_RETRY'}
}

Assert-011Identity
Write-Output 'IDENTITY=PASS'
if($Action -eq 'Diagnostics'){
    # Explicitly bypass provision/recovery paths. SELECT metadata only, no user data or SQL text.
    $queryJson=@(& $php -n $phpEvaluator --diagnostic-queries 2>$null)
    if($LASTEXITCODE -ne 0){throw 'MIGRATION_011_DIAGNOSTIC_QUERY_FAILED'}
    $queries=($queryJson -join "`n")|ConvertFrom-Json -ErrorAction Stop
    $packet=[ordered]@{}
    foreach($property in $queries.PSObject.Properties){
        $rows=ConvertFrom-Json -InputObject (Invoke-011Sql ([string]$property.Value)) -ErrorAction Stop
        $packet[$property.Name]=@();if($null -ne $rows){$packet[$property.Name]=@($rows)}
    }
    $packet['show_create_table']=$null
    if(@($packet['tables']|Where-Object{$_.TABLE_NAME -ceq 'microsoft_identities' -and $_.TABLE_TYPE -ceq 'BASE TABLE'}).Count -eq 1){
        $definition=Invoke-011Sql 'SHOW CREATE TABLE up_van_booking_test.microsoft_identities'
        $parts=@($definition -split "`t",2)
        if($parts.Count -ne 2 -or $parts[0] -cne 'microsoft_identities'){throw 'MIGRATION_011_SHOW_CREATE_OUTPUT_INVALID'}
        $packet['show_create_table']=$parts[1].TrimEnd([char[]]"`r`n")
    }
    Assert-011Identity
    $payload=ConvertTo-Json -InputObject $packet -Depth 20 -Compress
    $assessment=@($payload| & $php -n $phpEvaluator --diagnose-011 2>$null)
    if($LASTEXITCODE -ne 0){throw 'MIGRATION_011_READONLY_DIAGNOSTIC_FAILED'}
    $diagnostic=($assessment -join "`n")|ConvertFrom-Json -ErrorAction Stop
    if($diagnostic.status -cne 'DIAGNOSTIC_COMPLETE'){throw 'MIGRATION_011_READONLY_DIAGNOSTIC_FAILED'}
    Write-Output ($diagnostic|ConvertTo-Json -Compress -Depth 20)
    exit 0
}
if($Action -in @('VerifyExisting','ReadOnlyPreflight')){
    $count=Invoke-011Sql "SELECT COUNT(*) FROM mysql.user WHERE User='$account' AND Host='$hostName'"
    if(([string]$count).Trim() -cne '1'){throw 'MIGRATION_011_ACCOUNT_MISSING_VERIFY_ONLY'}
    Write-Output 'ACCOUNT_STATE=EXISTS'
}else{
Assert-011AccountMissing
if(Test-Path -LiteralPath (Get-Migration011CredentialPath) -PathType Leaf){throw 'MIGRATION_011_CREDENTIAL_STORE_ALREADY_EXISTS'}
if($Action -eq 'Preflight'){
    Write-Output "PREFLIGHT=PASS account=$account`@$hostName status=MISSING proposal_sha256=$actualHash credential_store=ABSENT"
    exit 0
}
if($ApprovedSha256 -cne $expectedHash -or $env:UPVAN_MIGRATION011_PROVISION_APPROVED -cne 'true'){
    throw 'MIGRATION_011_PROVISION_APPROVAL_REQUIRED'
}

$sql=[Text.Encoding]::UTF8.GetString($proposalBytes)
if($sql.Length -gt 0 -and [int][char]$sql[0] -eq 0xFEFF){$sql=$sql.Substring(1)}
$guard="SET @migration011_guard=IF(@@port=3306 AND @@server_uuid='$uuid' AND DATABASE()='$database' AND VERSION()='8.4.9' AND CURRENT_USER()='root@localhost' AND NOT EXISTS(SELECT 1 FROM mysql.user WHERE User='$account' AND Host='$hostName'),'DO 0','SELECT * FROM __migration011_target_rejected'); PREPARE migration011_target_guard FROM @migration011_guard; EXECUTE migration011_target_guard; DEALLOCATE PREPARE migration011_target_guard; SELECT 'UPVAN_MIGRATION011_GUARD_OK';"
$batch=$guard+"`r`n"+$sql+"`r`nSELECT 'UPVAN_MIGRATION011_DONE';`r`n"
$result=Invoke-Migration011MysqlCaptured -ClientPath $mysql -ClientArgs $clientArgs -Sql $batch
$parsed=$null
try{$parsed=Get-Migration011ProvisionOutput -Stdout $result.Stdout}catch{$result.Stdout=$null;$result.Stderr=$null;throw 'MIGRATION_011_PROVISION_OUTPUT_UNVERIFIED; inspect account read-only, no retry'}
$credentialStored=$false
if($parsed.Password){
    try{[void](Save-Migration011DpapiCredential -Password $parsed.Password);$credentialStored=$true}
    catch{$result.Stdout=$null;$result.Stderr=$null;throw 'CRITICAL_MIGRATION_011_CREDENTIAL_STORE_FAILED; account may exist and remains locked; inspect account read-only, no retry'}
    finally{$parsed.Password=$null}
}
if($result.ExitCode -ne 0 -or $result.InputWriteFailed -or !$parsed.Completed){
    $code=Get-Migration011MysqlErrorCode $result.Stderr;$result.Stdout=$null;$result.Stderr=$null
    if($credentialStored){throw "MIGRATION_011_PROVISION_PARTIAL_GRANTS_FAILED $code; credential stored via DPAPI; account remains locked; inspect, no retry"}
    throw "MIGRATION_011_PROVISION_FAILED $code; inspect account read-only, no retry"
}
$result.Stdout=$null;$result.Stderr=$null
Write-Output 'ACCOUNT_STATE=CREATED'
}
Assert-011Identity
$post=Invoke-011Sql "SELECT CONCAT(account_locked) FROM mysql.user WHERE User='$account' AND Host='$hostName'; SHOW GRANTS FOR '$account'@'$hostName';"
$lines=@(([string]$post).Trim() -split "`r?`n" | Where-Object {$_ -ne ''})
if($lines.Count -lt 2 -or $lines[0] -cne 'Y'){throw 'MIGRATION_011_POSTFLIGHT_ACCOUNT_LOCK_OR_GRANTS_UNVERIFIED'}
Write-Output 'ACCOUNT_LOCK=Y'
if($lines | Where-Object {$_ -match '^GRANT\s+[`''].*\s+TO\s+'}){throw 'MIGRATION_011_ROLE_ASSIGNMENT_REJECTED'}
$json=ConvertTo-Json -InputObject @($lines|Select-Object -Skip 1) -Compress -Depth 5
Invoke-Migration011GrantEvaluator -Runtime $php -Evaluator $phpEvaluator -InputJson $json
Write-Output 'GRANTS=PASS'
$credentialPath=Get-Migration011CredentialPath
Assert-Migration011CredentialFileAcl $credentialPath
$readBack=Read-Migration011DpapiCredential -Path $credentialPath
try{if($readBack.Length -lt 12){throw 'MIGRATION_011_DPAPI_DECRYPT_FAILED'}}finally{if($readBack){$readBack.Dispose()}}
Write-Output 'DPAPI_CREDENTIAL=READY'
if($Action -eq 'ReadOnlyPreflight'){
    $mandatoryRoles=Invoke-011Sql "SELECT IF(@@GLOBAL.mandatory_roles='',1,0)"
    if(([string]$mandatoryRoles).Trim() -cne '1'){throw 'MIGRATION_011_MANDATORY_ROLES_REJECTED'}
    $dump=Join-Path (Split-Path -Parent $mysql) 'mysqldump.exe'
    foreach($file in @($dump,(Join-Path $PSScriptRoot 'migration010-schema-export.ps1'),(Join-Path $PSScriptRoot 'migration010-backup-check.php'))){
        if(![IO.File]::Exists($file)){throw 'MIGRATION_011_BACKUP_TOOLING_MISSING'}
    }
    $dumpVersion=& $dump --no-defaults --version 2>$null
    if($LASTEXITCODE -ne 0 -or $dumpVersion -notmatch '\b8\.4\.9\b'){throw 'MIGRATION_011_BACKUP_TOOLING_VERSION_REJECTED'}
    Write-Output 'BACKUP_TOOLING=READY'
    $queryJson=@(& $php -n $phpEvaluator --preflight-queries 2>$null)
    if($LASTEXITCODE -ne 0){throw 'MIGRATION_011_PREFLIGHT_QUERY_GENERATION_FAILED'}
    $queries=($queryJson -join "`n")|ConvertFrom-Json -ErrorAction Stop
    $packet=[ordered]@{}
    foreach($property in $queries.PSObject.Properties){
        $packet[$property.Name]=ConvertFrom-Json -InputObject (Invoke-011Sql ([string]$property.Value)) -ErrorAction Stop
        if($null -eq $packet[$property.Name]){$packet[$property.Name]=@()}
        else{$packet[$property.Name]=@($packet[$property.Name])}
    }
    Assert-011Identity
    $payload=ConvertTo-Json -InputObject $packet -Depth 20 -Compress
    $assessment=@($payload | & $php -n $phpEvaluator --read-only-preflight 2>$null)
    if($LASTEXITCODE -ne 0){throw 'MIGRATION_011_READONLY_PREFLIGHT_REJECTED'}
    if($assessment -cnotcontains 'READ_ONLY_PREFLIGHT=PASS'){throw 'MIGRATION_011_READONLY_PREFLIGHT_REJECTED'}
    foreach($marker in @('READ_ONLY_PREFLIGHT=PASS','LEDGER=PASS','SCHEMA_PRECONDITIONS=PASS','INVENTORY=15_BASE_TABLES_AND_CALENDAR_VIEW')){
        if($assessment -cnotcontains $marker){throw 'MIGRATION_011_READONLY_PREFLIGHT_OUTPUT_INVALID'}
        Write-Output $marker
    }
}
$outcome=if($Action -in @('VerifyExisting','ReadOnlyPreflight')){'VERIFICATION'}else{'PROVISION'}
Write-Output "$outcome=PASS account=$account`@$hostName grants=PASS account_locked=Y credential=DPAPI_CURRENTUSER proposal_sha256=$actualHash"
