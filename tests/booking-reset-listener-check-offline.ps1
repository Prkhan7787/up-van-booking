$ErrorActionPreference='Stop'
$root=Split-Path -Parent $PSScriptRoot
$script=Join-Path $root 'scripts/booking-reset-listener-check.ps1'
$tokens=$null;$errors=$null
[System.Management.Automation.Language.Parser]::ParseFile($script,[ref]$tokens,[ref]$errors)|Out-Null
if($errors.Count){throw 'Listener checker syntax failed'}
$checks=0
try {
    # Inject OS table responses only in this test process. No socket or DB is opened.
    function global:Get-NetTCPConnection {
        [CmdletBinding()]param()
        if($global:listenerFixture -eq 'denied'){throw 'Access denied'}
        if($global:listenerFixture -eq 'failed'){throw 'Query failed'}
        if($global:listenerFixture -eq 'ipv4'){[pscustomobject]@{LocalPort=8088;State='Listen';LocalAddress='127.0.0.1'}}
        if($global:listenerFixture -eq 'ipv6'){[pscustomobject]@{LocalPort=8088;State='Listen';LocalAddress='::'}}
        if($global:listenerFixture -eq 'other'){[pscustomobject]@{LocalPort=8089;State='Listen';LocalAddress='::'}}
    }
    foreach($case in @(@('empty','LISTENER_ABSENT'),@('other','LISTENER_ABSENT'),@('ipv4','LISTENER_PRESENT'),@('ipv6','LISTENER_PRESENT'))){
        $global:listenerFixture=$case[0]
        $result=@(& $script)
        if($LASTEXITCODE -ne 0 -or $result.Count -ne 1 -or $result[0] -cne $case[1]){throw "Listener check failed for $($case[0])"}
        $checks++
    }
    foreach($case in @('denied','failed')){
        $global:listenerFixture=$case
        $result=@(& $script)
        if($LASTEXITCODE -ne 1 -or $result.Count -ne 0){throw 'Query errors must not produce absent marker'}
        $checks++
    }
} finally {
    Remove-Item Function:\Get-NetTCPConnection
    Remove-Variable listenerFixture -Scope Global
}
Write-Output "PASS: listener checker offline ($checks checks); OS query mocked, no database connection."
