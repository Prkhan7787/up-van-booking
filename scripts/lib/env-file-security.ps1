Set-StrictMode -Version Latest

function Get-UpVanExpectedEnvAcl([ValidateSet('Localhost', 'IIS')][string]$Mode, [string]$PoolName, [Security.Principal.SecurityIdentifier]$PoolSidOverride) {
    $currentSid = [Security.Principal.WindowsIdentity]::GetCurrent().User
    $fullControl = [int][Security.AccessControl.FileSystemRights]::FullControl
    $read = [int]([Security.AccessControl.FileSystemRights]::ReadAndExecute -bor [Security.AccessControl.FileSystemRights]::Synchronize)
    $entries = [ordered]@{}
    $entries[$currentSid.Value] = $fullControl
    $entries['S-1-5-18'] = $fullControl
    $entries['S-1-5-32-544'] = $fullControl
    if ($Mode -eq 'IIS') {
        if ($null -ne $PoolSidOverride) { $poolSid = $PoolSidOverride }
        else {
            if ([string]::IsNullOrWhiteSpace($PoolName)) { throw 'IIS mode requires the exact application pool name.' }
            $poolSid = [Security.Principal.NTAccount]::new("IIS AppPool\$PoolName").Translate([Security.Principal.SecurityIdentifier])
        }
        if ($entries.Contains($poolSid.Value)) { throw 'IIS application pool identity overlaps a reserved ACL principal.' }
        $entries[$poolSid.Value] = $read
    }
    return $entries
}

function Get-UpVanAclFingerprint([string]$Path) {
    $acl = Get-Acl -LiteralPath $Path
    return $acl.Sddl
}

function Test-UpVanEnvAcl([string]$Path, [ValidateSet('Localhost', 'IIS')][string]$Mode, [string]$PoolName, [Security.Principal.SecurityIdentifier]$PoolSidOverride) {
    if (-not (Test-Path -LiteralPath $Path -PathType Leaf)) { return $false }
    if ((Get-Item -LiteralPath $Path).Attributes -band [IO.FileAttributes]::ReparsePoint) { return $false }
    $expected = Get-UpVanExpectedEnvAcl -Mode $Mode -PoolName $PoolName -PoolSidOverride $PoolSidOverride
    $acl = Get-Acl -LiteralPath $Path
    if (-not $acl.AreAccessRulesProtected) { return $false }
    if ($acl.GetOwner([Security.Principal.SecurityIdentifier]).Value -ne [Security.Principal.WindowsIdentity]::GetCurrent().User.Value) { return $false }
    $actual = @{}
    foreach ($rule in $acl.GetAccessRules($true, $true, [Security.Principal.SecurityIdentifier])) {
        if ($rule.IsInherited -or $rule.AccessControlType -ne [Security.AccessControl.AccessControlType]::Allow -or $rule.InheritanceFlags -ne [Security.AccessControl.InheritanceFlags]::None -or $rule.PropagationFlags -ne [Security.AccessControl.PropagationFlags]::None) { return $false }
        $sid = $rule.IdentityReference.Value
        if ($actual.ContainsKey($sid)) { return $false }
        $actual[$sid] = [int]$rule.FileSystemRights
    }
    if ($actual.Count -ne $expected.Count) { return $false }
    foreach ($sid in $expected.Keys) {
        if (-not $actual.ContainsKey($sid) -or $actual[$sid] -ne $expected[$sid]) { return $false }
    }
    return $true
}

function Assert-UpVanEnvAcl([string]$Path, [ValidateSet('Localhost', 'IIS')][string]$Mode, [string]$PoolName, [Security.Principal.SecurityIdentifier]$PoolSidOverride) {
    if (-not (Test-UpVanEnvAcl -Path $Path -Mode $Mode -PoolName $PoolName -PoolSidOverride $PoolSidOverride)) {
        throw '.env ACL is not the exact protected allowlist for the selected mode; no secret may be written.'
    }
}

function Set-UpVanEnvAcl([string]$Path, [ValidateSet('Localhost', 'IIS')][string]$Mode, [string]$PoolName, [Security.Principal.SecurityIdentifier]$PoolSidOverride) {
    $acl = Get-Acl -LiteralPath $Path
    $acl.SetAccessRuleProtection($true, $false)
    foreach ($rule in @($acl.Access)) { [void]$acl.RemoveAccessRuleSpecific($rule) }
    # The caller must already own this file (or have rights to set its DACL).
    # Avoid SetOwner: ordinary Windows users may not hold SeRestorePrivilege,
    # and the security policy requires preserving the verified owner anyway.
    $spec = Get-UpVanExpectedEnvAcl -Mode $Mode -PoolName $PoolName -PoolSidOverride $PoolSidOverride
    foreach ($sidValue in $spec.Keys) {
        $sid = [Security.Principal.SecurityIdentifier]::new($sidValue)
        $rights = [Security.AccessControl.FileSystemRights]$spec[$sidValue]
        [void]$acl.AddAccessRule([Security.AccessControl.FileSystemAccessRule]::new(
            $sid, $rights, [Security.AccessControl.InheritanceFlags]::None,
            [Security.AccessControl.PropagationFlags]::None,
            [Security.AccessControl.AccessControlType]::Allow
        ))
    }
    Set-Acl -LiteralPath $Path -AclObject $acl
    Assert-UpVanEnvAcl -Path $Path -Mode $Mode -PoolName $PoolName -PoolSidOverride $PoolSidOverride
}

function Write-UpVanAtomicEnvFile([string]$Path, [string]$Text, [string]$Mode, [string]$PoolName, [string]$ExpectedOriginalSddl, [Security.Principal.SecurityIdentifier]$PoolSidOverride, [string]$StagingId = '', [string]$StagingDirectory = '') {
    # Fail closed before writing any secret. ReplaceFile may carry the target ACL
    # forward, so both sides are verified against the exact allowlist first.
    Assert-UpVanEnvAcl -Path $Path -Mode $Mode -PoolName $PoolName -PoolSidOverride $PoolSidOverride
    if ((Get-UpVanAclFingerprint -Path $Path) -cne $ExpectedOriginalSddl) { throw '.env ACL changed after preflight; refusing replacement.' }
    if ($StagingId -ne '' -and $StagingId -notmatch '^[a-f0-9]{32}$') { throw 'Invalid transaction staging identifier.' }
    if ($StagingId -eq '') {
        $tempName = '.env.secure-' + [Guid]::NewGuid().ToString('N') + '.tmp'
        $backupName = '.env.secure-backup-' + [Guid]::NewGuid().ToString('N') + '.tmp'
        $recoveryName = '.env.secure-recovery-' + [Guid]::NewGuid().ToString('N') + '.tmp'
    } else {
        $tempName = '.env.secure-' + $StagingId + '.tmp'
        $backupName = '.env.secure-backup-' + $StagingId + '.tmp'
        $recoveryName = '.env.secure-recovery-' + $StagingId + '.tmp'
    }
    $stageRoot = if ([string]::IsNullOrWhiteSpace($StagingDirectory)) { Split-Path -Parent $Path } else { [IO.Path]::GetFullPath($StagingDirectory) }
    if (-not (Test-Path -LiteralPath $stageRoot -PathType Container)) { throw 'Atomic staging directory is unavailable.' }
    if ([IO.Path]::GetPathRoot([IO.Path]::GetFullPath($Path)) -cne [IO.Path]::GetPathRoot($stageRoot)) { throw 'Atomic staging directory must be on the same volume as .env.' }
    $tempPath = Join-Path $stageRoot $tempName
    $backupPath = Join-Path $stageRoot $backupName
    $recoveryPath = Join-Path $stageRoot $recoveryName
    foreach ($candidate in @($tempPath,$backupPath,$recoveryPath)) {
        if (Test-Path -LiteralPath $candidate) { throw 'Transaction staging file already exists; validate and clean it before retry.' }
    }
    $replaced = $false
    $keepProtectedBackup = $false
    $stage = 'staging'
    try {
        New-Item -ItemType File -Path $tempPath -ErrorAction Stop | Out-Null
        Set-UpVanEnvAcl -Path $tempPath -Mode $Mode -PoolName $PoolName -PoolSidOverride $PoolSidOverride
        Assert-UpVanEnvAcl -Path $tempPath -Mode $Mode -PoolName $PoolName -PoolSidOverride $PoolSidOverride
        [IO.File]::WriteAllText($tempPath, $Text, [Text.UTF8Encoding]::new($false))
        Assert-UpVanEnvAcl -Path $Path -Mode $Mode -PoolName $PoolName -PoolSidOverride $PoolSidOverride
        # Backup is created atomically by ReplaceFile; its ACL comes from the
        # already-verified destination. false does not ignore ACL merge errors.
        $stage = 'replace'
        [IO.File]::Replace($tempPath, $Path, $backupPath, $false)
        $replaced = $true
        $stage = 'backup_acl'
        Assert-UpVanEnvAcl -Path $backupPath -Mode $Mode -PoolName $PoolName -PoolSidOverride $PoolSidOverride
        # ReplaceFile preserves the destination DACL. Verify it rather than
        # rewriting it: ordinary users can be denied Set-Acl after replacement
        # even though the protected destination ACL is already correct.
        $stage = 'destination_acl'
        Assert-UpVanEnvAcl -Path $Path -Mode $Mode -PoolName $PoolName -PoolSidOverride $PoolSidOverride
        $stage = 'complete'
    } catch {
        $failureType = $_.Exception.GetType().Name
        Write-Verbose "Atomic .env replacement failed at stage $stage ($failureType)."
        if ($replaced -and (Test-Path -LiteralPath $backupPath -PathType Leaf)) {
            try {
                Assert-UpVanEnvAcl -Path $backupPath -Mode $Mode -PoolName $PoolName -PoolSidOverride $PoolSidOverride
                [IO.File]::Replace($backupPath, $Path, $recoveryPath, $false)
                Assert-UpVanEnvAcl -Path $Path -Mode $Mode -PoolName $PoolName -PoolSidOverride $PoolSidOverride
                if ((Get-UpVanAclFingerprint -Path $Path) -cne $ExpectedOriginalSddl) { throw 'Original DACL did not return after replacement rollback.' }
                $replaced = $false
            } catch {
                $rollbackFailureType = $_.Exception.GetType().Name
                Write-Verbose "Atomic .env rollback failed at stage $stage ($rollbackFailureType)."
                $keepProtectedBackup = $true
                throw "Atomic replacement postcondition failed at stage $stage ($failureType; rollback $rollbackFailureType). A protected backup was retained; stop and inspect .env before continuing."
            }
        }
        throw $_
    } finally {
        if (Test-Path -LiteralPath $tempPath) { Remove-Item -LiteralPath $tempPath -Force -ErrorAction SilentlyContinue }
        if (-not $keepProtectedBackup) {
            if (Test-Path -LiteralPath $backupPath) { Remove-Item -LiteralPath $backupPath -Force -ErrorAction SilentlyContinue }
            if (Test-Path -LiteralPath $recoveryPath) { Remove-Item -LiteralPath $recoveryPath -Force -ErrorAction SilentlyContinue }
        }
    }
}
