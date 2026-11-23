function Invoke-MaintenanceMysqlCaptured {
    param(
        [Parameter(Mandatory=$true)][string]$ClientPath,
        [Parameter(Mandatory=$true)][string[]]$ClientArgs,
        [Parameter(Mandatory=$true)][string]$Query,
        [switch]$ViaStdin
    )
    $psi=New-Object System.Diagnostics.ProcessStartInfo
    $psi.FileName=$ClientPath
    $psi.Arguments=$ClientArgs -join ' '
    if(!$ViaStdin){
        if($Query.Contains('"')){throw 'UNSUPPORTED_SQL_ARGUMENT_QUOTING'}
        $psi.Arguments+=' --execute="'+$Query+'"'
    }
    $psi.UseShellExecute=$false
    $psi.CreateNoWindow=$true
    $psi.RedirectStandardInput=[bool]$ViaStdin
    $psi.RedirectStandardOutput=$true
    $psi.RedirectStandardError=$true
    $proc=New-Object System.Diagnostics.Process
    $proc.StartInfo=$psi
    try {
        if(!$proc.Start()){throw 'MYSQL_PROCESS_START_FAILED'}
        $outTask=$proc.StandardOutput.ReadToEndAsync()
        $errTask=$proc.StandardError.ReadToEndAsync()
        $inputWriteFailed=$false
        if($ViaStdin){
            $queryBytes=([Text.UTF8Encoding]::new($false)).GetBytes($Query)
            try {
                $proc.StandardInput.BaseStream.Write($queryBytes,0,$queryBytes.Length)
                $proc.StandardInput.BaseStream.Flush()
            } catch [System.IO.IOException] {
                $inputWriteFailed=$true
            } finally {
                try {$proc.StandardInput.Close()} catch [System.IO.IOException] {$inputWriteFailed=$true}
            }
        }
        $proc.WaitForExit()
        return @{ExitCode=$proc.ExitCode; Stdout=$outTask.Result; Stderr=$errTask.Result; InputWriteFailed=$inputWriteFailed}
    } finally {$proc.Dispose()}
}

function Join-MaintenanceGrantBatch {
    param([Parameter(Mandatory=$true)][string]$Guard,[Parameter(Mandatory=$true)][string]$Body)
    if(!$Guard.TrimEnd().EndsWith(';')){throw 'GRANT_GUARD_STATEMENT_SEPARATOR_MISSING'}
    return $Guard.TrimEnd()+"`r`n"+$Body
}

function Get-MaintenanceProposalCommentHeader {
    param([Parameter(Mandatory=$true)][string]$Proposal)
    if($Proposal.Length -gt 0 -and [int][char]$Proposal[0] -eq 0xFEFF){$Proposal=$Proposal.Substring(1)}
    $reader=[IO.StringReader]::new($Proposal)
    $header=New-Object System.Text.StringBuilder
    $foundComment=$false
    try {
        while($null -ne ($line=$reader.ReadLine())){
            $trimmed=$line.TrimStart([char[]]@([char]32,[char]9))
            if($trimmed.StartsWith('-- ') -or $trimmed.StartsWith("--`t")){
                [void]$header.Append($line).Append("`r`n")
                $foundComment=$true
                continue
            }
            if([string]::IsNullOrWhiteSpace($line)){
                if($foundComment){[void]$header.Append("`r`n")}
                continue
            }
            break
        }
    } finally {$reader.Dispose()}
    if(!$foundComment){throw 'PROPOSAL_COMMENT_HEADER_MISSING'}
    return $header.ToString()
}
