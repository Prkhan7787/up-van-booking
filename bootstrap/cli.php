<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'App\\')) return;
    $path = dirname(__DIR__) . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($path)) require $path;
});
App\Core\Env::load(dirname(__DIR__) . '/.env');
date_default_timezone_set('UTC');
ini_set('display_errors', '0');
set_exception_handler(static function (Throwable $error): void {
    // Never emit PDO messages, SQL, connection strings, arguments or stack traces.
    $message = $error instanceof App\Database\SafetyException ? $error->getMessage() : 'Operation failed; no automatic retry or recovery performed.';
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
});
