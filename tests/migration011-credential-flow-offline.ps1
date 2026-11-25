$ErrorActionPreference='Stop'
. (Join-Path $PSScriptRoot '../scripts/lib/migration011-apply-credentials.ps1')
$checks=0
function Assert([bool]$Condition,[string]$Message){if(!$Condition){throw "FAIL: $Message"};$script:checks++}
$directory=Join-Path ([IO.Path]::GetTempPath()) ('upvan011-dpapi-offline-'+[guid]::NewGuid().ToString('N'))
$path=Join-Path $directory 'apply.dpapi'
$sample='offline-only-credential-!@#-unicode-รถตู้'
$secure=$null;$loaded=$null;$plainPtr=[IntPtr]::Zero;$loadedPtr=[IntPtr]::Zero
try{
    $output="UPVAN_MIGRATION011_GUARD_OK`nup_van_migration011_apply`t127.0.0.1`t$sample`t1`nUPVAN_MIGRATION011_DONE`n"
    $parsed=Get-Migration011ProvisionOutput $output
    Assert ($parsed.GuardPassed -and $parsed.Completed -and $parsed.Password -ceq $sample) 'generated MySQL result is captured only for the exact account and host'
    $captured=[string]$parsed.Password
    $secure=ConvertTo-SecureString -String $captured -AsPlainText -Force
    $saved=Save-Migration011DpapiCredential -Password $captured -Path $path
    $captured=$null;$parsed.Password=$null
    Assert ($saved -ceq $path -and (Test-Path -LiteralPath $path -PathType Leaf)) 'DPAPI credential stored at the dedicated path'
    $stored=[IO.File]::ReadAllText($path,[Text.Encoding]::ASCII)
    Assert (!$stored.Contains($sample)) 'DPAPI file contains no plaintext credential'
    $loaded=Read-Migration011DpapiCredential -Path $path
    $loadedPtr=[Runtime.InteropServices.Marshal]::SecureStringToBSTR($loaded)
    $roundTrip=[Runtime.InteropServices.Marshal]::PtrToStringBSTR($loadedPtr)
    Assert ($roundTrip -ceq $sample) 'CurrentUser DPAPI round trip decrypts for this Windows user'
    $child=Invoke-Migration011RunnerChild -Password $loaded -RunnerArguments @() -Mock
    Assert ($child.ExitCode -eq 0 -and $child.Stdout.Trim() -ceq 'MOCK_CHILD_CREDENTIAL_RECEIVED=TRUE') 'Apply Runner child receives the credential in process environment'
    Assert (!$child.Stdout.Contains($sample) -and !$child.Stderr.Contains($sample)) 'child output does not reveal the credential'
    $bad=$false;try{$null=Get-Migration011ProvisionOutput "UPVAN_MIGRATION011_GUARD_OK`nother_user`t127.0.0.1`t$sample`t1`n"}catch{$bad=$_.Exception.Message -eq 'MIGRATION_011_PROVISION_OUTPUT_INVALID'}
    Assert $bad 'provision output for any other account is rejected'
    $bad=$false;try{$null=Get-Migration011ProvisionOutput "UPVAN_MIGRATION011_GUARD_OK`nup_van_migration011_apply`t127.0.0.1`t$sample`t2`n"}catch{$bad=$_.Exception.Message -eq 'MIGRATION_011_PROVISION_OUTPUT_INVALID'}
    Assert $bad 'unsupported MySQL authentication factor output is rejected'
    $partial=Get-Migration011ProvisionOutput "UPVAN_MIGRATION011_GUARD_OK`nup_van_migration011_apply`t127.0.0.1`t$sample`t1`n"
    Assert ($partial.GuardPassed -and !$partial.Completed -and $partial.Password -ceq $sample) 'partial provision retains generated credential for safe storage but is not marked complete'
    $bad=$false;try{$null=Get-Migration011ProvisionOutput "UPVAN_MIGRATION011_GUARD_OK`nup_van_migration011_apply`t127.0.0.1`t$sample`t1`nup_van_migration011_apply`t127.0.0.1`t$sample`t1`n"}catch{$bad=$_.Exception.Message -eq 'MIGRATION_011_GENERATED_PASSWORD_OUTPUT_AMBIGUOUS'}
    Assert $bad 'ambiguous generated-password output is rejected'
    Assert ((Get-Migration011LoginPathStatus -ExitCode 0 -Output "[client]`r`n[upvan3306-backup]`r`nuser=root`r`n") -ceq 'PRESENT') 'valid DBA login path is found in CRLF utility output'
    Assert ((Get-Migration011LoginPathStatus -ExitCode 0 -Output "[client]`r`n[other-path]`r`n") -ceq 'MISSING') 'missing DBA login path is distinguished from a valid path'
    Assert ((Get-Migration011LoginPathStatus -ExitCode 1 -Output '[upvan3306-backup]') -ceq 'INVALID') 'failed login-path utility is rejected'
    Assert ((Get-Migration011LoginPathStatus -ExitCode 0 -Output 'unrecognized output') -ceq 'INVALID') 'unrecognized login-path output is rejected'
    $expectedIdentity="3306`t77218fbd-d66d-11f1-9a0a-5c2886f057d5`tup_van_booking_test`t8.4.9`troot@localhost"
    Assert (Test-Migration011DbaIdentityOutput -Output $expectedIdentity) 'exact DBA database identity is accepted'
    Assert (!(Test-Migration011DbaIdentityOutput -Output ($expectedIdentity -replace '77218fbd-d66d-11f1-9a0a-5c2886f057d5','wrong-uuid'))) 'identity mismatch fails closed'
    $childStart=[Diagnostics.ProcessStartInfo]::new();$childStart.UseShellExecute=$false
    Set-Migration011ProvisionChildEnvironment -StartInfo $childStart -SessionEnvironment @{USERPROFILE='C:\Users\Offline';APPDATA='C:\Users\Offline\AppData\Roaming';LOCALAPPDATA='C:\Users\Offline\AppData\Local'}
    Assert ($childStart.EnvironmentVariables['APPDATA'] -ceq 'C:\Users\Offline\AppData\Roaming' -and $childStart.EnvironmentVariables['UPVAN_MIGRATION011_PROVISION_APPROVED'] -ceq 'true') 'child receives required Windows profile context and process-scoped provision approval'
    $badStart=[Diagnostics.ProcessStartInfo]::new();$badStart.UseShellExecute=$false
    $bad=$false;try{Set-Migration011ProvisionChildEnvironment -StartInfo $badStart -SessionEnvironment @{USERPROFILE='C:\Users\Offline'}}catch{$bad=$_.Exception.Message -eq 'MIGRATION_011_USER_SESSION_ENVIRONMENT_MISSING'}
    Assert $bad 'child launch is blocked when required Windows user environment is missing'
    Write-Output "PASS: $checks Migration 011 credential-flow offline checks; DPAPI CurrentUser and mock child only."
}finally{
    if($plainPtr -ne [IntPtr]::Zero){[Runtime.InteropServices.Marshal]::ZeroFreeBSTR($plainPtr)}
    if($loadedPtr -ne [IntPtr]::Zero){[Runtime.InteropServices.Marshal]::ZeroFreeBSTR($loadedPtr)}
    if($loaded){$loaded.Dispose()};if($secure){$secure.Dispose()};$roundTrip=$null;$stored=$null;$sample=$null
    if(Test-Path -LiteralPath $path){Remove-Item -LiteralPath $path -Force}
    if(Test-Path -LiteralPath $directory){Remove-Item -LiteralPath $directory -Force}
}
