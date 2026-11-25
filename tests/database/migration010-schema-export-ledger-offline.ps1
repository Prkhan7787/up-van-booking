#Requires -Version 5.1
#Requires -PSEdition Desktop
$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$root = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
$scripts = Join-Path $root 'scripts'
$exporter = Join-Path $scripts 'migration010-schema-export.ps1'
$tokens = $null
$errors = $null
$ast = [Management.Automation.Language.Parser]::ParseFile($exporter, [ref]$tokens, [ref]$errors)
if ($errors.Count) { throw ($errors.Message -join "`n") }

# Load only the exporter functions. Neither the MySQL prologue nor the real dump runs.
foreach ($name in @('Read-BackupInventory', 'Invoke-Pre010Backup')) {
    $functionAst = $ast.Find({
        param($node)
        $node -is [Management.Automation.Language.FunctionDefinitionAst] -and $node.Name -eq $name
    }, $false)
    . ([ScriptBlock]::Create($functionAst.Extent.Text))
}
. (Join-Path $scripts 'lib/migration011-apply-credentials.ps1')
$php = Resolve-Migration011EvaluatorRuntime $root
$backupChecker = Join-Path $scripts 'migration010-backup-check.php'
$baseline = @(& $php -n $backupChecker --expected-ledger)
if ($LASTEXITCODE -ne 0 -or $baseline.Count -ne 9) { throw 'Expected baseline fixture unavailable.' }
$applied011 = & $php -n $backupChecker --expected-microsoft-011
if ($LASTEXITCODE -ne 0) { throw 'Expected 011 plan fixture unavailable.' }
$failed011 = "LEDGER`t011_microsoft_identities`tb381d5b7e093398d072dfceb337e1f7b355a204459b4bc723a680cd40f595664`tfailed`t1`t1`tstatement_failed"
$baseTables = @('approval_decisions','audit_logs','auth_login_buckets','booking_notifications','booking_passengers',
    'booking_requests','booking_reviews','booking_status_history','drivers','microsoft_identities','roles','schema_migrations',
    'user_roles','users','vehicle_assignments','vehicles')
$schemaRows = @("IDENTITY`t77218fbd-d66d-11f1-9a0a-5c2886f057d5`t3306`tup_van_booking_test`t8.4.9`troot@localhost")
$schemaRows += @($baseTables | ForEach-Object { "OBJECT`t$_`tBASE TABLE`tInnoDB" })
$schemaRows += @("OBJECT`tcalendar_events`tVIEW`t-", "VIEW`t53454C4543542031`t726F6F74406C6F63616C686F7374`tDEFINER`tNONE`tutf8mb4`tutf8mb4_unicode_ci",
    "DEPENDENCY`tup_van_booking_test`tbooking_requests", "DEPENDENCY`tup_van_booking_test`tvehicle_assignments")
$calendarRead = "CALENDAR_READ`t0"
$baseEvidence = $schemaRows + $baseline
$expectedRows = $baseEvidence + $failed011 + $calendarRead
$common = @()
$backupGuard = 'offline'
$mysql = {
    if ('--batch' -cnotin $args -or '--raw' -cnotin $args -or '--skip-column-names' -cnotin $args -or
        -not ($args -clike "*SELECT 'LEDGER',version,checksum,status,completed_statements,total_statements,COALESCE(failure_code,'NULL')*")) {
        throw 'MySQL TSV contract changed.'
    }
    $global:LASTEXITCODE = 0
    $fixtureRows
}
$checks = 0
function Assert([bool]$Ok, [string]$Label) {
    if (!$Ok) { throw "FAIL: $Label" }
    $script:checks++
}
function Assert-Rejected([string[]]$Rows, [string]$Code, [string]$Label, [bool]$Reconciliation = $true) {
    $fixtureRows = $Rows
    try {
        $null = Read-BackupInventory 'offline fixture' $Reconciliation
    } catch {
        Assert ($_.Exception.Message -ceq $Code -or $_.Exception.Message.StartsWith($Code + ': ')) $Label
        return $_.Exception.Message
    }
    throw "Expected rejection: $Label"
}

$planFields = $applied011 -split "`t"
$oldPattern = '^' + [regex]::Escape($planFields[0]) + "`t" + [regex]::Escape($planFields[1]) +
    "`tfailed`t1`t1`t(statement_failed|postcondition_failed)$"
Assert ($failed011 -cnotmatch $oldPattern) 'a correct MySQL 8.4 TSV row fails the previous checksum-skipping regex'

# Pass fixture bytes through a real native process to exercise PS 5.1 line handling.
$file = [IO.Path]::GetTempFileName()
try {
    foreach ($ending in @("`r`n", "`n")) {
        [IO.File]::WriteAllText($file, ($expectedRows -join $ending) + $ending, [Text.Encoding]::ASCII)
        $fixtureRows = @(& $php -n -r 'readfile($argv[1]);' $file)
        Assert ($LASTEXITCODE -eq 0 -and $fixtureRows.Count -eq $expectedRows.Count -and $fixtureRows[-2] -ceq $failed011) 'native stdout strips line endings and preserves tabs'
        $accepted = Read-BackupInventory 'offline fixture' $true
        Assert ($accepted.HasFailed011 -and $accepted.Has011 -and $accepted.Tables.Count -eq 16 -and $accepted.Ledger.Count -eq 10) 'accepts exact failed 1/1 with 16 tables and calendar view'
    }
} finally {
    [IO.File]::Delete($file)
}

$fixtureRows = $baseEvidence + ($failed011 -creplace 'statement_failed$', 'postcondition_failed') + $calendarRead
Assert ((Read-BackupInventory 'offline fixture' $true).HasFailed011) 'accepts the other eligible failure code'
$fixtureRows = $baseEvidence + $applied011 + $calendarRead
$accepted = Read-BackupInventory 'offline fixture'
Assert ($accepted.Has011 -and !$accepted.HasFailed011 -and $accepted.Tables.Count -eq 16) 'ordinary backup accepts applied 011'
$fixtureRows = @($baseEvidence -cnotlike "OBJECT`tmicrosoft_identities`t*") + $calendarRead
$accepted = Read-BackupInventory 'offline fixture'
Assert (!$accepted.Has011 -and $accepted.Tables.Count -eq 15) 'ordinary backup accepts exact baseline'
$null = Assert-Rejected $fixtureRows 'BACKUP_011_FAILED_LEDGER_REQUIRED' 'reconciliation rejects absent 011'
$null = Assert-Rejected $expectedRows 'BACKUP_LEDGER_NOT_PRE010' 'ordinary backup rejects failed 011' $false

$swapped = $failed011 -split "`t"
$swapped = $swapped[0, 2, 1, 3, 4, 5, 6] -join "`t"
foreach ($bad in @(
    $applied011, $swapped,
    ($failed011 -creplace '[a-f0-9]{64}', ('0' * 64)),
    ($failed011 -creplace "failed`t1`t1", "failed`t0`t1"),
    ($failed011 -creplace "failed`t1`t1", "failed`t1`t2"),
    ($failed011 -creplace 'statement_failed$', 'NULL'),
    ($failed011 -creplace 'statement_failed$', ''),
    ($failed011 -creplace 'statement_failed$', 'Statement_Failed'),
    ($failed011 -creplace 'statement_failed$', 'statement\tfailed'),
    ($failed011 -creplace 'statement_failed$', "statement`tfailed"),
    ($failed011 + [char]0),
    ($failed011 + " extra"),
    (($failed011 -split "`t")[0..5] -join "`t")
)) {
    $null = Assert-Rejected ($baseEvidence + $bad + $calendarRead) 'BACKUP_011_FAILED_LEDGER_REJECTED' 'rejects nonmatching 011 TSV'
}
$message = Assert-Rejected ($baseEvidence + ($failed011 -creplace 'statement_failed$', 'NULL') + $calendarRead) 'BACKUP_011_FAILED_LEDGER_REJECTED' 'renders expected and actual safely'
Assert ($message -clike "*'statement_failed or postcondition_failed'. Actual: *'NULL'") 'reports actual NULL for a failed row'
$wrongBaseline = @($baseline)
$wrongBaseline[0] = $wrongBaseline[0] -creplace '[a-f0-9]{64}', ('0' * 64)
$null = Assert-Rejected ($schemaRows + $wrongBaseline + $failed011 + $calendarRead) 'BACKUP_LEDGER_NOT_PRE010' 'rejects baseline checksum mismatch'
$driver010 = "LEDGER`t010_driver_auth`t$('0' * 64)`tapplied`t3`t3`tNULL"
$null = Assert-Rejected ($baseEvidence + $driver010 + $failed011 + $calendarRead) 'BACKUP_LEDGER_NOT_PRE010' 'rejects 010 in reconciliation'
$null = Assert-Rejected @($expectedRows -cnotlike "OBJECT`tmicrosoft_identities`t*") 'BACKUP_CALENDAR_INVENTORY_REJECTED' 'rejects missing 16th table'
$null = Assert-Rejected @($expectedRows -cnotlike "OBJECT`tcalendar_events`t*") 'BACKUP_CALENDAR_INVENTORY_REJECTED' 'rejects missing calendar view'

# Stop after the real entry function accepts the ledger, before creating files.
function Get-CalendarRestoreSql { throw 'MOCK_REACHED_VIEW_PROCESSING' }
$fixtureRows = $expectedRows
try {
    Invoke-Pre010Backup -Reconciliation $true
    throw 'Mock stop was not reached.'
} catch {
    Assert ($_.Exception.Message -ceq 'MOCK_REACHED_VIEW_PROCESSING') 'export entry no longer compares the full 10-row ledger to the 9-row plan'
}
Write-Output "PASS: $checks Migration 010/011 backup inventory and exporter checks (Windows PowerShell 5.1, offline)."
