<?php
declare(strict_types=1);
$vendorAutoload = dirname(__DIR__).'/vendor/autoload.php';
if (is_file($vendorAutoload)) require_once $vendorAutoload;
spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'App\\')) return;
    $file = dirname(__DIR__) . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) require $file;
});
require dirname(__DIR__) . '/app/helpers.php';
App\Core\Env::load(dirname(__DIR__) . '/.env');
date_default_timezone_set('Asia/Bangkok');
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', dirname(__DIR__) . '/storage/logs/php.log');
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_name('up_van_session');
session_save_path(dirname(__DIR__) . '/storage/sessions');
session_set_cookie_params(App\Core\SessionCookiePolicy::params($_SERVER, App\Core\Env::get('SESSION_SECURE', 'false') === 'true'));
session_start();
