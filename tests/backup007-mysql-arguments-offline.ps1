$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest
if ($PSVersionTable.PSVersion.Major -ne 5) { throw 'Run with Windows PowerShell 5.1.' }
$scriptPath = Join-Path (Split-Path -Parent $PSScriptRoot) 'scripts\backup-and-preflight-007.ps1'
$tokens = $null; $errors = $null
$ast = [Management.Automation.Language.Parser]::ParseFile($scriptPath, [ref]$tokens, [ref]$errors)
if ($errors.Count) { throw 'Backup script syntax failed.' }
$fn = $ast.Find({param($n) $n -is [Management.Automation.Language.FunctionDefinitionAst] -and $n.Name -eq 'Invoke-MySql'}, $true)
. ([scriptblock]::Create($fn.Extent.Text))
$fixture = Join-Path $PSScriptRoot ('mysql args fixture ' + [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $fixture | Out-Null
try {
    $mysqlExe = Join-Path $fixture 'client-echo.exe'
    # This executable has no MySQL or network functionality. It captures argv/stdin only.
    Add-Type -TypeDefinition @'
using System;
using System.Text;
public class ClientEcho {
    public static int Main(string[] args) {
        if (args.Length == 1 && args[0].StartsWith("--execute=")) {
            Console.WriteLine(Convert.ToBase64String(Encoding.UTF8.GetBytes(args[0])));
            return 0;
        }
        using (var buffer = new System.IO.MemoryStream()) {
            Console.OpenStandardInput().CopyTo(buffer);
            string sql = Encoding.UTF8.GetString(buffer.ToArray());
            if (sql.Contains("SIMULATE_CLIENT_ERROR")) {
                Console.Error.WriteLine("ERROR at line 1: Unknown command '\\,'.");
                return 1;
            }
            if (sql.Contains("SIMULATE_FAILURE")) {
                Console.Error.WriteLine("ERROR 1045 (28000): PRIVATE_DIAGNOSTIC_FIXTURE");
                return 1;
            }
            Console.WriteLine(Convert.ToBase64String(buffer.ToArray()));
            foreach (var arg in args) Console.WriteLine(Convert.ToBase64String(Encoding.UTF8.GetBytes(arg)));
        }
        return 0;
    }
}
'@ -OutputAssembly $mysqlExe -OutputType ConsoleApplication
    $LoginPath = 'upvan3306-backup'; $expectedPort = 3306; $database = 'up_van_booking_test'
    $original = 'SELECT @@port,@@server_uuid,REPLACE(@@datadir,"\\","/"),VERSION(),DATABASE()'
    $legacy = & $mysqlExe "--execute=$original"
    $legacyArg = [Text.Encoding]::UTF8.GetString([Convert]::FromBase64String($legacy))
    if ($legacyArg -eq "--execute=$original") { throw 'Expected legacy PS 5.1 quote corruption was not reproduced.' }
    if (-not $legacyArg.Contains('\,')) { throw 'Expected backslash-comma client escape was not reproduced.' }
    Write-Output 'PASS: legacy --execute loses embedded quotes and produces backslash-comma on PS 5.1.'
    $queries = @(
        $original,
        'SELECT @@port,@@server_uuid,REPLACE(@@datadir,CHAR(92),CHAR(47)),VERSION(),DATABASE()',
        ('SELECT ''' + [char]0x0E01 + [char]0x0E02 + ''',"double quoted",''C:\path\'',1'),
        "SELECT 'multiline';`nSELECT 2"
    )
    # Exercise every literal SQL payload at every call site, without running it against a database.
    $calls = $ast.FindAll({param($n) $n -is [Management.Automation.Language.CommandAst] -and $n.GetCommandName() -eq 'Invoke-MySql'}, $true)
    foreach ($call in $calls) {
        foreach ($element in $call.CommandElements) {
            if ($element -is [Management.Automation.Language.StringConstantExpressionAst] -and $element.Value -match '^(SELECT|CHECK) ') { $queries += $element.Value }
            elseif ($element -is [Management.Automation.Language.ExpandableStringExpressionAst] -and $element.NestedExpressions.Count -eq 0 -and $element.Value -match '^SELECT ') { $queries += $element.Value }
        }
    }
    foreach ($query in $queries) {
        $result = @(Invoke-MySql -Sql $query -Step 'ARGUMENT_TEST')
        $actual = [Text.Encoding]::UTF8.GetString([Convert]::FromBase64String($result[0]))
        if ($actual -cne ($query.TrimEnd() + ";`n")) { throw 'SQL stdin bytes changed.' }
        $argv = @($result | Select-Object -Skip 1 | ForEach-Object { [Text.Encoding]::UTF8.GetString([Convert]::FromBase64String($_)) })
        if ($argv -contains "--execute=$query" -or $argv -notcontains '--binary-mode' -or $argv -notcontains '--port=3306') { throw 'Unexpected native arguments.' }
    }
    $rejected = $false
    try { Invoke-MySql -Sql 'SELECT SIMULATE_FAILURE' -Step 'VIEW_CHECK' | Out-Null } catch {
        $message = $_.Exception.Message
        $rejected = $message -match 'step=VIEW_CHECK exit=1 native=1045 sqlstate=28000' -and $message -notmatch 'PRIVATE_DIAGNOSTIC_FIXTURE|SIMULATE_FAILURE'
    }
    if (-not $rejected) { throw 'Failure diagnostics missing step/codes or leaked query/stderr.' }
    $rejected = $false
    try { Invoke-MySql -Sql 'SELECT SIMULATE_CLIENT_ERROR' -Step 'IDENTITY' | Out-Null } catch {
        $rejected = $_.Exception.Message -eq 'MYSQL_READ_FAILED step=IDENTITY exit=1 native=UNKNOWN sqlstate=UNKNOWN'
    }
    if (-not $rejected) { throw 'Unnumbered client error did not stop safely.' }
    Write-Output "PASS: $($queries.Count) SQL payloads preserved as UTF-8 stdin; pinned argv; sanitized nonzero-exit diagnostics; PS 5.1 syntax. No database connection."
} finally {
    if (Test-Path -LiteralPath $fixture) { Remove-Item -LiteralPath $fixture -Recurse -Force }
}
