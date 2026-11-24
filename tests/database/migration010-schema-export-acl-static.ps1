#Requires -Version 5.1
#Requires -PSEdition Desktop
$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$root = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
$script = Join-Path $root 'scripts\migration010-schema-export.ps1'
$tokens = $null
$errors = $null
$ast = [System.Management.Automation.Language.Parser]::ParseFile($script, [ref]$tokens, [ref]$errors)
if ($errors.Count -ne 0) { throw 'Schema export PowerShell syntax failed.' }

$helperNames = @('New-PrivateSnapshotDirectory', 'Assert-PrivateSnapshotFile', 'Get-SnapshotSha256', 'Write-SnapshotManifest', 'Invoke-FreshSnapshotPreflight')
$helpers = @($ast.FindAll({
    param($node)
    $node -is [System.Management.Automation.Language.FunctionDefinitionAst] -and
        $node.Name -in $helperNames
}, $false))
if ($helpers.Count -ne $helperNames.Count) { throw 'Export helper functions are missing.' }

$allowedCommands = @('Set-StrictMode', 'Read-Identity', 'Invoke-Pre010Backup', 'Read-BackupInventory',
    'Convert-BackupHex', 'Get-CalendarRestoreSql') + $helperNames
$commands = $ast.FindAll({ param($node) $node -is [System.Management.Automation.Language.CommandAst] }, $true)
foreach ($command in $commands) {
    $name = $command.GetCommandName()
    if ($name -and $name -notin $allowedCommands) { throw "Unexpected exporter command dependency: $name" }
}

$topLevel = @($ast.EndBlock.Statements | ForEach-Object { $_.Extent.Text })
$prepare = [Array]::IndexOf($topLevel, 'New-PrivateSnapshotDirectory -Path $directory')
$dump = @($topLevel | Where-Object { $_ -like '& $dump @common *' })
if ($prepare -lt 0 -or $dump.Count -ne 1 -or $prepare -ge [Array]::IndexOf($topLevel, $dump[0])) {
    throw 'Snapshot ACL must be verified before mysqldump runs.'
}
if ($dump[0] -notmatch ' --no-data ' -or $ast.Extent.Text -notmatch "'--no-login-paths'" -or
    $ast.Extent.Text -notmatch "'--password'") {
    throw 'Expected schema-only dump and interactive credential options.'
}
$backupFunction = $ast.Find({
    param($node)
    $node -is [System.Management.Automation.Language.FunctionDefinitionAst] -and $node.Name -eq 'Invoke-Pre010Backup'
}, $false)
$backupStatements = @($backupFunction.Body.EndBlock.Statements | ForEach-Object { $_.Extent.Text })
$backupDump = @($backupStatements | Where-Object { $_ -like '& $dump @common *' })
if ($backupDump.Count -ne 1 -or $backupDump[0] -notmatch '--init-command=\$backupGuard' -or
    $backupDump[0] -notmatch '--lock-tables.*--hex-blob.*--skip-extended-insert' -or
    $backupDump[0] -notmatch 'up_van_booking_test @tables$' -or $backupDump[0] -match '--(?:no-data|all-databases|lock-all-tables|force)\b' -or
    [Array]::IndexOf($backupStatements, 'New-PrivateSnapshotDirectory -Path $directory') -lt 0 -or
    [Array]::IndexOf($backupStatements, 'New-PrivateSnapshotDirectory -Path $directory') -ge [Array]::IndexOf($backupStatements, $backupDump[0])) {
    throw 'Full backup must use the guarded inventory and a private directory before dumping data.'
}

# Load only the filesystem helpers under test. Never execute the export or MySQL.
foreach ($helper in $helpers) { . ([ScriptBlock]::Create($helper.Extent.Text)) }
$temp = [IO.Path]::Combine([IO.Path]::GetTempPath(), 'upvan-schema-acl-test-' + [Guid]::NewGuid().ToString('N'))
$privatePath = [IO.Path]::Combine($temp, 'private')
$existingPath = [IO.Path]::Combine($temp, 'existing')
$probe = [IO.Path]::Combine($privatePath, 'schema.sql')
$manifestPath = [IO.Path]::Combine($privatePath, 'manifest.json')
$fixtureWrapper = [IO.Path]::Combine($temp, 'offline-wrapper.ps1')
$fixtureResult = [IO.Path]::Combine($privatePath, 'offline-output.json')
$badParent = [IO.Path]::Combine($temp, 'not-a-directory')
$previousModulePath = $env:PSModulePath
$autoLoadingVariable = Get-Variable PSModuleAutoLoadingPreference -ErrorAction SilentlyContinue
$previousAutoLoading = if ($autoLoadingVariable) { $autoLoadingVariable.Value } else { 'All' }

try {
    [IO.Directory]::CreateDirectory($temp) | Out-Null
    foreach ($module in @('Microsoft.PowerShell.Security','Microsoft.PowerShell.Utility','Microsoft.PowerShell.Management')) {
        if (Get-Module $module) { Remove-Module $module }
    }
    $env:PSModulePath = [IO.Path]::Combine($temp, 'missing-modules')
    $PSModuleAutoLoadingPreference = 'None'
    foreach ($name in @('Set-Acl','Get-FileHash','ConvertTo-Json','Join-Path')) {
        if (Get-Command $name -ErrorAction SilentlyContinue) { throw "$name was expected to be unavailable." }
    }

    New-PrivateSnapshotDirectory -Path $privatePath
    $sid = [Security.Principal.WindowsIdentity]::GetCurrent().User
    $directoryAcl = [IO.DirectoryInfo]::new($privatePath).GetAccessControl()
    $directoryRules = $directoryAcl.GetAccessRules($true, $true, [Security.Principal.SecurityIdentifier])
    if (!$directoryAcl.AreAccessRulesProtected -or $directoryRules.Count -ne 1 -or
        !$directoryRules[0].IdentityReference.Equals($sid) -or $directoryRules[0].IsInherited) {
        throw 'Directory must be private without the Security module.'
    }
    if (Get-Module Microsoft.PowerShell.Security) { throw 'ACL creation unexpectedly loaded the Security module.' }

    [IO.File]::WriteAllBytes($probe, [byte[]]@())
    Assert-PrivateSnapshotFile -Path $probe
    if ((Get-SnapshotSha256 -Path $probe) -cne 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855') {
        throw 'SHA-256 did not match the empty-input test vector.'
    }
    [IO.File]::WriteAllBytes($probe, [Text.Encoding]::ASCII.GetBytes('abc'))
    $hash = Get-SnapshotSha256 -Path $probe
    if ($hash -cne 'ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad') {
        throw 'SHA-256 did not match the abc test vector.'
    }
    $exclusive = [IO.File]::Open($probe, [IO.FileMode]::Open, [IO.FileAccess]::ReadWrite, [IO.FileShare]::None)
    $exclusive.Dispose()
    Write-SnapshotManifest -Path $manifestPath -Manifest ([ordered]@{
        port=3306; sha256=$hash; tables=@('users','drivers'); live_schema_verified=$false
    })
    Assert-PrivateSnapshotFile -Path $manifestPath
    $parsed = [System.Web.Script.Serialization.JavaScriptSerializer]::new().DeserializeObject([IO.File]::ReadAllText($manifestPath))
    if ($parsed['port'] -isnot [int] -or $parsed['port'] -ne 3306 -or $parsed['sha256'] -cne $hash -or
        ($parsed['tables'] -join ',') -cne 'users,drivers' -or
        $parsed['live_schema_verified'] -isnot [bool] -or $parsed['live_schema_verified']) {
        throw 'The manifest must preserve its JSON types and SHA-256.'
    }

    [IO.File]::WriteAllText($fixtureWrapper, @'
param([string]$Action, [string]$PhpRuntimePath, [string]$SchemaSnapshotDirectory)
if ($Action -cne 'Preflight' -or $PhpRuntimePath -cne 'offline-php.exe') { exit 12 }
[IO.File]::ReadAllText([IO.Path]::Combine($SchemaSnapshotDirectory, 'offline-output.json'))
'@)
    $php = 'offline-php.exe'
    $evidence = @{
        database='up_van_booking_test';uuid='77218fbd-d66d-11f1-9a0a-5c2886f057d5';sha256=$hash
        export_started_utc='2026-11-24T04:20:00Z';export_finished_utc='2026-11-24T04:20:01Z'
    }
    $result = $evidence.Clone()
    $result.Remove('sha256')
    $result['snapshot_sha256'] = $hash
    $result['status'] = 'PASS'
    $result['mode'] = 'preflight'
    $result['schema_source'] = 'offline_snapshot'
    $result['live_schema_verified'] = $false
    $result['apply_ready'] = $false
    $serializer = [System.Web.Script.Serialization.JavaScriptSerializer]::new()
    [IO.File]::WriteAllText($fixtureResult, $serializer.Serialize($result))
    $verification = @(Invoke-FreshSnapshotPreflight -Directory $privatePath -Manifest $evidence -Wrapper $fixtureWrapper)
    if ($verification[-1] -cne 'FRESH_DBA_SCHEMA_PRECONDITIONS=PASS') { throw 'Matching fresh preflight evidence should pass.' }
    foreach ($mismatch in @{ snapshot_sha256=('0' * 64); export_started_utc='2026-11-23T04:20:00Z'; apply_ready=$true }.GetEnumerator()) {
        $invalidResult = $result.Clone()
        $invalidResult[$mismatch.Key] = $mismatch.Value
        [IO.File]::WriteAllText($fixtureResult, $serializer.Serialize($invalidResult))
        try {
            $null = Invoke-FreshSnapshotPreflight -Directory $privatePath -Manifest $evidence -Wrapper $fixtureWrapper
            throw "Expected preflight to reject mismatched $($mismatch.Key)."
        } catch {
            if ($_.Exception.Message -cne 'FRESH_DBA_SCHEMA_PREFLIGHT_EVIDENCE_REJECTED') { throw }
        }
    }
    $fileRules = [IO.FileInfo]::new($probe).GetAccessControl().GetAccessRules($true, $true, [Security.Principal.SecurityIdentifier])
    if ($fileRules.Count -ne 1 -or !$fileRules[0].IdentityReference.Equals($sid) -or !$fileRules[0].IsInherited -or
        $fileRules[0].AccessControlType -ne [Security.AccessControl.AccessControlType]::Allow) {
        throw 'Snapshot files must inherit the current-user-only ACL.'
    }

    [IO.Directory]::CreateDirectory($existingPath) | Out-Null
    $existingBefore = [IO.DirectoryInfo]::new($existingPath).GetAccessControl().GetSecurityDescriptorSddlForm([Security.AccessControl.AccessControlSections]::Access)
    [IO.File]::WriteAllText($badParent, 'offline ACL failure probe')
    foreach ($invalid in @($existingPath, [IO.Path]::Combine($badParent, 'invalid'))) {
        try {
            New-PrivateSnapshotDirectory -Path $invalid
            throw 'Expected snapshot directory creation to fail.'
        } catch {
            if ($_.Exception.Message -notlike 'SCHEMA_SNAPSHOT_ACL_FAILED:*') { throw }
        }
    }
    $existingAfter = [IO.DirectoryInfo]::new($existingPath).GetAccessControl().GetSecurityDescriptorSddlForm([Security.AccessControl.AccessControlSections]::Access)
    if ($existingBefore -cne $existingAfter) { throw 'An existing directory must be left untouched.' }
} finally {
    $env:PSModulePath = $previousModulePath
    $PSModuleAutoLoadingPreference = $previousAutoLoading
    foreach ($file in @($probe, $manifestPath, $fixtureResult, $fixtureWrapper, $badParent)) {
        if ([IO.File]::Exists($file)) { [IO.File]::Delete($file) }
    }
    foreach ($directory in @($privatePath, $existingPath, $temp)) {
        if ([IO.Directory]::Exists($directory)) { [IO.Directory]::Delete($directory) }
    }
}

Write-Output 'PASS: PowerShell 5.1 syntax, ACL guards, no module commands, SHA-256, and fresh preflight evidence. No database contact.'
