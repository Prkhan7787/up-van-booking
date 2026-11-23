<?php
declare(strict_types=1);
namespace App\Core;

final class ErrorReporter
{
    public static function report(\Throwable $error, ?string $correlationId = null): string
    {
        // Deliberately do not stringify the exception; PDO messages can include identifiers or DSNs.
        $correlationId ??= bin2hex(random_bytes(12));
        error_log(self::safeRecord($correlationId));
        return $correlationId;
    }

    public static function safeRecord(string $correlationId): string
    {
        if (!preg_match('/^[a-f0-9]{24}$/D', $correlationId)) throw new \InvalidArgumentException('Invalid correlation identifier.');
        return json_encode(['event' => 'web.request_failed', 'correlation_id' => $correlationId], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
