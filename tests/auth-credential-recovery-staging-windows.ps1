if ([Environment]::OSVersion.Platform -ne [PlatformID]::Win32NT) { throw 'Recovery staging behavior test requires Windows.' }
$ErrorActionPreference='Stop'
$root=(Join-Path $PSScriptRoot ('.recovery-staging-test-' + [Guid]::NewGuid().ToString('N')))
New-Item -ItemType Directory -Path $root -Force | Out-Null
. (Join-Path $PSScriptRoot '..\scripts\lib\env-file-security.ps1')
. (Join-Path $PSScriptRoot '..\scripts\lib\recovery-staging.ps1')
$stageRoot=Join-Path $root '.env.recovery-staging'
Ensure-UpVanRecoveryStagingDirectory -Path $stageRoot
$checks=0
function Assert-Stage([bool]$Condition,[string]$Label){if(-not $Condition){throw "Recovery staging behavior failed: $Label"};$script:checks++;Write-Output "PASS: $Label"}
try {
    $id=[Guid]::NewGuid().ToString('N')
    $paths=Get-UpVanRecoveryStagingPaths -Directory $stageRoot -TransactionId $id
    foreach($path in $paths){New-Item -ItemType File -Path $path -Force|Out-Null;[IO.File]::WriteAllText($path,'dummy-only');Set-UpVanEnvAcl -Path $path -Mode Localhost -PoolName '' -PoolSidOverride $null}
    $otherId=[Guid]::NewGuid().ToString('N')
    $other=Get-UpVanRecoveryStagingPaths -Directory $stageRoot -TransactionId $otherId | Select-Object -First 1
    New-Item -ItemType File -Path $other -Force|Out-Null;[IO.File]::WriteAllText($other,'dummy-only');Set-UpVanEnvAcl -Path $other -Mode Localhost -PoolName '' -PoolSidOverride $null
    $removed=Remove-UpVanRecoveryStaging -Directory $stageRoot -TransactionId $id
    Assert-Stage ($removed -eq 3 -and @($paths|Where-Object{Test-Path -LiteralPath $_}).Count -eq 0) 'only the matching transaction staging set is removed'
    Assert-Stage (Test-Path -LiteralPath $other) 'unowned staging file remains untouched'
    $badRejected=$false
    try{[void](Get-UpVanRecoveryStagingPaths -Directory $stageRoot -TransactionId 'not-a-transaction')}catch{$badRejected=$true}
    Assert-Stage $badRejected 'invalid transaction identifiers cannot select cleanup paths'
    $target=Join-Path $root '.env-dummy-test'
    New-Item -ItemType File -Path $target -Force|Out-Null;[IO.File]::WriteAllText($target,'old-dummy');Set-UpVanEnvAcl -Path $target -Mode Localhost -PoolName '' -PoolSidOverride $null
    $targetSddl=Get-UpVanAclFingerprint -Path $target
    $writerId=[Guid]::NewGuid().ToString('N')
    Write-UpVanAtomicEnvFile -Path $target -Text 'new-dummy' -Mode Localhost -PoolName '' -ExpectedOriginalSddl $targetSddl -PoolSidOverride $null -StagingId $writerId -StagingDirectory $stageRoot
    $writerArtifacts=Get-UpVanRecoveryStagingPaths -Directory $stageRoot -TransactionId $writerId
    Assert-Stage ([IO.File]::ReadAllText($target) -ceq 'new-dummy' -and @($writerArtifacts|Where-Object{Test-Path -LiteralPath $_}).Count -eq 0) 'atomic .env replacement in restricted staging folder cleans its transaction artifacts'
    $unsafe=Join-Path $stageRoot ('.env.secure-'+[Guid]::NewGuid().ToString('N')+'.tmp')
    New-Item -ItemType File -Path $unsafe -Force|Out-Null
    $unsafeAcl=Get-Acl -LiteralPath $unsafe;$unsafeAcl.SetAccessRuleProtection($false,$true);Set-Acl -LiteralPath $unsafe -AclObject $unsafeAcl
    $unsafeRejected=$false
    try{[void](Remove-UpVanRecoveryStaging -Directory $stageRoot -TransactionId ([IO.Path]::GetFileName($unsafe).Substring(12,32)))}catch{$unsafeRejected=$true}
    Assert-Stage ($unsafeRejected -and (Test-Path -LiteralPath $unsafe)) 'unsafe ACL staging is retained and rejected'
    Write-Output "PASS: $checks Windows recovery staging checks; scratch files contained dummy data only."
} finally { Remove-Item -LiteralPath $root -Recurse -Force -ErrorAction SilentlyContinue }
