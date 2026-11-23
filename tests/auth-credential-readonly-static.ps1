$ErrorActionPreference = 'Stop'
$root = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$cli = Get-Content -LiteralPath (Join-Path $root 'scripts\verify-auth-test-credentials-readonly.php') -Raw
$checker = Get-Content -LiteralPath (Join-Path $root 'app\Database\AuthCredentialReadOnlyCheck.php') -Raw
$safeException = Get-Content -LiteralPath (Join-Path $root 'app\Database\SafeDatabaseException.php') -Raw
$factory = Get-Content -LiteralPath (Join-Path $root 'app\Database\ConnectionFactory.php') -Raw
$checks = [ordered]@{
    'CLI uses only fixed test profiles and the existing ConnectionFactory' = $cli.Contains("'auth_test'") -and $cli.Contains("'test_provisioner'") -and $cli.Contains("ConnectionFactory::config(") -and $cli.Contains("ConnectionFactory::connect(") -and $cli.Contains("'up_van_booking_test'") -and $cli.Contains('PROCESS_PASSWORD_OVERRIDE_PRESENT')
    'CLI checks the expected identity and database with SELECT' = ($cli.Contains('SELECT CURRENT_USER() AS account_identity, DATABASE() AS database_name') -and $cli.Contains('$profile[''username''] . ''@127.0.0.1'''))
    'CLI output is restricted to safe result fields and never emits config or secrets' = $cli.Contains('json_encode($report') -and -not $cli.Contains('print_r(') -and -not $cli.Contains('var_export($config') -and -not $cli.Contains('$config[''password'']')
    'CLI contains no persistent data or privilege mutations' = -not ($cli -match '(?im)\b(CREATE|ALTER|DROP|INSERT|UPDATE|DELETE|GRANT|REVOKE)\s+(USER|DATABASE|TABLE|VIEW|INDEX|INTO|SET|ON|FROM|ROLE)?\b')
    'Checker defaults to deny for wrong identity, database, and unverified grants' = $checker.Contains('WRONG_DATABASE') -and $checker.Contains('WRONG_ACCOUNT') -and $checker.Contains('GRANTS_NOT_VERIFIED')
    'PDO diagnostics keep only SQLSTATE and native numeric code' = $safeException.Contains('$info[1]') -and $safeException.Contains("'UNKNOWN'") -and -not $safeException.Contains('$info[2]') -and -not $safeException.Contains('getMessage()')
    'ConnectionFactory retains existing scoped GrantVerifier checks' = $factory.Contains('GrantVerifier::currentUserGrants($pdo)') -and $factory.Contains('AuthRuntimeGrantPlan::rules()') -and $factory.Contains('TestProvisionerGrantPlan::rules()')
}
foreach ($entry in $checks.GetEnumerator()) {
    if (-not $entry.Value) { throw "Read-only credential static check failed: $($entry.Key)" }
    Write-Output "PASS: $($entry.Key)"
}
Write-Output "PASS: $($checks.Count) static read-only credential checks; no database contacted."
