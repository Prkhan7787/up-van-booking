Set-StrictMode -Version Latest

function Assert-PrivateRecoveryAcl([string]$Path,[bool]$Directory) {
    $script:aclOperation='READ_ACL'
    $item=Get-Item -LiteralPath $Path -Force -ErrorAction Stop
    if($item.PSIsContainer -ne $Directory -or ($item.Attributes -band [IO.FileAttributes]::ReparsePoint)) { throw 'Recovery object type rejected' }
    $acl=Get-Acl -LiteralPath $Path -ErrorAction Stop
    $userSid=[Security.Principal.WindowsIdentity]::GetCurrent().User.Value
    $script:aclOperation='VERIFY_OWNER'
    if($acl.GetOwner([Security.Principal.SecurityIdentifier]).Value -ne $userSid) { throw 'Recovery owner mismatch' }
    $script:aclOperation='VERIFY_DACL'
    $rules=@($acl.GetAccessRules($true,$true,[Security.Principal.SecurityIdentifier]))
    if(!$acl.AreAccessRulesProtected -or $rules.Count -ne 2) { throw 'Recovery ACL rejected' }
    $seen=@{}
    $expectedInheritance=if($Directory){[Security.AccessControl.InheritanceFlags]'ContainerInherit,ObjectInherit'}else{[Security.AccessControl.InheritanceFlags]::None}
    foreach($rule in $rules) {
        $sid=$rule.IdentityReference.Value
        if($sid -notin @($userSid,'S-1-5-18') -or $seen.ContainsKey($sid) -or $rule.IsInherited -or
           $rule.AccessControlType -ne 'Allow' -or $rule.FileSystemRights -ne 'FullControl' -or
           $rule.InheritanceFlags -ne $expectedInheritance -or $rule.PropagationFlags -ne 'None') { throw 'Unexpected recovery access' }
        $seen[$sid]=$true
    }
}

function Set-PrivateRecoveryAcl([string]$Path,[bool]$Directory) {
    $script:aclOperation='READ_OWNER'
    $item=Get-Item -LiteralPath $Path -Force -ErrorAction Stop
    if($item.PSIsContainer -ne $Directory -or ($item.Attributes -band [IO.FileAttributes]::ReparsePoint)) { throw 'Recovery object type rejected' }
    $userSid=[Security.Principal.WindowsIdentity]::GetCurrent().User
    $owner=(Get-Acl -LiteralPath $Path -ErrorAction Stop).GetOwner([Security.Principal.SecurityIdentifier]).Value
    if($owner -ne $userSid.Value) { throw 'Recovery owner mismatch; ownership is not changed' }
    # Load and persist DACL only. Never mark Owner/Group/SACL as modified.
    $section=[Security.AccessControl.AccessControlSections]::Access
    if($Directory) { $acl=[IO.Directory]::GetAccessControl($Path,$section);$inherit=[Security.AccessControl.InheritanceFlags]'ContainerInherit,ObjectInherit' }
    else { $acl=[IO.File]::GetAccessControl($Path,$section);$inherit=[Security.AccessControl.InheritanceFlags]::None }
    $acl.SetAccessRuleProtection($true,$false)
    foreach($rule in @($acl.GetAccessRules($true,$false,[Security.Principal.SecurityIdentifier]))) { [void]$acl.RemoveAccessRuleSpecific($rule) }
    foreach($sid in @($userSid,[Security.Principal.SecurityIdentifier]::new('S-1-5-18'))) {
        [void]$acl.AddAccessRule([Security.AccessControl.FileSystemAccessRule]::new($sid,'FullControl',$inherit,'None','Allow'))
    }
    $script:aclOperation='WRITE_DACL'
    if($Directory) { [IO.Directory]::SetAccessControl($Path,$acl) }
    else { [IO.File]::SetAccessControl($Path,$acl) }
    Assert-PrivateRecoveryAcl $Path $Directory
}

function Confirm-PrivateRecoveryFile([string]$Path) {
    # Post-write verification is read-only. Never reset owner or ACL after secrets exist.
    try {
        Assert-PrivateRecoveryAcl (Split-Path -Parent $Path) $true
        Assert-PrivateRecoveryAcl $Path $false
    } catch {
        $failedOperation=$script:aclOperation
        $script:stage='ERROR_CLEANUP'
        if(Test-Path -LiteralPath $Path -PathType Leaf) { Remove-Item -LiteralPath $Path -Force -ErrorAction Stop }
        $script:stage='POST_WRITE_ACL'
        $script:aclOperation=$failedOperation
        throw
    }
}
