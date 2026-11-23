param(
    [string]$LoginPath = 'upvan3306-backup',
    [ValidateSet('007','008')][string]$MigrationVersion = '007',
    [switch]$ViewCheckOnly
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest
if ($LoginPath -notmatch '\A[A-Za-z0-9_-]{1,32}\z') { throw 'Login path name is invalid.' }

$projectRoot = Split-Path -Parent $PSScriptRoot
$mysqlBin = 'C:\Program Files\MySQL\MySQL Server 8.4\bin'
$mysqlExe = Join-Path $mysqlBin 'mysql.exe'
$dumpExe = Join-Path $mysqlBin 'mysqldump.exe'
$migration007Hash = '0622c9006ae5be29c583082618e3ec2ef130bd44f39d8373038634d8cc6b03e8'
$migration008Hash = '33b6ecc03325835b1908271fa9086afdc63a4a488b18824758f12b15b5015233'
$migrationHash = if ($MigrationVersion -eq '007') { $migration007Hash } else { $migration008Hash }
$draftName = if ($MigrationVersion -eq '007') { '007_vehicle_fuel_details.sql' } else { '008_partial_a4_request_snapshot.sql' }
$draftPath = Join-Path $projectRoot (Join-Path 'database\drafts' $draftName)
if ((Get-FileHash -LiteralPath $draftPath -Algorithm SHA256).Hash.ToLowerInvariant() -ne $migrationHash) { throw "Migration $MigrationVersion draft checksum mismatch." }
$database = 'up_van_booking_test'
$expectedPort = 3306
$expectedUuid = '77218fbd-d66d-11f1-9a0a-5c2886f057d5'
$expectedDatadir = 'C:/Users/User/mysql-data'
$baseTables = @(
    'approval_decisions', 'audit_logs', 'auth_login_buckets', 'booking_notifications',
    'booking_passengers', 'booking_requests', 'booking_reviews', 'booking_status_history',
    'drivers', 'roles', 'schema_migrations', 'user_roles', 'users', 'vehicle_assignments', 'vehicles'
)

function Assert-ExitCode([string]$Step, [int]$Code) {
    if ($Code -ne 0) { throw "$Step failed (exit=$Code). Stop; no migration was applied." }
}

function Invoke-MySql([string]$Sql, [string]$Step = 'READ_QUERY') {
    if ($Step -notmatch '\A[A-Z0-9_]+\z' -or $LoginPath -notmatch '\A[A-Za-z0-9_-]{1,32}\z' -or
        $database -ne 'up_van_booking_test' -or $expectedPort -ne 3306) { throw 'MySQL client arguments rejected.' }
    # PS 5.1 native argument serialization can strip embedded SQL quotes.
    # Send SQL as UTF-8 stdin bytes, never through the Windows command line.
    $mysqlArgs = @(
        "--login-path=$LoginPath", '--protocol=TCP', '--host=127.0.0.1', "--port=$expectedPort",
        "--database=$database", '--default-character-set=utf8mb4', '--batch', '--raw', '--binary-mode', '--skip-column-names'
    )
    $info = New-Object System.Diagnostics.ProcessStartInfo
    $info.FileName = $mysqlExe
    $info.Arguments = $mysqlArgs -join ' '
    $info.WorkingDirectory = Split-Path -Parent $mysqlExe
    $info.UseShellExecute = $false
    $info.CreateNoWindow = $true
    $info.RedirectStandardInput = $true
    $info.RedirectStandardOutput = $true
    $info.RedirectStandardError = $true
    $info.StandardOutputEncoding = [Text.Encoding]::UTF8
    $info.StandardErrorEncoding = [Text.Encoding]::UTF8
    $process = New-Object System.Diagnostics.Process
    $process.StartInfo = $info
    try {
        [void]$process.Start()
        $stdoutTask = $process.StandardOutput.ReadToEndAsync()
        $stderrTask = $process.StandardError.ReadToEndAsync()
        $bytes = [Text.Encoding]::UTF8.GetBytes($Sql.TrimEnd() + ";`n")
        $process.StandardInput.BaseStream.Write($bytes, 0, $bytes.Length)
        $process.StandardInput.Close()
        $process.WaitForExit()
        $output = $stdoutTask.GetAwaiter().GetResult()
        $errorText = $stderrTask.GetAwaiter().GetResult()
        if ($process.ExitCode -ne 0) {
            $native = 'UNKNOWN'; $state = 'UNKNOWN'
            if ($errorText -match 'ERROR\s+(\d+)(?:\s+\(([A-Z0-9]{5})\))?') {
                $native = $Matches[1]
                if ($Matches.ContainsKey(2) -and $Matches[2]) { $state = $Matches[2] }
            }
            throw "MYSQL_READ_FAILED step=$Step exit=$($process.ExitCode) native=$native sqlstate=$state"
        }
        if ($output.Length -gt 0) { return ($output.TrimEnd([char[]]"`r`n") -split '\r?\n') }
    } catch {
        if ($_.Exception.Message -like 'MYSQL_READ_FAILED *') { throw }
        throw "MYSQL_CLIENT_FAILED step=$Step error_type=$($_.Exception.GetType().Name)"
    } finally { $process.Dispose() }
}

function Set-PrivateAcl([string]$Path, [bool]$IsDirectory) {
    $item = Get-Item -LiteralPath $Path -Force -ErrorAction Stop
    if ($item.PSIsContainer -ne $IsDirectory -or ($item.Attributes -band [IO.FileAttributes]::ReparsePoint)) {
        throw 'ACL object type rejected.'
    }
    # Read and persist Access (DACL) only. Do not load or write SACL, Owner or Group.
    # Set-Acl on the provider descriptor can request SeSecurityPrivilege on Windows.
    $section = [System.Security.AccessControl.AccessControlSections]::Access
    if ($IsDirectory) { $acl = [IO.Directory]::GetAccessControl($item.FullName, $section) }
    else { $acl = [IO.File]::GetAccessControl($item.FullName, $section) }
    $acl.SetAccessRuleProtection($true, $false)
    foreach ($rule in @($acl.Access)) { [void]$acl.RemoveAccessRuleAll($rule) }
    $currentSid = [System.Security.Principal.WindowsIdentity]::GetCurrent().User
    $systemSid = New-Object System.Security.Principal.SecurityIdentifier('S-1-5-18')
    if ($IsDirectory) {
        $inherit = [System.Security.AccessControl.InheritanceFlags]::ContainerInherit -bor [System.Security.AccessControl.InheritanceFlags]::ObjectInherit
        $propagate = [System.Security.AccessControl.PropagationFlags]::None
        $userRule = New-Object System.Security.AccessControl.FileSystemAccessRule($currentSid, 'FullControl', $inherit, $propagate, 'Allow')
        $systemRule = New-Object System.Security.AccessControl.FileSystemAccessRule($systemSid, 'FullControl', $inherit, $propagate, 'Allow')
    } else {
        $userRule = New-Object System.Security.AccessControl.FileSystemAccessRule($currentSid, 'FullControl', 'Allow')
        $systemRule = New-Object System.Security.AccessControl.FileSystemAccessRule($systemSid, 'FullControl', 'Allow')
    }
    $acl.SetAccessRule($userRule)
    $acl.SetAccessRule($systemRule)
    if ($IsDirectory) { [IO.Directory]::SetAccessControl($item.FullName, $acl) }
    else { [IO.File]::SetAccessControl($item.FullName, $acl) }

    if ($IsDirectory) { $check = [IO.Directory]::GetAccessControl($item.FullName, $section) }
    else { $check = [IO.File]::GetAccessControl($item.FullName, $section) }
    if (-not $check.AreAccessRulesProtected) { throw "ACL verification failed: $Path" }
    $allowedSids = @($check.Access | ForEach-Object {
        try { $_.IdentityReference.Translate([System.Security.Principal.SecurityIdentifier]).Value } catch { 'UNRESOLVED' }
    } | Sort-Object -Unique)
    $expectedSids = @($currentSid.Value, $systemSid.Value) | Sort-Object -Unique
    if (($allowedSids -join ',') -ne ($expectedSids -join ',')) { throw "ACL principal verification failed: $Path" }
    $rules = @($check.GetAccessRules($true, $true, [System.Security.Principal.SecurityIdentifier]))
    $expectedInheritance = if ($IsDirectory) { $inherit } else { [System.Security.AccessControl.InheritanceFlags]::None }
    if ($rules.Count -ne 2) { throw 'ACL rule count verification failed.' }
    foreach ($rule in $rules) {
        if ($rule.IsInherited -or $rule.AccessControlType -ne 'Allow' -or
            $rule.FileSystemRights -ne 'FullControl' -or $rule.InheritanceFlags -ne $expectedInheritance -or
            $rule.PropagationFlags -ne 'None') { throw 'ACL permissions verification failed.' }
    }
}

function Get-VehicleFingerprint {
    $rows = @(Invoke-MySql -Step 'VEHICLE_FINGERPRINT' -Sql "SELECT HEX(CAST(JSON_ARRAY(id,registration_number,registration_province,label,passenger_capacity,is_active,created_by,updated_by,created_at,updated_at) AS CHAR CHARACTER SET utf8mb4)) FROM vehicles ORDER BY id")
    $canonical = ($rows -join "`n") + "`n"
    $sha = [System.Security.Cryptography.SHA256]::Create()
    try { $bytes = [System.Text.Encoding]::UTF8.GetBytes($canonical); $hash = ([BitConverter]::ToString($sha.ComputeHash($bytes))).Replace('-', '').ToLowerInvariant() }
    finally { $sha.Dispose() }
    return [pscustomobject]@{ Count = $rows.Count; Sha256 = $hash }
}

function Convert-HexToUtf8([string]$Hex) {
    if ([string]::IsNullOrWhiteSpace($Hex) -or $Hex -notmatch '\A(?:[0-9A-Fa-f]{2})+\z') { throw 'Invalid hex metadata.' }
    $bytes = New-Object byte[] ($Hex.Length / 2)
    for ($i = 0; $i -lt $bytes.Length; $i++) { $bytes[$i] = [Convert]::ToByte($Hex.Substring($i * 2, 2), 16) }
    return [System.Text.Encoding]::UTF8.GetString($bytes)
}

function Test-CalendarViewCheck([string[]]$Rows, [string]$Database) {
    $records = @()
    $code = 'VIEW_CHECK_OK'
    if ($null -eq $Rows -or $Rows.Count -eq 0) { $code = 'VIEW_CHECK_NO_ROWS' }
    # Parenthesize concatenation or interpolate: PS 5.1 otherwise adds an array
    # to the database string, producing ONE combined expected table name.
    $expectedNames = @("${Database}.calendar_events", 'calendar_events')
    foreach ($line in $Rows) {
        $parts = ([string]$line) -split "`t"
        if ($parts.Count -ne 4) {
            return [pscustomobject]@{ Status = 'FAIL'; Code = 'VIEW_CHECK_OUTPUT_FORMAT'; Records = $records; ColumnCount = $parts.Count }
        }
        if ($parts[0] -notin $expectedNames -or $parts[1] -ne 'check') {
            return [pscustomobject]@{ Status = 'FAIL'; Code = 'VIEW_CHECK_UNEXPECTED_RESULT'; Records = $records }
        }
        # CHECK TABLE returns metadata diagnostics, never application row data.
        # JSON encoding at the call site keeps tabs/control characters escaped.
        $records += [pscustomobject]@{ Table = $parts[0]; Op = $parts[1]; Msg_type = $parts[2]; Msg_text = $parts[3] }
        if ($parts[2] -ne 'status' -or $parts[3] -ne 'OK') { $code = 'VIEW_CHECK_NOT_OK' }
    }
    return [pscustomobject]@{ Status = $(if ($code -eq 'VIEW_CHECK_OK') { 'PASS' } else { 'FAIL' }); Code = $code; Records = $records }
}

if (-not (Test-Path -LiteralPath $mysqlExe -PathType Leaf) -or -not (Test-Path -LiteralPath $dumpExe -PathType Leaf)) {
    throw 'MySQL 8.4 client tools were not found at the pinned installation path.'
}
if (-not (Test-Path -LiteralPath (Join-Path $env:APPDATA 'MySQL\.mylogin.cnf') -PathType Leaf)) {
    throw 'MySQL login path is not prepared. Run the mysql_config_editor setup command first.'
}

# Keep client credentials private; MySQL clients read this file for the named login path.
Set-PrivateAcl (Join-Path $env:APPDATA 'MySQL\.mylogin.cnf') $false

$identityRows = @(Invoke-MySql -Step 'IDENTITY' -Sql 'SELECT @@port,@@server_uuid,REPLACE(@@datadir,CHAR(92),CHAR(47)),VERSION(),DATABASE()')
if ($identityRows.Count -ne 1) { throw 'Could not verify one target identity row.' }
$identity = $identityRows[0] -split "`t"
$actualDatadir = $identity[2].TrimEnd('/')
if ([int]$identity[0] -ne $expectedPort -or $identity[1] -ne $expectedUuid -or
    $actualDatadir -ine $expectedDatadir -or $identity[4] -ne $database -or $identity[3] -notmatch '^8\.4\.') {
    throw 'Target identity mismatch. Backup and preflight stopped.'
}
Write-Output "IDENTITY=PASS port=$($identity[0]) uuid=$($identity[1]) database=$($identity[4]) version=$($identity[3])"

$tableRows = @(Invoke-MySql -Step 'TABLE_INVENTORY' -Sql "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME")
$actualTables = @($tableRows | ForEach-Object { ([string]$_).Trim() } | Where-Object { $_ })
if (($actualTables -join ',') -ne (($baseTables | Sort-Object) -join ',')) {
    throw 'Base table inventory did not match the pinned 15-table list. Backup stopped.'
}
$viewType = @(Invoke-MySql -Step 'VIEW_TYPE' -Sql "SELECT TABLE_TYPE FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='calendar_events'")
if ($viewType.Count -ne 1 -or $viewType[0] -ne 'VIEW') { throw 'calendar_events is not the expected view.' }

$checkRows = @(Invoke-MySql -Step 'VIEW_CHECK' -Sql 'CHECK TABLE calendar_events')
$viewCheck = Test-CalendarViewCheck -Rows $checkRows -Database $database
foreach ($record in $viewCheck.Records) {
    Write-Output ($record | ConvertTo-Json -Compress)
}
if ($viewCheck.Status -ne 'PASS') { throw "$($viewCheck.Code): CHECK TABLE calendar_events did not pass. No dump was started." }
Write-Output 'VIEW_CHECK=PASS'
if ($ViewCheckOnly) { Write-Output 'VIEW_CHECK_ONLY_COMPLETE; no dump or Migration 007 preflight was started.'; exit 0 }

# Build an executable CREATE VIEW statement from Information Schema metadata (definition is hex-encoded over the CLI).
$viewRows = @(Invoke-MySql -Step 'VIEW_DEFINITION' -Sql "SELECT HEX(VIEW_DEFINITION),HEX(SUBSTRING_INDEX(DEFINER,'@',1)),HEX(SUBSTRING_INDEX(DEFINER,'@',-1)),SECURITY_TYPE,CHECK_OPTION,CHARACTER_SET_CLIENT,COLLATION_CONNECTION FROM INFORMATION_SCHEMA.VIEWS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='calendar_events'")
if ($viewRows.Count -ne 1) { throw 'Could not retrieve one complete calendar_events definition.' }
$viewParts = $viewRows[0] -split "`t"
if ($viewParts.Count -ne 7 -or [string]::IsNullOrWhiteSpace($viewParts[0])) { throw 'calendar_events definition metadata is incomplete.' }
try {
    $viewDefinition = Convert-HexToUtf8 $viewParts[0]
    $definerUser = Convert-HexToUtf8 $viewParts[1]
    $definerHost = Convert-HexToUtf8 $viewParts[2]
} catch { throw 'Could not decode view metadata. No dump was started.' }
if ($viewDefinition -notmatch '^\s*SELECT\b' -and $viewDefinition -notmatch '^\s*WITH\b') { throw 'View definition did not look like a SELECT query.' }
if ($viewParts[5] -notmatch '\A[A-Za-z0-9_]+\z' -or $viewParts[6] -notmatch '\A[A-Za-z0-9_]+\z') { throw 'View character set metadata is invalid.' }
$quotedUser = '`' + $definerUser.Replace('`', '``') + '`'
$quotedHost = '`' + $definerHost.Replace('`', '``') + '`'
$security = if ($viewParts[3] -eq 'INVOKER') { 'INVOKER' } elseif ($viewParts[3] -eq 'DEFINER') { 'DEFINER' } else { throw 'Unsupported view security type.' }
$viewSql = "SET NAMES $($viewParts[5]) COLLATE $($viewParts[6]);`r`nCREATE ALGORITHM=UNDEFINED DEFINER=$quotedUser@$quotedHost SQL SECURITY $security VIEW ``calendar_events`` AS`r`n$viewDefinition"
if ($viewParts[4] -eq 'LOCAL') { $viewSql += "`r`nWITH LOCAL CHECK OPTION" }
elseif ($viewParts[4] -eq 'CASCADED') { $viewSql += "`r`nWITH CASCADED CHECK OPTION" }
elseif ($viewParts[4] -ne 'NONE') { throw 'Unsupported view CHECK OPTION.' }
$viewSql += ";`r`n"

$backupRoot = Join-Path $env:USERPROFILE ("mysql-3306-pre$MigrationVersion-" + [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $backupRoot | Out-Null
Set-PrivateAcl $backupRoot $true
$dumpPath = Join-Path $backupRoot ($database + '_base_tables.sql')
$viewPath = Join-Path $backupRoot 'calendar_events_view_restore.sql'
$manifestPath = Join-Path $backupRoot 'backup-manifest.json'
$utf8NoBom = [System.Text.UTF8Encoding]::new($false)
[System.IO.File]::WriteAllText($viewPath, $viewSql, $utf8NoBom)

$before = Get-VehicleFingerprint
$dumpArgs = @(
    "--login-path=$LoginPath", '--protocol=TCP', '--host=127.0.0.1', "--port=$expectedPort",
    '--default-character-set=utf8mb4', '--lock-tables', '--skip-single-transaction', '--hex-blob', '--triggers', '--no-tablespaces',
    $database
) + $baseTables
$stderrPath = Join-Path $backupRoot 'mysqldump.stderr.txt'
$dumpProcess = Start-Process -FilePath $dumpExe -ArgumentList $dumpArgs -WorkingDirectory $mysqlBin -RedirectStandardOutput $dumpPath -RedirectStandardError $stderrPath -Wait -PassThru -NoNewWindow
$dumpExit = $dumpProcess.ExitCode
if ($dumpExit -ne 0) {
    Remove-Item -LiteralPath $dumpPath -Force -ErrorAction SilentlyContinue
    throw "mysqldump failed (exit=$dumpExit); partial dump discarded."
}
if ((Test-Path -LiteralPath $stderrPath) -and (Get-Item -LiteralPath $stderrPath).Length -eq 0) { Remove-Item -LiteralPath $stderrPath -Force }
$after = Get-VehicleFingerprint
if ($before.Count -ne $after.Count -or $before.Sha256 -ne $after.Sha256) {
    Remove-Item -LiteralPath $dumpPath -Force -ErrorAction SilentlyContinue
    throw 'Vehicle fingerprint changed around the locked dump; partial backup rejected.'
}
$createdTables = @(Select-String -LiteralPath $dumpPath -Pattern '^CREATE TABLE `([^`]+)`' | ForEach-Object { $_.Matches[0].Groups[1].Value } | Sort-Object)
if (($createdTables -join ',') -ne (($baseTables | Sort-Object) -join ',')) {
    Remove-Item -LiteralPath $dumpPath -Force -ErrorAction SilentlyContinue
    throw 'Dump table list did not match the expected 15 tables; partial backup rejected.'
}
$dumpItem = Get-Item -LiteralPath $dumpPath
if ($dumpItem.Length -le 0) { throw 'Dump file is empty.' }
$dumpHash = (Get-FileHash -LiteralPath $dumpPath -Algorithm SHA256).Hash.ToLowerInvariant()
Set-PrivateAcl $viewPath $false
$manifest = [ordered]@{
    database = $database; port = $expectedPort; server_uuid = $expectedUuid; datadir = $expectedDatadir
    backup_scope = '15 base tables; calendar_events view excluded and saved as executable CREATE VIEW SQL'
    tables = $baseTables; bytes = $dumpItem.Length; sha256 = $dumpHash
    vehicles = @{ count = $after.Count; fingerprint_sha256 = $after.Sha256; fields = @('id','registration_number','registration_province','label','passenger_capacity','is_active','created_by','updated_by','created_at','updated_at') }
    created_utc = [DateTime]::UtcNow.ToString('o')
}
[System.IO.File]::WriteAllText($manifestPath, ($manifest | ConvertTo-Json -Depth 5), $utf8NoBom)
Set-PrivateAcl $manifestPath $false
Set-PrivateAcl $dumpPath $false

Write-Output "BACKUP=PASS path=$dumpPath bytes=$($dumpItem.Length) sha256=$dumpHash"
Write-Output "VIEW_RESTORE_SQL=PASS path=$viewPath (not applied)"
Write-Output "VEHICLES=PASS count=$($after.Count) fingerprint_sha256=$($after.Sha256) fields=10"
Write-Output "TABLES=PASS count=$($createdTables.Count)"

$phpExe = Join-Path $projectRoot '.runtime\php84\php.exe'
$runner = Join-Path $projectRoot "bin\db-migration-$MigrationVersion.php"
if (-not (Test-Path -LiteralPath $phpExe -PathType Leaf) -or -not (Test-Path -LiteralPath $runner -PathType Leaf)) { throw "Pinned PHP runtime or Migration $MigrationVersion runner was not found." }
Push-Location -LiteralPath $projectRoot
try {
    $phpArgs = @('-n','-d','extension_dir=.runtime/php84/ext','-d','extension=pdo_mysql','-d','extension=mbstring','-d','extension=openssl',$runner,'--preflight',"--backup=$dumpPath","--backup-sha256=$dumpHash")
    & $phpExe @phpArgs
    $preflightExit = $LASTEXITCODE
} finally { Pop-Location }
Write-Output "PREFLIGHT_EXIT_CODE=$preflightExit"
exit $preflightExit
