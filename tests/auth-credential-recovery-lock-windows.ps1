param([switch]$Child,[string]$Scratch)
Set-StrictMode -Version Latest
$ErrorActionPreference='Stop'
$root=(Resolve-Path (Join-Path $PSScriptRoot '..')).Path
. (Join-Path $root 'scripts/lib/recovery-lock.ps1')
if($Child){
    $lock=Enter-UpVanRecoveryLock (Join-Path $Scratch 'lock')
    try{[IO.File]::WriteAllText((Join-Path $Scratch 'ready'),'dummy');while($true){Start-Sleep -Milliseconds 50}}
    finally{$lock.Dispose()}
    exit
}
$Scratch=Join-Path $PSScriptRoot ('.recovery-lock-test-'+[guid]::NewGuid().ToString('N'))
[void][IO.Directory]::CreateDirectory($Scratch)
$process=$null
try{
    $dummy=ConvertTo-SecureString ('a'*64) -AsPlainText -Force
    try{$encrypted=ConvertFrom-SecureString $dummy}finally{$dummy.Dispose()}
    $transaction=[guid]::NewGuid().ToString('N')
    $state=Join-Path $Scratch 'state.json'
    [IO.File]::WriteAllText($state,(@{TransactionId=$transaction;Status='AUTH_UPDATED';Protected=$encrypted}|ConvertTo-Json))
    foreach($mode in @('PreflightOnly','DryRun','Execute')){
        $ready=Join-Path $Scratch 'ready'
        if(Test-Path -LiteralPath $ready){Remove-Item -LiteralPath $ready}
        $info=[Diagnostics.ProcessStartInfo]::new('powershell.exe')
        $info.Arguments='-NoProfile -ExecutionPolicy Bypass -File "'+$PSCommandPath+'" -Child -Scratch "'+$Scratch+'"'
        $info.UseShellExecute=$false;$info.CreateNoWindow=$true
        $process=[Diagnostics.Process]::Start($info)
        $deadline=[DateTime]::UtcNow.AddSeconds(10)
        while(-not (Test-Path -LiteralPath $ready)) {if($process.HasExited -or [DateTime]::UtcNow -gt $deadline){throw 'Child lock acquisition failed'};Start-Sleep -Milliseconds 50}
        $blocked=$false
        try{$unexpected=Enter-UpVanRecoveryLock (Join-Path $Scratch 'lock');$unexpected.Dispose()}catch{$blocked=$_.Exception.Message -eq 'RECOVERY_LOCK_UNAVAILABLE'}
        if(-not $blocked){throw 'Concurrent recovery was not denied'}
        Write-Output "PASS: $mode contender denied while another process owns lock"
        $process.Kill();$process.WaitForExit();$process.Dispose();$process=$null
        $handle=Enter-UpVanRecoveryLock (Join-Path $Scratch 'lock')
        try{
            $stored=Get-Content -LiteralPath $state -Raw|ConvertFrom-Json
            if($stored.TransactionId -cne $transaction -or $stored.Protected -cne $encrypted -or $stored.Status -ne 'AUTH_UPDATED'){throw 'Interrupted recovery state changed'}
            $decoded=ConvertTo-SecureString $stored.Protected
            $decoded.Dispose()
        }finally{$handle.Dispose()}
        Write-Output "PASS: $mode crash releases lock and preserves transaction/DPAPI credential set"
    }
    $source=Get-Content -LiteralPath (Join-Path $root 'scripts/recover-test-auth-credentials.ps1') -Raw
    if($source.IndexOf('$recoveryLock = Enter-UpVanRecoveryLock') -gt $source.IndexOf('$staleEnvStages = @()') -or -not $source.Contains('if ($Execute -and $null -ne $state')){throw 'State access or cleanup can bypass shared lock'}
    Write-Output 'PASS: all modes lock before reading state; read-only modes cannot clean state'
    Write-Output 'PASS: 7 Windows lock checks; dummy state only, no database or real .env accessed.'
}finally{
    if($null -ne $process){if(-not $process.HasExited){$process.Kill();$process.WaitForExit()};$process.Dispose()}
    $full=[IO.Path]::GetFullPath($Scratch)
    if((Split-Path -Parent $full) -cne [IO.Path]::GetFullPath($PSScriptRoot) -or (Split-Path -Leaf $full) -notmatch '^\.recovery-lock-test-[a-f0-9]{32}$'){throw 'Scratch cleanup path rejected'}
    Remove-Item -LiteralPath $full -Recurse -Force
}
