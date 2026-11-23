param([switch]$Execute)

# Preparation is harmless; execution requires this explicit switch and later approval.
if (-not $Execute) {
    Write-Output 'NOT RUN: Stage D execution requires administrator approval and -Execute.'
    exit 0
}
$ErrorActionPreference = 'Stop'
$stageDProject = Split-Path -Parent $PSScriptRoot
$stageDTestExit = $null
$stageDPostExit = $null
Push-Location -LiteralPath $stageDProject
try {
    # A separate shell is required: php.ps1 calls exit.
    & powershell -NoProfile -ExecutionPolicy Bypass -File scripts/php.ps1 scripts/stage-d-preflight.php
    if ($LASTEXITCODE -ne 0) { throw 'Preflight failed; integration tests were not started.' }
    try {
        $env:TEST_DB_EXECUTION_APPROVED = 'true'
        & powershell -NoProfile -ExecutionPolicy Bypass -File scripts/php.ps1 tests/database/integration.php --execute --confirm=up_van_booking_test
        $stageDTestExit = $LASTEXITCODE
    } finally {
        $env:TEST_DB_EXECUTION_APPROVED = 'false'
        & powershell -NoProfile -ExecutionPolicy Bypass -File scripts/php.ps1 scripts/stage-d-preflight.php
        $stageDPostExit = $LASTEXITCODE
    }
    if ($stageDPostExit -ne 0) { throw 'Postflight failed; inspect only the test target.' }
    if ($stageDTestExit -ne 0) { throw 'Integration tests failed; inspect run-tagged cleanup evidence without resetting the database.' }
} finally {
    $env:TEST_DB_EXECUTION_APPROVED = 'false'
    Pop-Location
}
