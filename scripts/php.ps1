# Isolated CLI PHP; does not read or modify any other project's PHP runtime.
$projectPath = Split-Path -Parent $PSScriptRoot
Push-Location -LiteralPath $projectPath
try {
    & '.\.runtime\php84\php.exe' -n -d extension_dir=.runtime/php84/ext -d extension=pdo_mysql -d extension=mbstring -d extension=openssl @args
    $phpExitCode = $LASTEXITCODE
} finally { Pop-Location }
exit $phpExitCode
