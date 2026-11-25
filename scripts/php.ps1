param([string]$PhpRuntimePath = '')
# Configure extensions for this child process only; never edit a shared php.ini.
$ErrorActionPreference='Stop'
$projectPath = Split-Path -Parent $PSScriptRoot
$candidates=@((Join-Path $projectPath '.runtime\php84\php.exe'))
# Discover the main project's approved sibling runtime without embedding Unicode
# path literals that Windows PowerShell 5.1 may decode using the legacy code page.
$commonDirectory=git -C $projectPath rev-parse --path-format=absolute --git-common-dir
if ($LASTEXITCODE -eq 0 -and $commonDirectory) {
    $mainProject=Split-Path -Parent ([string]$commonDirectory)
    $candidates+=Join-Path ($mainProject+'.runtime') 'php84\php.exe'
}
if ($PhpRuntimePath) { $candidates=@($PhpRuntimePath) }
$runtime=$candidates | Where-Object { Test-Path -LiteralPath $_ -PathType Leaf } | Select-Object -First 1
if (!$runtime) { throw 'PROJECT_PHP_RUNTIME_MISSING: supply -PhpRuntimePath with an approved PHP 8.4 executable' }
$runtime=(Resolve-Path -LiteralPath $runtime).ProviderPath
if ([IO.Path]::GetFileName($runtime) -ine 'php.exe') { throw 'PROJECT_PHP_RUNTIME_REJECTED' }
$extensionDirectory=Join-Path (Split-Path -Parent $runtime) 'ext'
$flags=@('-n','-d',"extension_dir=$extensionDirectory",'-d','extension=pdo_mysql','-d','extension=mbstring','-d','extension=openssl','-d','extension=curl')
$check=& $runtime @flags -r 'echo PHP_MAJOR_VERSION,".",PHP_MINOR_VERSION; foreach (["pdo_mysql","mbstring","openssl","curl"] as $ext) { if (!extension_loaded($ext)) exit(3); }'
if ($LASTEXITCODE -ne 0 -or ($check -join '') -ne '8.4') { throw 'PROJECT_PHP_RUNTIME_OR_EXTENSIONS_REJECTED' }
Push-Location -LiteralPath $projectPath
try {
    & $runtime @flags @args
    $phpExitCode = $LASTEXITCODE
} finally { Pop-Location }
exit $phpExitCode
