<?php
declare(strict_types=1);
namespace App\Modules\Booking;

interface BookingCalendarRepository
{
    /** Return only UTC intervals, no identities or descriptions. Fail if the bounded result is incomplete. */
    public function monthWindows(string $startUtc,string $endUtc): array;
    public function eligibleVehicles(?int $passengers): int;
    public function availableVehicles(BookingCalendarWindow $window): int;
    public function pendingRequests(BookingCalendarWindow $window): int;
}
