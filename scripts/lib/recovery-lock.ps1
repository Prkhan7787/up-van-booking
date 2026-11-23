function Enter-UpVanRecoveryLock([string]$Path) {
    if (Test-Path -LiteralPath $Path) {
        if ((Get-Item -LiteralPath $Path).Attributes -band [IO.FileAttributes]::ReparsePoint) { throw 'RECOVERY_LOCK_UNSAFE' }
    }
    try { $handle=[IO.File]::Open($Path,[IO.FileMode]::OpenOrCreate,[IO.FileAccess]::ReadWrite,[IO.FileShare]::None) }
    catch { throw 'RECOVERY_LOCK_UNAVAILABLE' }
    try {
        if ($handle.Length -ne 0) { throw 'RECOVERY_LOCK_UNSAFE' }
        return $handle
    } catch { $handle.Dispose(); throw }
}
