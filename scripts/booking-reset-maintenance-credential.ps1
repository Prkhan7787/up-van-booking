function Read-MaintenanceDpapiCredential {
    param([Parameter(Mandatory=$true)][string]$Path)
    if(!(Test-Path -LiteralPath $Path -PathType Leaf)){throw 'MAINTENANCE_DPAPI_FILE_MISSING'}
    $cipher=[IO.File]::ReadAllText($Path,[Text.Encoding]::ASCII).Trim()
    if([string]::IsNullOrWhiteSpace($cipher)){throw 'MAINTENANCE_DPAPI_FILE_EMPTY'}
    try {$secure=ConvertTo-SecureString -String $cipher -ErrorAction Stop}
    catch {throw 'MAINTENANCE_DPAPI_DECRYPT_FAILED'}
    $ptr=[IntPtr]::Zero
    try {
        $ptr=[Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure)
        $plain=[Runtime.InteropServices.Marshal]::PtrToStringBSTR($ptr)
        if([string]::IsNullOrEmpty($plain)){throw 'MAINTENANCE_DPAPI_DECRYPT_FAILED'}
        return $plain
    } finally {
        if($ptr -ne [IntPtr]::Zero){[Runtime.InteropServices.Marshal]::ZeroFreeBSTR($ptr)}
    }
}
