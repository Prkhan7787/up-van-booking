param([string]$LoginPath='upvan3306-backup')
# Diagnostic evidence only. Never consumed by the Reset Runner as permission to write.
$ErrorActionPreference='Stop'
Set-StrictMode -Version Latest
if($LoginPath -cne 'upvan3306-backup'){throw 'Only approved backup Login Path is accepted.'}
$mysqlBin='C:\Program Files\MySQL\MySQL Server 8.4\bin'
$mysql=Join-Path $mysqlBin 'mysql.exe'
function Read-Metadata([string]$Sql){
    $psi=[Diagnostics.ProcessStartInfo]::new();$psi.FileName=$mysql;$psi.WorkingDirectory=$mysqlBin
    $psi.Arguments="--login-path=$LoginPath --protocol=TCP --host=127.0.0.1 --port=3306 --database=up_van_booking_test --default-character-set=utf8mb4 --batch --raw --skip-column-names --skip-reconnect"
    $psi.UseShellExecute=$false;$psi.CreateNoWindow=$true;$psi.RedirectStandardInput=$true;$psi.RedirectStandardOutput=$true;$psi.RedirectStandardError=$true
    $psi.StandardOutputEncoding=[Text.Encoding]::UTF8;$psi.StandardErrorEncoding=[Text.Encoding]::UTF8
    $p=[Diagnostics.Process]::new();$p.StartInfo=$psi
    try{
        [void]$p.Start();$out=$p.StandardOutput.ReadToEndAsync();$err=$p.StandardError.ReadToEndAsync()
        $p.StandardInput.WriteLine($Sql);$p.StandardInput.Close();$p.WaitForExit();$stdout=$out.GetAwaiter().GetResult();$stderr=$err.GetAwaiter().GetResult()
        if($p.ExitCode -ne 0){
            $code='unknown';if($stderr -match 'ERROR (\d+)'){$code=$Matches[1]}
            throw "Metadata SELECT failed exit=$($p.ExitCode) mysql_code=$code"
        }
        if([string]::IsNullOrWhiteSpace($stdout)){return @()}
        return @($stdout.TrimEnd([char[]]"`r`n") -split '\r?\n')
    }finally{$p.Dispose()}
}
function Metadata-Identity {
    $r=@(Read-Metadata 'SELECT @@port,@@server_uuid,DATABASE(),VERSION(),CURRENT_USER();')
    if($r.Count -ne 1){throw 'Metadata identity row count mismatch.'}
    $f=@($r[0] -split "`t")
    if($f.Count -ne 5 -or $f[0] -cne '3306' -or $f[1] -cne '77218fbd-d66d-11f1-9a0a-5c2886f057d5' -or $f[2] -cne 'up_van_booking_test' -or $f[3] -notmatch '^8\.4\.' -or !$f[4] -or $f[4] -ceq 'up_van_auth_test@127.0.0.1'){throw 'Metadata target/account identity rejected.'}
    return @{port=$f[0];uuid=$f[1];db=$f[2];version=$f[3];account=$f[4]}
}
$identity=Metadata-Identity
$hashSql="SELECT LOWER(SHA2(CONCAT(VIEW_DEFINITION,CHAR(9),DEFINER,CHAR(9),SECURITY_TYPE,CHAR(9),CHECK_OPTION,CHAR(9),CHARACTER_SET_CLIENT,CHAR(9),COLLATION_CONNECTION),256)) FROM information_schema.VIEWS WHERE TABLE_SCHEMA='up_van_booking_test' AND TABLE_NAME='calendar_events';"
$before=@(Read-Metadata $hashSql)
$rows=@(Read-Metadata "SELECT TABLE_SCHEMA,TABLE_NAME FROM information_schema.VIEW_TABLE_USAGE WHERE VIEW_SCHEMA='up_van_booking_test' AND VIEW_NAME='calendar_events' ORDER BY TABLE_SCHEMA,TABLE_NAME;")
$deps=@();foreach($row in $rows){$f=@($row -split "`t");if($f.Count -ne 2){throw 'Metadata dependency row malformed.'};$deps+=,@($f[0],$f[1])}
$after=@(Read-Metadata $hashSql)
$identityAfter=Metadata-Identity
foreach($key in @('port','uuid','db','version','account')){if($identity[$key] -cne $identityAfter[$key]){throw 'Metadata identity changed.'}}
if($before.Count -ne 1 -or $after.Count -ne 1 -or $before[0] -cnotmatch '^[0-9a-f]{64}$' -or $before[0] -cne $after[0]){throw 'View definition unavailable or changed during inspection.'}
[ordered]@{diagnostic_only=$true;identity=$identity;observed_at_epoch=[DateTimeOffset]::UtcNow.ToUnixTimeSeconds();dependencies=@($deps);view_definition_sha256=$before[0];view_definition_sha256_after=$after[0];ddl_change_protection='NOT_ESTABLISHED_BY_REPORT'} | ConvertTo-Json -Depth 8
