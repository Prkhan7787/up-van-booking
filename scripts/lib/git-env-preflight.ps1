Set-StrictMode -Version Latest

function ConvertTo-UpVanProcessArgument([string]$Value) {
    return '"' + $Value.Replace('"', '\"') + '"'
}

function Invoke-UpVanGitProcess([string]$GitPath, [string[]]$Arguments) {
    $startInfo = [Diagnostics.ProcessStartInfo]::new()
    $startInfo.FileName = $GitPath
    $startInfo.Arguments = (($Arguments | ForEach-Object { ConvertTo-UpVanProcessArgument ([string]$_) }) -join ' ')
    $startInfo.UseShellExecute = $false
    $startInfo.CreateNoWindow = $true
    $startInfo.RedirectStandardOutput = $true
    $startInfo.RedirectStandardError = $true
    $process = [Diagnostics.Process]::new()
    $process.StartInfo = $startInfo
    try {
        if (-not $process.Start()) { throw 'Git process could not be started.' }
        $stdoutTask = $process.StandardOutput.ReadToEndAsync()
        $stderrTask = $process.StandardError.ReadToEndAsync()
        $process.WaitForExit()
        return [pscustomobject]@{
            ExitCode = $process.ExitCode
            StdOut = $stdoutTask.Result
            # Keep stderr private; fatal diagnostics may include local paths.
            HasStdErr = -not [string]::IsNullOrEmpty($stderrTask.Result)
        }
    } catch {
        throw 'Git command could not be run safely.'
    } finally {
        $process.Dispose()
    }
}

function Test-UpVanGitMarkerInAncestors([string]$ProjectRoot) {
    $directory = [IO.DirectoryInfo]::new((Resolve-Path -LiteralPath $ProjectRoot).Path)
    while ($null -ne $directory) {
        if (Test-Path -LiteralPath (Join-Path $directory.FullName '.git')) { return $true }
        $directory = $directory.Parent
    }
    return $false
}

function Get-UpVanEnvGitTrackingStatus(
    [string]$ProjectRoot,
    [string]$GitPath,
    [scriptblock]$ProcessInvoker = ${function:Invoke-UpVanGitProcess}
) {
    $resolvedRoot = (Resolve-Path -LiteralPath $ProjectRoot).Path
    $hasGitEnvironmentOverride = @('GIT_DIR','GIT_WORK_TREE','GIT_INDEX_FILE') | Where-Object {
        -not [string]::IsNullOrWhiteSpace([Environment]::GetEnvironmentVariable($_,'Process'))
    }
    if ($hasGitEnvironmentOverride) {
        throw 'Git environment overrides are present; tracking status cannot be trusted.'
    }

    if ([string]::IsNullOrWhiteSpace($GitPath)) {
        $gitCommand = Get-Command git.exe -ErrorAction SilentlyContinue
        if ($null -eq $gitCommand) {
            if (Test-UpVanGitMarkerInAncestors -ProjectRoot $resolvedRoot) {
                throw 'Git repository marker exists but Git is unavailable; .env tracking cannot be verified.'
            }
            return [pscustomobject]@{ Status='not_verifiable_git_unavailable'; Tracked=$null }
        }
        $GitPath = $gitCommand.Source
    }

    $probe = & $ProcessInvoker $GitPath @('-C', $resolvedRoot, 'rev-parse', '--show-toplevel')
    if ($probe.ExitCode -eq 0) {
        if ([string]::IsNullOrWhiteSpace($probe.StdOut)) { throw 'Git repository probe returned no root.' }
        $tracked = & $ProcessInvoker $GitPath @('-C', $resolvedRoot, 'ls-files', '--error-unmatch', '--', '.env')
        if ($tracked.ExitCode -eq 0) { return [pscustomobject]@{ Status='tracked'; Tracked=$true } }
        if ($tracked.ExitCode -eq 1) { return [pscustomobject]@{ Status='untracked'; Tracked=$false } }
        throw 'Git tracking query failed; refusing credential-file changes.'
    }

    if ($probe.ExitCode -eq 128 -and -not (Test-UpVanGitMarkerInAncestors -ProjectRoot $resolvedRoot)) {
        return [pscustomobject]@{ Status='not_verifiable_no_repository'; Tracked=$null }
    }
    throw 'Git repository probe failed; refusing credential-file changes.'
}
