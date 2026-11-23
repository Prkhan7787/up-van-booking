Set-StrictMode -Version Latest

function Test-UpVanRecoveryStagingDirectory([string]$Path) {
    if (-not (Test-Path -LiteralPath $Path -PathType Container)) { return $false }
    if ((Get-Item -LiteralPath $Path).Attributes -band [IO.FileAttributes]::ReparsePoint) { return $false }
    $acl=Get-Acl -LiteralPath $Path
    if (-not $acl.AreAccessRulesProtected -or $acl.GetOwner([Security.Principal.SecurityIdentifier]).Value -ne [Security.Principal.WindowsIdentity]::GetCurrent().User.Value) { return $false }
    $expected=@{}
    foreach($sid in @([Security.Principal.WindowsIdentity]::GetCurrent().User.Value,'S-1-5-18','S-1-5-32-544')){$expected[$sid]=$true}
    $actual=@{}
    foreach($rule in $acl.GetAccessRules($true,$true,[Security.Principal.SecurityIdentifier])){
        if($rule.IsInherited -or $rule.AccessControlType -ne [Security.AccessControl.AccessControlType]::Allow -or $rule.FileSystemRights -ne [Security.AccessControl.FileSystemRights]::FullControl -or $rule.InheritanceFlags -ne ([Security.AccessControl.InheritanceFlags]::ContainerInherit -bor [Security.AccessControl.InheritanceFlags]::ObjectInherit) -or $rule.PropagationFlags -ne [Security.AccessControl.PropagationFlags]::None){return $false}
        if($actual.ContainsKey($rule.IdentityReference.Value)){return $false}
        $actual[$rule.IdentityReference.Value]=$true
    }
    if($actual.Count -ne $expected.Count){return $false}
    foreach($sid in $expected.Keys){if(-not $actual.ContainsKey($sid)){return $false}}
    return $true
}

function Ensure-UpVanRecoveryStagingDirectory([string]$Path) {
    $full=[IO.Path]::GetFullPath($Path)
    if(Test-Path -LiteralPath $full){
        if(-not (Test-UpVanRecoveryStagingDirectory -Path $full)){throw 'Recovery staging directory ACL is not the exact protected Localhost allowlist.'}
        return
    }
    New-Item -ItemType Directory -Path $full -ErrorAction Stop | Out-Null
    $acl=Get-Acl -LiteralPath $full
    $acl.SetAccessRuleProtection($true,$false)
    foreach($entry in @($acl.Access)){[void]$acl.RemoveAccessRuleSpecific($entry)}
    foreach($sidText in @([Security.Principal.WindowsIdentity]::GetCurrent().User.Value,'S-1-5-18','S-1-5-32-544')){
        $sid=[Security.Principal.SecurityIdentifier]::new($sidText)
        $rule=[Security.AccessControl.FileSystemAccessRule]::new($sid,[Security.AccessControl.FileSystemRights]::FullControl,[Security.AccessControl.InheritanceFlags]::ContainerInherit -bor [Security.AccessControl.InheritanceFlags]::ObjectInherit,[Security.AccessControl.PropagationFlags]::None,[Security.AccessControl.AccessControlType]::Allow)
        [void]$acl.AddAccessRule($rule)
    }
    Set-Acl -LiteralPath $full -AclObject $acl
    if(-not (Test-UpVanRecoveryStagingDirectory -Path $full)){throw 'Recovery staging directory ACL verification failed.'}
}

function Get-UpVanRecoveryStagingPaths([string]$Directory,[string]$TransactionId) {
    if ($TransactionId -notmatch '^[a-f0-9]{32}$') { throw 'Invalid recovery transaction identifier.' }
    $base = [IO.Path]::GetFullPath($Directory)
    if (-not (Test-UpVanRecoveryStagingDirectory -Path $base)) { throw 'Recovery staging directory ACL verification failed.' }
    return @(
        (Join-Path $base ('.env.secure-' + $TransactionId + '.tmp')),
        (Join-Path $base ('.env.secure-backup-' + $TransactionId + '.tmp')),
        (Join-Path $base ('.env.secure-recovery-' + $TransactionId + '.tmp'))
    )
}

function Remove-UpVanRecoveryStaging([string]$Directory,[string]$TransactionId) {
    $removed = 0
    foreach ($path in (Get-UpVanRecoveryStagingPaths -Directory $Directory -TransactionId $TransactionId)) {
        if (-not (Test-Path -LiteralPath $path -PathType Leaf)) { continue }
        if ((Get-Item -LiteralPath $path).Attributes -band [IO.FileAttributes]::ReparsePoint) { throw 'Recovery staging reparse point rejected.' }
        Assert-UpVanEnvAcl -Path $path -Mode Localhost -PoolName '' -PoolSidOverride $null
        Remove-Item -LiteralPath $path -Force
        $removed++
    }
    return $removed
}
