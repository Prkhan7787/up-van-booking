function Get-MaintenanceGrantMysqlError {
    param([string]$Stderr)
    # Never return the raw client message: it may include SQL or a generated password.
    $match=[regex]::Match($Stderr, 'ERROR\s+(?<code>[0-9]{3,5})\s+\((?<state>[0-9A-Z]{5})\)', [Text.RegularExpressions.RegexOptions]::IgnoreCase)
    if(!$match.Success){return 'mysql_code=UNKNOWN sqlstate=UNKNOWN'}
    return "mysql_code=$($match.Groups['code'].Value) sqlstate=$($match.Groups['state'].Value.ToUpperInvariant())"
}

function Get-MaintenanceGrantApplyFailure {
    param([int]$ExitCode,[string]$Stderr,[bool]$GuardPassed,[bool]$Created,[bool]$InputWriteFailed=$false)
    $step=if(!$GuardPassed){'identity_guard_or_client'}elseif(!$Created){'create_user_or_missing_result'}else{'grant_after_create_user'}
    $state=if($Created){'partial_account_possible'}else{'account_creation_unconfirmed'}
    $mysqlError=Get-MaintenanceGrantMysqlError -Stderr $Stderr
    return "GRANT_APPLY_FAILED step=$step exit=$ExitCode input_write_failed=$($InputWriteFailed.ToString().ToLowerInvariant()) $mysqlError state=$state; inspect account and grants read-only, no retry"
}

function Get-MaintenanceGrantApplyOutput {
    param([string]$Stdout,[int]$ExitCode,[string]$Stderr)
    $lines=@($Stdout.Trim() -split "`r?`n" | Where-Object {$_ -ne ''})
    if($lines.Count -gt 0 -and $lines[0] -cne 'UPVAN_GRANT_GUARD_OK'){
        throw "GRANT_APPLY_FAILED step=identity_guard_result exit=$ExitCode $(Get-MaintenanceGrantMysqlError -Stderr $Stderr) state=account_creation_unconfirmed; inspect account read-only, no retry"
    }
    $result=[pscustomobject]@{GuardPassed=($lines.Count -gt 0); Created=$false; Password=$null; LineCount=$lines.Count}
    if($lines.Count -gt 1){
        $parts=@($lines[1] -split "`t")
        if($parts.Count -ne 4 -or $parts[0] -cne 'up_van_reset_maintenance' -or $parts[1] -cne '127.0.0.1' -or $parts[2].Length -lt 12 -or $parts[3] -cne '1'){
            throw "GENERATED_PASSWORD_RESULT_UNVERIFIED step=create_user_output exit=$ExitCode state=partial_account_possible; inspect account read-only, no retry"
        }
        $result.Created=$true
        $result.Password=$parts[2]
        $parts[2]=$null
    }
    return $result
}
