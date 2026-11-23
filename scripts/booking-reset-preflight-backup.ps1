param([string]$LoginPath='upvan3306-backup')

# Read-only reset inventory plus a separately captured test-database backup.
# This script contains no UPDATE, DELETE, DDL, GRANT, or gate changes.
$ErrorActionPreference='Stop'
Set-StrictMode -Version Latest
$database='up_van_booking_test'; $hostName='127.0.0.1'; $port=3306
$uuid='77218fbd-d66d-11f1-9a0a-5c2886f057d5'
$mysqlBin='C:\Program Files\MySQL\MySQL Server 8.4\bin'
$mysql=Join-Path $mysqlBin 'mysql.exe'; $dump=Join-Path $mysqlBin 'mysqldump.exe'
if($LoginPath -cne 'upvan3306-backup'){throw 'Only the approved backup Login Path is accepted.'}
foreach($tool in @($mysql,$dump)){if(!(Test-Path -LiteralPath $tool -PathType Leaf)){throw 'Pinned MySQL 8.4 client executable is missing.'}}
if(!(Test-Path -LiteralPath (Join-Path $env:APPDATA 'MySQL\.mylogin.cnf') -PathType Leaf)){throw 'MySQL Login Path file is missing.'}

function Convert-QueryOutput([AllowNull()][string]$Output){
    if([string]::IsNullOrEmpty($Output)){return [string[]]@()}
    $trimmed=$Output.TrimEnd([char[]]"`r`n")
    if([string]::IsNullOrEmpty($trimmed)){return [string[]]@()}
    return [string[]]($trimmed -split '\r?\n')
}
function Invoke-ReadSqlResult([string]$Sql){
    $args=@("--login-path=$LoginPath",'--protocol=TCP',"--host=$hostName","--port=$port", "--database=$database",'--default-character-set=utf8mb4','--batch','--raw','--binary-mode','--skip-column-names','--skip-reconnect')
    $psi=[Diagnostics.ProcessStartInfo]::new();$psi.FileName=$mysql;$psi.Arguments=$args -join ' ';$psi.WorkingDirectory=$mysqlBin;$psi.UseShellExecute=$false;$psi.CreateNoWindow=$true;$psi.RedirectStandardInput=$true;$psi.RedirectStandardOutput=$true;$psi.RedirectStandardError=$true;$psi.StandardOutputEncoding=[Text.Encoding]::UTF8;$psi.StandardErrorEncoding=[Text.Encoding]::UTF8
    $p=[Diagnostics.Process]::new();$p.StartInfo=$psi
    try{[void]$p.Start();$out=$p.StandardOutput.ReadToEndAsync();$err=$p.StandardError.ReadToEndAsync();$bytes=[Text.Encoding]::UTF8.GetBytes($Sql.TrimEnd()+";`n");$p.StandardInput.BaseStream.Write($bytes,0,$bytes.Length);$p.StandardInput.Close();$p.WaitForExit();$stdout=$out.GetAwaiter().GetResult();$stderr=$err.GetAwaiter().GetResult();return [pscustomobject]@{Rows=[string[]](Convert-QueryOutput $stdout);ExitCode=$p.ExitCode;Stderr=$stderr}}finally{$p.Dispose()}
}
function Invoke-ReadSql([string]$Sql){
    $result=Invoke-ReadSqlResult $Sql
    if($result.ExitCode -ne 0){throw "MySQL read failed (exit=$($result.ExitCode)). $($result.Stderr)"}
    return [string[]]$result.Rows
}
function Format-SafeDiagnostic([string]$Value){
    if($null -eq $Value){return ''}
    return ($Value -replace '[\r\n\t]+',' ' -replace '\s{2,}',' ').Trim()
}
function Get-CheckTableAssessment([string[]]$Rows,[int]$ExitCode){
    $parsed=@();foreach($row in $Rows){$p=@($row -split "`t",4);if($p.Count -eq 4){$parsed += [pscustomobject]@{Table=$p[0];Operation=$p[1];MsgType=$p[2];MsgText=$p[3]}}else{$parsed += [pscustomobject]@{Table='';Operation='';MsgType='UNPARSED';MsgText=$row}}}
    $qualifiedViewName="$($database).calendar_events"
    $healthy=($ExitCode -eq 0 -and $parsed.Count -gt 0 -and @($parsed|Where-Object{($_.Table -cne 'calendar_events' -and $_.Table -cne $qualifiedViewName) -or $_.Operation -cne 'check' -or $_.MsgType -cne 'status' -or $_.MsgText -cne 'OK'}).Count -eq 0)
    return [pscustomobject]@{Healthy=$healthy;Rows=@($parsed)}
}
function Assert-One([string]$Label,[string[]]$Actual,[string[]]$Expected){if(($Actual -join "`n") -cne ($Expected -join "`n")){throw "$Label mismatch; stopped."}}
function Test-TargetIdentity([string[]]$Fields,[string]$ExpectedUuid,[string]$ExpectedDatabase,[string]$ExpectedPort='3306'){
    $mismatch=@()
    if($Fields.Count -ne 6){return [pscustomobject]@{Pass=$false;Mismatches=@('column_count')}}
    if($Fields[1] -cne $ExpectedPort){$mismatch+='port'}
    if($Fields[2] -cne $ExpectedUuid){$mismatch+='server_uuid'}
    if($Fields[3] -cne $ExpectedDatabase){$mismatch+='database'}
    if($Fields[4] -notmatch '^8\.4\.'){$mismatch+='server_version'}
    if([string]::IsNullOrWhiteSpace($Fields[5])){$mismatch+='backup_login_account'}
    return [pscustomobject]@{Pass=($mismatch.Count -eq 0);Mismatches=@($mismatch)}
}

$identity=@(Invoke-ReadSql "SELECT @@hostname,@@port,@@server_uuid,DATABASE(),VERSION(),CURRENT_USER()")
if($identity.Count -ne 1){throw "IDENTITY_QUERY_ROW_COUNT_MISMATCH rows=$($identity.Count); stopped."}
$i=@($identity[0] -split "`t" | ForEach-Object{$_.Trim()})
$identityResult=Test-TargetIdentity -Fields $i -ExpectedUuid $uuid -ExpectedDatabase $database
if(!$identityResult.Pass){Write-Output "IDENTITY=FAIL mismatches=$($identityResult.Mismatches -join ',') host=$($i[0]) port=$($i[1]) uuid=$($i[2]) database=$($i[3]) version=$($i[4]) backup_account=$($i[5])";throw 'MySQL target identity mismatch; stopped.'}
Write-Output "IDENTITY=PASS host=$($i[0]) port=$($i[1]) uuid=$($i[2]) database=$($i[3]) version=$($i[4]) backup_account=$($i[5])"

$tables=@(Invoke-ReadSql "SELECT CONCAT(TABLE_NAME,CHAR(9),TABLE_TYPE) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME")
$base=@($tables|Where-Object{($_ -split "`t")[1] -eq 'BASE TABLE'}|ForEach-Object{($_ -split "`t")[0]})
$required=@('approval_decisions','audit_logs','auth_login_buckets','booking_notifications','booking_passengers','booking_requests','booking_reviews','booking_status_history','drivers','roles','schema_migrations','user_roles','users','vehicle_assignments','vehicles')
Assert-One 'Base table inventory' (@($base|Sort-Object)) (@($required|Sort-Object))
$view=@($tables|Where-Object{$_ -ceq "calendar_events`tVIEW"});if($view.Count -ne 1){throw 'calendar_events view missing or unexpected.'}
$viewCheckResult=Invoke-ReadSqlResult 'CHECK TABLE calendar_events'
$viewCheck=Get-CheckTableAssessment -Rows $viewCheckResult.Rows -ExitCode $viewCheckResult.ExitCode
if($viewCheck.Healthy){Write-Output 'VIEW_CHECK=PASS'}else{Write-Output "VIEW_CHECK=WARNING exit=$($viewCheckResult.ExitCode) stderr=$(Format-SafeDiagnostic $viewCheckResult.Stderr)";foreach($row in $viewCheck.Rows){Write-Output "VIEW_CHECK_ROW table=$($row.Table) operation=$($row.Operation) msg_type=$($row.MsgType) msg_text=$(Format-SafeDiagnostic $row.MsgText)"}}

$columns=@(Invoke-ReadSql "SELECT CONCAT(TABLE_NAME,CHAR(9),COLUMN_NAME) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,ORDINAL_POSITION")
$columnMap=@{};foreach($row in $columns){$p=$row -split "`t";$columnMap[$p[0]]=(@($columnMap[$p[0]])+@($p[1]))}
foreach($requiredColumn in @('booking_requests:id','booking_requests:workflow_status','booking_requests:deleted_at','vehicle_assignments:id','vehicle_assignments:booking_request_id','vehicle_assignments:vehicle_id','vehicle_assignments:driver_id','vehicle_assignments:status','vehicle_assignments:starts_at','vehicle_assignments:ends_at')){$p=$requiredColumn.Split(':');if($p[1] -notin $columnMap[$p[0]]){throw "Required schema column missing: $requiredColumn"}}

$statusRows=@(Invoke-ReadSql "SELECT CONCAT(COALESCE(workflow_status,'<NULL>'),CHAR(9),COUNT(*)) FROM booking_requests GROUP BY workflow_status ORDER BY workflow_status")
$totals=@(Invoke-ReadSql "SELECT CONCAT('TOTAL',CHAR(9),COUNT(*)),CONCAT('ACTIVE',CHAR(9),SUM(deleted_at IS NULL)),CONCAT('SOFT_DELETED',CHAR(9),SUM(deleted_at IS NOT NULL)) FROM booking_requests")
$assignments=@(Invoke-ReadSql "SELECT CONCAT(status,CHAR(9),COUNT(*)) FROM vehicle_assignments WHERE status IN ('active','completed') GROUP BY status ORDER BY status")
$calendarRows=@(Invoke-ReadSql 'SELECT CONCAT(assignment_status,CHAR(9),workflow_status,CHAR(9),COUNT(*)) FROM calendar_events GROUP BY assignment_status,workflow_status ORDER BY assignment_status,workflow_status')
$fks=@(Invoke-ReadSql "SELECT CONCAT(k.TABLE_NAME,CHAR(9),k.COLUMN_NAME,CHAR(9),k.CONSTRAINT_NAME,CHAR(9),k.REFERENCED_TABLE_NAME,CHAR(9),k.REFERENCED_COLUMN_NAME,CHAR(9),r.DELETE_RULE) FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME WHERE k.CONSTRAINT_SCHEMA=DATABASE() AND k.REFERENCED_TABLE_NAME IS NOT NULL ORDER BY k.TABLE_NAME,k.CONSTRAINT_NAME,k.ORDINAL_POSITION")
$grants=@(Invoke-ReadSql 'SHOW GRANTS FOR CURRENT_USER')
Write-Output 'REQUEST_COUNTS_BY_STATUS:'; $statusRows|ForEach-Object{Write-Output $_};Write-Output 'REQUEST_TOTALS:';$totals|ForEach-Object{Write-Output $_};Write-Output 'ACTIVE_COMPLETED_ASSIGNMENTS:';$assignments|ForEach-Object{Write-Output $_};Write-Output 'CALENDAR_EVENT_COUNTS:';$calendarRows|ForEach-Object{Write-Output $_};Write-Output 'FOREIGN_KEYS:';$fks|ForEach-Object{Write-Output $_};Write-Output 'RUNTIME_GRANTS:';$grants|ForEach-Object{Write-Output $_}

$viewMeta=@(Invoke-ReadSql "SELECT HEX(VIEW_DEFINITION),HEX(SUBSTRING_INDEX(DEFINER,'@',1)),HEX(SUBSTRING_INDEX(DEFINER,'@',-1)),SECURITY_TYPE,CHECK_OPTION,CHARACTER_SET_CLIENT,COLLATION_CONNECTION FROM information_schema.VIEWS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='calendar_events'")
if($viewMeta.Count -ne 1){throw 'Could not read exactly one calendar_events view definition.'};$v=@($viewMeta[0] -split "`t");if($v.Count -ne 7){throw 'View metadata invalid.'}
function HexUtf8([string]$h){if($h -notmatch '\A(?:[0-9a-fA-F]{2})+\z'){throw 'Malformed view metadata hex.'};$b=[byte[]]::new($h.Length/2);for($n=0;$n -lt $b.Length;$n++){$b[$n]=[Convert]::ToByte($h.Substring($n*2,2),16)};[Text.Encoding]::UTF8.GetString($b)}
$definition=HexUtf8 $v[0];$definerUser=HexUtf8 $v[1];$definerHost=HexUtf8 $v[2];if($definition -notmatch '^\s*(SELECT|WITH)\b' -or $v[5] -notmatch '\A\w+\z' -or $v[6] -notmatch '\A\w+\z'){throw 'View definition validation failed.'}
$showCreate=@(Invoke-ReadSql 'SHOW CREATE VIEW calendar_events');if($showCreate.Count -ne 1){throw 'SHOW CREATE VIEW did not return exactly one row.'};$showCreateFields=@($showCreate[0] -split "`t");if($showCreateFields.Count -lt 2 -or $showCreateFields[1] -notmatch 'CREATE.*VIEW `calendar_events` AS'){throw 'SHOW CREATE VIEW did not return a valid definition.'};$shownDefinition=Format-SafeDiagnostic $showCreateFields[1];Write-Output "SHOW_CREATE_VIEW=PASS definition=$shownDefinition"
$viewDependencies=@(Invoke-ReadSql "SELECT CONCAT(TABLE_SCHEMA,CHAR(9),TABLE_NAME) FROM information_schema.VIEW_TABLE_USAGE WHERE VIEW_SCHEMA='up_van_booking_test' AND VIEW_NAME='calendar_events' ORDER BY TABLE_SCHEMA,TABLE_NAME")
if($viewDependencies.Count -ne 2){throw 'Calendar view dependency inventory must contain exactly two tables.'};$dependencyTables=@($viewDependencies|ForEach-Object{$parts=@($_ -split "`t");if($parts.Count -ne 2 -or $parts[0] -cne $database){throw 'Calendar view dependency schema mismatch.'};$parts[1]}|Sort-Object -Unique);Assert-One 'Calendar view dependency tables' $dependencyTables @('booking_requests','vehicle_assignments');Write-Output "VIEW_DEPENDENCIES=PASS count=$($viewDependencies.Count) tables=$($dependencyTables -join ',')"
$viewRead=@(Invoke-ReadSql 'SELECT COUNT(*) FROM calendar_events');if($viewRead.Count -ne 1 -or $viewRead[0] -notmatch '\A[0-9]+\z'){throw 'Read-only SELECT from calendar_events failed; backup stopped.'};Write-Output "VIEW_SELECT=PASS event_count=$($viewRead[0])"
$qUser='`'+$definerUser.Replace('`','``')+'`';$qHost='`'+$definerHost.Replace('`','``')+'`';$security=if($v[3] -in @('INVOKER','DEFINER')){$v[3]}else{throw 'Unsupported view security type.'}
$viewSql="SET NAMES $($v[5]) COLLATE $($v[6]);`r`nCREATE ALGORITHM=UNDEFINED DEFINER=$qUser@$qHost SQL SECURITY $security VIEW ``calendar_events`` AS`r`n$definition"
if($v[4] -eq 'LOCAL'){$viewSql+=' WITH LOCAL CHECK OPTION'}elseif($v[4] -eq 'CASCADED'){$viewSql+=' WITH CASCADED CHECK OPTION'}elseif($v[4] -ne 'NONE'){throw 'Unsupported view check option.'};$viewSql+=';'+"`r`n"

$backup=Join-Path $env:USERPROFILE ('upvan-reset-preflight-'+[guid]::NewGuid().ToString('N'));New-Item -ItemType Directory -Path $backup|Out-Null
$basePath=Join-Path $backup 'up_van_booking_test_base_tables.sql';$viewPath=Join-Path $backup 'calendar_events_view_restore.sql';$stderr=Join-Path $backup 'mysqldump.stderr.txt'
[IO.File]::WriteAllText($viewPath,$viewSql,[Text.UTF8Encoding]::new($false))
$dumpArgs=@("--login-path=$LoginPath",'--protocol=TCP',"--host=$hostName","--port=$port",'--default-character-set=utf8mb4','--lock-tables','--skip-single-transaction','--hex-blob','--triggers','--no-tablespaces',$database)+$required
$proc=Start-Process -FilePath $dump -ArgumentList $dumpArgs -WorkingDirectory $mysqlBin -RedirectStandardOutput $basePath -RedirectStandardError $stderr -Wait -PassThru -NoNewWindow
if($proc.ExitCode -ne 0){throw "mysqldump failed (exit=$($proc.ExitCode)); no reset allowed."}
$created=@(Select-String -LiteralPath $basePath -Pattern '^CREATE TABLE `([^`]+)`'|ForEach-Object{$_.Matches[0].Groups[1].Value}|Sort-Object);Assert-One 'Backup table list' $created @($required|Sort-Object)
$file=Get-Item -LiteralPath $basePath;if($file.Length -le 0){throw 'Base table dump is empty.'};$baseHash=(Get-FileHash -LiteralPath $basePath -Algorithm SHA256).Hash.ToLowerInvariant();$viewFile=Get-Item -LiteralPath $viewPath;$viewHash=(Get-FileHash -LiteralPath $viewPath -Algorithm SHA256).Hash.ToLowerInvariant()
Write-Output "BACKUP=PASS exit=$($proc.ExitCode) base_path=$basePath bytes=$($file.Length) sha256=$baseHash"
Write-Output "VIEW_BACKUP=PASS path=$viewPath bytes=$($viewFile.Length) sha256=$viewHash"
Write-Output "PREFLIGHT=READ_ONLY_PASS archive=$backup"
