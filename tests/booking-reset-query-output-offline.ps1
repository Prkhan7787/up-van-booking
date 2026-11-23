$ErrorActionPreference='Stop'
Set-StrictMode -Version Latest
$database='up_van_booking_test'
function Convert-QueryOutput([AllowNull()][string]$Output){
    if([string]::IsNullOrEmpty($Output)){return [string[]]@()}
    $trimmed=$Output.TrimEnd([char[]]"`r`n")
    if([string]::IsNullOrEmpty($trimmed)){return [string[]]@()}
    return [string[]]($trimmed -split '\r?\n')
}
function Assert-Rows([string]$Name,[AllowEmptyCollection()][string[]]$Actual,[int]$Expected){
    if($Actual.Count -ne $Expected){throw "$Name expected $Expected rows, got $($Actual.Count)."}
}
function Test-TargetIdentity([string[]]$Fields,[string]$ExpectedUuid,[string]$ExpectedDatabase,[string]$ExpectedPort='3306'){
    $mismatch=@()
    if($Fields.Count -ne 6){return [pscustomobject]@{Pass=$false;Mismatches=@('column_count')}}
    if($Fields[1] -cne $ExpectedPort){$mismatch+='port'}
    if($Fields[2] -cne $ExpectedUuid){$mismatch+='server_uuid'}
    if($Fields[3] -cne $ExpectedDatabase){$mismatch+='database'}
    if($Fields[4] -notmatch '^8\.4\.'){$mismatch+='server_version'}
    if([string]::IsNullOrWhiteSpace($Fields[5])){$mismatch+='backup_login_account'}
    return [pscustomobject]@{Pass=($mismatch.Count -eq 0);Mismatches=@($mismatch)}
}
function Get-CheckTableAssessment([string[]]$Rows,[int]$ExitCode){
    $parsed=@();foreach($row in $Rows){$p=@($row -split "`t",4);if($p.Count -eq 4){$parsed += [pscustomobject]@{Table=$p[0];Operation=$p[1];MsgType=$p[2];MsgText=$p[3]}}else{$parsed += [pscustomobject]@{Table='';Operation='';MsgType='UNPARSED';MsgText=$row}}}
    $qualifiedViewName="$($database).calendar_events"
    $healthy=($ExitCode -eq 0 -and $parsed.Count -gt 0 -and @($parsed|Where-Object{($_.Table -cne 'calendar_events' -and $_.Table -cne $qualifiedViewName) -or $_.Operation -cne 'check' -or $_.MsgType -cne 'status' -or $_.MsgText -cne 'OK'}).Count -eq 0)
    return [pscustomobject]@{Healthy=$healthy;Rows=@($parsed)}
}
$zero=@(Convert-QueryOutput '')
$blank=@(Convert-QueryOutput "`r`n")
$one=@(Convert-QueryOutput 'row-one')
$many=@(Convert-QueryOutput "row-one`r`nrow-two`r`n")
Assert-Rows 'empty stdout' $zero 0
Assert-Rows 'blank stdout' $blank 0
Assert-Rows 'single row' $one 1
Assert-Rows 'multiple rows' $many 2
if($one[0] -cne 'row-one' -or $many[1] -cne 'row-two'){throw 'Query row content changed.'}
$uuid='77218fbd-d66d-11f1-9a0a-5c2886f057d5';$db='up_van_booking_test'
$target=@('localhost','3306',$uuid,$db,'8.4.27','backup_user@localhost')
$identity=Test-TargetIdentity $target $uuid $db
if(!$identity.Pass){throw 'Backup login identity must be allowed independently from runtime identity.'}
$badUuid=@('localhost','3306','wrong-uuid',$db,'8.4.27','backup_user@localhost')
$wrong=Test-TargetIdentity $badUuid $uuid $db
if($wrong.Pass -or $wrong.Mismatches -notcontains 'server_uuid'){throw 'Wrong UUID was not rejected.'}
$badPort=@('localhost','3307',$uuid,$db,'8.4.27','backup_user@localhost')
if((Test-TargetIdentity $badPort $uuid $db).Pass){throw 'Wrong port was not rejected.'}
$badDb=@('localhost','3306',$uuid,'other_db','8.4.27','backup_user@localhost')
if((Test-TargetIdentity $badDb $uuid $db).Pass){throw 'Wrong database was not rejected.'}
$checkOk=Get-CheckTableAssessment @("calendar_events`tcheck`tstatus`tOK") 0
if(!$checkOk.Healthy -or $checkOk.Rows[0].MsgText -cne 'OK'){throw 'CHECK TABLE OK row was not recognized.'}
$checkQualified=Get-CheckTableAssessment @("up_van_booking_test.calendar_events`tcheck`tstatus`tOK") 0
if(!$checkQualified.Healthy){throw 'Schema-qualified CHECK TABLE name was not recognized.'}
$checkError=Get-CheckTableAssessment @("calendar_events`tcheck`terror`terror 1356: view references invalid object") 0
if($checkError.Healthy -or $checkError.Rows[0].MsgType -cne 'error' -or $checkError.Rows[0].MsgText -notmatch '1356'){throw 'CHECK TABLE error details were not preserved.'}
$checkWarning=Get-CheckTableAssessment @("calendar_events`tcheck`tstatus`tOK","calendar_events`tcheck`tnote`tnote text") 0
if($checkWarning.Healthy -or $checkWarning.Rows.Count -ne 2){throw 'Additional CHECK TABLE diagnostic rows were ignored.'}
$checkExit=Get-CheckTableAssessment @() 1
if($checkExit.Healthy){throw 'CHECK TABLE nonzero Exit Code was treated as healthy.'}
$projectRoot=Split-Path -Parent $PSScriptRoot
$resetSource=Get-Content -LiteralPath (Join-Path $projectRoot 'scripts/booking-reset-preflight-backup.ps1') -Raw
$migration=Get-Content -LiteralPath (Join-Path $projectRoot 'database/migrations/002_booking_design.sql') -Raw
if($migration -notmatch 'a\.status\s+AS\s+assignment_status\s*,\s*b\.workflow_status'){throw 'Schema fixture no longer declares calendar_events columns as assignment_status and workflow_status.'}
if($resetSource -notmatch 'FROM calendar_events GROUP BY assignment_status,workflow_status ORDER BY assignment_status,workflow_status'){throw 'Calendar inventory query must use the actual view columns.'}
if($resetSource -match 'FROM calendar_events GROUP BY status\b|SELECT CONCAT\(status,CHAR\(9\),COUNT\(\)\) FROM calendar_events'){throw 'Calendar inventory query references nonexistent bare status column.'}
if($resetSource -notmatch 'FROM vehicle_assignments WHERE status IN'){throw 'Assignment inventory query must use vehicle_assignments.status.'}
if($resetSource -notmatch 'FROM booking_requests GROUP BY workflow_status'){throw 'Request inventory query must use booking_requests.workflow_status.'}
if($resetSource -notmatch 'information_schema\.VIEW_TABLE_USAGE WHERE VIEW_SCHEMA=''up_van_booking_test'' AND VIEW_NAME=''calendar_events'''){throw 'View dependencies must use MySQL 8.4 VIEW_TABLE_USAGE scoped to the expected schema.'}
if($resetSource -match 'VIEW_COLUMN_USAGE'){throw 'Unsupported VIEW_COLUMN_USAGE metadata query remains.'}
foreach($column in @('vehicle_assignments:id','vehicle_assignments:booking_request_id','vehicle_assignments:vehicle_id','vehicle_assignments:driver_id','vehicle_assignments:status','vehicle_assignments:starts_at','vehicle_assignments:ends_at','booking_requests:id','booking_requests:workflow_status')){if($migration -notmatch [regex]::Escape($column.Split(':')[1])){throw "Migration fixture missing view dependency column $column"}}
Write-Output 'PASS: query output and CHECK TABLE diagnostics; schema-aligned status columns verified for booking_requests, vehicle_assignments, and calendar_events.'
