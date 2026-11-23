Set-StrictMode -Version Latest
$ErrorActionPreference='Stop'
$root=(Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$path=Join-Path $root 'scripts/recover-test-auth-credentials.ps1'
$tokens=$null;$errors=$null
$ast=[System.Management.Automation.Language.Parser]::ParseFile($path,[ref]$tokens,[ref]$errors)
if($errors.Count){throw 'Root check source syntax error'}
$branch=$ast.Find({param($n)$n -is [System.Management.Automation.Language.IfStatementAst] -and $n.Clauses[0].Item1.Extent.Text -eq '$RootConnectionOnly'},$true)
if($null -eq $branch){throw 'Root-only branch missing'}
$text=$branch.Extent.Text
$source=Get-Content -LiteralPath $path -Raw
if($source.IndexOf($text) -gt $source.IndexOf('$recoveryLock = Enter-UpVanRecoveryLock')){throw 'Root-only check touches recovery state setup'}
if($text -match 'Save-RecoveryState|Move-RecoveryState|Write-UpVanAtomicEnvFile|Remove-Item|reconcile-|Set-RecoveryEnv'){throw 'Root check contains a mutation path'}
if(-not $text.Contains("Invoke-RecoveryPhp 'root-check'") -or -not $text.Contains('exit 0') -or -not $text.Contains("'UP_VAN_RECOVERY_ROOT_PASSWORD',`$previousRoot,'Process'")){throw 'Root check does not exit and restore environment'}
Write-Output 'PASS: root-only branch exits before recovery lock/state and restores prompt environment'
$php=Get-Content -LiteralPath (Join-Path $root 'scripts/recover-test-auth-credentials-execute.php') -Raw
$start=$php.IndexOf('$root = recoverRootConnection($sqlStep);')
$stop=$php.IndexOf("if (`$action === 'root-check') recoverOutput('PASS','ROOT_CONNECTION','ROOT_CONNECTION_VERIFIED');",$start)
$target=$php.IndexOf('$preflight = assertRecoveryTargetAndGrants($root, $sqlStep);',$start)
if($start -lt 0 -or $stop -lt $start -or $target -lt $stop){throw 'Root-only PHP branch cannot be proven read-only'}
Write-Output 'PASS: PHP root-only action exits after connect/identity/version, before account or recovery operations'
$priorPreference=$ErrorActionPreference
try{
    $ErrorActionPreference='Continue'
    $output=& powershell.exe -NoProfile -ExecutionPolicy Bypass -File $path -RootConnectionOnly -Execute -ConfirmTarget up_van_booking_test 2>&1 | Out-String
    $code=$LASTEXITCODE
}finally{$ErrorActionPreference=$priorPreference}
if($code -eq 0 -or -not $output.Contains('Choose exactly one')){throw 'Root-only mutual exclusion failed'}
Write-Output 'PASS: RootConnectionOnly with Execute is rejected before configuration/password prompt'
Write-Output 'PASS: 3 root-only offline checks; no database connection attempted.'
