$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot '..\scripts\lib\git-env-preflight.ps1')
$root = Join-Path $env:TEMP ('up-van-git-preflight-' + [Guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $root -ErrorAction Stop | Out-Null
$checks = 0
function Assert-Check([bool]$Condition, [string]$Name) {
    if (-not $Condition) { throw "Git preflight regression failed: $Name" }
    $script:checks++
    Write-Output "PASS: $Name"
}

try {
    $noRepositoryRunner = {
        param([string]$Path, [string[]]$Arguments)
        [pscustomobject]@{ ExitCode=128; StdOut=''; StdErr='fatal: not a git repository' ; HasStdErr=$true }
    }
    $noRepo = Get-UpVanEnvGitTrackingStatus -ProjectRoot $root -GitPath 'mock-git' -ProcessInvoker $noRepositoryRunner
    Assert-Check ($noRepo.Status -eq 'not_verifiable_no_repository' -and $null -eq $noRepo.Tracked) 'No .git repository is reported as not verifiable, not as an untracked pass'

    New-Item -ItemType Directory -Path (Join-Path $root '.git') | Out-Null
    $failingProbeRunner = {
        param([string]$Path, [string[]]$Arguments)
        [pscustomobject]@{ ExitCode=2; StdOut=''; StdErr='private diagnostic detail'; HasStdErr=$true }
    }
    $failedClosed = $false
    $safeError = ''
    try { [void](Get-UpVanEnvGitTrackingStatus -ProjectRoot $root -GitPath 'mock-git' -ProcessInvoker $failingProbeRunner) }
    catch { $failedClosed = $true; $safeError = $_.Exception.Message }
    Assert-Check ($failedClosed -and $safeError -notmatch 'private diagnostic detail') 'Unexpected Git probe failure blocks without exposing stderr'

    $failingIndexRunner = {
        param([string]$Path, [string[]]$Arguments)
        if ($Arguments -contains 'rev-parse') { [pscustomobject]@{ ExitCode=0; StdOut="$root`n"; StdErr=''; HasStdErr=$false } }
        else { [pscustomobject]@{ ExitCode=128; StdOut=''; StdErr='private index error'; HasStdErr=$true } }
    }
    $indexFailedClosed = $false
    try { [void](Get-UpVanEnvGitTrackingStatus -ProjectRoot $root -GitPath 'mock-git' -ProcessInvoker $failingIndexRunner) }
    catch { $indexFailedClosed = $true }
    Assert-Check $indexFailedClosed 'Git tracking query failure inside a repository fails closed'

    $trackedRunner = {
        param([string]$Path, [string[]]$Arguments)
        if ($Arguments -contains 'rev-parse') { [pscustomobject]@{ ExitCode=0; StdOut="$root`n"; StdErr=''; HasStdErr=$false } }
        else { [pscustomobject]@{ ExitCode=0; StdOut='.env'; StdErr=''; HasStdErr=$false } }
    }
    $tracked = Get-UpVanEnvGitTrackingStatus -ProjectRoot $root -GitPath 'mock-git' -ProcessInvoker $trackedRunner
    Assert-Check ($tracked.Status -eq 'tracked' -and $tracked.Tracked) 'Tracked .env is identified for rejection'

    $untrackedRunner = {
        param([string]$Path, [string[]]$Arguments)
        if ($Arguments -contains 'rev-parse') { [pscustomobject]@{ ExitCode=0; StdOut="$root`n"; StdErr=''; HasStdErr=$false } }
        else { [pscustomobject]@{ ExitCode=1; StdOut=''; StdErr=''; HasStdErr=$false } }
    }
    $untracked = Get-UpVanEnvGitTrackingStatus -ProjectRoot $root -GitPath 'mock-git' -ProcessInvoker $untrackedRunner
    Assert-Check ($untracked.Status -eq 'untracked' -and $false -eq $untracked.Tracked) 'Untracked .env is distinguished only after a successful repository probe'

    Write-Output "PASS: $checks Git environment preflight regression checks; no MySQL or provisioning command was run."
} finally {
    Remove-Item -LiteralPath $root -Recurse -Force -ErrorAction SilentlyContinue
}
