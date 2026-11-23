$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest
if ($PSVersionTable.PSVersion.Major -ne 5) { throw 'Run with Windows PowerShell 5.1.' }
$path = Join-Path (Split-Path -Parent $PSScriptRoot) 'scripts\backup-and-preflight-007.ps1'
$tokens = $null; $errors = $null
$ast = [Management.Automation.Language.Parser]::ParseFile($path, [ref]$tokens, [ref]$errors)
if ($errors.Count) { throw 'Backup script syntax failed.' }
$fn = $ast.Find({param($n) $n -is [Management.Automation.Language.FunctionDefinitionAst] -and $n.Name -eq 'Test-CalendarViewCheck'}, $true)
. ([scriptblock]::Create($fn.Extent.Text))
$database = 'up_van_booking_test'
$qualified = "${database}.calendar_events`tcheck`tstatus`tOK"
$oldNames = @($database + '.calendar_events', 'calendar_events')
if ($oldNames.Count -ne 1 -or (($qualified -split "`t")[0] -in $oldNames)) { throw 'Legacy parser bug not reproduced.' }
$cases = @(
    @{ Rows = @($qualified); Status = 'PASS'; Code = 'VIEW_CHECK_OK' },
    @{ Rows = @("calendar_events`tcheck`tstatus`tOK"); Status = 'PASS'; Code = 'VIEW_CHECK_OK' },
    @{ Rows = @(); Status = 'FAIL'; Code = 'VIEW_CHECK_NO_ROWS' },
    @{ Rows = @("${database}.calendar_events`tcheck`terror`tView references invalid columns or definer lacks rights"); Status = 'FAIL'; Code = 'VIEW_CHECK_NOT_OK' },
    @{ Rows = @("${database}.calendar_events`tcheck`twarning`tPermission denied", $qualified); Status = 'FAIL'; Code = 'VIEW_CHECK_NOT_OK' },
    @{ Rows = @("${database}.calendar_events`tcheck`tstatus`tCorrupt"); Status = 'FAIL'; Code = 'VIEW_CHECK_NOT_OK' },
    @{ Rows = @("other.calendar_events`tcheck`tstatus`tOK"); Status = 'FAIL'; Code = 'VIEW_CHECK_UNEXPECTED_RESULT' },
    @{ Rows = @("${database}.calendar_events`trepair`tstatus`tOK"); Status = 'FAIL'; Code = 'VIEW_CHECK_UNEXPECTED_RESULT' },
    @{ Rows = @('Table Op Msg_type Msg_text'); Status = 'FAIL'; Code = 'VIEW_CHECK_OUTPUT_FORMAT' },
    @{ Rows = @("calendar_events`tcheck`tstatus"); Status = 'FAIL'; Code = 'VIEW_CHECK_OUTPUT_FORMAT' }
)
foreach ($case in $cases) {
    $result = Test-CalendarViewCheck -Rows $case.Rows -Database $database
    if ($result.Status -ne $case.Status -or $result.Code -ne $case.Code) { throw "Unexpected parser result: $($result.Code)" }
}
$diagnostic = Test-CalendarViewCheck -Rows @("calendar_events`tcheck`terror`tView is invalid") -Database $database
if ($diagnostic.Records[0].Msg_type -ne 'error' -or $diagnostic.Records[0].Msg_text -ne 'View is invalid') { throw 'Native metadata diagnostics changed.' }
Write-Output "PASS: legacy array-expression bug reproduced; $($cases.Count) parser cases; Msg_type/Msg_text preserved; PS 5.1 syntax. No database connection."
