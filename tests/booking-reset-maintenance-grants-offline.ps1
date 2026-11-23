$ErrorActionPreference='Stop'
$root=Split-Path -Parent $PSScriptRoot
$runner=Join-Path $root 'scripts/booking-reset-maintenance-grants.ps1'
$source=Get-Content -LiteralPath $runner -Raw
$tokens=$null;$errors=$null
[System.Management.Automation.Language.Parser]::ParseFile($runner,[ref]$tokens,[ref]$errors)|Out-Null
if($errors.Count -ne 0){throw 'PowerShell parser rejected controlled grant runner'}
$diagnostics=Join-Path $root 'scripts/booking-reset-maintenance-grant-diagnostics.ps1'
$diagTokens=$null;$diagErrors=$null
[System.Management.Automation.Language.Parser]::ParseFile($diagnostics,[ref]$diagTokens,[ref]$diagErrors)|Out-Null
if($diagErrors.Count -ne 0){throw 'PowerShell parser rejected grant diagnostics'}
. $diagnostics
$client=Join-Path $root 'scripts/booking-reset-maintenance-mysql-client.ps1'
$clientTokens=$null;$clientErrors=$null
[System.Management.Automation.Language.Parser]::ParseFile($client,[ref]$clientTokens,[ref]$clientErrors)|Out-Null
if($clientErrors.Count -ne 0){throw 'PowerShell parser rejected mysql client helper'}
. $client
$guardSql="SELECT 'UPVAN_GRANT_GUARD_OK'; "
$bodySql="-- same comment boundary as proposal`r`nSELECT 'UPVAN_GRANT_BATCH_OK';"
$batch=Join-MaintenanceGrantBatch -Guard $guardSql -Body $bodySql
if($batch -cne ($guardSql.TrimEnd()+"`r`n"+$bodySql)){throw 'Guard and proposal SQL batch boundary changed'}
$expectedHeader="-- first comment`r`n-- second comment`r`n`r`n"
$variant=0
foreach($newline in @("`n","`r`n","`r")){
    $variant++
    $commentSource="-- first comment${newline}-- second comment${newline}${newline}CREATE USER must not be sent"
    try {$actualHeader=Get-MaintenanceProposalCommentHeader -Proposal $commentSource} catch {throw "Comment header parser failed for line ending variant $variant"}
    if($actualHeader -cne $expectedHeader){throw "Comment header parser returned wrong line ending variant $variant"}
    if((Get-MaintenanceProposalCommentHeader -Proposal (([string][char]0xFEFF)+$commentSource)) -cne $expectedHeader){throw 'Comment header parser failed for UTF-8 BOM'}
}
if((Get-MaintenanceProposalCommentHeader -Proposal "`n-- first comment`nCREATE USER must not be sent") -cne "-- first comment`r`n"){throw 'Leading blank line broke comment parsing'}
$missingHeaderRejected=$false
try {$null=Get-MaintenanceProposalCommentHeader -Proposal 'CREATE USER must not be sent'} catch {$missingHeaderRejected=$true}
if(!$missingHeaderRejected){throw 'A proposal without leading comments was accepted'}
$separatorRejected=$false
try {$null=Join-MaintenanceGrantBatch -Guard "SELECT 'UPVAN_GRANT_GUARD_OK'" -Body $bodySql} catch {$separatorRejected=$true}
if(!$separatorRejected){throw 'Missing SQL statement separator was accepted'}
$php=Join-Path $root '.runtime/php84/php.exe'
$probe=Join-Path $root 'tests/fixtures/booking-grant-stdin-probe.php'
$transport=Invoke-MaintenanceMysqlCaptured -ClientPath $php -ClientArgs @($probe) -Query $batch -ViaStdin
$expectedBatchHash=([BitConverter]::ToString(([Security.Cryptography.SHA256]::Create()).ComputeHash(([Text.UTF8Encoding]::new($false)).GetBytes($batch)))).Replace('-','').ToLowerInvariant()
if($transport.ExitCode -ne 0 -or $transport.InputWriteFailed -or $transport.Stdout.Trim() -cne $expectedBatchHash){throw 'UTF-8 stdin batch was not transferred intact'}
$fakeSecret='FAKE_PASSWORD_MUST_NOT_LEAK'
$before=Get-MaintenanceGrantApplyFailure -ExitCode 1 -Stderr "ERROR 1064 (42000) at line 1: $fakeSecret" -GuardPassed $false -Created $false
if($before -cne 'GRANT_APPLY_FAILED step=identity_guard_or_client exit=1 input_write_failed=false mysql_code=1064 sqlstate=42000 state=account_creation_unconfirmed; inspect account and grants read-only, no retry' -or $before.Contains($fakeSecret)){throw 'Pre-CREATE failure diagnostic is wrong or leaks client output'}
$pipeFailure=Get-MaintenanceGrantApplyFailure -ExitCode 1 -Stderr 'client closed input' -GuardPassed $false -Created $false -InputWriteFailed $true
if($pipeFailure -notmatch 'input_write_failed=true mysql_code=UNKNOWN sqlstate=UNKNOWN'){throw 'Broken stdin pipe was not reported safely'}
$create=Get-MaintenanceGrantApplyFailure -ExitCode 1 -Stderr 'ERROR 1044 (42000) at line 1' -GuardPassed $true -Created $false
if($create -notmatch 'step=create_user_or_missing_result exit=1 input_write_failed=false mysql_code=1044 sqlstate=42000'){throw 'CREATE USER failure was misclassified'}
$after=Get-MaintenanceGrantApplyFailure -ExitCode 1 -Stderr "ERROR 1142 (42000) at line 6: $fakeSecret" -GuardPassed $true -Created $true
if($after -notmatch 'step=grant_after_create_user exit=1 input_write_failed=false mysql_code=1142 sqlstate=42000 state=partial_account_possible' -or $after.Contains($fakeSecret)){throw 'Post-CREATE failure diagnostic is wrong or leaks client output'}
$unknown=Get-MaintenanceGrantMysqlError -Stderr "client rejected: $fakeSecret"
if($unknown -cne 'mysql_code=UNKNOWN sqlstate=UNKNOWN' -or $unknown.Contains($fakeSecret)){throw 'Unknown client failure leaks output'}
$noOutput=Get-MaintenanceGrantApplyOutput -Stdout '' -ExitCode 1 -Stderr 'ERROR 1064 (42000)'
if($noOutput.GuardPassed -or $noOutput.Created -or $noOutput.LineCount -ne 0){throw 'Failure before guard was misclassified'}
$guardOnly=Get-MaintenanceGrantApplyOutput -Stdout "UPVAN_GRANT_GUARD_OK`n" -ExitCode 1 -Stderr 'ERROR 1044 (42000)'
if(!$guardOnly.GuardPassed -or $guardOnly.Created -or $guardOnly.LineCount -ne 1){throw 'Failure after guard but before password output was misclassified'}
$partialOutput="UPVAN_GRANT_GUARD_OK`nup_van_reset_maintenance`t127.0.0.1`t$fakeSecret`t1`n"
$partial=Get-MaintenanceGrantApplyOutput -Stdout $partialOutput -ExitCode 1 -Stderr 'ERROR 1142 (42000)'
if(!$partial.GuardPassed -or !$partial.Created -or $partial.Password -cne $fakeSecret -or $partial.LineCount -ne 2){throw 'Partial CREATE USER result was not preserved for DPAPI storage'}
$invalidOutputRejected=$false
try {$null=Get-MaintenanceGrantApplyOutput -Stdout "UPVAN_GRANT_GUARD_OK`ninvalid $fakeSecret" -ExitCode 1 -Stderr 'ERROR 1142 (42000)'} catch {if($_.Exception.Message.Contains($fakeSecret)){throw 'Malformed output diagnostic leaked a secret'};$invalidOutputRejected=$true}
if(!$invalidOutputRejected){throw 'Malformed password output was accepted'}
$proposal=Join-Path $root 'scripts/booking-reset-maintenance-grants.proposed.sql'
$hash=(Get-FileHash -LiteralPath $proposal -Algorithm SHA256).Hash.ToLowerInvariant()
$proposalSql=[Text.Encoding]::UTF8.GetString([IO.File]::ReadAllBytes($proposal))
$proposalHeader=Get-MaintenanceProposalCommentHeader -Proposal $proposalSql
$readOnlyBatch=Join-MaintenanceGrantBatch -Guard $guardSql -Body ($proposalHeader+"SELECT 'UPVAN_GRANT_BATCH_OK';")
if($proposalHeader -match '(?m)^\s*(?:CREATE USER|GRANT)\b' -or $readOnlyBatch -match '(?m)^\s*(?:CREATE USER|GRANT)\b' -or !$readOnlyBatch.Contains("`r`n$proposalHeader")){throw 'Read-only batch fixture contains a mutation or does not match proposal comment boundary'}
if(!$source.Contains("`$expectedHash='$hash'")){throw 'Controlled grant runner SHA-256 pin mismatch'}
if($source.Contains('StartsWith([char]0xFEFF)') -or !$source.Contains('[int][char]$sql[0] -eq 0xFEFF')){throw 'BOM check can strip the first hyphen of an unmarked SQL comment in Windows PowerShell 5.1'}
foreach($marker in @('--host=127.0.0.1','--port=3306','@@server_uuid','DATABASE()','CURRENT_USER()','IDENTITY_QUERY_FAILED','APPROVED_SHA256_REQUIRED','ConvertFrom-SecureString','MAINTENANCE_GRANTS_MISMATCH')){
    if(!$source.Contains($marker)){throw "Missing controlled runner guard: $marker"}
}
if(!$source.Contains("[ValidateSet('Preflight','GuardCheck','BatchCheck','Apply','Postflight')]") -or !$source.Contains("SELECT 'UPVAN_GRANT_GUARD_OK'") -or !$source.Contains("if(`$Phase -eq 'GuardCheck')")){throw 'Read-only same-session guard check is missing'}
if($source.IndexOf("if(`$Phase -eq 'GuardCheck')") -gt $source.IndexOf('Join-MaintenanceGrantBatch -Guard $guard -Body $sql')){throw 'GuardCheck can reach CREATE USER SQL'}
if($source.IndexOf("if(`$Phase -eq 'GuardCheck')") -gt $source.IndexOf("if(`$ApprovedSha256.ToLowerInvariant()")){throw 'GuardCheck unexpectedly requires Apply approval'}
if(!$source.Contains("if(`$Phase -eq 'BatchCheck')") -or !$source.Contains('Get-MaintenanceProposalCommentHeader -Proposal $sql') -or !$source.Contains('Join-MaintenanceGrantBatch -Guard $guard -Body $sql') -or !$source.Contains(' -ViaStdin')){throw 'Apply and read-only batch check must use stdin transport'}
if($source.IndexOf("if(`$Phase -eq 'BatchCheck')") -gt $source.IndexOf("if(`$ApprovedSha256.ToLowerInvariant()")){throw 'BatchCheck unexpectedly requires Apply approval'}
if(!$source.Contains('Get-MaintenanceGrantApplyOutput') -or !$source.Contains('Get-MaintenanceGrantApplyFailure') -or !$source.Contains('CREDENTIAL_STORAGE_FAILED')){throw 'Apply result must separate guard marker from generated password and classify failures'}
if($source -match 'Write-Host\s+.*(?:password|secret)'){throw 'Potential secret output'}
if(!$source.Contains('[IO.File]::ReadAllBytes($proposal)') -or !$source.Contains('[Text.Encoding]::UTF8.GetString($proposalBytes)') -or $source.Contains('Get-Content -LiteralPath $proposal')){throw 'Apply must execute the exact bytes that passed SHA-256'}
if(!$source.Contains('[IO.FileMode]::CreateNew') -or $source.Contains('Set-Content -LiteralPath $credentialPath')){throw 'DPAPI credential must not overwrite an existing file'}
if(!$source.Contains('@@generated_random_password_length>=12') -or !(Get-Content -LiteralPath $diagnostics -Raw).Contains("`$parts[3] -cne '1'")){throw 'Generated password length and authentication factor must be verified'}
$preflightEnd=$source.IndexOf("if(`$Phase -eq 'Preflight'){exit 0}")
if($preflightEnd -lt 0){throw 'Preflight exit is missing'}
$preflightSource=$source.Substring(0,$preflightEnd)
if($preflightSource.Contains('Join-MaintenanceGrantBatch -Guard $guard -Body $sql') -or $preflightSource.Contains('ConvertFrom-SecureString') -or $preflightSource.Contains('[IO.FileMode]::CreateNew')){throw 'Preflight enters Apply or credential storage'}
$sql=Get-Content -LiteralPath $proposal -Raw
$grants=@($sql -split ';' | ForEach-Object {($_ -replace '(?m)^--.*$','').Trim()} | Where-Object {$_ -like 'GRANT *'})
$php=Join-Path $root '.runtime/php84/php.exe'
$evaluate=Join-Path $root 'scripts/booking-reset-maintenance-grants-evaluate.php'
$json=ConvertTo-Json -InputObject $grants -Compress -Depth 5
$result=$json | & $php -n $evaluate
if($LASTEXITCODE -ne 0 -or $result -cne 'MAINTENANCE_GRANTS=PASS'){throw 'Postflight evaluator rejected the exact proposal'}
$extra=@($grants)+@("GRANT DELETE ON ``up_van_booking_test``.``booking_requests`` TO 'up_van_reset_maintenance'@'127.0.0.1'")
$json=ConvertTo-Json -InputObject $extra -Compress -Depth 5
$null=$json | & $php -n $evaluate
if($LASTEXITCODE -eq 0){throw 'Postflight evaluator accepted an excess DELETE privilege'}
Write-Output "PASS: maintenance grant runner syntax, hash and safety markers; SHA-256 $hash; SQL not executed."
