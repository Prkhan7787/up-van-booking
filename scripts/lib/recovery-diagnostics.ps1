function ConvertFrom-UpVanRecoveryDiagnostic([string]$Output, [int]$ExitCode) {
    $result = [pscustomobject]@{ExitCode=$ExitCode;Status='FAIL';Step='PHP_PROCESS';Code='INVALID_DIAGNOSTIC_SCHEMA';Account=$null;SqlState=$null;NativeCode=$null}
    if ([string]::IsNullOrWhiteSpace($Output)) { $result.Code='EMPTY_DIAGNOSTIC_OUTPUT'; return $result }
    try { $parsed = ConvertFrom-Json -InputObject $Output -ErrorAction Stop }
    catch { $result.Code='MALFORMED_DIAGNOSTIC_JSON'; return $result }
    if ($null -eq $parsed -or $parsed -isnot [pscustomobject]) { return $result }
    $properties = $parsed.PSObject.Properties
    foreach ($key in @('status','step','code')) {
        if ($null -eq $properties[$key] -or $properties[$key].Value -isnot [string]) { return $result }
    }
    if ($properties['status'].Value -cnotin @('PASS','FAIL') -or $properties['step'].Value -cnotmatch '^[A-Z][A-Z0-9_]{0,63}$' -or $properties['code'].Value -cnotmatch '^[A-Z][A-Z0-9_]{0,95}$') { return $result }
    foreach ($property in $properties) {
        if ($property.Name -cnotin @('status','step','code','account','sqlstate','native_code','accounts','grants','target')) { return $result }
    }
    foreach ($key in @('account','sqlstate','native_code')) {
        $value = if ($null -ne $properties[$key]) { $properties[$key].Value } else { $null }
        if ($null -eq $value) { continue }
        if ($key -eq 'account' -and ($value -isnot [string] -or $value -cnotin @('up_van_auth_test','up_van_test_provisioner'))) { return $result }
        if ($key -eq 'sqlstate' -and ($value -isnot [string] -or $value -cnotmatch '^[A-Z0-9]{5}$')) { return $result }
        if ($key -eq 'native_code' -and (($value -isnot [int] -and $value -isnot [long]) -or $value -lt 0 -or $value -gt 2147483647)) { return $result }
    }
    $result.Status=$properties['status'].Value; $result.Step=$properties['step'].Value; $result.Code=$properties['code'].Value
    if ($null -ne $properties['account']) { $result.Account=$properties['account'].Value }
    if ($null -ne $properties['sqlstate']) { $result.SqlState=$properties['sqlstate'].Value }
    if ($null -ne $properties['native_code']) { $result.NativeCode=$properties['native_code'].Value }
    if ($ExitCode -ne 0 -and $result.Status -eq 'PASS') { $result.Status='FAIL'; $result.Step='PHP_PROCESS'; $result.Code='PROCESS_EXIT_MISMATCH' }
    return $result
}
