<?php
declare(strict_types=1);
namespace App\Core;

/** Coordinates web POSTs with the isolated test reset process on this host. */
final class BookingWriteWindow
{
    private static mixed $webHandle = null;

    private static function open(): mixed
    {
        $path = dirname(__DIR__, 2).'/storage/booking-reset-writes.lock';
        $handle = @fopen($path, 'c');
        if ($handle === false) throw new \RuntimeException('BOOKING_WRITE_WINDOW_UNAVAILABLE');
        return $handle;
    }

    public static function enterWebWrite(): bool
    {
        if (self::$webHandle !== null) return true;
        $handle = self::open();
        if (!flock($handle, LOCK_SH | LOCK_NB)) { fclose($handle); return false; }
        self::$webHandle = $handle; // Held through response completion, including DB writes.
        return true;
    }

    public static function acquireMaintenance(): mixed
    {
        $handle = self::open();
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            throw new \RuntimeException('MAINTENANCE_WEB_WRITE_IN_FLIGHT');
        }
        return $handle;
    }
}
