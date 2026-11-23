$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest
$path = Join-Path (Split-Path -Parent $PSScriptRoot) 'scripts\backup-and-preflight-007.ps1'
$tokens = $null; $errors = $null
$ast = [System.Management.Automation.Language.Parser]::ParseFile($path, [ref]$tokens, [ref]$errors)
if ($errors.Count) { throw 'Backup script syntax failed.' }
$fn = $ast.Find({ param($node) $node -is [System.Management.Automation.Language.FunctionDefinitionAst] -and $node.Name -eq 'Set-PrivateAcl' }, $true)
if ($null -eq $fn) { throw 'ACL function not found.' }
# Load only the ACL function. Never run the backup entry point or access login files/MySQL.
. ([scriptblock]::Create($fn.Extent.Text))
$identity = [Security.Principal.WindowsIdentity]::GetCurrent()
$principal = New-Object Security.Principal.WindowsPrincipal($identity)
$admin = $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
Write-Output "ELEVATED_TOKEN=$admin"
if ($admin) { throw 'Run this test from a non-elevated Windows PowerShell terminal.' }
$fixture = Join-Path $PSScriptRoot ('acl-fixture-' + [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $fixture | Out-Null
try {
    Set-PrivateAcl $fixture $true
    $file = Join-Path $fixture 'nonsecret-fixture.txt'
    [IO.File]::WriteAllText($file, 'OFFLINE-NONSECRET-FIXTURE')
    $before = (Get-FileHash -LiteralPath $file -Algorithm SHA256).Hash
    $owner = (Get-Acl -LiteralPath $file).Owner
    # Simulate an existing file with an unwanted explicit principal.
    $acl = [IO.File]::GetAccessControl($file, [Security.AccessControl.AccessControlSections]::Access)
    $acl.AddAccessRule([Security.AccessControl.FileSystemAccessRule]::new([Security.Principal.SecurityIdentifier]::new('S-1-1-0'), 'Read', 'Allow'))
    [IO.File]::SetAccessControl($file, $acl)
    Set-PrivateAcl $file $false
    Set-PrivateAcl $file $false
    if ((Get-FileHash -LiteralPath $file -Algorithm SHA256).Hash -ne $before) { throw 'File content changed.' }
    if ((Get-Acl -LiteralPath $file).Owner -ne $owner) { throw 'Owner changed.' }
    $acl = [IO.File]::GetAccessControl($file, [Security.AccessControl.AccessControlSections]::Access)
    $rules = @($acl.GetAccessRules($true, $true, [Security.Principal.SecurityIdentifier]))
    if ($rules.Count -ne 2 -or -not $acl.AreAccessRulesProtected) { throw 'Private DACL not enforced.' }
    $rejected = $false
    try { Set-PrivateAcl $file $true } catch { $rejected = $true }
    if (-not $rejected) { throw 'Object type mismatch was not rejected.' }
    $rejected = $false
    try { Set-PrivateAcl (Join-Path $fixture 'missing') $false } catch { $rejected = $true }
    if (-not $rejected) { throw 'Missing path was not rejected.' }
    Write-Output 'PASS: PS 5.1 syntax; non-admin DACL for directory/file; unwanted principal removed; repeated call; content/owner unchanged; failures stop.'
} finally {
    if (Test-Path -LiteralPath $fixture) { Remove-Item -LiteralPath $fixture -Recurse -Force }
}
