#Requires -Version 5.1
#Requires -PSEdition Desktop
[CmdletBinding()]
param([switch]$VerifyPreflight, [switch]$BackupPre010, [switch]$Backup011Reconciliation)
$ErrorActionPreference='Stop'
Set-StrictMode -Version Latest
$selectedModes=@();if($VerifyPreflight){$selectedModes+='VerifyPreflight'};if($BackupPre010){$selectedModes+='BackupPre010'};if($Backup011Reconciliation){$selectedModes+='Backup011Reconciliation'}
if ($selectedModes.Count -gt 1) { throw 'CHOOSE_ONE_EXPORT_MODE' }
$mysqlBin='C:\Program Files\MySQL\MySQL Server 8.4\bin'
$mysql=[IO.Path]::Combine($mysqlBin, 'mysql.exe')
$dump=[IO.Path]::Combine($mysqlBin, 'mysqldump.exe')
$projectRoot=Split-Path -Parent $PSScriptRoot
. (Join-Path $PSScriptRoot 'lib/migration011-apply-credentials.ps1')
$php=Resolve-Migration011EvaluatorRuntime $projectRoot
$runtimeRoot=Split-Path -Parent (Split-Path -Parent $php)
$backupChecker = [IO.Path]::Combine($PSScriptRoot, 'migration010-backup-check.php')
foreach($exe in @($mysql,$dump)){
    if(![IO.File]::Exists($exe)){throw 'MYSQL_CLIENT_MISSING'}
    $version=& $exe --no-defaults --version
    if($LASTEXITCODE -ne 0 -or $version -notmatch '\b8\.4\.9\b'){throw 'MYSQL_CLIENT_VERSION_REJECTED'}
}
if(![IO.File]::Exists($php)){throw 'PROJECT_PHP_RUNTIME_MISSING'}
$common=@('--no-defaults','--login-path=upvan3306-backup','--protocol=TCP','--host=127.0.0.1','--port=3306','--default-character-set=utf8mb4')

function New-PrivateSnapshotDirectory {
    param([Parameter(Mandatory=$true)][string]$Path)

    try {
        $directoryInfo = [IO.DirectoryInfo]::new($Path)
        if ($directoryInfo.Exists -or [IO.File]::Exists($Path)) {
            throw 'Snapshot path already exists.'
        }

        $sid = [Security.Principal.WindowsIdentity]::GetCurrent().User
        $inheritance = [Security.AccessControl.InheritanceFlags]'ContainerInherit,ObjectInherit'
        $acl = [Security.AccessControl.DirectorySecurity]::new()
        $acl.SetAccessRuleProtection($true, $false)
        $acl.SetOwner($sid)
        $acl.AddAccessRule([Security.AccessControl.FileSystemAccessRule]::new(
            $sid, [Security.AccessControl.FileSystemRights]::FullControl, $inheritance,
            [Security.AccessControl.PropagationFlags]::None, [Security.AccessControl.AccessControlType]::Allow
        ))

        # .NET Framework creates the directory with its ACL in one operation.
        # The exporter does not depend on Set-Acl or PowerShell module discovery.
        $directoryInfo.Create($acl)
        $directoryInfo.Refresh()
        $actualAcl = $directoryInfo.GetAccessControl()
        $rules = $actualAcl.GetAccessRules($true, $true, [Security.Principal.SecurityIdentifier])
        if (($directoryInfo.Attributes -band [IO.FileAttributes]::ReparsePoint) -or
            !$actualAcl.AreAccessRulesProtected -or
            !$actualAcl.GetOwner([Security.Principal.SecurityIdentifier]).Equals($sid) -or
            $rules.Count -ne 1 -or $rules[0].IsInherited -or
            !$rules[0].IdentityReference.Equals($sid) -or
            $rules[0].FileSystemRights -ne [Security.AccessControl.FileSystemRights]::FullControl -or
            $rules[0].InheritanceFlags -ne $inheritance -or
            $rules[0].PropagationFlags -ne [Security.AccessControl.PropagationFlags]::None -or
            $rules[0].AccessControlType -ne [Security.AccessControl.AccessControlType]::Allow) {
            throw 'Snapshot ACL does not match the required policy.'
        }
    } catch {
        throw "SCHEMA_SNAPSHOT_ACL_FAILED: No export started. Directory: $Path"
    }
}

function Assert-PrivateSnapshotFile {
    param([Parameter(Mandatory=$true)][string]$Path)

    $file = [IO.FileInfo]::new($Path)
    if (!$file.Exists -or ($file.Attributes -band [IO.FileAttributes]::ReparsePoint)) {
        throw "SCHEMA_SNAPSHOT_FILE_REJECTED: $Path"
    }

    $sid = [Security.Principal.WindowsIdentity]::GetCurrent().User
    $rules = $file.GetAccessControl().GetAccessRules($true, $true, [Security.Principal.SecurityIdentifier])
    if ($rules.Count -ne 1 -or !$rules[0].IsInherited -or
        !$rules[0].IdentityReference.Equals($sid) -or
        $rules[0].FileSystemRights -ne [Security.AccessControl.FileSystemRights]::FullControl -or
        $rules[0].AccessControlType -ne [Security.AccessControl.AccessControlType]::Allow) {
        throw "SCHEMA_SNAPSHOT_FILE_ACL_REJECTED: $Path"
    }
}

function Get-SnapshotSha256 {
    param([Parameter(Mandatory=$true)][string]$Path)

    $algorithm = [Security.Cryptography.SHA256]::Create()
    try {
        $stream = [IO.File]::Open($Path, [IO.FileMode]::Open, [IO.FileAccess]::Read, [IO.FileShare]::Read)
        try {
            $hash = $algorithm.ComputeHash($stream)
            return [BitConverter]::ToString($hash).Replace('-', '').ToLowerInvariant()
        } finally {
            $stream.Dispose()
        }
    } finally {
        $algorithm.Dispose()
    }
}

function Write-SnapshotManifest {
    param(
        [Parameter(Mandatory=$true)][string]$Path,
        [Parameter(Mandatory=$true)][System.Collections.IDictionary]$Manifest
    )

    # Windows PowerShell 5.1 runs on .NET Framework. Use its JSON serializer even
    # when the Microsoft.PowerShell.Utility module cannot be loaded.
    [void][Reflection.Assembly]::Load('System.Web.Extensions, Version=4.0.0.0, Culture=neutral, PublicKeyToken=31bf3856ad364e35')
    $json = [System.Web.Script.Serialization.JavaScriptSerializer]::new().Serialize($Manifest)
    $bytes = [Text.Encoding]::UTF8.GetBytes($json)
    $stream = [IO.File]::Open($Path, [IO.FileMode]::CreateNew, [IO.FileAccess]::Write, [IO.FileShare]::None)
    try {
        $stream.Write($bytes, 0, $bytes.Length)
    } finally {
        $stream.Dispose()
    }
}

function Read-Identity([string]$Stage) {
    $rows=@(& $mysql @common --batch --raw --skip-column-names --database=up_van_booking_test '--execute=SELECT @@server_uuid,@@port,DATABASE(),VERSION(),CURRENT_USER()')
    if($LASTEXITCODE -ne 0 -or $rows.Count -ne 1){throw "DBA_AUTH_OR_IDENTITY_QUERY_FAILED: $Stage"}
    $fields=$rows[0] -split "`t"
    if($fields.Count -ne 5 -or $fields[0] -cne '77218fbd-d66d-11f1-9a0a-5c2886f057d5' -or $fields[1] -cne '3306' -or
        $fields[2] -cne 'up_van_booking_test' -or $fields[3] -cne '8.4.9' -or $fields[4] -notin @('root@localhost','root@127.0.0.1')){throw 'DBA_IDENTITY_REJECTED'}
    return $fields[4]
}

function Read-BackupInventory([string]$Stage,[bool]$Reconciliation=$false) {
    $query = @'
SELECT 'IDENTITY',@@server_uuid,@@port,DATABASE(),VERSION(),CURRENT_USER();
SELECT 'OBJECT',TABLE_NAME,TABLE_TYPE,COALESCE(ENGINE,'-') FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME;
SELECT 'VIEW',HEX(VIEW_DEFINITION),HEX(DEFINER),SECURITY_TYPE,CHECK_OPTION,CHARACTER_SET_CLIENT,COLLATION_CONNECTION FROM information_schema.VIEWS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='calendar_events';
SELECT 'DEPENDENCY',TABLE_SCHEMA,TABLE_NAME FROM information_schema.VIEW_TABLE_USAGE WHERE VIEW_SCHEMA=DATABASE() AND VIEW_NAME='calendar_events' ORDER BY TABLE_SCHEMA,TABLE_NAME;
SELECT 'LEDGER',version,checksum,status,completed_statements,total_statements,COALESCE(failure_code,'NULL') FROM schema_migrations ORDER BY version;
SELECT 'CALENDAR_READ',COUNT(*) FROM calendar_events;
'@
    $rows = @(& $mysql @common "--init-command=$backupGuard" --skip-reconnect --batch --raw --skip-column-names --database=up_van_booking_test "--execute=$query")
    if ($LASTEXITCODE -ne 0 -or $rows.Count -lt 1) { throw "BACKUP_INVENTORY_QUERY_FAILED: $Stage" }
    if ($rows[0] -cnotmatch '^IDENTITY\t77218fbd-d66d-11f1-9a0a-5c2886f057d5\t3306\tup_van_booking_test\t8\.4\.9\troot@(localhost|127\.0\.0\.1)$') {
        throw 'BACKUP_IDENTITY_REJECTED'
    }

    $tables = @()
    $views = @()
    $ledger = @()
    $dependencies = @()
    $viewRows = @()
    $calendarCount = @()
    for ($i = 1; $i -lt $rows.Count; $i++) {
        $fields = $rows[$i] -split "`t"
        switch -CaseSensitive ($fields[0]) {
            'OBJECT' {
                if ($fields.Count -ne 4 -or $fields[1] -cnotmatch '\A[a-z_][a-z0-9_]*\z') { throw 'BACKUP_OBJECT_REJECTED' }
                if ($fields[2] -ceq 'BASE TABLE' -and $fields[3] -ceq 'InnoDB') { $tables += $fields[1] }
                elseif ($fields[2] -ceq 'VIEW') { $views += $fields[1] }
                else { throw 'BACKUP_TABLE_ENGINE_OR_OBJECT_TYPE_UNSUPPORTED' }
            }
            'VIEW' { $viewRows += ,$fields }
            'DEPENDENCY' { $dependencies += $rows[$i] }
            'LEDGER' { $ledger += $rows[$i] }
            'CALENDAR_READ' { $calendarCount += $rows[$i] }
            default { throw 'BACKUP_INVENTORY_RESULT_REJECTED' }
        }
    }
    $ledgerPlan = @(& $php -n $backupChecker --expected-ledger)
    if ($LASTEXITCODE -ne 0 -or $ledgerPlan.Count -ne 9) { throw 'BACKUP_LEDGER_PLAN_REJECTED' }
    if ($ledger.Count -notin @(9, 10) -or
        ![string]::Equals(($ledger[0..8] -join "`n"), ($ledgerPlan -join "`n"), [StringComparison]::Ordinal)) {
        throw 'BACKUP_LEDGER_NOT_PRE010'
    }
    $has011 = $ledger.Count -eq 10
    if ($Reconciliation -and !$has011) { throw 'BACKUP_011_FAILED_LEDGER_REQUIRED' }
    if ($has011) {
        $ledger011Plan = @(& $php -n $backupChecker --expected-microsoft-011)
        if ($LASTEXITCODE -ne 0 -or $ledger011Plan.Count -ne 1 -or
            $ledger011Plan[0] -cnotmatch '\ALEDGER\t011_microsoft_identities\t[a-f0-9]{64}\tapplied\t1\t1\tNULL\z') {
            throw 'BACKUP_LEDGER_PLAN_REJECTED'
        }
        if ($Reconciliation) {
            # Both sources emit LEDGER, version, checksum, status, completed, total, failure_code.
            $expected011 = $ledger011Plan[0] -split "`t"
            $prefix = ($expected011[0..2] -join "`t") + "`tfailed`t1`t1`t"
            $eligibleRows = @(($prefix + 'statement_failed'), ($prefix + 'postcondition_failed'))
            if ([Array]::IndexOf($eligibleRows, $ledger[9]) -lt 0) {
                $actual011 = $ledger[9] -split "`t"
                $actual = 'malformed TSV row'
                if ($actual011.Count -eq 7 -and $ledger[9] -cmatch '\ALEDGER(?:\t[A-Za-z0-9_]{0,64}){6}\z') {
                    $actual = "'" + ($actual011[1..6] -join "', '") + "'"
                }
                throw ("BACKUP_011_FAILED_LEDGER_REJECTED: Expected version, checksum, status, completed, total, failure_code " +
                    "'$($expected011[1])', '$($expected011[2])', 'failed', '1', '1', 'statement_failed or postcondition_failed'. Actual: $actual")
            }
        } elseif (![string]::Equals($ledger[9], $ledger011Plan[0], [StringComparison]::Ordinal)) {
            throw 'BACKUP_LEDGER_NOT_PRE010'
        }
    }
    $expectedTables = @('approval_decisions','audit_logs','auth_login_buckets','booking_notifications','booking_passengers',
        'booking_requests','booking_reviews','booking_status_history','drivers','roles','schema_migrations','user_roles',
        'users','vehicle_assignments','vehicles')
    if ($has011) { $expectedTables += 'microsoft_identities' }
    if ($views.Count -ne 1 -or $views[0] -cne 'calendar_events' -or
        ($dependencies -join '|') -cne "DEPENDENCY`tup_van_booking_test`tbooking_requests|DEPENDENCY`tup_van_booking_test`tvehicle_assignments" -or
        (($tables | Sort-Object) -join "`n") -cne (($expectedTables | Sort-Object) -join "`n") -or
        $viewRows.Count -ne 1 -or $viewRows[0].Count -ne 7 -or $calendarCount.Count -ne 1 -or
        $calendarCount[0] -cnotmatch '^CALENDAR_READ\t[0-9]+$') { throw 'BACKUP_CALENDAR_INVENTORY_REJECTED' }
    return @{ Tables=$tables; Identity=$rows[0]; View=$viewRows[0]; Dependencies=$dependencies; Ledger=$ledger; CalendarCount=$calendarCount[0]; Has011=$has011; HasFailed011=$Reconciliation }
}

function Convert-BackupHex([string]$Hex) {
    if ($Hex -cnotmatch '\A(?:[0-9A-F]{2})+\z') { throw 'BACKUP_VIEW_HEX_REJECTED' }
    $bytes = [byte[]]::new($Hex.Length / 2)
    for ($i = 0; $i -lt $bytes.Length; $i++) { $bytes[$i] = [Convert]::ToByte($Hex.Substring($i * 2, 2), 16) }
    return [Text.UTF8Encoding]::new($false, $true).GetString($bytes)
}

function Get-CalendarRestoreSql([string[]]$View) {
    $definition = Convert-BackupHex $View[1]
    $definer = Convert-BackupHex $View[2]
    if ($definer -cnotmatch '\A([a-zA-Z0-9_]+)@([a-zA-Z0-9_.:%-]+)\z') { throw 'BACKUP_VIEW_DEFINER_REJECTED' }
    $quotedDefiner = '`' + $Matches[1] + '`@`' + $Matches[2] + '`'
    if ($definition -notmatch '^\s*SELECT\b' -or $View[3] -cnotin @('INVOKER','DEFINER') -or
        $View[4] -cnotin @('NONE','LOCAL','CASCADED') -or $View[5] -cnotmatch '\A\w+\z' -or $View[6] -cnotmatch '\A\w+\z') {
        throw 'BACKUP_VIEW_DEFINITION_REJECTED'
    }
    $option = if ($View[4] -ceq 'NONE') { '' } else { " WITH $($View[4]) CHECK OPTION" }
    return "SET NAMES $($View[5]) COLLATE $($View[6]);`r`nCREATE ALGORITHM=UNDEFINED DEFINER=$quotedDefiner SQL SECURITY $($View[3]) VIEW " +
        '`calendar_events` AS' + "`r`n$definition$option;`r`n"
}

function Invoke-Pre010Backup([bool]$Reconciliation=$false) {
    # This executes on every client connection, including the dump connection. A mismatch
    # errors before any inventory queries or export. No durable server state is changed.
    $backupGuard = "DO IF(@@server_uuid='77218fbd-d66d-11f1-9a0a-5c2886f057d5' AND @@port=3306 AND VERSION()='8.4.9' AND CURRENT_USER() IN ('root@localhost','root@127.0.0.1'),0,JSON_EXTRACT(CONCAT('server identity rejected ',@@server_uuid),'$'))"
    $before = Read-BackupInventory '1/3 Inventory before backup' $Reconciliation
    $viewSql = Get-CalendarRestoreSql $before.View
    $parent = [IO.Path]::Combine([Environment]::GetFolderPath('LocalApplicationData'), 'UPVanBooking\pre010-backups')
    $directory = [IO.Path]::Combine($parent, [Guid]::NewGuid().ToString('N'))
    [void][IO.Directory]::CreateDirectory($parent)
    New-PrivateSnapshotDirectory -Path $directory
    $Host.UI.WriteLine("BACKUP_WORKING_DIRECTORY=$directory")
    $started = [DateTime]::UtcNow.ToString('o')
    $base = [IO.Path]::Combine($directory, 'up_van_booking_test_base_tables.sql')
    $view = [IO.Path]::Combine($directory, 'calendar_events_view_restore.sql')
    $tables = $before.Tables

    $Host.UI.WriteLine('2/3 Base table export through the existing DBA MySQL Login Path.')
    & $dump @common "--init-command=$backupGuard" --lock-tables --skip-single-transaction --quick --hex-blob --add-locks --skip-extended-insert --triggers --skip-routines --skip-events --no-tablespaces --set-gtid-purged=OFF --column-statistics=0 --comments --dump-date "--result-file=$base" up_van_booking_test @tables
    if ($LASTEXITCODE -ne 0) { throw 'BACKUP_DUMP_FAILED_DO_NOT_USE' }
    Assert-PrivateSnapshotFile -Path $base

    $after = Read-BackupInventory '3/3 Inventory after backup' $Reconciliation
    foreach ($field in @('Identity','Tables','View','Dependencies','Ledger','CalendarCount','Has011','HasFailed011')) {
        if (($before[$field] -join "`n") -cne ($after[$field] -join "`n")) { throw "BACKUP_STATE_CHANGED_DO_NOT_USE: $field" }
    }
    $checkArguments=@($base)+@($tables);if($Reconciliation){$checkArguments+='--allow-failed-011'}
    & $php -n $backupChecker @checkArguments
    if ($LASTEXITCODE -ne 0) { throw 'BACKUP_CONTENT_REJECTED_DO_NOT_USE' }
    [IO.File]::WriteAllText($view, $viewSql, [Text.UTF8Encoding]::new($false))
    Assert-PrivateSnapshotFile -Path $view
    $baseHash = Get-SnapshotSha256 -Path $base
    $viewHash = Get-SnapshotSha256 -Path $view
    $manifest = [ordered]@{
        format='upvan-pre010-logical-backup-v1';host='127.0.0.1';port=3306;database='up_van_booking_test'
        uuid='77218fbd-d66d-11f1-9a0a-5c2886f057d5';version='8.4.9'
        started_utc=$started;finished_utc=[DateTime]::UtcNow.ToString('o');tables=$tables
        ledger=$(if($before.HasFailed011){'001-009 applied; 011 failed 1/1; 010 absent'}elseif($before.Has011){'001-009 applied; 011 applied; 010 absent'}else{'001-009 applied; 010 absent'});calendar_view='calendar_events';calendar_security=$before.View[3]
        calendar_dependencies=@('booking_requests','vehicle_assignments');calendar_count=[long](($before.CalendarCount -split "`t")[1])
        base_tables_sha256=$baseHash;base_tables_bytes=[IO.FileInfo]::new($base).Length
        calendar_view_sha256=$viewHash;calendar_view_bytes=[IO.FileInfo]::new($view).Length
        restore_verified=$false;apply_ready=$false
    }
    $manifestPath = [IO.Path]::Combine($directory, 'backup-manifest.json')
    Write-SnapshotManifest -Path $manifestPath -Manifest $manifest
    Assert-PrivateSnapshotFile -Path $manifestPath
    $manifestHash=Get-SnapshotSha256 -Path $manifestPath
    # Verify the exported snapshot with the guard for its ledger branch.
    $verificationRequest=[System.Web.Script.Serialization.JavaScriptSerializer]::new().Serialize(@{directory=$directory;manifest_sha256=$manifestHash})
    if($Reconciliation -or !$before.Has011){
        $verifyMode=if($Reconciliation){'--verify-011-reconciliation-backup'}else{'--verify-011-backup'}
        $verification=@($verificationRequest | & $php -n (Join-Path $PSScriptRoot 'migration011-provision-grants-check.php') $verifyMode)
        if($LASTEXITCODE -ne 0){throw 'BACKUP_011_VERIFICATION_FAILED_DO_NOT_USE'}
        $Host.UI.WriteLine(($verification -join "`n"))
    }else{
        $verifyScript=Join-Path $PSScriptRoot 'migration010-apply-preflight.php'
        $verifyArgs=@('-n',(ConvertTo-Migration011WindowsArgument $verifyScript),'--verify-backup')
        $verification=Invoke-Migration011MysqlCaptured -ClientPath $php -ClientArgs $verifyArgs -Sql $verificationRequest
        if($verification.ExitCode -ne 0 -or $verification.InputWriteFailed){throw 'BACKUP_010_VERIFICATION_FAILED_DO_NOT_USE'}
        $Host.UI.WriteLine($verification.Stdout.Trim())
    }
    "BACKUP_DIRECTORY=$directory"
    "BASE_TABLES=$($tables.Count)"
    "BASE_SHA256=$baseHash"
    "CALENDAR_VIEW_SHA256=$viewHash"
    "MANIFEST_SHA256=$manifestHash"
    "PRE010_BACKUP=PASS ledger_branch=$(if($before.HasFailed011){'011_FAILED_RECONCILIATION'}elseif($before.Has011){'011_APPLIED'}else{'011_ABSENT'})"
}

if ($BackupPre010) {
    Invoke-Pre010Backup
    return
}
if ($Backup011Reconciliation) {
    Invoke-Pre010Backup -Reconciliation $true
    return
}

function Invoke-FreshSnapshotPreflight {
    param(
        [Parameter(Mandatory=$true)][string]$Directory,
        [Parameter(Mandatory=$true)][System.Collections.IDictionary]$Manifest,
        [Parameter(Mandatory=$true)][string]$Wrapper
    )

    $powershell = [IO.Path]::Combine($PSHOME, 'powershell.exe')
    if (![IO.File]::Exists($wrapper) -or ![IO.File]::Exists($powershell)) {
        throw 'PREFLIGHT_WRAPPER_OR_POWERSHELL_MISSING'
    }

    # Keep both files open for reading so their names and contents cannot be replaced
    # before the runner verifies the newly exported snapshot.
    $schemaLock = [IO.File]::Open([IO.Path]::Combine($Directory, 'schema.sql'), 'Open', 'Read', 'Read')
    try {
        $manifestLock = [IO.File]::Open([IO.Path]::Combine($Directory, 'manifest.json'), 'Open', 'Read', 'Read')
        try {
            $output = @(& $powershell -NoLogo -NoProfile -ExecutionPolicy Bypass -File $wrapper -Action Preflight -PhpRuntimePath $php -SchemaSnapshotDirectory $Directory)
            $preflightExit = $LASTEXITCODE
        } finally {
            $manifestLock.Dispose()
        }
    } finally {
        $schemaLock.Dispose()
    }
    $output
    if ($preflightExit -ne 0) { throw "FRESH_DBA_SCHEMA_PREFLIGHT_FAILED_EXIT_$preflightExit" }

    $resultLines = @($output -match '^\{')
    if ($resultLines.Count -ne 1) { throw 'FRESH_DBA_SCHEMA_PREFLIGHT_RESULT_MISSING' }
    $result = [System.Web.Script.Serialization.JavaScriptSerializer]::new().DeserializeObject($resultLines[0])
    if ($result['status'] -cne 'PASS' -or $result['mode'] -cne 'preflight' -or
        $result['database'] -cne $Manifest['database'] -or $result['uuid'] -cne $Manifest['uuid'] -or
        $result['schema_source'] -cne 'offline_snapshot' -or $result['snapshot_sha256'] -cne $Manifest['sha256'] -or
        $result['export_started_utc'] -cne $Manifest['export_started_utc'] -or
        $result['export_finished_utc'] -cne $Manifest['export_finished_utc'] -or
        $result['live_schema_verified'] -isnot [bool] -or $result['live_schema_verified'] -or
        $result['apply_ready'] -isnot [bool] -or $result['apply_ready']) {
        throw 'FRESH_DBA_SCHEMA_PREFLIGHT_EVIDENCE_REJECTED'
    }
    'FRESH_DBA_SCHEMA_PRECONDITIONS=PASS'
}

$before=Read-Identity 'Before export'
$started=[DateTime]::UtcNow.ToString('o')
$parent=[IO.Path]::Combine([Environment]::GetFolderPath('LocalApplicationData'), 'UPVanBooking\schema-snapshots')
$directory=[IO.Path]::Combine($parent, [Guid]::NewGuid().ToString('N'))
[void][IO.Directory]::CreateDirectory($parent)
New-PrivateSnapshotDirectory -Path $directory
$tables=@('users','drivers','roles','auth_login_buckets','vehicles','booking_requests','schema_migrations')
$schema=[IO.Path]::Combine($directory, 'schema.sql')
$Host.UI.WriteLine('Exporting definitions only. Enter the same DBA password at the hidden prompt.')
& $dump @common --compact --skip-opt --create-options --no-data --skip-triggers --skip-routines --skip-events --no-tablespaces --set-gtid-purged=OFF --skip-add-drop-table --skip-set-charset --skip-lock-tables --column-statistics=0 "--result-file=$schema" up_van_booking_test @tables
if($LASTEXITCODE -ne 0){throw 'SCHEMA_EXPORT_FAILED_DO_NOT_USE_PARTIAL_FILE'}
$after=Read-Identity 'After export'
if($before -cne $after){throw 'DBA_IDENTITY_CHANGED'}
Assert-PrivateSnapshotFile -Path $schema
$sha256=Get-SnapshotSha256 -Path $schema
$manifest=[ordered]@{
    format='upvan-schema-only-v1';host='127.0.0.1';port=3306;database='up_van_booking_test'
    uuid='77218fbd-d66d-11f1-9a0a-5c2886f057d5';version='8.4.9';export_started_utc=$started
    export_finished_utc=[DateTime]::UtcNow.ToString('o');sha256=$sha256;tables=$tables;live_schema_verified=$false
}
$manifestPath=[IO.Path]::Combine($directory, 'manifest.json')
Write-SnapshotManifest -Path $manifestPath -Manifest $manifest
Assert-PrivateSnapshotFile -Path $manifestPath

$snapshotCheck=[IO.Path]::Combine($PSScriptRoot, 'migration010-schema-snapshot-check.php')
$checkInfo=[Diagnostics.ProcessStartInfo]::new()
$checkInfo.FileName=$php
$checkInfo.WorkingDirectory=$runtimeRoot
$checkInfo.UseShellExecute=$false
$checkInfo.Arguments='-n -d extension_dir=.runtime/php84/ext -d extension=pdo_mysql -d extension=mbstring -d extension=openssl "' + $snapshotCheck + '" "' + $directory + '"'
$checkProcess=[Diagnostics.Process]::Start($checkInfo)
try {
    $checkProcess.WaitForExit()
    if($checkProcess.ExitCode -ne 0){throw 'SCHEMA_SNAPSHOT_REJECTED_DO_NOT_USE'}
} finally {
    $checkProcess.Dispose()
}
"SCHEMA_SNAPSHOT_DIRECTORY=$directory"
"SCHEMA_SHA256=$sha256"
if ($VerifyPreflight) {
    $wrapper = [IO.Path]::Combine($PSScriptRoot, 'migration-preflight-credential.ps1')
    Invoke-FreshSnapshotPreflight -Directory $directory -Manifest $manifest -Wrapper $wrapper
}
'SCHEMA_SNAPSHOT_IS_NOT_LIVE_APPLY_APPROVAL'
