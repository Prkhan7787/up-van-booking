$ErrorActionPreference='Stop'
$project=Split-Path -Parent $PSScriptRoot
$marker=Join-Path ([IO.Path]::GetTempPath()) ('upvan-lock-'+[guid]::NewGuid().ToString('N'))
$job=Start-Job -ArgumentList $project,$marker,'maintenance' -ScriptBlock {
    param($projectPath,$readyPath,$mode)
    Set-Location -LiteralPath $projectPath
    & .\scripts\php.ps1 tests/booking-reset-maintenance-window-holder-offline.php $readyPath $mode
    if($LASTEXITCODE -ne 0){throw "Holder failed exit=$LASTEXITCODE"}
}
try {
    $ready=$false
    for($i=0;$i -lt 60;$i++){
        if(Test-Path -LiteralPath $marker){$ready=$true;break}
        if($job.State -ne 'Running'){break}
        Start-Sleep -Milliseconds 50
    }
    if(!$ready){throw 'Cross-process lock holder did not start'}
    Push-Location -LiteralPath $project
    try {
        $blocked=& .\scripts\php.ps1 tests/booking-reset-maintenance-window-check-offline.php blocked
        if($LASTEXITCODE -ne 0 -or $blocked -cne 'WINDOW=blocked'){throw 'Cross-process write was not blocked'}
        Wait-Job -Job $job -Timeout 10 | Out-Null
        if($job.State -ne 'Completed'){throw 'Holder failed to release lock'}
        $available=& .\scripts\php.ps1 tests/booking-reset-maintenance-window-check-offline.php available
        if($LASTEXITCODE -ne 0 -or $available -cne 'WINDOW=available'){throw 'Lock remained after exception'}
    } finally {Pop-Location}
    Write-Output 'PASS: cross-process web write blocked and exception released lock; no MySQL connection made.'
} finally {
    if($job.State -eq 'Running'){Stop-Job -Job $job}
    Remove-Job -Job $job -Force
    if(Test-Path -LiteralPath $marker){Remove-Item -LiteralPath $marker}
}

$marker=Join-Path ([IO.Path]::GetTempPath()) ('upvan-lock-'+[guid]::NewGuid().ToString('N'))
$job=Start-Job -ArgumentList $project,$marker,'web' -ScriptBlock {
    param($projectPath,$readyPath,$mode)
    Set-Location -LiteralPath $projectPath
    & .\scripts\php.ps1 tests/booking-reset-maintenance-window-holder-offline.php $readyPath $mode
    if($LASTEXITCODE -ne 0){throw "Holder failed exit=$LASTEXITCODE"}
}
try {
    $ready=$false
    for($i=0;$i -lt 60;$i++){
        if(Test-Path -LiteralPath $marker){$ready=$true;break}
        if($job.State -ne 'Running'){break}
        Start-Sleep -Milliseconds 50
    }
    if(!$ready){throw 'Cross-process web lock holder did not start'}
    Push-Location -LiteralPath $project
    try {
        $blocked=& .\scripts\php.ps1 tests/booking-reset-maintenance-window-check-offline.php maintenance_blocked
        if($LASTEXITCODE -ne 0 -or $blocked -cne 'WINDOW=maintenance_blocked'){throw 'Maintenance entered during web write'}
        Wait-Job -Job $job -Timeout 10 | Out-Null
        if($job.State -ne 'Completed'){throw 'Web holder failed to release lock'}
        $available=& .\scripts\php.ps1 tests/booking-reset-maintenance-window-check-offline.php maintenance_available
        if($LASTEXITCODE -ne 0 -or $available -cne 'WINDOW=maintenance_available'){throw 'Web lock remained after exception'}
    } finally {Pop-Location}
    Write-Output 'PASS: cross-process web lock and exception release; no MySQL connection made.'
} finally {
    if($job.State -eq 'Running'){Stop-Job -Job $job}
    Remove-Job -Job $job -Force
    if(Test-Path -LiteralPath $marker){Remove-Item -LiteralPath $marker}
}
