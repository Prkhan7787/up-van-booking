. (Join-Path $PSScriptRoot 'migration011-apply-lifecycle.ps1')

function Get-Migration011CredentialPath {
    $root=[IO.Path]::Combine([Environment]::GetFolderPath('LocalApplicationData'),'UPVanBooking','migration011')
    return [IO.Path]::Combine($root,'apply.dpapi')
}

function Resolve-Migration011EvaluatorRuntime([string]$ProjectRoot) {
    # Read Git metadata as UTF-8 rather than decoding Unicode native git stdout in PS 5.1.
    $project=[IO.Path]::GetFullPath($ProjectRoot)
    $gitEntry=Join-Path $project '.git'
    $main=$project
    if([IO.File]::Exists($gitEntry)){
        $entry=[IO.File]::ReadAllText($gitEntry,[Text.Encoding]::UTF8).Trim()
        if($entry -notmatch '^gitdir: (.+)$'){throw 'MIGRATION_011_GIT_METADATA_INVALID'}
        $gitDir=$Matches[1]
        if(![IO.Path]::IsPathRooted($gitDir)){$gitDir=Join-Path $project $gitDir}
        $gitDir=[IO.Path]::GetFullPath($gitDir)
        $commonFile=Join-Path $gitDir 'commondir'
        if(![IO.File]::Exists($commonFile)){throw 'MIGRATION_011_GIT_METADATA_INVALID'}
        $common=[IO.File]::ReadAllText($commonFile,[Text.Encoding]::UTF8).Trim()
        if(![IO.Path]::IsPathRooted($common)){$common=Join-Path $gitDir $common}
        $main=Split-Path -Parent ([IO.Path]::GetFullPath($common))
    }
    $candidates=@((Join-Path $project '.runtime\php84\php.exe'),(Join-Path $main '.runtime\php84\php.exe'),(Join-Path ($main+'.runtime') 'php84\php.exe'))
    foreach($candidate in $candidates){
        if([IO.File]::Exists($candidate)){return [IO.Path]::GetFullPath($candidate)}
    }
    throw 'MIGRATION_011_GRANT_EVALUATOR_RUNTIME_MISSING'
}

function Invoke-Migration011GrantEvaluator {
    param([string]$Runtime,[string]$Evaluator,[string]$InputJson='', [switch]$RuntimeCheck)
    if(![IO.File]::Exists($Runtime) -or [IO.Path]::GetFileName($Runtime) -ine 'php.exe'){throw 'MIGRATION_011_GRANT_EVALUATOR_RUNTIME_INVALID'}
    if(![IO.File]::Exists($Evaluator)){throw 'MIGRATION_011_GRANT_EVALUATOR_SCRIPT_MISSING'}
    $psi=[Diagnostics.ProcessStartInfo]::new();$psi.FileName=$Runtime
    $arguments=@('-n',$Evaluator)
    if($RuntimeCheck){$arguments+='--runtime-check'}
    $psi.Arguments=(($arguments|ForEach-Object{ConvertTo-Migration011WindowsArgument $_}) -join ' ')
    $psi.WorkingDirectory=Split-Path -Parent (Split-Path -Parent $Evaluator)
    $psi.UseShellExecute=$false;$psi.CreateNoWindow=$true
    $psi.RedirectStandardInput=$true;$psi.RedirectStandardOutput=$true;$psi.RedirectStandardError=$true
    $child=[Diagnostics.Process]::new();$child.StartInfo=$psi
    try{
        try{if(!$child.Start()){throw 'start'}}catch{throw 'MIGRATION_011_GRANT_EVALUATOR_CHILD_FAILED'}
        $outTask=$child.StandardOutput.ReadToEndAsync();$errTask=$child.StandardError.ReadToEndAsync()
        $child.StandardInput.Write($InputJson);$child.StandardInput.Close();$child.WaitForExit()
        $expected=if($RuntimeCheck){'MIGRATION_011_EVALUATOR_RUNTIME=8.4'}else{'MIGRATION_011_GRANTS=PASS'}
        if($child.ExitCode -ne 0 -or $outTask.Result.Trim() -cne $expected -or $errTask.Result.Length -gt 0){throw 'MIGRATION_011_GRANT_EVALUATOR_VALIDATION_FAILED'}
    }finally{$child.Dispose()}
}

function Get-Migration011LoginPathStatus {
    param([int]$ExitCode,[string]$Output,[string]$LoginPathName='upvan3306-backup')
    if($ExitCode -ne 0 -or [string]::IsNullOrWhiteSpace($Output)){return 'INVALID'}
    $names=@([regex]::Matches($Output,'\[(?<name>[^\]\r\n]+)\]') | ForEach-Object {$_.Groups['name'].Value})
    if($names.Count -eq 0){return 'INVALID'}
    if($names -ccontains $LoginPathName){return 'PRESENT'}
    return 'MISSING'
}

function Test-Migration011DbaIdentityOutput {
    param([string]$Output,[string]$ExpectedUuid='77218fbd-d66d-11f1-9a0a-5c2886f057d5')
    $parts=@(([string]$Output).Trim() -split "`t",5)
    return ($parts.Count -eq 5 -and $parts[0] -ceq '3306' -and $parts[1] -ceq $ExpectedUuid -and
        $parts[2] -ceq 'up_van_booking_test' -and $parts[3] -ceq '8.4.9' -and $parts[4] -ceq 'root@localhost')
}

function Set-Migration011ProvisionChildEnvironment {
    param([Parameter(Mandatory=$true)][Diagnostics.ProcessStartInfo]$StartInfo,[Parameter(Mandatory=$true)][Collections.IDictionary]$SessionEnvironment)
    foreach($name in @('USERPROFILE','APPDATA','LOCALAPPDATA')){
        $value=[string]$SessionEnvironment[$name]
        if([string]::IsNullOrWhiteSpace($value)){throw 'MIGRATION_011_USER_SESSION_ENVIRONMENT_MISSING'}
        $StartInfo.EnvironmentVariables[$name]=$value
    }
    $StartInfo.EnvironmentVariables['UPVAN_MIGRATION011_PROVISION_APPROVED']='true'
}

function New-Migration011PrivateDirectory([string]$Path) {
    if([IO.Directory]::Exists($Path) -or [IO.File]::Exists($Path)){throw 'MIGRATION_011_CREDENTIAL_DIRECTORY_ALREADY_EXISTS'}
    $sid=[Security.Principal.WindowsIdentity]::GetCurrent().User
    $inheritance=[Security.AccessControl.InheritanceFlags]'ContainerInherit,ObjectInherit'
    $acl=[Security.AccessControl.DirectorySecurity]::new()
    $acl.SetAccessRuleProtection($true,$false);$acl.SetOwner($sid)
    foreach($identity in @($sid,[Security.Principal.SecurityIdentifier]::new('S-1-5-18'))){
        $acl.AddAccessRule([Security.AccessControl.FileSystemAccessRule]::new($identity,[Security.AccessControl.FileSystemRights]::FullControl,$inheritance,[Security.AccessControl.PropagationFlags]::None,[Security.AccessControl.AccessControlType]::Allow))
    }
    [void][IO.Directory]::CreateDirectory($Path,$acl)
    $actual=[IO.DirectoryInfo]::new($Path).GetAccessControl()
    $rules=$actual.GetAccessRules($true,$true,[Security.Principal.SecurityIdentifier])
    $allowed=@{}
    foreach($rule in $rules){
        if($rule.AccessControlType -ne [Security.AccessControl.AccessControlType]::Allow -or
           ($rule.FileSystemRights -band [Security.AccessControl.FileSystemRights]::FullControl) -ne [Security.AccessControl.FileSystemRights]::FullControl){
            throw 'MIGRATION_011_CREDENTIAL_DIRECTORY_ACL_REJECTED'
        }
        $allowed[$rule.IdentityReference.Value]=$true
    }
    $systemSid='S-1-5-18'
    if(!$actual.AreAccessRulesProtected -or $rules.Count -ne 2 -or !$actual.GetOwner([Security.Principal.SecurityIdentifier]).Equals($sid) -or
       !$allowed.ContainsKey($sid.Value) -or !$allowed.ContainsKey($systemSid)){
        throw 'MIGRATION_011_CREDENTIAL_DIRECTORY_ACL_REJECTED'
    }
}

function Assert-Migration011CredentialFileAcl([string]$Path) {
    $sid=[Security.Principal.WindowsIdentity]::GetCurrent().User
    $file=[IO.FileInfo]::new($Path)
    $acl=$file.GetAccessControl()
    $rules=$acl.GetAccessRules($true,$true,[Security.Principal.SecurityIdentifier])
    $allowed=@{}
    foreach($rule in $rules){
        if($rule.AccessControlType -ne [Security.AccessControl.AccessControlType]::Allow -or
           ($rule.FileSystemRights -band [Security.AccessControl.FileSystemRights]::FullControl) -ne [Security.AccessControl.FileSystemRights]::FullControl){
            throw 'MIGRATION_011_CREDENTIAL_FILE_ACL_REJECTED'
        }
        $allowed[$rule.IdentityReference.Value]=$true
    }
    if($rules.Count -ne 2 -or !$acl.GetOwner([Security.Principal.SecurityIdentifier]).Equals($sid) -or
       !$allowed.ContainsKey($sid.Value) -or !$allowed.ContainsKey('S-1-5-18')){
        throw 'MIGRATION_011_CREDENTIAL_FILE_ACL_REJECTED'
    }
}

function Save-Migration011DpapiCredential {
    param([Parameter(Mandatory=$true)][string]$Password,[string]$Path=(Get-Migration011CredentialPath))
    if([string]::IsNullOrEmpty($Password)){throw 'MIGRATION_011_CREDENTIAL_EMPTY'}
    $directory=[IO.Path]::GetDirectoryName($Path)
    if([IO.File]::Exists($path)){throw 'MIGRATION_011_CREDENTIAL_ALREADY_EXISTS'}
    if(![IO.Directory]::Exists($directory)){New-Migration011PrivateDirectory $directory}
    $secure=ConvertTo-SecureString -String $Password -AsPlainText -Force
    $cipher=$secure | ConvertFrom-SecureString
    $bytes=[Text.Encoding]::ASCII.GetBytes($cipher)
    $stream=$null
    try{$stream=[IO.File]::Open($path,[IO.FileMode]::CreateNew,[IO.FileAccess]::Write,[IO.FileShare]::None);$stream.Write($bytes,0,$bytes.Length);$stream.Flush($true);$stream.Dispose();$stream=$null;Assert-Migration011CredentialFileAcl $path}
    catch{if([IO.File]::Exists($path)){Remove-Item -LiteralPath $path -Force -ErrorAction SilentlyContinue};throw 'MIGRATION_011_DPAPI_STORE_FAILED'}
    finally{if($stream){$stream.Dispose()};[Array]::Clear($bytes,0,$bytes.Length);$cipher=$null;$secure.Dispose()}
    return $path
}

function Read-Migration011DpapiCredential {
    param([string]$Path=(Get-Migration011CredentialPath))
    $path=$Path
    if(![IO.File]::Exists($path)){throw 'MIGRATION_011_CREDENTIAL_MISSING'}
    try{
        $cipher=[IO.File]::ReadAllText($path,[Text.Encoding]::ASCII).Trim()
        if(!$cipher){throw 'empty'}
        $secure=ConvertTo-SecureString -String $cipher -ErrorAction Stop
        if($secure.Length -lt 12){$secure.Dispose();throw 'short'}
        return $secure
    }catch{throw 'MIGRATION_011_DPAPI_DECRYPT_FAILED'}
}

function Get-Migration011ProvisionOutput {
    param([string]$Stdout)
    $lines=@($Stdout -split "`r?`n" | Where-Object {$_ -ne ''})
    $guard=@($lines | Where-Object {$_ -ceq 'UPVAN_MIGRATION011_GUARD_OK'}).Count -eq 1
    $done=@($lines | Where-Object {$_ -ceq 'UPVAN_MIGRATION011_DONE'}).Count -eq 1
    $credentialLines=@()
    foreach($line in $lines){
        $parts=@($line -split "`t",4)
        if($parts.Count -eq 4){
            if($parts[0] -cne 'up_van_migration011_apply' -or $parts[1] -cne '127.0.0.1' -or $parts[3] -cne '1'){
                throw 'MIGRATION_011_PROVISION_OUTPUT_INVALID'
            }
            $credentialLines+=,$parts
        }elseif($line -match "`t"){
            throw 'MIGRATION_011_PROVISION_OUTPUT_INVALID'
        }
    }
    if($credentialLines.Count -gt 1){throw 'MIGRATION_011_GENERATED_PASSWORD_OUTPUT_AMBIGUOUS'}
    $password=$null
    if($credentialLines.Count -eq 1){$password=[string]$credentialLines[0][2];if($password.Length -lt 12){$password=$null;throw 'MIGRATION_011_GENERATED_PASSWORD_OUTPUT_INVALID'}}
    if(!$guard -or ($done -and !$password)){throw 'MIGRATION_011_PROVISION_OUTPUT_INVALID'}
    return [pscustomobject]@{GuardPassed=$guard;Completed=$done;Password=$password;LineCount=$lines.Count}
}

function Get-Migration011MysqlErrorCode([string]$Stderr) {
    $m=[regex]::Match($Stderr,'ERROR\s+(?<code>[0-9]{3,5})\s+\((?<state>[0-9A-Z]{5})\)',[Text.RegularExpressions.RegexOptions]::IgnoreCase)
    if(!$m.Success){return 'mysql_code=UNKNOWN sqlstate=UNKNOWN'}
    return "mysql_code=$($m.Groups['code'].Value) sqlstate=$($m.Groups['state'].Value.ToUpperInvariant())"
}

function Invoke-Migration011MysqlCaptured {
    param([Parameter(Mandatory=$true)][string]$ClientPath,[Parameter(Mandatory=$true)][string[]]$ClientArgs,[Parameter(Mandatory=$true)][string]$Sql,[string]$WorkingDirectory='')
    $psi=[Diagnostics.ProcessStartInfo]::new();$psi.FileName=$ClientPath;$psi.Arguments=($ClientArgs -join ' ')
    if($WorkingDirectory){$psi.WorkingDirectory=$WorkingDirectory}
    $psi.UseShellExecute=$false;$psi.CreateNoWindow=$true;$psi.RedirectStandardInput=$true;$psi.RedirectStandardOutput=$true;$psi.RedirectStandardError=$true
    $process=[Diagnostics.Process]::new();$process.StartInfo=$psi
    try{
        if(!$process.Start()){throw 'MIGRATION_011_MYSQL_START_FAILED'}
        $outTask=$process.StandardOutput.ReadToEndAsync();$errTask=$process.StandardError.ReadToEndAsync();$writeFailed=$false
        $inputBytes=[Text.UTF8Encoding]::new($false).GetBytes($Sql)
        try{$process.StandardInput.BaseStream.Write($inputBytes,0,$inputBytes.Length);$process.StandardInput.BaseStream.Flush()}catch [IO.IOException]{$writeFailed=$true}
        finally{[Array]::Clear($inputBytes,0,$inputBytes.Length);try{$process.StandardInput.Close()}catch [IO.IOException]{$writeFailed=$true}}
        $process.WaitForExit()
        return @{ExitCode=$process.ExitCode;Stdout=$outTask.Result;Stderr=$errTask.Result;InputWriteFailed=$writeFailed}
    }finally{$process.Dispose()}
}

function ConvertTo-Migration011WindowsArgument([string]$Value) {
    if($Value -notmatch '[\s"]'){return $Value}
    return '"'+($Value -replace '(\\*)"','$1$1\"' -replace '(\\+)$','$1$1')+'"'
}

function Invoke-Migration011RunnerChild {
    param([Parameter(Mandatory=$true)][Security.SecureString]$Password,[Parameter(Mandatory=$true)][AllowEmptyCollection()][string[]]$RunnerArguments,[switch]$Mock,[scriptblock]$OnStarted)
    $project=Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
    $script=if($Mock){Join-Path $PSScriptRoot '../../tests/fixtures/migration011-child-env-mock.ps1'}else{Join-Path $project 'bin/db-migration-011.php'}
    $fullScript=[IO.Path]::GetFullPath($script)
    $exe=Join-Path $PSHOME 'powershell.exe';$ptr=[IntPtr]::Zero;$plain=$null;$psi=$null;$proc=$null
    try{
        $ptr=[Runtime.InteropServices.Marshal]::SecureStringToBSTR($Password);$plain=[Runtime.InteropServices.Marshal]::PtrToStringBSTR($ptr)
        if($Mock){$args=@('-NoProfile','-ExecutionPolicy','Bypass','-File',$fullScript)}
        else{
            if($env:TEST_DB_EXECUTION_APPROVED -cne 'true' -or $env:TEST_MIGRATION_011_APPLY_APPROVED -cne 'true'){throw 'MIGRATION_011_APPROVAL_REQUIRED'}
            $runtime=Assert-Migration011ApplyRuntime $project
            $args=@('-n','-d','extension_dir=ext','-d','extension=pdo_mysql','-d','extension=mbstring','-d','extension=openssl','-d','extension=curl',$fullScript)+$RunnerArguments
            $exe=$runtime
        }
        $psi=[Diagnostics.ProcessStartInfo]::new();$psi.FileName=$exe;$psi.Arguments=(($args|ForEach-Object{ConvertTo-Migration011WindowsArgument ([string]$_)}) -join ' ')
        if(!$Mock){$psi.WorkingDirectory=Split-Path -Parent $runtime}
        $psi.UseShellExecute=$false;$psi.CreateNoWindow=$true;$psi.RedirectStandardOutput=$true;$psi.RedirectStandardError=$true
        $env=$psi.EnvironmentVariables
        $env['MIGRATION_011_APPLY_DB_HOST']='127.0.0.1';$env['MIGRATION_011_APPLY_DB_PORT']='3306';$env['MIGRATION_011_APPLY_DB_DATABASE']='up_van_booking_test'
        $env['MIGRATION_011_APPLY_DB_USERNAME']='up_van_migration011_apply';$env['MIGRATION_011_APPLY_DB_PASSWORD']=$plain
        # Inherit explicitly approved process gates; never invent approval here.
        $proc=[Diagnostics.Process]::new();$proc.StartInfo=$psi
        if(!$proc.Start()){throw 'MIGRATION_011_CHILD_START_FAILED'}
        $outTask=$proc.StandardOutput.ReadToEndAsync();$errTask=$proc.StandardError.ReadToEndAsync()
        $journalFailed=$false
        if($OnStarted){try{& $OnStarted $proc.Id $proc.StartTime.ToUniversalTime().Ticks}catch{$journalFailed=$true}}
        $proc.WaitForExit()
        $stdout=$outTask.Result;$stderr=$errTask.Result
        if($journalFailed){throw 'MIGRATION_011_RUNNER_JOURNAL_FAILED_NO_RETRY'}
        return @{ExitCode=$proc.ExitCode;Stdout=$stdout;Stderr=$stderr}
    }finally{
        if($psi){foreach($name in @('MIGRATION_011_APPLY_DB_PASSWORD','MIGRATION_011_APPLY_DB_HOST','MIGRATION_011_APPLY_DB_PORT','MIGRATION_011_APPLY_DB_DATABASE','MIGRATION_011_APPLY_DB_USERNAME','TEST_DB_EXECUTION_APPROVED','TEST_MIGRATION_011_APPLY_APPROVED')){$psi.EnvironmentVariables.Remove($name)}}
        if($proc){$proc.Dispose()};$plain=$null;if($ptr -ne [IntPtr]::Zero){[Runtime.InteropServices.Marshal]::ZeroFreeBSTR($ptr)}
    }
}
