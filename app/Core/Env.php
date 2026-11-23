<?php
declare(strict_types=1);
namespace App\Core;

final class Env
{
    private static array $values = [];
    public static function load(string $path): void
    {
        if (!is_file($path)) return;
        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) continue;
            if (!preg_match('/^([A-Z][A-Z0-9_]*)=(.*)$/', $line, $m)) {
                throw new \RuntimeException('Invalid environment configuration.');
            }
            self::$values[$m[1]] = trim(trim($m[2]), "\"'");
        }
    }
    public static function get(string $key, string $default = ''): string
    {
        $value = getenv($key);
        return $value !== false ? $value : (self::$values[$key] ?? $default);
    }
}
