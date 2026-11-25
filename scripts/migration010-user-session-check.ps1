param(
    [string]$ResultPath = (Join-Path (Join-Path $env:LOCALAPPDATA 'UPVanBooking') 'migration010-user-session-check.json')
)

$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'lib/migration011-apply-credentials.ps1')
$results = [ordered]@{
    checked_utc = [DateTime]::UtcNow.ToString('o')
    status = 'BLOCKED'
    windows_user = $null
    integrity = 'UNKNOWN'
    DBA_LOGIN = 'BLOCKED'
    DBA_CURRENT_USER = $null
    DBA_VERSION = $null
    DBA_SERVER_UUID = $null
    APPLY011_ACCOUNT = 'UNKNOWN'
    APPLY011_ACCOUNT_LOCK = 'UNKNOWN'
    APPLY_CREDENTIAL = 'MISSING'
    APPLY011_CREDENTIAL = 'MISSING'
    result_file = $ResultPath
}

try {
    $identity = [Security.Principal.WindowsIdentity]::GetCurrent()
    $results.windows_user = $identity.Name
    $who = (& whoami.exe /groups 2>$null | Out-String)
    if ($who -match 'S-1-16-8192') { $results.integrity = 'MEDIUM' }
    elseif ($who -match 'S-1-16-12288') { $results.integrity = 'HIGH' }
    elseif ($who -match 'S-1-16-4096') { $results.integrity = 'LOW' }

    if ($results.integrity -ne 'MEDIUM') { throw 'NORMAL_WINDOWS_MEDIUM_SESSION_REQUIRED' }

    $mysqlEditor = @(
        'C:\Program Files\MySQL\MySQL Server 8.4\bin\mysql_config_editor.exe',
        'C:\Program Files\MySQL\MySQL Server 8.0\bin\mysql_config_editor.exe'
    ) | Where-Object { Test-Path -LiteralPath $_ } | Select-Object -First 1
    $mysql = @(
        'C:\Program Files\MySQL\MySQL Server 8.4\bin\mysql.exe',
        'C:\Program Files\MySQL\MySQL Server 8.0\bin\mysql.exe'
    ) | Where-Object { Test-Path -LiteralPath $_ } | Select-Object -First 1
    if ($mysqlEditor) {
        # MySQL's utility masks passwords. Keep output only in memory and retain path names.
        $pathOutput = & $mysqlEditor print --all 2>$null | Out-String
        $pathExit = $LASTEXITCODE
        $pathStatus = Get-Migration011LoginPathStatus -ExitCode $pathExit -Output $pathOutput
        $pathOutput = $null
        if ($pathStatus -ne 'PRESENT') {
            $results.DBA_LOGIN = 'BLOCKED'
        } elseif (-not $mysql) {
            $results.DBA_LOGIN = 'BLOCKED'
        } else {
            $query = 'SELECT CONCAT(CURRENT_USER(), CHAR(9), VERSION(), CHAR(9), @@server_uuid)'
            $probe = & $mysql "--login-path=upvan3306-backup" '--protocol=TCP' '--host=127.0.0.1' '--port=3306' '--database=up_van_booking_test' '--connect-timeout=5' '--batch' '--raw' '--skip-column-names' '--execute' $query 2>$null
            $probeExit = $LASTEXITCODE
            $line = [string](@($probe) | Select-Object -First 1)
            $parts = $line -split "`t", 3
            if ($probeExit -eq 0 -and $parts.Count -eq 3 -and $parts[2] -eq '77218fbd-d66d-11f1-9a0a-5c2886f057d5' -and $parts[1] -match '^8\.4\.') {
                $results.DBA_LOGIN = 'PASS'
                $results.DBA_CURRENT_USER = $parts[0]
                $results.DBA_VERSION = $parts[1]
                $results.DBA_SERVER_UUID = $parts[2]
            } elseif ($probeExit -eq 0) {
                $results.DBA_LOGIN = 'FAIL'
            } else {
                $results.DBA_LOGIN = 'FAIL'
            }
            $probe = $null; $line = $null; $parts = $null
            if ($results.DBA_LOGIN -eq 'PASS') {
                $accountQuery = "SELECT CONCAT(COUNT(*), CHAR(9), COALESCE(MAX(account_locked), 'UNKNOWN')) FROM mysql.user WHERE user='up_van_migration011_apply' AND host='127.0.0.1'"
                $accountProbe = & $mysql '--login-path=upvan3306-backup' '--protocol=TCP' '--host=127.0.0.1' '--port=3306' '--database=up_van_booking_test' '--connect-timeout=5' '--batch' '--raw' '--skip-column-names' '--execute' $accountQuery 2>$null
                $accountExit = $LASTEXITCODE
                $accountLine = [string](@($accountProbe) | Select-Object -First 1)
                $accountParts = $accountLine -split "`t", 2
                if ($accountExit -eq 0 -and $accountParts.Count -eq 2 -and $accountParts[0] -eq '0') {
                    $results.APPLY011_ACCOUNT = 'MISSING'
                    $results.APPLY011_ACCOUNT_LOCK = 'NOT_APPLICABLE'
                } elseif ($accountExit -eq 0 -and $accountParts.Count -eq 2 -and $accountParts[0] -eq '1' -and $accountParts[1] -in @('Y','N')) {
                    $results.APPLY011_ACCOUNT = 'PRESENT'
                    $results.APPLY011_ACCOUNT_LOCK = $accountParts[1]
                } else {
                    $results.APPLY011_ACCOUNT = 'UNKNOWN'
                }
                $accountProbe = $null; $accountLine = $null; $accountParts = $null
            }
        }
    }

    $store = Join-Path (Join-Path $env:LOCALAPPDATA 'UPVanBooking') 'migration010-apply.dpapi'
    if (Test-Path -LiteralPath $store -PathType Leaf) {
        $results.APPLY_CREDENTIAL = 'READY'
    }
    $store011 = Join-Path (Join-Path (Join-Path $env:LOCALAPPDATA 'UPVanBooking') 'migration011') 'apply.dpapi'
    if (Test-Path -LiteralPath $store011 -PathType Leaf) {
        $results.APPLY011_CREDENTIAL = 'PRESENT_UNVERIFIED'
    }
} catch {
    $results.status = 'BLOCKED'
}

if ($results.integrity -eq 'MEDIUM' -and $results.DBA_LOGIN -eq 'PASS' -and $results.APPLY_CREDENTIAL -eq 'READY') {
    $results.status = 'CREDENTIALS_READY_NO_APPLY'
}

$parent = Split-Path -Parent $ResultPath
if (-not (Test-Path -LiteralPath $parent)) { New-Item -ItemType Directory -Path $parent -Force | Out-Null }
$json = $results | ConvertTo-Json -Depth 4
[IO.File]::WriteAllText($ResultPath, $json, [Text.UTF8Encoding]::new($false))
$results.GetEnumerator() | ForEach-Object { '{0}={1}' -f $_.Key,$_.Value }
