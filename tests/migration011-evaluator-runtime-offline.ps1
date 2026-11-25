#Requires -Version 5.1
$ErrorActionPreference='Stop'
. (Join-Path $PSScriptRoot '../scripts/lib/migration011-apply-credentials.ps1')
$checks=0
function Assert([bool]$Condition,[string]$Message){if(!$Condition){throw "FAIL: $Message"};$script:checks++}
function Rejected([scriptblock]$Operation,[string]$Code){try{& $Operation;return $false}catch{return $_.Exception.Message -ceq $Code}}
$project=Split-Path -Parent $PSScriptRoot
$runtime=Resolve-Migration011EvaluatorRuntime $project
$evaluator=Join-Path $project 'scripts/migration011-provision-grants-check.php'
$temp=[IO.Path]::Combine([IO.Path]::GetTempPath(),('upvan011-runtime-'+[guid]::NewGuid().ToString('N')))
[void][IO.Directory]::CreateDirectory($temp)
try{
    Assert ([IO.File]::Exists($runtime)) 'existing project PHP runtime resolves'
    Invoke-Migration011GrantEvaluator -Runtime $runtime -Evaluator $evaluator -RuntimeCheck
    Assert $true 'actual PHP 8.4 loads worktree evaluator dependencies without MySQL'
    $missing=Join-Path $temp 'empty';[void][IO.Directory]::CreateDirectory($missing)
    Assert (Rejected {Resolve-Migration011EvaluatorRuntime $missing} 'MIGRATION_011_GRANT_EVALUATOR_RUNTIME_MISSING') 'missing runtime fails closed'
    Assert (Rejected {Invoke-Migration011GrantEvaluator -Runtime (Join-Path $missing 'php.exe') -Evaluator $evaluator -RuntimeCheck} 'MIGRATION_011_GRANT_EVALUATOR_RUNTIME_INVALID') 'invalid runtime rejected'
    Assert (Rejected {Invoke-Migration011GrantEvaluator -Runtime $runtime -Evaluator (Join-Path $missing 'absent.php') -RuntimeCheck} 'MIGRATION_011_GRANT_EVALUATOR_SCRIPT_MISSING') 'missing evaluator rejected'
    $failing=Join-Path $temp 'fail.php';[IO.File]::WriteAllText($failing,'<?php exit(9);')
    Assert (Rejected {Invoke-Migration011GrantEvaluator -Runtime $runtime -Evaluator $failing -RuntimeCheck} 'MIGRATION_011_GRANT_EVALUATOR_VALIDATION_FAILED') 'nonzero child exit rejected'
    [IO.File]::WriteAllText($failing,'<?php echo "MIGRATION_011_EVALUATOR_RUNTIME=8.3";')
    Assert (Rejected {Invoke-Migration011GrantEvaluator -Runtime $runtime -Evaluator $failing -RuntimeCheck} 'MIGRATION_011_GRANT_EVALUATOR_VALIDATION_FAILED') 'wrong runtime version marker rejected'
    # Simulate a main checkout containing Thai characters, independent of source file encoding.
    $main=Join-Path $temp ('main-'+[char]0x0E23+[char]0x0E16)
    $gitDir=Join-Path $main '.git/worktrees/fixture';[void][IO.Directory]::CreateDirectory($gitDir)
    [IO.File]::WriteAllText((Join-Path $gitDir 'commondir'),'../..',[Text.Encoding]::UTF8)
    $worktree=Join-Path $temp 'worktree';[void][IO.Directory]::CreateDirectory($worktree)
    [IO.File]::WriteAllText((Join-Path $worktree '.git'),('gitdir: '+$gitDir),[Text.Encoding]::UTF8)
    $fakeRuntime=Join-Path $main '.runtime/php84/php.exe';[void][IO.Directory]::CreateDirectory((Split-Path -Parent $fakeRuntime))
    [IO.File]::WriteAllText($fakeRuntime,'not an executable')
    Assert ((Resolve-Migration011EvaluatorRuntime $worktree) -ceq [IO.Path]::GetFullPath($fakeRuntime)) 'UTF-8 Git metadata resolves Thai main checkout runtime'
    Assert (Rejected {Invoke-Migration011GrantEvaluator -Runtime $fakeRuntime -Evaluator $evaluator -RuntimeCheck} 'MIGRATION_011_GRANT_EVALUATOR_CHILD_FAILED') 'invalid executable child start rejected'
    $source=[IO.File]::ReadAllText((Join-Path $project 'scripts/migration011-apply-account-provision.ps1'))
    Assert ($source.IndexOf('Invoke-Migration011GrantEvaluator -Runtime $php -Evaluator $phpEvaluator -RuntimeCheck') -lt $source.IndexOf('$editorOutput=')) 'runtime and dependency probe precedes MySQL interaction'
    Assert ($source.Contains("if(`$Action -in @('VerifyExisting','ReadOnlyPreflight'))") -and $source.IndexOf('Assert-011AccountMissing') -gt 0) 'existing account has a separate read-only verification branch'
    Write-Output "PASS: $checks Migration 011 evaluator runtime offline checks; no MySQL connection."
}finally{
    $resolved=[IO.Path]::GetFullPath($temp)
    if($resolved.StartsWith([IO.Path]::GetFullPath([IO.Path]::GetTempPath()),[StringComparison]::OrdinalIgnoreCase) -and ([IO.Path]::GetFileName($resolved) -match '^upvan011-runtime-[a-f0-9]{32}$')){
        Remove-Item -LiteralPath $resolved -Recurse -Force
    }
}
