param([switch]$ChildFixture)
Set-StrictMode -Version Latest
$ErrorActionPreference='Stop'
$root=(Resolve-Path (Join-Path $PSScriptRoot '..')).Path
. (Join-Path $root 'scripts\lib\recovery-diagnostics.ps1')
if ($ChildFixture) {
    ConvertFrom-UpVanRecoveryDiagnostic '{"status":"FAIL","step":"PDO","code":"PDO_ERROR","sqlstate":"HY000","native_code":2002}' 1 | ConvertTo-Json -Compress
    exit 1
}
$cases=@(
    @{Name='missing optional properties';Json='{"status":"PASS","step":"PREFLIGHT","code":"TARGETS_AND_GRANTS_VALID"}';Exit=0;Code='TARGETS_AND_GRANTS_VALID'},
    @{Name='explicit null properties';Json='{"status":"PASS","step":"PREFLIGHT","code":"OK","account":null,"sqlstate":null,"native_code":null}';Exit=0;Code='OK'},
    @{Name='preflight extra fields';Json='{"status":"PASS","step":"PREFLIGHT","code":"OK","accounts":"BOTH_PRESENT","grants":"PASS","target":"up_van_booking_test"}';Exit=0;Code='OK'},
    @{Name='missing required property';Json='{"status":"FAIL","step":"PDO"}';Exit=1;Code='INVALID_DIAGNOSTIC_SCHEMA'},
    @{Name='malformed JSON';Json='{broken';Exit=1;Code='MALFORMED_DIAGNOSTIC_JSON'},
    @{Name='empty output';Json='';Exit=1;Code='EMPTY_DIAGNOSTIC_OUTPUT'},
    @{Name='unexpected output schema';Json='{"status":"PASS","step":"OK","code":"OK","unexpected":"synthetic-sensitive-value"}';Exit=0;Code='INVALID_DIAGNOSTIC_SCHEMA'},
    @{Name='array schema';Json='[]';Exit=0;Code='INVALID_DIAGNOSTIC_SCHEMA'},
    @{Name='invalid account';Json='{"status":"PASS","step":"OK","code":"OK","account":"root"}';Exit=0;Code='INVALID_DIAGNOSTIC_SCHEMA'},
    @{Name='unsafe code';Json='{"status":"FAIL","step":"PDO","code":"synthetic-sensitive-value"}';Exit=1;Code='INVALID_DIAGNOSTIC_SCHEMA'},
    @{Name='invalid native code type';Json='{"status":"FAIL","step":"PDO","code":"PDO_ERROR","native_code":"secret"}';Exit=1;Code='INVALID_DIAGNOSTIC_SCHEMA'},
    @{Name='nonzero process with PASS';Json='{"status":"PASS","step":"OK","code":"OK"}';Exit=1;Code='PROCESS_EXIT_MISMATCH'}
)
foreach ($case in $cases) {
    $result=ConvertFrom-UpVanRecoveryDiagnostic -Output $case.Json -ExitCode $case.Exit
    if ($result.Code -cne $case.Code) { throw "Diagnostic regression failed: $($case.Name)" }
    # Every result is safe for consumers running StrictMode, even on invalid input.
    $null=$result.Account; $null=$result.SqlState; $null=$result.NativeCode
    if (($result | ConvertTo-Json -Compress).Contains('synthetic-sensitive-value')) { throw 'Diagnostic leaked synthetic input.' }
    Write-Output "PASS: $($case.Name)"
}
$outerOutput=& powershell.exe -NoProfile -ExecutionPolicy Bypass -File $PSCommandPath -ChildFixture | Out-String
$outerExit=$LASTEXITCODE
$outer=$outerOutput | ConvertFrom-Json
if ($outerExit -ne 1 -or $outer.Step -ne 'PDO' -or $outer.SqlState -ne 'HY000' -or $outer.NativeCode -ne 2002 -or $null -ne $outer.Account) { throw 'Child PowerShell diagnostics or exit code were lost.' }
Write-Output 'PASS: missing account, safe PDO details and exit code survive child PowerShell'
Write-Output 'PASS: 13 diagnostic regression checks; no database or real .env accessed.'
