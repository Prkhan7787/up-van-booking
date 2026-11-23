#requires -Version 5.1
Set-StrictMode -Version Latest
$ErrorActionPreference='Stop'
$count=0
function Check([bool]$Ok,[string]$Name){if(!$Ok){throw ('FAIL '+$Name)};$script:count++}
$path=Join-Path $PSScriptRoot '..\scripts\prepare-3307-migrator-account.ps1'
$source=[IO.File]::ReadAllText($path);$tokens=$null;$errors=$null
$ast=[Management.Automation.Language.Parser]::ParseFile($path,[ref]$tokens,[ref]$errors)
Check ($errors.Count -eq 0) 'Windows PowerShell syntax'
Check (!$source.Contains('StandardInputEncoding=')) 'no unsupported .NET Framework stdin encoding property'
Check ($source.Contains('Read-Host') -and $source.Contains('-AsSecureString')) 'hidden root prompt'
Check ($source.Contains('RedirectStandardInput=$true') -and $source.Contains('Send-Restore006RootInput $proc $payload')) 'secret through stdin'
$assignment=@($ast.FindAll({param($n)$n -is [Management.Automation.Language.AssignmentStatementAst] -and $n.Left.Extent.Text -eq '$info.Arguments'},$true))
Check ($assignment.Count -eq 1 -and $assignment[0].Extent.Text -notmatch '\$(payload|plain|secure)') 'arguments contain no secret'
Check ($source.Contains('UseShellExecute=$false') -and $source.Contains('CreateNoWindow=$true')) 'no shell/window'
Check ($assignment[0].Extent.Text.Contains('extension_dir=.runtime/php84/ext') -and !$assignment[0].Extent.Text.Contains('$ext')) 'ASCII extension directory matches project runtime launcher'
Check ($source.IndexOf('Assert-UpVanProvisioningPhpRuntime') -lt $source.IndexOf("`$stage='ROOT_HIDDEN_PROMPT'")) 'loaded driver check precedes hidden prompt'
Check ($source.Contains("'failure_type'")) 'safe failure classification reaches outer PowerShell'
Check ($source.Contains("@('schema_check','schema_field')") -and $source.Contains("'^[A-Za-z0-9_.]{1,128}$'")) 'schema diagnostic labels safely forwarded to outer shell'
Check ($source.Contains("'error_type','error_file'") -and $source.Contains("['error_line']")) 'safe throwable origin reaches outer shell'
Check ($source.Contains('PHP_SOURCE_REVISION_MISMATCH') -and $source.Contains('SCHEMA006_V3')) 'loaded PHP revision must match wrapper'
Check ($source.Contains('StandardError.ReadToEndAsync') -and !$source.Contains('Write-Host $output')) 'raw child output not forwarded'
Check ($source.Contains('ZeroFreeBSTR') -and $source.Contains('$secure.Dispose()')) 'secure prompt memory cleanup'
Check ($source.Contains("-cne 'mysql-3307-37e4d222-dda2-11f1-b503-5c2886f057d5'")) 'explicit UUID confirmation'
$commands=@($ast.FindAll({param($n)$n -is [Management.Automation.Language.CommandAst]},$true))
Check (@($commands|Where-Object {$_.GetCommandName() -in @('Set-Content','Add-Content','Remove-Item','Set-Acl','Start-Transcript','Start-Process','Stop-Process')}).Count -eq 0) 'no file state or server changes'
# Execute only the parsed transport helper against an offline PHP fixture, not
# the wrapper script. The fixture has no PDO/config/bootstrap/MySQL operations.
$fn=$ast.Find({param($n)$n -is [Management.Automation.Language.FunctionDefinitionAst] -and $n.Name -eq 'Send-Restore006RootInput'},$true)
Invoke-Expression $fn.Extent.Text
$fixture=Join-Path $env:TEMP ('restore006-stdin-'+[guid]::NewGuid().ToString('N')+'.php')
$php=(Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..\.runtime\php84\php.exe')).Path
try {
    $cases=@('ASCII_ONLY_123', 'punctuation-''"$()`;!&|<>\', ('offline-'+[char]0x0e01+[char]0x0e02+[char]::ConvertFromUtf32(0x1f642)), " leading and trailing `t ", "line1`r`nline2`n", ("embedded"+[char]0+"nul"))
    foreach($dummy in $cases) {
        # Only synthetic fixture data goes to this temporary offline file.
        $expected=[Convert]::ToBase64String([Text.UTF8Encoding]::new($false).GetBytes($dummy))
        $code='<?php $d=json_decode(stream_get_contents(STDIN),true);echo extension_loaded("pdo_mysql") && extension_loaded("mbstring") && is_array($d) && ($d["root_password"]??null) === base64_decode("'+$expected+'") ? "PASS" : "FAIL";'
        [IO.File]::WriteAllText($fixture,$code,[Text.UTF8Encoding]::new($false))
        $info=New-Object Diagnostics.ProcessStartInfo
        $info.FileName=$php;$info.Arguments='-n -d extension_dir=.runtime/php84/ext -d extension=pdo_mysql -d extension=mbstring "'+$fixture+'"'
        $info.WorkingDirectory=(Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..')).Path
        $info.UseShellExecute=$false;$info.CreateNoWindow=$true
        $info.RedirectStandardInput=$true;$info.RedirectStandardOutput=$true;$info.RedirectStandardError=$true
        $child=New-Object Diagnostics.Process;$child.StartInfo=$info
        [void]$child.Start()
        $secure=ConvertTo-SecureString -String $dummy -AsPlainText -Force
        $ptr=[Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure)
        try {$plain=[Runtime.InteropServices.Marshal]::PtrToStringBSTR($ptr);Send-Restore006RootInput $child (@{root_password=$plain}|ConvertTo-Json -Compress)}
        finally {[Runtime.InteropServices.Marshal]::ZeroFreeBSTR($ptr);$secure.Dispose();$plain=$null}
        $output=$child.StandardOutput.ReadToEnd();[void]$child.StandardError.ReadToEnd();$child.WaitForExit()
        Check ($output -ceq 'PASS' -and $child.ExitCode -eq 0) 'secure-string to UTF8 stdin roundtrip with real project modules loaded'
        $child.Dispose();$child=$null
    }
} finally {
    if($null -ne $child){$child.Dispose()}
    if([IO.File]::Exists($fixture)){[IO.File]::Delete($fixture)}
}
$promptFixture=Join-Path $env:TEMP ('restore006-prompt-'+[guid]::NewGuid().ToString('N')+'.ps1')
try {
    foreach($stub in @('[securestring]::new()',"throw [OperationCanceledException]::new('DUMMY_RAW_ERROR')")) {
        $code='function Read-Host {param($Prompt,[switch]$AsSecureString) '+$stub+'}'+[Environment]::NewLine+"& '"+$path.Replace("'","''")+"' -PreflightOnly -ConfirmTarget mysql-3307-37e4d222-dda2-11f1-b503-5c2886f057d5"+[Environment]::NewLine+'exit $LASTEXITCODE'
        [IO.File]::WriteAllText($promptFixture,$code,[Text.UTF8Encoding]::new($true))
        $raw=& powershell.exe -NoProfile -ExecutionPolicy Bypass -File $promptFixture
        $exitCode=$LASTEXITCODE;$r=$raw | ConvertFrom-Json
        $expected=if($stub -eq '[securestring]::new()'){'ROOT_PASSWORD_EMPTY'}else{'ROOT_PROMPT_FAILED'}
        Check ($exitCode -eq 1 -and $r.code -eq $expected -and $r.diagnostic_revision -eq 'SCHEMA006_V3') ('mock prompt diagnostic '+$r.step+' '+$r.code+' exit='+$exitCode)
        Check (!(($raw | Out-String).Contains('DUMMY_RAW_ERROR'))) 'prompt raw exception is not forwarded'
    }
} finally {if([IO.File]::Exists($promptFixture)){[IO.File]::Delete($promptFixture)}}
Write-Output ('PASS: '+$count+' Windows PowerShell account preparation safety checks; no MySQL action or hidden prompt invoked')
