[CmdletBinding()]
param()
$ErrorActionPreference='Stop'
. (Join-Path $PSScriptRoot 'booking-reset-maintenance-credential.ps1')
$project=Split-Path -Parent $PSScriptRoot
$credentialPath=Join-Path (Join-Path $env:LOCALAPPDATA 'UPVanBooking') 'reset-maintenance.dpapi'
$php=Join-Path $project '.runtime/php84/php.exe'
if(!(Test-Path -LiteralPath $php -PathType Leaf)){throw 'PROJECT_PHP_RUNTIME_MISSING'}
$password=Read-MaintenanceDpapiCredential -Path $credentialPath
$settings=[ordered]@{
    RESET_MAINTENANCE_DB_HOST='127.0.0.1'
    RESET_MAINTENANCE_DB_PORT='3306'
    RESET_MAINTENANCE_DB_DATABASE='up_van_booking_test'
    RESET_MAINTENANCE_DB_USERNAME='up_van_reset_maintenance'
    RESET_MAINTENANCE_DB_PASSWORD=$password
    UPVAN_MAINTENANCE_PREFLIGHT_WRAPPER='1'
}
$psi=New-Object System.Diagnostics.ProcessStartInfo
$psi.FileName=$php
$psi.Arguments='-n -d extension_dir=.runtime/php84/ext -d extension=pdo_mysql -d extension=mbstring -d extension=openssl scripts/booking-reset-maintenance-account-preflight.php'
$psi.WorkingDirectory=$project
$psi.UseShellExecute=$false
$psi.CreateNoWindow=$true
$psi.RedirectStandardOutput=$true
$psi.RedirectStandardError=$true
foreach($key in $settings.Keys){$psi.EnvironmentVariables[$key]=[string]$settings[$key]}
$proc=New-Object System.Diagnostics.Process
$proc.StartInfo=$psi
try {
    try {
        if(!$proc.Start()){throw 'PROJECT_PHP_START_FAILED'}
    } finally {
        $password=$null
        $settings['RESET_MAINTENANCE_DB_PASSWORD']=$null
        $psi.EnvironmentVariables['RESET_MAINTENANCE_DB_PASSWORD']=''
    }
    $stdoutTask=$proc.StandardOutput.ReadToEndAsync()
    $stderrTask=$proc.StandardError.ReadToEndAsync()
    $proc.WaitForExit()
    $exitCode=$proc.ExitCode
    $stdout=$stdoutTask.Result.Trim()
    $stderr=$stderrTask.Result
} finally {$password=$null;$proc.Dispose()}
if($exitCode -eq 0 -and $stdout -match '^MAINTENANCE_ACCOUNT_PREFLIGHT=PASS account=up_van_reset_maintenance@127\.0\.0\.1 database=up_van_booking_test uuid=77218fbd-d66d-11f1-9a0a-5c2886f057d5 grants=PASS dependencies=PASS calendar=PASS rollback_columns=PASS$'){
    Write-Output $stdout
    exit 0
}
$safeError=[regex]::Match($stderr,'MAINTENANCE_ACCOUNT_PREFLIGHT=FAIL step=[a-z_]+ sqlstate=(?:[A-Z0-9]{5}|UNKNOWN) mysql_code=(?:[0-9]+|UNKNOWN)')
if($safeError.Success){Write-Output $safeError.Value}
throw "MAINTENANCE_ACCOUNT_PREFLIGHT_FAILED exit=$exitCode"
