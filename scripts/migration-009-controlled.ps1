param(
    [Parameter(Mandatory)][ValidateSet('Preflight','Apply','Postflight','GrantRuntime')][string]$Phase,
    [Parameter(Mandatory)][string]$Bundle,
    [string]$ConfirmTarget
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest
$root = Split-Path -Parent $PSScriptRoot
$mysqlBin = 'C:\Program Files\MySQL\MySQL Server 8.4\bin'
$database = 'up_van_booking_test'
$uuid = '77218fbd-d66d-11f1-9a0a-5c2886f057d5'
$dataDir = 'C:/Users/User/mysql-data'
$baseTables = @(
    'approval_decisions','audit_logs','auth_login_buckets','booking_notifications',
    'booking_passengers','booking_requests','booking_reviews','booking_status_history',
    'drivers','roles','schema_migrations','user_roles','users','vehicle_assignments','vehicles'
)
$client = @('--no-defaults','--login-path=upvan3306-backup','--protocol=TCP','--host=127.0.0.1','--port=3306',"--database=$database",'--default-character-set=utf8mb4','--batch','--raw','--skip-column-names','--skip-reconnect')
$identityCondition = "@@port=3306 AND @@server_uuid='$uuid' AND DATABASE()='$database' AND REPLACE(RTRIM(@@datadir),CHAR(92),'/')='$dataDir/' AND VERSION() LIKE '8.4.%'"
$OutputEncoding = [Text.UTF8Encoding]::new($false)

function Invoke-Sql([string]$Sql) {
    $lines = $Sql | & (Join-Path $mysqlBin 'mysql.exe') @client
    if ($LASTEXITCODE -ne 0) { throw "MySQL failed in ${Phase} (exit=$LASTEXITCODE). Stop for manual review." }
    return $lines
}

function Write-Guard([string]$Condition) {
    # On a failed guard, the prepared statement reads a nonexistent table.
    # The batch client stops at the first error and never reconnects.
    return "SET @guard=IF(($Condition),'DO 0','SELECT * FROM __upvan_migration_guard_failed'); PREPARE target_guard FROM @guard; EXECUTE target_guard; DEALLOCATE PREPARE target_guard;"
}

function Assert-Same([string[]]$Actual,[string[]]$Expected,[string]$Label) {
    if (($Actual -join "`n") -cne ($Expected -join "`n")) { throw "$Label mismatch. Stop for manual review." }
}

function Get-Ledger {
    @(Invoke-Sql 'SELECT CONCAT_WS(CHAR(9),version,checksum,status,completed_statements,total_statements) FROM schema_migrations ORDER BY version;')
}

function Get-CalendarViewSql {
    Assert-Same @(Invoke-Sql "SELECT TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='calendar_events';") @('VIEW') 'Calendar view type'
    $rows = @(Invoke-Sql 'SHOW CREATE VIEW calendar_events;')
    if ($rows.Count -ne 1) { throw 'Expected exactly one calendar_events definition.' }
    $parts = $rows[0] -split "`t"
    if ($parts.Count -ne 4 -or $parts[0] -cne 'calendar_events' -or
        $parts[1] -notmatch '^CREATE ALGORITHM=(UNDEFINED|MERGE|TEMPTABLE) DEFINER=`[^`]+`@`[^`]+` SQL SECURITY (INVOKER|DEFINER) VIEW `calendar_events` AS (SELECT|WITH)\b' -or
        $parts[2] -notmatch '\A[a-zA-Z0-9_]+\z' -or $parts[3] -notmatch '\A[a-zA-Z0-9_]+\z') {
        throw 'Calendar view definition is incomplete.'
    }
    return "SET NAMES $($parts[2]) COLLATE $($parts[3]);`n$($parts[1]);`n"
}

function Get-BaseTableCounts {
    $actual = @(Invoke-Sql "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME;")
    Assert-Same $actual $baseTables 'Base table inventory'
    $counts = [ordered]@{}
    foreach ($table in $baseTables) {
        $count = @(Invoke-Sql ('SELECT COUNT(*) FROM `'+$table+'`;'))
        if ($count.Count -ne 1 -or $count[0] -notmatch '\A[0-9]+\z') { throw "Invalid row count for $table." }
        $counts[$table] = [long]$count[0]
    }
    return $counts
}

function Assert-BaseTableDump([string]$Path, $ExpectedCounts) {
    if (!(Test-Path -LiteralPath $Path -PathType Leaf) -or (Get-Item -LiteralPath $Path).Length -lt 1024 -or
        !(Get-Content -LiteralPath $Path -Tail 4 | Where-Object {$_ -match '^-- Dump completed'})) {
        throw 'Base-table backup is missing or incomplete.'
    }
    $tables = @(Select-String -LiteralPath $Path -Pattern '^CREATE TABLE `([a-z_]+)`' |
        ForEach-Object { $_.Matches[0].Groups[1].Value } | Sort-Object)
    Assert-Same $tables $baseTables 'Dump table inventory'
    if (Select-String -LiteralPath $Path -Pattern '^(?:/\*!\d+\s+)?CREATE.*\bVIEW\s+`calendar_events`' -Quiet) {
        throw 'View definition unexpectedly found in base-table dump.'
    }
    $counts = @{}
    foreach ($hit in @(Select-String -LiteralPath $Path -Pattern '^INSERT INTO `([a-z_]+)` VALUES \(')) {
        $table = $hit.Matches[0].Groups[1].Value
        if ($table -notin $baseTables) { throw 'Dump has an unexpected INSERT target.' }
        $counts[$table] = 1 + [long]$counts[$table]
    }
    foreach ($table in $baseTables) {
        if ([long]$counts[$table] -ne [long]$ExpectedCounts[$table]) { throw "Dump row count mismatch for $table." }
    }
}

function Get-Fingerprints($Columns) {
    $result = [ordered]@{}
    foreach ($table in $Columns.Keys) {
        if ($table -notmatch '\A[a-z_]+\z' -or $Columns[$table] -notmatch '\A[a-z_][a-z_0-9]*(,[a-z_][a-z_0-9]*)*\z') { throw 'Fingerprint projection rejected.' }
        $projection = ($Columns[$table] -split ',' | ForEach-Object {'`'+$_+'`'}) -join ','
        $sql = 'SET time_zone=''+00:00''; SELECT SHA2(CAST(JSON_ARRAY('+($projection -replace '`bucket_digest`','HEX(`bucket_digest`)')+') AS CHAR),256) AS digest FROM `'+$table+'` ORDER BY digest;'
        $rows = @(Invoke-Sql $sql)
        if (@($rows | Where-Object {$_ -notmatch '\A[0-9a-f]{64}\z'}).Count -ne 0) { throw 'Fingerprint is malformed.' }
        $hasher = [Security.Cryptography.SHA256]::Create()
        try { $hash = [BitConverter]::ToString($hasher.ComputeHash([Text.Encoding]::UTF8.GetBytes(($rows -join '')))).Replace('-','').ToLowerInvariant() }
        finally { $hasher.Dispose() }
        $result[$table] = "$($rows.Count):$hash"
    }
    return $result
}

$expectedIdentity = @(Invoke-Sql "SELECT IF($identityCondition,'PASS','FAIL');")
Assert-Same $expectedIdentity @('PASS') 'MySQL test identity'
if (Get-NetTCPConnection -LocalPort 8088 -State Listen -ErrorAction SilentlyContinue) { throw 'Pause the verified PHP test listener during migration to prevent concurrent writes.' }

$planCode = @'
require 'bootstrap/cli.php';
echo json_encode(App\Database\MigrationPlan::load(true,true,true,true), JSON_THROW_ON_ERROR);
'@
$plan = & (Join-Path $PSScriptRoot 'php.ps1') -r $planCode | ConvertFrom-Json
if ($LASTEXITCODE -ne 0 -or @($plan).Count -ne 9) { throw 'Pinned migration plan failed.' }
$entry = $plan[8]
$hash = [string]$entry.sha256
$version = [string]$entry.version
if ($hash -notmatch '\A[0-9a-f]{64}\z' -or $version -cne '009_booking_soft_delete' -or @($entry.statements).Count -ne 1) { throw 'Migration 009 pin rejected.' }
$prior = @(foreach ($item in $plan[0..7]) { "$($item.version)`t$($item.sha256)`tapplied`t$(@($item.statements).Count)`t$(@($item.statements).Count)" })
$expectedPost = $prior + @("$version`t$hash`tapplied`t1`t1")
$softColumns = "TABLE_SCHEMA=DATABASE() AND TABLE_NAME='booking_requests' AND COLUMN_NAME IN ('deleted_at','deleted_by','deletion_reason')"
$schemaPrecondition = "(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE $softColumns)=0"
$schemaPrecondition += " AND EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='booking_requests' AND COLUMN_NAME='a4_request_data' AND DATA_TYPE='json' AND IS_NULLABLE='YES')"
$schemaPrecondition += " AND (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('booking_requests','users','schema_migrations') AND ENGINE='InnoDB')=3"
$schemaPrecondition += " AND EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='id' AND COLUMN_TYPE='bigint unsigned' AND COLUMN_KEY='PRI')"
$schemaPrecondition += " AND (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='booking_requests' AND CONSTRAINT_NAME IN ('fk_booking_deleted_by','chk_booking_deleted_fields'))=0"
$schemaPostcondition = "(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE $softColumns AND IS_NULLABLE='YES' AND COLUMN_DEFAULT IS NULL AND ((COLUMN_NAME='deleted_at' AND COLUMN_TYPE='datetime(6)') OR (COLUMN_NAME='deleted_by' AND COLUMN_TYPE='bigint unsigned') OR (COLUMN_NAME='deletion_reason' AND COLUMN_TYPE='varchar(100)')))=3"
$schemaPostcondition += " AND EXISTS(SELECT 1 FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='booking_requests' AND CONSTRAINT_NAME='fk_booking_deleted_by' AND COLUMN_NAME='deleted_by' AND REFERENCED_TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME='users' AND REFERENCED_COLUMN_NAME='id')"
$schemaPostcondition += " AND EXISTS(SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='booking_requests' AND CONSTRAINT_NAME='chk_booking_deleted_fields' AND CONSTRAINT_TYPE='CHECK' AND ENFORCED='YES')"
$ledgerGuard = '(SELECT COUNT(*) FROM schema_migrations)=8'
foreach ($item in $plan[0..7]) {
    if ($item.version -notmatch '\A[0-9]{3}_[a-z0-9_]+\z' -or $item.sha256 -notmatch '\A[0-9a-f]{64}\z') { throw 'Invalid prior pin.' }
    $count = @($item.statements).Count
    $ledgerGuard += " AND EXISTS(SELECT 1 FROM schema_migrations WHERE version='$($item.version)' AND checksum='$($item.sha256)' AND status='applied' AND completed_statements=$count AND total_statements=$count)"
}
$backup = Join-Path $Bundle 'up_van_booking_test_base_tables.sql'
$viewBackup = Join-Path $Bundle 'calendar_events_view_restore.sql'
$baselineFile = Join-Path $Bundle 'preflight.json'
$postflightFile = Join-Path $Bundle 'postflight.json'

if ($Phase -eq 'Preflight') {
    Assert-Same (Get-Ledger) $prior 'Ledger 001–008'
    Assert-Same @(Invoke-Sql "SELECT IF($schemaPrecondition,'PASS','FAIL');") @('PASS') 'Preflight schema'
    $runtime = & (Join-Path $PSScriptRoot 'php.ps1') (Join-Path $root 'bin/booking-capabilities-readonly.php') | ConvertFrom-Json
    if ($LASTEXITCODE -ne 0 -or $runtime.identity.uuid -cne $uuid -or $runtime.identity.db -cne $database -or
        $runtime.capabilities.submission.status -ne 'PASS' -or $runtime.capabilities.submission.account_guard.status -ne 'PASS' -or
        $runtime.capabilities.officer_read.status -ne 'PASS') { throw 'Runtime preflight failed.' }
    if (Test-Path -LiteralPath $Bundle) { throw 'Backup bundle already exists.' }
    New-Item -Path $Bundle -ItemType Directory | Out-Null
    $sid = [Security.Principal.WindowsIdentity]::GetCurrent().User.Value
    & icacls.exe $Bundle /inheritance:r /grant:r "*${sid}:(OI)(CI)F" '*S-1-5-18:(OI)(CI)F' | Out-Null
    if ($LASTEXITCODE -ne 0) { throw 'Backup folder ACL setup failed.' }
    $viewSql = Get-CalendarViewSql
    [IO.File]::WriteAllText($viewBackup, $viewSql, [Text.UTF8Encoding]::new($false))
    $rowCounts = Get-BaseTableCounts
    $columns = [ordered]@{}
    foreach ($line in @(Invoke-Sql "SELECT CONCAT(TABLE_NAME,CHAR(9),GROUP_CONCAT(COLUMN_NAME ORDER BY ORDINAL_POSITION)) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('booking_requests','booking_status_history','audit_logs','vehicles','vehicle_assignments','approval_decisions') GROUP BY TABLE_NAME ORDER BY TABLE_NAME;")) {
        $parts = $line -split "`t",2
        $columns[$parts[0]] = $parts[1]
    }
    if ($columns.Count -ne 6) { throw 'Expected six business data projections.' }
    $fingerprints = Get-Fingerprints $columns
    & (Join-Path $mysqlBin 'mysqldump.exe') --no-defaults --login-path=upvan3306-backup --protocol=TCP --host=127.0.0.1 --port=3306 --default-character-set=utf8mb4 --lock-tables --skip-single-transaction --quick --hex-blob --triggers --no-tablespaces --set-gtid-purged=OFF --skip-extended-insert "--result-file=$backup" $database @baseTables
    $dumpExit = $LASTEXITCODE
    if ($dumpExit -ne 0) { throw "Base-table backup failed (exit=$dumpExit)." }
    Assert-BaseTableDump $backup $rowCounts
    $afterCounts = Get-BaseTableCounts
    $afterFingerprints = Get-Fingerprints $columns
    foreach ($table in $baseTables) {
        if ($afterCounts[$table] -ne $rowCounts[$table]) { throw "Data count changed during backup in $table." }
    }
    foreach ($table in $fingerprints.Keys) {
        if ($afterFingerprints[$table] -cne $fingerprints[$table]) { throw "Business data changed during backup in $table." }
    }
    if ((Get-CalendarViewSql) -cne $viewSql -or [IO.File]::ReadAllText($viewBackup) -cne $viewSql) {
        throw 'Calendar view changed during backup or the saved definition mismatched.'
    }
    $report = [ordered]@{
        status='PASS';uuid=$uuid;migration_sha256=$hash;mysqldump_exit=$dumpExit
        backup_sha256=(Get-FileHash -LiteralPath $backup).Hash;backup_bytes=(Get-Item -LiteralPath $backup).Length
        view_sha256=(Get-FileHash -LiteralPath $viewBackup).Hash;view_bytes=(Get-Item -LiteralPath $viewBackup).Length
        row_counts=$rowCounts;columns=$columns;fingerprints=$fingerprints
    }
    $report | ConvertTo-Json -Depth 6 | Set-Content -LiteralPath $baselineFile -Encoding utf8
    "PREFLIGHT=PASS DUMP_EXIT=$dumpExit TABLES=$($rowCounts.Count) MIGRATION_SHA256=$hash"
    "BASE_TABLE_BACKUP_BYTES=$($report.backup_bytes) SHA256=$($report.backup_sha256)"
    "VIEW_BACKUP_BYTES=$($report.view_bytes) SHA256=$($report.view_sha256)"
    "ROW_COUNTS=$($rowCounts | ConvertTo-Json -Compress)"
    return
}

$baseline = Get-Content -LiteralPath $baselineFile -Raw | ConvertFrom-Json -AsHashtable
if ($baseline.status -ne 'PASS' -or $baseline.uuid -cne $uuid -or $baseline.migration_sha256 -cne $hash -or
    $baseline.mysqldump_exit -ne 0 -or (Get-FileHash -LiteralPath $backup).Hash -cne $baseline.backup_sha256 -or
    (Get-FileHash -LiteralPath $viewBackup).Hash -cne $baseline.view_sha256 -or
    (Get-Item -LiteralPath $backup).Length -ne $baseline.backup_bytes -or
    (Get-Item -LiteralPath $viewBackup).Length -ne $baseline.view_bytes -or
    [IO.File]::ReadAllText($viewBackup) -cne (Get-CalendarViewSql)) {
    throw 'Verified Preflight, base-table dump, or view definition is missing or mismatched.'
}
Assert-BaseTableDump $backup $baseline.row_counts

function Assert-DataPreserved {
    $current = Get-Fingerprints $baseline.columns
    foreach ($table in $baseline.fingerprints.Keys) {
        if ($current[$table] -cne $baseline.fingerprints[$table]) { throw "Data changed in $table. Stop for manual review." }
    }
}

if ($Phase -eq 'Apply') {
    if ($ConfirmTarget -cne $database -or $env:TEST_DB_EXECUTION_APPROVED -ne 'true' -or $env:DB_PHASE_B_APPROVED -ne 'false') { throw '009 execution gate closed.' }
    Assert-Same (Get-Ledger) $prior 'Ledger 001–008'
    Assert-DataPreserved
    $guard = Write-Guard "$identityCondition AND GET_LOCK('up_van_booking_test:migrations',0)=1 AND $ledgerGuard AND $schemaPrecondition"
    $statement = [string]$entry.statements[0]
    $schemaGuard = Write-Guard $schemaPostcondition
    $applyResult = @(Invoke-Sql @"
$guard
INSERT INTO schema_migrations (version,checksum,status,total_statements) VALUES ('$version','$hash','started',1);
$statement;
$schemaGuard
UPDATE schema_migrations SET status='applied',completed_statements=1,finished_at=CURRENT_TIMESTAMP(6) WHERE version='$version' AND status='started' AND checksum='$hash';
SELECT IF(ROW_COUNT()=1,'APPLY=PASS','APPLY=LEDGER_UPDATE_FAILED');
"@)
    Assert-Same $applyResult @('APPLY=PASS') 'Apply result'
    $applyResult
    return
}

Assert-Same (Get-Ledger) $expectedPost 'Ledger 001–009'
Assert-Same @(Invoke-Sql "SELECT IF(($schemaPostcondition) AND NOT EXISTS(SELECT 1 FROM booking_requests WHERE deleted_at IS NOT NULL OR deleted_by IS NOT NULL OR deletion_reason IS NOT NULL),'PASS','FAIL');") @('PASS') 'Postflight schema/tombstones'
Assert-DataPreserved
if ($Phase -eq 'Postflight') {
    [ordered]@{status='PASS';uuid=$uuid;migration_sha256=$hash;backup_sha256=$baseline.backup_sha256;view_sha256=$baseline.view_sha256} |
        ConvertTo-Json | Set-Content -LiteralPath $postflightFile -Encoding utf8
    'POSTFLIGHT=PASS DATA_FINGERPRINTS_MATCH=6/6'
    return
}

$postflight = Get-Content -LiteralPath $postflightFile -Raw | ConvertFrom-Json
if ($ConfirmTarget -cne $database -or $postflight.status -ne 'PASS' -or $postflight.uuid -cne $uuid -or
    $postflight.migration_sha256 -cne $hash -or $postflight.backup_sha256 -cne $baseline.backup_sha256 -or
    $postflight.view_sha256 -cne $baseline.view_sha256) { throw 'Postflight approval checks failed.' }
$scope = "TABLE_SCHEMA=DATABASE() AND GRANTEE=CONCAT(QUOTE('up_van_auth_test'),'@',QUOTE('127.0.0.1'))"
$checks = @(
    @{Table='booking_requests';Column='deleted_at';Right='SELECT'},
    @{Table='booking_requests';Column='deleted_at';Right='UPDATE'},
    @{Table='booking_requests';Column='deleted_by';Right='UPDATE'},
    @{Table='booking_requests';Column='deletion_reason';Right='UPDATE'},
    @{Table='vehicle_assignments';Column='booking_request_id';Right='SELECT'}
)
$missing = @(foreach ($check in $checks) {
    $present = @(Invoke-Sql "SELECT COUNT(*) FROM information_schema.COLUMN_PRIVILEGES WHERE $scope AND TABLE_NAME='$($check.Table)' AND COLUMN_NAME='$($check.Column)' AND PRIVILEGE_TYPE='$($check.Right)';")
    if ($present.Count -ne 1 -or $present[0] -notin @('0','1')) { throw 'Unexpected grant metadata.' }
    if ($present[0] -eq '0') { $check }
})
$grantStatements = @(foreach ($group in ($missing | Group-Object Table,Right)) {
    $first = $group.Group[0]
    $fields = ($group.Group | ForEach-Object {$_.Column}) -join ','
    "GRANT $($first.Right) ($fields) ON $database.$($first.Table) TO 'up_van_auth_test'@'127.0.0.1';"
})
if ($grantStatements.Count -gt 0) {
    $guard = Write-Guard "$identityCondition AND $schemaPostcondition"
    Invoke-Sql ($guard + "`n" + ($grantStatements -join "`n"))
}
"GRANTED_COLUMN_PRIVILEGES=$($missing.Count)"
