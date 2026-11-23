$ErrorActionPreference = 'Stop'
if ([Environment]::OSVersion.Platform -ne [PlatformID]::Win32NT) { throw 'Windows ACL behavioral tests require Windows.' }
$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
. (Join-Path $projectRoot 'scripts\lib\env-file-security.ps1')

$root = Join-Path (Join-Path $projectRoot 'storage') ('up-van-acl-test-' + [Guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $root -ErrorAction Stop | Out-Null
$directorySddl = (Get-Acl -LiteralPath $root).Sddl
$checks = 0
function Assert-Behavior([bool]$Condition, [string]$Label) {
    if (-not $Condition) { throw "Windows ACL behavioral test failed: $Label" }
    $script:checks++
    Write-Output "PASS: $Label"
}
function New-TestFile([string]$Path, [string]$Value, [string]$Mode, [string]$PoolName, [Security.Principal.SecurityIdentifier]$PoolSid) {
    [IO.File]::WriteAllText($Path, $Value, [Text.UTF8Encoding]::new($false))
    Set-UpVanEnvAcl -Path $Path -Mode $Mode -PoolName $PoolName -PoolSidOverride $PoolSid
}

try {
    # Localhost: exactly current user, SYSTEM, and Administrators; no inheritance.
    $localhostFile = Join-Path $root 'localhost-target.txt'
    New-TestFile -Path $localhostFile -Value 'old-localhost-marker' -Mode Localhost -PoolName '' -PoolSid $null
    $localAcl = Get-Acl -LiteralPath $localhostFile
    Assert-Behavior (Test-UpVanEnvAcl -Path $localhostFile -Mode Localhost -PoolName '' -PoolSidOverride $null) 'Localhost ACL passes exact allowlist check'
    Assert-Behavior ($localAcl.AreAccessRulesProtected -and $localAcl.Access.Count -eq 3) 'Localhost ACL is protected from inheritance and contains exactly three principals'
    Assert-Behavior (-not (Test-UpVanEnvAcl -Path $localhostFile -Mode IIS -PoolName 'NotUsed' -PoolSidOverride ([Security.Principal.SecurityIdentifier]::new('S-1-5-20')))) 'Localhost ACL does not include an IIS pool identity'

    # IIS: synthetic SID exercises the additional-principal code path without requiring IIS installation.
    $poolSid = [Security.Principal.SecurityIdentifier]::new('S-1-5-20')
    $iisFile = Join-Path $root 'iis-target.txt'
    New-TestFile -Path $iisFile -Value 'old-iis-marker' -Mode IIS -PoolName '' -PoolSid $poolSid
    $iisAcl = Get-Acl -LiteralPath $iisFile
    Assert-Behavior (Test-UpVanEnvAcl -Path $iisFile -Mode IIS -PoolName '' -PoolSidOverride $poolSid) 'IIS ACL passes exact allowlist check with its specified pool identity'
    Assert-Behavior ($iisAcl.AreAccessRulesProtected -and $iisAcl.Access.Count -eq 4) 'IIS ACL contains only the three base principals and one pool identity'
    Assert-Behavior (-not (Test-UpVanEnvAcl -Path $iisFile -Mode Localhost -PoolName '' -PoolSidOverride $null)) 'IIS ACL is rejected by Localhost policy because it includes the pool identity'
    $iisSddl = Get-UpVanAclFingerprint -Path $iisFile
    Write-UpVanAtomicEnvFile -Path $iisFile -Text 'new-iis-marker' -Mode IIS -PoolName '' -ExpectedOriginalSddl $iisSddl -PoolSidOverride $poolSid
    Assert-Behavior ([IO.File]::ReadAllText($iisFile) -eq 'new-iis-marker' -and (Get-UpVanAclFingerprint -Path $iisFile) -ceq $iisSddl) 'IIS ReplaceFile preserves and verifies its exact allowlist DACL'
    Write-UpVanAtomicEnvFile -Path $iisFile -Text 'old-iis-marker' -Mode IIS -PoolName '' -ExpectedOriginalSddl $iisSddl -PoolSidOverride $poolSid
    Assert-Behavior ([IO.File]::ReadAllText($iisFile) -eq 'old-iis-marker' -and (Get-UpVanAclFingerprint -Path $iisFile) -ceq $iisSddl) 'IIS rollback restores dummy content and ACL'

    # The atomic helper keeps the already-verified destination ACL and verifies the final DACL.
    $originalSddl = Get-UpVanAclFingerprint -Path $localhostFile
    Write-UpVanAtomicEnvFile -Path $localhostFile -Text 'new-localhost-marker' -Mode Localhost -PoolName '' -ExpectedOriginalSddl $originalSddl
    Assert-Behavior ([IO.File]::ReadAllText($localhostFile) -eq 'new-localhost-marker') 'ReplaceFile updates dummy content'
    Assert-Behavior ((Get-UpVanAclFingerprint -Path $localhostFile) -ceq $originalSddl) 'ReplaceFile postcondition preserves the approved Localhost DACL'

    # Rollback restores content and the captured ACL, using only a scratch file.
    Write-UpVanAtomicEnvFile -Path $localhostFile -Text 'old-localhost-marker' -Mode Localhost -PoolName '' -ExpectedOriginalSddl $originalSddl
    Assert-Behavior ([IO.File]::ReadAllText($localhostFile) -eq 'old-localhost-marker') 'Rollback restores original dummy content'
    Assert-Behavior ((Get-UpVanAclFingerprint -Path $localhostFile) -ceq $originalSddl) 'Rollback restores the captured ACL'

    # Inject a post-replacement verification failure once to exercise the
    # helper's automatic recovery path without touching any real configuration.
    $script:rollbackTarget = $localhostFile
    $script:injectPostReplaceFailure = $true
    $script:realAssertAcl = (Get-Command Assert-UpVanEnvAcl -CommandType Function).ScriptBlock
    function Assert-UpVanEnvAcl([string]$Path, [ValidateSet('Localhost', 'IIS')][string]$Mode, [string]$PoolName, [Security.Principal.SecurityIdentifier]$PoolSidOverride) {
        if ($script:injectPostReplaceFailure -and $Path -eq $script:rollbackTarget -and [IO.File]::ReadAllText($Path) -eq 'force-postcondition-failure') {
            $script:injectPostReplaceFailure = $false
            throw 'Injected test-only postcondition failure.'
        }
        & $script:realAssertAcl $Path $Mode $PoolName $PoolSidOverride
    }
    $autoRollbackObserved = $false
    try { Write-UpVanAtomicEnvFile -Path $localhostFile -Text 'force-postcondition-failure' -Mode Localhost -PoolName '' -ExpectedOriginalSddl $originalSddl }
    catch { $autoRollbackObserved = -not $script:injectPostReplaceFailure }
    Remove-Item Function:\Assert-UpVanEnvAcl
    Set-Item Function:\Assert-UpVanEnvAcl -Value $script:realAssertAcl
    Assert-Behavior ($autoRollbackObserved -and [IO.File]::ReadAllText($localhostFile) -eq 'old-localhost-marker') 'Post-replacement failure triggers automatic content rollback'
    Assert-Behavior ((Get-UpVanAclFingerprint -Path $localhostFile) -ceq $originalSddl) 'Automatic rollback restores the original DACL'

    # Reject a broadened ACL before replacing any content.
    & icacls.exe $localhostFile /grant '*S-1-5-32-545:(R)' | Out-Null
    if ($LASTEXITCODE -ne 0) { throw 'Could not prepare the broadened DACL fixture.' }
    Assert-Behavior (-not (Test-UpVanEnvAcl -Path $localhostFile -Mode Localhost -PoolName '' -PoolSidOverride $null)) 'Unexpected Users grant makes ACL fail closed'
    $rejected = $false
    try { Write-UpVanAtomicEnvFile -Path $localhostFile -Text 'must-not-write' -Mode Localhost -PoolName '' -ExpectedOriginalSddl $originalSddl } catch { $rejected = $true }
    Assert-Behavior ($rejected -and [IO.File]::ReadAllText($localhostFile) -eq 'old-localhost-marker') 'Unsafe ACL is refused before file contents change'

    # Cause a real ReplaceFile failure by denying sharing, then verify original state and cleanup.
    & icacls.exe $localhostFile /remove:g '*S-1-5-32-545' | Out-Null
    if ($LASTEXITCODE -ne 0) { throw 'Could not restore the DACL fixture before replacement-failure test.' }
    $originalSddl = Get-UpVanAclFingerprint -Path $localhostFile
    $locked = [IO.File]::Open($localhostFile, [IO.FileMode]::Open, [IO.FileAccess]::ReadWrite, [IO.FileShare]::None)
    $replaceFailed = $false
    try { Write-UpVanAtomicEnvFile -Path $localhostFile -Text 'replace-failure-marker' -Mode Localhost -PoolName '' -ExpectedOriginalSddl $originalSddl }
    catch { $replaceFailed = $true }
    finally { $locked.Dispose() }
    Assert-Behavior $replaceFailed 'Locked destination makes Atomic ReplaceFile fail'
    Assert-Behavior ([IO.File]::ReadAllText($localhostFile) -eq 'old-localhost-marker') 'ReplaceFile failure leaves original content untouched'
    Assert-Behavior ((Get-UpVanAclFingerprint -Path $localhostFile) -ceq $originalSddl) 'ReplaceFile failure leaves original DACL untouched'
    Assert-Behavior (@(Get-ChildItem -LiteralPath $root -Filter '.env.secure-*.tmp').Count -eq 0) 'Failed replacement removes its restricted temporary file'

    Assert-Behavior ((Get-Acl -LiteralPath $root).Sddl -ceq $directorySddl) 'Test operations do not change the scratch directory ACL'
    Write-Output "PASS: $checks Windows ACL behavioral checks; scratch files contained dummy values only."
} finally {
    Remove-Item -LiteralPath $root -Recurse -Force -ErrorAction SilentlyContinue
}
