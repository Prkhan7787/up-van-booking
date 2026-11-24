param(
    [switch]$Probe,
    [switch]$MockClient,
    [string]$ResultPath,
    [string]$MockUser,
    [string]$Scenario
)

$ErrorActionPreference = 'Stop'
if ($MockClient) {
    if ($Probe) {
        Add-Type @'
using System.Runtime.InteropServices;
public static class MockConsole {
    [DllImport("msvcrt.dll", CallingConvention = CallingConvention.Cdecl)]
    public static extern int _getch();
    [DllImport("msvcrt.dll", CallingConvention = CallingConvention.Cdecl)]
    public static extern int _cputs(string message);
}
'@
        [void][MockConsole]::_cputs('Offline mock only. Press Enter. Do not type a password: ')
        while (($key = [MockConsole]::_getch()) -ne 13) {
            if ($key -lt 0) {
                [Console]::Error.WriteLine('Mock console input unavailable')
                exit 1
            }
        }
        [void][MockConsole]::_cputs([Environment]::NewLine)
    }
    while ($line = [Console]::ReadLine()) {
        switch -Regex ($line) {
            '^CHECK$' { [Console]::WriteLine('MOCK_OK') }
            '^FAIL$' {
                [Console]::Error.WriteLine('ERROR 1045 (28000): mock private detail')
                exit 1
            }
            "^SELECT '(UPVAN_END_[0-9a-f]+)';$" { [Console]::WriteLine($Matches[1]) }
            '^SELECT CONCAT\(@@port' {
                [Console]::WriteLine('3306|77218fbd-d66d-11f1-9a0a-5c2886f057d5|up_van_booking_test|8.4.9|root@localhost')
                [Console]::WriteLine($(if ($Scenario -eq 'AlreadyUnlocked') { 'N|1' } else { 'Y|1' }))
            }
            'ACCOUNT UNLOCK;' {
                if ($Scenario -ne 'MissingUnlockConfirmation') { [Console]::WriteLine('UNLOCKED|N') }
            }
            'ACCOUNT LOCK;' {
                if ($Scenario -eq 'LockFailure') {
                    [Console]::Error.WriteLine('ERROR 1142 (42000): mock private detail')
                    exit 1
                }
                [Console]::WriteLine('LOCKED|Y')
            }
            "^SELECT CONCAT\('IDENTITY" {
                if ($Scenario -eq 'LoginFailure' -and $MockUser -eq 'up_van_migration010_apply') {
                    [Console]::Error.WriteLine('ERROR 1045 (28000): mock private detail')
                    exit 1
                }
                [Console]::WriteLine('IDENTITY|up_van_migration010_apply@127.0.0.1|up_van_booking_test|8.4.9|77218fbd-d66d-11f1-9a0a-5c2886f057d5')
            }
            '^SHOW GRANTS' {
                [Console]::WriteLine('GRANT USAGE ON *.* TO mock')
                foreach ($table in @('users','drivers','roles','schema_migrations','auth_login_buckets','vehicles','booking_requests')) {
                    [Console]::WriteLine(('GRANT SELECT ON {0}up_van_booking_test{0}.{0}{1}{0} TO mock' -f [char]96,$table))
                }
                [Console]::WriteLine('NONE')
            }
            "^SELECT CONCAT\('META" { [Console]::WriteLine('META|1') }
        }
    }
    exit 0
}

$script = Join-Path $PSScriptRoot '..\..\scripts\migration010-apply-account-diagnostic.ps1'
. $script
if ($Probe) {
    $client = Start-Migration010ConsoleClient (Join-Path $PSHOME 'powershell.exe') ('-NoProfile -ExecutionPolicy Bypass -File "' + $PSCommandPath + '" -MockClient -Probe')
    $process = $client.Process
    try {
        $stdout = $process.StandardOutput.ReadToEndAsync()
        $process.StandardInput.WriteLine('CHECK')
        $process.StandardInput.WriteLine('FAIL')
        $process.StandardInput.Close()
        if (!$process.WaitForExit(120000)) { $process.Kill(); throw 'MOCK_PROMPT_TIMEOUT' }
        $passed = $process.ExitCode -eq 1 -and $stdout.Result.Trim() -ceq 'MOCK_OK' -and $client.Stderr.Result -match '^ERROR 1045 \(28000\)'
        if (!$passed) { throw 'MOCK_PROMPT_FAILED' }
        [IO.File]::WriteAllText($ResultPath, 'CONSOLE_PROMPT_AND_CAPTURE=PASS')
    } catch {
        [IO.File]::WriteAllText($ResultPath, 'CONSOLE_PROMPT_AND_CAPTURE=FAIL')
        throw
    } finally { $process.Dispose() }
    return
}
$output = & powershell.exe -NoProfile -ExecutionPolicy Bypass -File $script -OfflineTest 2>&1
if ($LASTEXITCODE -ne 0 -or ($output -join "`n") -notmatch 'Offline diagnostic output and JSON checks PASS') {
    throw 'MIGRATION_010_ACCOUNT_DIAGNOSTIC_OFFLINE_FAILED'
}
function Start-Migration010Mysql([string]$User) {
    if ($Scenario -eq 'ApplyStartException' -and $User -ne 'root') { throw 'MOCK_START_FAILURE' }
    $arguments = '-NoProfile -ExecutionPolicy Bypass -File "' + $PSCommandPath + '" -MockClient -MockUser ' + $User + ' -Scenario ' + $Scenario
    Start-Migration010ConsoleClient (Join-Path $PSHOME 'powershell.exe') $arguments
}

$testRoot = Join-Path ([IO.Path]::GetTempPath()) ('migration010-diagnostic-flow-' + [Guid]::NewGuid().ToString('N'))
New-Migration010DiagnosticStore $testRoot
try {
    foreach ($Scenario in @('Success','LoginFailure','MissingUnlockConfirmation','ApplyStartException','LockFailure','AlreadyUnlocked')) {
        $path = Join-Path $testRoot ($Scenario + '.json')
        Invoke-Migration010Diagnostic $path
        $savedText = [IO.File]::ReadAllText($path)
        $steps = @{}
        foreach ($row in ($savedText | ConvertFrom-Json).Results) { $steps[$row.Step] = $row }
        if ($savedText.Contains('mock private detail')) { throw 'UNSANITIZED_DIAGNOSTIC' }
        if ($Scenario -eq 'AlreadyUnlocked') {
            if ($steps.ACCOUNT_LOCK_INITIAL.Status -ne 'FAIL' -or $steps.ContainsKey('TEMPORARY_UNLOCK')) { throw 'UNLOCKED_PRECHECK_FAILED' }
            continue
        }
        $expectedLock = if ($Scenario -eq 'LockFailure') { 'FAIL' } else { 'PASS' }
        if ($steps.ACCOUNT_LOCK_FINAL.Status -ne $expectedLock) { throw "CLEANUP_TEST_FAILED: $Scenario" }
        switch ($Scenario) {
            'Success' {
                foreach ($stage in @('CONNECTION_LOGIN','EFFECTIVE_GRANTS','METADATA_VISIBILITY')) {
                    if ($steps[$stage].Status -ne 'PASS') { throw "SUCCESS_STAGE_FAILED: $stage" }
                }
            }
            'LoginFailure' {
                if ($steps.CONNECTION_LOGIN.Status -ne 'FAIL' -or $steps.CONNECTION_LOGIN.MysqlErrorCode -ne '1045' -or $steps.ContainsKey('EFFECTIVE_GRANTS')) { throw 'LOGIN_ERROR_TEST_FAILED' }
            }
            'MissingUnlockConfirmation' {
                if ($steps.TEMPORARY_UNLOCK.Status -ne 'FAIL' -or $steps.TEMPORARY_UNLOCK.ExitCode -ne 0 -or $steps.ContainsKey('CONNECTION_LOGIN')) { throw 'UNLOCK_CONFIRMATION_TEST_FAILED' }
            }
            'ApplyStartException' {
                if ($steps.CONNECTION_LOGIN.Status -ne 'FAIL') { throw 'APPLY_START_EXCEPTION_TEST_FAILED' }
            }
            'LockFailure' {
                if ($steps.ACCOUNT_LOCK_FINAL.MysqlErrorCode -ne '1142') { throw 'LOCK_ERROR_TEST_FAILED' }
            }
        }
    }
} finally {
    foreach ($file in @(Get-ChildItem -LiteralPath $testRoot -File)) { Remove-Item -LiteralPath $file.FullName }
    Remove-Item -LiteralPath $testRoot
}
'Migration 010 Apply Account diagnostic offline tests PASS (6 flow scenarios plus output and JSON checks)'
