[CmdletBinding()]
param(
    [Parameter(Mandatory=$true)][ValidateSet('Preflight','Rehearsal','Apply')][string]$Phase,
    [Parameter(Mandatory=$true)][int]$ActorId,
    [Parameter()][string]$ApprovedScope
)
$ErrorActionPreference='Stop'
if($ActorId -ne 11){throw 'ACTOR_ID_11_REQUIRED'}
$expectedScope='up_van_booking_test:77218fbd-d66d-11f1-9a0a-5c2886f057d5:29=awaiting_assignment.v7,30=awaiting_assignment.v5,31=awaiting_rejection_notice.v7,32=awaiting_assignment.v6,33=submitted.v2,34=submitted.v2'
if($Phase -eq 'Apply'){
    if($ApprovedScope -cne $expectedScope){throw 'EXPLICIT_APPLY_SCOPE_REQUIRED'}
}elseif($PSBoundParameters.ContainsKey('ApprovedScope')){throw 'APPROVAL_ONLY_VALID_FOR_APPLY'}
. (Join-Path $PSScriptRoot 'booking-reset-maintenance-credential.ps1')
$project=Split-Path -Parent $PSScriptRoot
$php=Join-Path $project '.runtime/php84/php.exe'
if(!(Test-Path -LiteralPath $php -PathType Leaf)){throw 'PROJECT_PHP_RUNTIME_MISSING'}
$credentialPath=Join-Path (Join-Path $env:LOCALAPPDATA 'UPVanBooking') 'reset-maintenance.dpapi'
$password=Read-MaintenanceDpapiCredential -Path $credentialPath
$mode=$Phase.ToLowerInvariant()
$psi=New-Object System.Diagnostics.ProcessStartInfo
$psi.FileName=$php
$psi.Arguments="-n -d extension_dir=.runtime/php84/ext -d extension=pdo_mysql -d extension=mbstring -d extension=openssl scripts/booking-reset-runner.php $mode --actor-id=11"
if($Phase -eq 'Apply'){$psi.Arguments += " --approved-scope=$expectedScope"}
$psi.WorkingDirectory=$project
$psi.UseShellExecute=$false
$psi.CreateNoWindow=$true
$psi.RedirectStandardOutput=$true
$psi.RedirectStandardError=$true
$psi.EnvironmentVariables['RESET_MAINTENANCE_DB_HOST']='127.0.0.1'
$psi.EnvironmentVariables['RESET_MAINTENANCE_DB_PORT']='3306'
$psi.EnvironmentVariables['RESET_MAINTENANCE_DB_DATABASE']='up_van_booking_test'
$psi.EnvironmentVariables['RESET_MAINTENANCE_DB_USERNAME']='up_van_reset_maintenance'
$psi.EnvironmentVariables['RESET_MAINTENANCE_DB_PASSWORD']=$password
$proc=New-Object System.Diagnostics.Process
$proc.StartInfo=$psi
try {
    try {
        if(!$proc.Start()){throw 'PROJECT_PHP_START_FAILED'}
    } finally {
        $password=$null
        $psi.EnvironmentVariables['RESET_MAINTENANCE_DB_PASSWORD']=''
    }
    $stdoutTask=$proc.StandardOutput.ReadToEndAsync()
    $stderrTask=$proc.StandardError.ReadToEndAsync()
    $proc.WaitForExit()
    $exitCode=$proc.ExitCode
    $stdout=$stdoutTask.Result
    $stderr=$stderrTask.Result
} finally {$password=$null;$proc.Dispose()}
$lines=@($stdout -split "`r?`n" | Where-Object {$_ -ne ''})
foreach($line in $lines){
    if($line -notmatch '^(?:VIEW_DEPENDENCIES=\{|PREFLIGHT=PASS |BACKUP_(?:base|view)=PASS |COUNTS=\{|TARGET=[0-9]+\t|ASSIGNMENTS=[0-9]+ |REHEARSAL=PASS |APPLY_COMMITTED=YES |APPLY_COMMIT_OUTCOME=UNVERIFIED$|POSTFLIGHT=PASS )'){
        throw 'UNEXPECTED_RESET_RUNNER_OUTPUT'
    }
    Write-Output $line
}
if($exitCode -ne 0){
    $safeError=[regex]::Match($stderr,'(?m)^RESET=FAIL step=[a-z_]+ (?:reason=[A-Z0-9_:-]+|sqlstate=(?:[A-Z0-9]{5}|UNKNOWN) mysql_code=(?:[0-9]+|UNKNOWN)(?: (?:column|object)=[A-Za-z0-9_.]+)?)\r?$')
    if($safeError.Success){Write-Output $safeError.Value}
    else {Write-Output 'RESET_DIAGNOSTIC_UNAVAILABLE'}
    throw "RESET_RUNNER_FAILED exit=$exitCode"
}
if($Phase -eq 'Preflight' -and !($lines -match '^PREFLIGHT=PASS ')){throw 'RESET_PREFLIGHT_RESULT_MISSING'}
if($Phase -eq 'Rehearsal' -and !($lines -match '^REHEARSAL=PASS ')){throw 'RESET_REHEARSAL_RESULT_MISSING'}
if($Phase -eq 'Apply' -and (!($lines -match '^APPLY_COMMITTED=YES ') -or !($lines -match '^POSTFLIGHT=PASS '))){throw 'RESET_APPLY_POSTFLIGHT_MISSING'}
