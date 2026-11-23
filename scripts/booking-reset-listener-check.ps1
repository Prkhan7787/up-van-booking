$ErrorActionPreference='Stop'
Set-StrictMode -Version Latest
try {
    # Query the whole table: filtering at the cmdlet level can report a no-match
    # error. Local filtering covers IPv4, IPv6 and wildcard listeners alike.
    $connections=@(Get-NetTCPConnection -ErrorAction Stop)
    $listeners=@($connections | Where-Object {$_.LocalPort -eq 8088 -and $_.State -eq 'Listen'})
    if($listeners.Count -gt 0){Write-Output 'LISTENER_PRESENT'}
    else {Write-Output 'LISTENER_ABSENT'}
    exit 0
} catch {
    [Console]::Error.WriteLine('CHECK_FAILED')
    exit 1
}
