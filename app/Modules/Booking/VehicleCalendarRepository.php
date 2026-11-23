<?php
declare(strict_types=1);
namespace App\Modules\Booking;

interface VehicleCalendarRepository
{
    public function activeVehicles(): array;
    public function activeVehicle(int $id): ?array;
    public function monthWindows(int $vehicleId,string $startUtc,string $endUtc): array;
    public function available(int $vehicleId,BookingCalendarWindow $window): bool;
    public function pending(int $vehicleId,BookingCalendarWindow $window): int;
}
