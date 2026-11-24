param([switch]$OfflineTest)

$ErrorActionPreference = 'Stop'

function Get-Migration010MysqlErrorCode([string]$Stderr) {
    $match = [regex]::Match($Stderr, 'ERROR\s+(?<code>[0-9]{3,5})\s+\((?<state>[0-9A-Z]{5})\)', [Text.RegularExpressions.RegexOptions]::IgnoreCase)
    if (!$match.Success) { return [pscustomobject]@{ Code = 'UNKNOWN'; SqlState = 'UNKNOWN' } }
    return [pscustomobject]@{ Code = $match.Groups['code'].Value; SqlState = $match.Groups['state'].Value.ToUpperInvariant() }
}

function Get-Migration010StepResult([string]$Step, [int]$ExitCode, [string]$Stderr, [bool]$Passed) {
    $error = Get-Migration010MysqlErrorCode $Stderr
    return [pscustomobject]@{ Step = $Step; Status = $(if ($Passed -and $ExitCode -eq 0) { 'PASS' } else { 'FAIL' }); ExitCode = $ExitCode; MysqlErrorCode = $error.Code; SqlState = $error.SqlState; TimestampUtc = [DateTime]::UtcNow.ToString('o') }
}

function Test-Migration010OutputLine([string]$Output, [string]$ExpectedLine) {
    return @($Output -split "`r?`n" | ForEach-Object { $_.Trim() } | Where-Object { $_ -ceq $ExpectedLine }).Count -eq 1
}

function Start-Migration010ConsoleClient([string]$FilePath, [string]$Arguments) {
    $info = [Diagnostics.ProcessStartInfo]::new($FilePath, $Arguments)
    $info.UseShellExecute = $false
    $info.CreateNoWindow = $false
    $info.RedirectStandardInput = $true
    $info.RedirectStandardOutput = $true
    $info.RedirectStandardError = $true
    # Discard any inherited legacy credential. The client reads its own console prompt.
    $info.EnvironmentVariables.Remove('MYSQL_PWD')
    $process = [Diagnostics.Process]::Start($info)
    return @{ Process = $process; Stderr = $process.StandardError.ReadToEndAsync(); PendingRead = $null }
}

function Start-Migration010Mysql([string]$User) {
    Write-Host "MySQL password prompt for $User"
    $arguments = "--no-defaults --no-login-paths --protocol=TCP --host=$hostName --port=3306 --user=$User --database=$database --password --connect-timeout=5 --skip-reconnect --batch --raw --skip-column-names --unbuffered"
    return Start-Migration010ConsoleClient $mysql $arguments
}

function Invoke-Migration010Mysql($Client, [string]$Sql, [int]$TimeoutSeconds = 15) {
    $process = $Client.Process
    $marker = 'UPVAN_END_' + [Guid]::NewGuid().ToString('N')
    $submitted = $false
    if (!$process.HasExited) {
        try {
            $process.StandardInput.WriteLine($Sql)
            $process.StandardInput.WriteLine("SELECT '$marker';")
            $process.StandardInput.Flush()
            $submitted = $true
        } catch [IO.IOException] {
            # The client can exit before reading stdin, for example on authentication failure.
        }
    }
    if ($submitted) {
        $lines = [Collections.Generic.List[string]]::new()
        $timer = [Diagnostics.Stopwatch]::StartNew()
        while ($true) {
            # If the unlock response timed out, cleanup can continue reading the
            # same DBA stream after sending the lock instead of starting a second read.
            if (!$Client.PendingRead) { $Client.PendingRead = $process.StandardOutput.ReadLineAsync() }
            $remaining = [Math]::Max(0, $TimeoutSeconds * 1000 - [int]$timer.ElapsedMilliseconds)
            if (!$Client.PendingRead.Wait($remaining)) { throw 'MYSQL_RESPONSE_TIMEOUT' }
            $line = $Client.PendingRead.Result
            $Client.PendingRead = $null
            if ($null -eq $line) { break }
            if ($line -ceq $marker) {
                return @{ ExitCode = 0; Stdout = $lines -join [Environment]::NewLine; Stderr = '' }
            }
            $lines.Add($line)
        }
    }
    if (!$process.WaitForExit(5000)) { throw 'MYSQL_RESPONSE_INCOMPLETE' }
    return @{ ExitCode = $process.ExitCode; Stdout = ''; Stderr = $Client.Stderr.Result }
}

function Close-Migration010Client($Client) {
    if (!$Client) { return }
    $process = $Client.Process
    try {
        try { $process.StandardInput.Close() } catch [IO.IOException] {}
        if (!$process.WaitForExit(2000)) {
            $process.Kill()
            [void]$process.WaitForExit(2000)
        }
    } finally { $process.Dispose() }
}

function New-Migration010DiagnosticStore([string]$BasePath) {
    $sid = [Security.Principal.WindowsIdentity]::GetCurrent().User
    $systemSid = New-Object Security.Principal.SecurityIdentifier('S-1-5-18')
    $acl = New-Object Security.AccessControl.DirectorySecurity
    $acl.SetAccessRuleProtection($true, $false)
    $acl.SetOwner($sid)
    $inherit = [Security.AccessControl.InheritanceFlags]'ContainerInherit,ObjectInherit'
    foreach ($entry in @(@($sid,'FullControl'), @($systemSid,'FullControl'))) {
        $rule = New-Object Security.AccessControl.FileSystemAccessRule($entry[0],$entry[1],$inherit,[Security.AccessControl.PropagationFlags]::None,[Security.AccessControl.AccessControlType]::Allow)
        [void]$acl.AddAccessRule($rule)
    }
    $dir = New-Object IO.DirectoryInfo($BasePath)
    if (!$dir.Exists) { $dir.Create($acl) }
    $actual = $dir.GetAccessControl()
    $rules = @($actual.GetAccessRules($true,$true,[Security.Principal.SecurityIdentifier]))
    if (!$actual.AreAccessRulesProtected -or !$actual.GetOwner([Security.Principal.SecurityIdentifier]).Equals($sid) -or $rules.Count -ne 2) { throw 'DIAGNOSTIC_STORE_ACL_REJECTED' }
    foreach ($rule in $rules) {
        if ($rule.AccessControlType -ne 'Allow' -or $rule.FileSystemRights -ne 'FullControl' -or $rule.IsInherited -or $rule.IdentityReference.Value -notin @($sid.Value,$systemSid.Value)) { throw 'DIAGNOSTIC_STORE_ACL_REJECTED' }
    }
}

function Save-Migration010Diagnostic([string]$Path, [System.Collections.IList]$Results) {
    $document = [ordered]@{ Format='upvan-migration010-account-diagnostic-v1'; UpdatedUtc=[DateTime]::UtcNow.ToString('o'); Results=@($Results) }
    $json = $document | ConvertTo-Json -Depth 5
    $temporary = $Path + '.' + [Guid]::NewGuid().ToString('N') + '.tmp'
    $backup = $Path + '.' + [Guid]::NewGuid().ToString('N') + '.bak'
    [IO.File]::WriteAllText($temporary,$json,(New-Object Text.UTF8Encoding($false)))
    if ([IO.File]::Exists($Path)) {
        [IO.File]::Replace($temporary,$Path,$backup)
        if ([IO.File]::Exists($backup)) { [IO.File]::Delete($backup) }
    } else { [IO.File]::Move($temporary,$Path) }
}

if ($OfflineTest) {
    $cases = @(
        @{ Text = 'ERROR 1045 (28000): Access denied for user'; Code = '1045'; State = '28000' },
        @{ Text = 'ERROR 1142 (42000): SELECT command denied'; Code = '1142'; State = '42000' },
        @{ Text = 'generic process failure'; Code = 'UNKNOWN'; State = 'UNKNOWN' }
    )
    foreach ($case in $cases) {
        $actual = Get-Migration010MysqlErrorCode $case.Text
        if ($actual.Code -cne $case.Code -or $actual.SqlState -cne $case.State) { throw 'ERROR_CODE_PARSER_TEST_FAILED' }
    }
    $auth = Get-Migration010StepResult 'CONNECTION_LOGIN' 1 $cases[0].Text $false
    $permission = Get-Migration010StepResult 'EFFECTIVE_GRANTS' 1 $cases[1].Text $false
    if ($auth.MysqlErrorCode -cne '1045' -or $permission.MysqlErrorCode -cne '1142' -or $auth.Status -cne 'FAIL' -or $permission.Status -cne 'FAIL') { throw 'STEP_CLASSIFICATION_TEST_FAILED' }
    if (!(Test-Migration010OutputLine "UNLOCKED`r`n" 'UNLOCKED')) { throw 'CRLF_UNLOCK_OUTPUT_TEST_FAILED' }
    $exitZeroValidationFail = Get-Migration010StepResult 'TEMPORARY_UNLOCK' 0 '' $false
    if ($exitZeroValidationFail.Status -cne 'FAIL' -or $exitZeroValidationFail.ExitCode -ne 0) { throw 'EXIT_ZERO_VALIDATION_TEST_FAILED' }
    $testRoot = Join-Path ([IO.Path]::GetTempPath()) ('migration010-diagnostic-' + [Guid]::NewGuid().ToString('N'))
    New-Migration010DiagnosticStore $testRoot
    $testResultPath = Join-Path $testRoot 'result.json'
    $simulated = [Collections.Generic.List[object]]::new()
    $simulated.Add((Get-Migration010StepResult 'CONNECTION_LOGIN' 0 '' $true))
    Save-Migration010Diagnostic $testResultPath $simulated
    $saved = Get-Content -Raw -LiteralPath $testResultPath | ConvertFrom-Json
    if ($saved.Results.Count -ne 1 -or $saved.Results[0].Step -ne 'CONNECTION_LOGIN' -or !$saved.Results[0].TimestampUtc) { throw 'DIAGNOSTIC_CHECKPOINT_TEST_FAILED' }
    $simulated.Add((Get-Migration010StepResult 'EFFECTIVE_GRANTS' 1 $cases[1].Text $false))
    Save-Migration010Diagnostic $testResultPath $simulated
    $savedText = Get-Content -Raw -LiteralPath $testResultPath
    $saved = $savedText | ConvertFrom-Json
    if ($saved.Results.Count -ne 2 -or $saved.Results[1].MysqlErrorCode -ne '1142' -or $savedText.Contains('SELECT command denied')) { throw 'DIAGNOSTIC_PARTIAL_RESULT_TEST_FAILED' }
    Remove-Item -LiteralPath $testResultPath -Force
    Remove-Item -LiteralPath $testRoot -Force
    'Offline diagnostic output and JSON checks PASS'
    return
}

function Add-Migration010Result([string]$Name, $Result, [bool]$Passed) {
    $results.Add((Get-Migration010StepResult $Name $Result.ExitCode $Result.Stderr $Passed))
    Save-Migration010Diagnostic $DiagnosticPath $results
}

function Invoke-Migration010Diagnostic([string]$DiagnosticPath) {
    $mysql = Join-Path $env:ProgramFiles 'MySQL\MySQL Server 8.4\bin\mysql.exe'
    $expectedUuid = '77218fbd-d66d-11f1-9a0a-5c2886f057d5'
    $account = 'up_van_migration010_apply'
    $hostName = '127.0.0.1'
    $database = 'up_van_booking_test'
    $results = [Collections.Generic.List[object]]::new()
    $dba = $null
    $apply = $null
    $unlockAttempted = $false
    $step = 'DBA_DATABASE_IDENTITY'
    $lockSql = "ALTER USER '$account'@'$hostName' ACCOUNT LOCK; SELECT CONCAT('LOCKED|',account_locked) FROM mysql.user WHERE BINARY User='$account' AND BINARY Host='$hostName';"
    Save-Migration010Diagnostic $DiagnosticPath $results
    Write-Host "RESULT_FILE=$DiagnosticPath"
    try {
        $dba = Start-Migration010Mysql 'root'
        $precheck = Invoke-Migration010Mysql $dba "SELECT CONCAT(@@port,'|',@@server_uuid,'|',DATABASE(),'|',VERSION(),'|',CURRENT_USER()); SELECT CONCAT(COALESCE(MAX(account_locked),'N'),'|',COUNT(*)) FROM mysql.user WHERE BINARY User='$account' AND BINARY Host='$hostName';" 120
        $expectedDba = "3306|$expectedUuid|$database|8.4.9|root"
        $identityPassed = $precheck.ExitCode -eq 0 -and ((Test-Migration010OutputLine $precheck.Stdout "$expectedDba@localhost") -or (Test-Migration010OutputLine $precheck.Stdout "$expectedDba@127.0.0.1"))
        Add-Migration010Result $step $precheck $identityPassed
        if (!$identityPassed) { return }
        $step = 'ACCOUNT_LOCK_INITIAL'
        $locked = Test-Migration010OutputLine $precheck.Stdout 'Y|1'
        Add-Migration010Result $step $precheck $locked
        if (!$locked) { return }

        $step = 'TEMPORARY_UNLOCK'
        $unlockAttempted = $true
        $unlock = Invoke-Migration010Mysql $dba "ALTER USER '$account'@'$hostName' ACCOUNT UNLOCK; SELECT CONCAT('UNLOCKED|',account_locked) FROM mysql.user WHERE BINARY User='$account' AND BINARY Host='$hostName';"
        $unlocked = $unlock.ExitCode -eq 0 -and (Test-Migration010OutputLine $unlock.Stdout 'UNLOCKED|N')
        Add-Migration010Result $step $unlock $unlocked
        if (!$unlocked) { return }

        $step = 'CONNECTION_LOGIN'
        $apply = Start-Migration010Mysql $account
        $login = Invoke-Migration010Mysql $apply "SELECT CONCAT('IDENTITY|',CURRENT_USER(),'|',DATABASE(),'|',VERSION(),'|',@@server_uuid);" 120
        $loginPassed = $login.ExitCode -eq 0 -and (Test-Migration010OutputLine $login.Stdout "IDENTITY|$account@$hostName|$database|8.4.9|$expectedUuid")
        Add-Migration010Result $step $login $loginPassed
        if (!$loginPassed) { return }

        $step = 'EFFECTIVE_GRANTS'
        $grants = Invoke-Migration010Mysql $apply 'SHOW GRANTS FOR CURRENT_USER; SELECT CURRENT_ROLE();'
        $grantRows = @($grants.Stdout -split '\r?\n' | Where-Object { $_ -like 'GRANT *' })
        $roleNone = Test-Migration010OutputLine $grants.Stdout 'NONE'
        $scopeInvalid = @($grantRows | Where-Object {
            $_ -notmatch '^GRANT USAGE ON \*\.\* TO .+' -and
            $_ -notmatch '^GRANT .+ ON `up_van_booking_test`\.`(users|drivers|roles|schema_migrations|auth_login_buckets|vehicles|booking_requests)` TO .+'
        }).Count -gt 0
        $delegation = @($grantRows | Where-Object { $_ -match 'WITH GRANT OPTION|WITH ADMIN OPTION' }).Count -gt 0
        $grantPass = $grants.ExitCode -eq 0 -and $grantRows.Count -eq 8 -and $roleNone -and !$scopeInvalid -and !$delegation
        Add-Migration010Result $step $grants $grantPass

        $step = 'METADATA_VISIBILITY'
        $metadata = Invoke-Migration010Mysql $apply "SELECT CONCAT('META|',COUNT(*)) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='$database' AND TABLE_NAME IN ('users','drivers','roles','schema_migrations','auth_login_buckets','vehicles','booking_requests');"
        $metaPass = $metadata.ExitCode -eq 0 -and $metadata.Stdout.Trim() -cmatch '^META\|[1-9][0-9]*$'
        Add-Migration010Result $step $metadata $metaPass
    } catch {
        Add-Migration010Result $step @{ ExitCode = 1; Stderr = '' } $false
    } finally {
        try {
            if ($unlockAttempted) {
                $lockPassed = $false
                try {
                    $lock = Invoke-Migration010Mysql $dba $lockSql
                    $lockPassed = $lock.ExitCode -eq 0 -and (Test-Migration010OutputLine $lock.Stdout 'LOCKED|Y')
                    Add-Migration010Result 'ACCOUNT_LOCK_FINAL' $lock $lockPassed
                } catch {
                    Add-Migration010Result 'ACCOUNT_LOCK_FINAL' @{ ExitCode = 1; Stderr = '' } $false
                } finally {
                    if (!$lockPassed) {
                        Write-Warning 'CRITICAL: Account lock unconfirmed. In a DBA session on the verified test instance, run:'
                        Write-Warning $lockSql
                    }
                }
            }
        } finally {
            try { Close-Migration010Client $apply }
            finally { Close-Migration010Client $dba }
        }
    }
}

if ($MyInvocation.InvocationName -ne '.') {
    if ([Console]::IsInputRedirected -or ![Environment]::UserInteractive) {
        throw 'Run the diagnostic in a visible Windows PowerShell console for the native MySQL prompts.'
    }
    $diagnosticRoot = Join-Path $env:LOCALAPPDATA 'UPVanBooking\migration010-diagnostics'
    if (!(Test-Path -LiteralPath $diagnosticRoot -PathType Container)) { New-Migration010DiagnosticStore $diagnosticRoot }
    $diagnosticPath = Join-Path $diagnosticRoot ('apply-account-' + [DateTime]::UtcNow.ToString('yyyyMMddTHHmmssfffZ') + '-' + [Guid]::NewGuid().ToString('N') + '.json')
    Invoke-Migration010Diagnostic $diagnosticPath
}
