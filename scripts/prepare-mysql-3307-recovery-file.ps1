#requires -Version 5.1
# Step 3 implementation; shared ACL helper is tested with nonsecret fixtures.
# PREPARATION ONLY. Never starts MySQL or connects to a database.
param([switch]$PreflightOnly)
Set-StrictMode -Version Latest
$ErrorActionPreference='Stop'
$stage='RUNTIME'
$aclOperation=$null
$initSqlPath=$null
trap {
    $failureStep=$stage;$failureOperation=$aclOperation;$failureType=$_.Exception.GetBaseException().GetType().Name
    $cleanup='NOT_NEEDED'
    if($initSqlPath) {
        if($stage -eq 'ERROR_CLEANUP') { $cleanup='FAILED' }
        else {
            try {
                if(Test-Path -LiteralPath $initSqlPath -PathType Leaf -ErrorAction Stop) {
                    Remove-Item -LiteralPath $initSqlPath -Force -ErrorAction Stop
                    $cleanup='REMOVED'
                } else { $cleanup='NO_FILE' }
            } catch { $cleanup='FAILED' }
        }
    }
    [ordered]@{status='FAIL';code='RECOVERY_PREPARATION_FAILED';step=$failureStep;acl_operation=$failureOperation;error_type=$failureType;cleanup=$cleanup} | ConvertTo-Json -Compress | Write-Host
    exit 1
}
if($PSVersionTable.PSEdition -ne 'Desktop' -or $PSVersionTable.PSVersion.Major -ne 5) { throw 'Use Windows PowerShell 5.1' }
$restoreData='C:\Users\User\mysql-restore-006-data'
$stage='PROCESS_CHECK'
$allMysql=@(Get-CimInstance Win32_Process -Filter "Name='mysqld.exe'" -ErrorAction Stop)
if(@($allMysql | Where-Object { -not $_.CommandLine }).Count -gt 0) { throw 'Process command lines not visible; STOP' }
$restoreProcesses=@($allMysql | Where-Object {
    $_.CommandLine.Contains($restoreData) -or $_.CommandLine -match '--port(?:=|\s+)3307(?:\s|$)'
})
if($restoreProcesses.Count -gt 0) { throw 'Restore MySQL process remains; STOP' }
$stage='LISTENER_CHECK'
if(@(Get-NetTCPConnection -State Listen -ErrorAction Stop | Where-Object LocalPort -eq 3307).Count -gt 0) { throw '3307 listener remains; STOP' }
Write-Host 'RESTORE_INSTANCE_STOPPED=PASS'
$stage='PATH_CHECK'
$profileRoot=[Environment]::GetFolderPath('UserProfile')
if([string]::IsNullOrWhiteSpace($profileRoot)) { throw 'Windows profile unavailable' }
$profileRoot=[IO.Path]::GetFullPath($profileRoot)
$projectRoot=[IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..')).TrimEnd('\')
$recoveryDir=Join-Path $profileRoot ('mysql-3307-recovery-'+[guid]::NewGuid().ToString('N'))
if($recoveryDir.StartsWith($projectRoot+'\',[StringComparison]::OrdinalIgnoreCase)) { throw 'Recovery path is inside project' }
$ancestor=$profileRoot
while($ancestor) {
    if(Test-Path -LiteralPath (Join-Path $ancestor '.git')) { throw 'Recovery path is inside a Git repository' }
    if(((Get-Item -LiteralPath $ancestor -Force).Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) { throw 'Recovery ancestor is a reparse point' }
    $parent=[IO.Directory]::GetParent($ancestor)
    $ancestor=if($null -eq $parent){$null}else{$parent.FullName}
}
$initSqlPath=Join-Path $recoveryDir 'root-init.sql'
$recoveryLog=Join-Path $recoveryDir 'restore3307.err'
if($PreflightOnly) {
    [ordered]@{status='PASS';mode='READ_ONLY_PREFLIGHT';target_port=3307;process_check='PASS';listener_check='PASS';outside_repository='PASS';files_created=$false;password_prompted=$false} | ConvertTo-Json -Compress | Write-Host
    return
}
$stage='ACL_HELPER_LOAD'
. (Join-Path $PSScriptRoot 'lib\mysql-3307-recovery-acl.ps1')
if(Test-Path -LiteralPath $recoveryDir) { throw 'Recovery directory already exists' }
$stage='CREATE_DIRECTORY'
[void](New-Item -ItemType Directory -Path $recoveryDir)
$stage='DIRECTORY_ACL'
Set-PrivateRecoveryAcl $recoveryDir $true
$stage='CREATE_INIT_FILE'
[void](New-Item -ItemType File -Path $initSqlPath)
$stage='INIT_FILE_ACL'
Set-PrivateRecoveryAcl $initSqlPath $false
$stage='PASSWORD_INPUT'
$first=Read-Host 'New root password for 3307 ONLY' -AsSecureString
$second=Read-Host 'Confirm new password' -AsSecureString
$firstPtr=[IntPtr]::Zero;$secondPtr=[IntPtr]::Zero
try {
    $stage='PASSWORD_VALIDATION'
    $firstPtr=[Runtime.InteropServices.Marshal]::SecureStringToBSTR($first)
    $secondPtr=[Runtime.InteropServices.Marshal]::SecureStringToBSTR($second)
    $plain=[Runtime.InteropServices.Marshal]::PtrToStringBSTR($firstPtr)
    $confirm=[Runtime.InteropServices.Marshal]::PtrToStringBSTR($secondPtr)
    if($plain -cne $confirm -or $plain.Length -lt 16 -or $plain.IndexOfAny([char[]]@(0,10,13)) -ge 0) { throw 'Password rejected' }
    $escaped=$plain.Replace("'","''")
    $sql="SET NAMES utf8mb4;`nSET SESSION sql_mode='NO_BACKSLASH_ESCAPES';`nALTER USER 'root'@'localhost' IDENTIFIED BY '$escaped';`n"
    $stage='PRE_WRITE_ACL'
    Assert-PrivateRecoveryAcl $recoveryDir $true
    Assert-PrivateRecoveryAcl $initSqlPath $false
    $stage='SQL_FILE_WRITE'
    [IO.File]::WriteAllText($initSqlPath,$sql,[Text.UTF8Encoding]::new($false))
    $stage='POST_WRITE_ACL'
    Confirm-PrivateRecoveryFile $initSqlPath
} catch {
    if($stage -eq 'ERROR_CLEANUP') { throw }
    $failedStage=$stage
    $stage='ERROR_CLEANUP'
    if(Test-Path -LiteralPath $initSqlPath) { Remove-Item -LiteralPath $initSqlPath -Force }
    $stage=$failedStage
    throw
} finally {
    if($firstPtr -ne [IntPtr]::Zero) { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($firstPtr) }
    if($secondPtr -ne [IntPtr]::Zero) { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($secondPtr) }
    $plain=$null;$confirm=$null;$escaped=$null;$sql=$null
    $first.Dispose();$second.Dispose()
}


# Confirm the same ACL again without reading the SQL contents.
$stage='FINAL_ACL_CHECK'
Assert-PrivateRecoveryAcl $recoveryDir $true
Assert-PrivateRecoveryAcl $initSqlPath $false
if((Get-Item -LiteralPath $initSqlPath).Length -le 0) { throw 'Recovery file empty' }
$marker=[ordered]@{
    status='RECOVERY_FILE_PREPARED'
    target_port=3307
    datadir=$restoreData
    recovery_file=$initSqlPath
    acl='CURRENT_USER_AND_SYSTEM_ONLY'
    source='docs/mysql-3307-root-recovery-plan.md Step 3'
    created_utc=[DateTime]::UtcNow.ToString('o')
}
$stage='STATUS_MARKER_WRITE'
[IO.File]::WriteAllText((Join-Path $recoveryDir 'prepared-status.json'),($marker | ConvertTo-Json),[Text.UTF8Encoding]::new($false))
Write-Host 'RECOVERY_FILE_PREPARED'
Write-Host ('RECOVERY_FILE='+$initSqlPath)
Write-Host 'ACL=PASS; STOP. Do not start MySQL with init-file yet.'
