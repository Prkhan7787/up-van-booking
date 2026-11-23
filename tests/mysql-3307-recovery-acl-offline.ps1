#requires -Version 5.1
Set-StrictMode -Version Latest
$ErrorActionPreference='Stop'
. (Join-Path $PSScriptRoot '..\scripts\lib\mysql-3307-recovery-acl.ps1')
$script:stage='OFFLINE_TEST'
$script:aclOperation=$null
$checks=0
function Check([bool]$Condition,[string]$Label) {
    if(!$Condition) { throw ('Offline check failed: '+$Label) }
    $script:checks++
}
$testRoot=[IO.Path]::GetFullPath($PSScriptRoot).TrimEnd('\')
$fixture=Join-Path $testRoot ('mysql3307-acl-fixture-'+[guid]::NewGuid().ToString('N'))
if(!$fixture.StartsWith($testRoot+'\',[StringComparison]::OrdinalIgnoreCase)) { throw 'Fixture path rejected' }
$file=Join-Path $fixture 'fixture.txt'
try {
    [void](New-Item -ItemType Directory -Path $fixture)
    $beforeOwner=(Get-Acl -LiteralPath $fixture).GetOwner([Security.Principal.SecurityIdentifier]).Value
    Set-PrivateRecoveryAcl $fixture $true
    Check ((Get-Acl -LiteralPath $fixture).GetOwner([Security.Principal.SecurityIdentifier]).Value -eq $beforeOwner) 'directory owner retained'
    Assert-PrivateRecoveryAcl $fixture $true
    Check $true 'directory exact private ACL'
    [void](New-Item -ItemType File -Path $file)
    $fileOwner=(Get-Acl -LiteralPath $file).GetOwner([Security.Principal.SecurityIdentifier]).Value
    Set-PrivateRecoveryAcl $file $false
    Check ((Get-Acl -LiteralPath $file).GetOwner([Security.Principal.SecurityIdentifier]).Value -eq $fileOwner) 'file owner retained'
    $before=(Get-Acl -LiteralPath $file).Sddl
    [IO.File]::WriteAllText($file,'OFFLINE NONSECRET FIXTURE',[Text.UTF8Encoding]::new($false))
    Confirm-PrivateRecoveryFile $file
    Check ((Get-Acl -LiteralPath $file).Sddl -eq $before) 'post-write check preserves descriptor without setting ACL'
    Check ((Get-Item -LiteralPath $file).Length -gt 0) 'post-write fixture remains'
    Set-PrivateRecoveryAcl $file $false
    Check ((Get-Acl -LiteralPath $file).GetOwner([Security.Principal.SecurityIdentifier]).Value -eq $fileOwner) 'DACL-only update needs no owner reassignment'
    $bad=[IO.File]::GetAccessControl($file,[Security.AccessControl.AccessControlSections]::Access)
    [void]$bad.AddAccessRule([Security.AccessControl.FileSystemAccessRule]::new([Security.Principal.SecurityIdentifier]::new('S-1-1-0'),'Read','Allow'))
    [IO.File]::SetAccessControl($file,$bad)
    $rejected=$false
    try { Confirm-PrivateRecoveryFile $file } catch { $rejected=$true }
    Check $rejected 'unexpected Everyone access fails closed'
    Check (!(Test-Path -LiteralPath $file)) 'failed post-write ACL verification removes fixture'
    Check ($script:stage -eq 'POST_WRITE_ACL' -and $script:aclOperation -eq 'VERIFY_DACL') 'failed operation retained safely'

    [void](New-Item -ItemType File -Path $file)
    Set-PrivateRecoveryAcl $file $false
    [IO.File]::WriteAllText($file,'OFFLINE NONSECRET FIXTURE')
    $savedAssert=(Get-Item Function:\Assert-PrivateRecoveryAcl).ScriptBlock
    try {
        Set-Item Function:\Assert-PrivateRecoveryAcl -Value { param($Path,$Directory) $script:aclOperation='READ_ACL'; throw [Security.AccessControl.PrivilegeNotHeldException]::new('SeRestorePrivilege') }
        $rejected=$false
        try { Confirm-PrivateRecoveryFile $file } catch { $rejected=$true }
        Check $rejected 'injected PrivilegeNotHeldException stops verification'
        Check (!(Test-Path -LiteralPath $file)) 'injected post-write ACL error removes fixture'
        Check ($script:stage -eq 'POST_WRITE_ACL' -and $script:aclOperation -eq 'READ_ACL') 'injected failure has precise stage and operation'
    } finally { Set-Item Function:\Assert-PrivateRecoveryAcl -Value $savedAssert }
    $source=[IO.File]::ReadAllText((Join-Path $PSScriptRoot '..\scripts\prepare-mysql-3307-recovery-file.ps1'))
    Check ($source.Contains('Confirm-PrivateRecoveryFile $initSqlPath') -and $source.Contains('acl_operation=$failureOperation') -and !$source.Contains('$_.Exception.Message')) 'preparer uses verification and redacted diagnostics'
    Write-Output ('PASS: '+$checks+' Windows ACL offline checks; dummy data only; no password prompt or MySQL action')
} finally {
    if([IO.Path]::GetFullPath($fixture).StartsWith($testRoot+'\',[StringComparison]::OrdinalIgnoreCase)) {
        if(Test-Path -LiteralPath $file) { Remove-Item -LiteralPath $file -Force }
        if(Test-Path -LiteralPath $fixture) { Remove-Item -LiteralPath $fixture -Force }
    }
}
