<?php
declare(strict_types=1);
namespace App\Core;

/** Independent test-only write gate. Unset is denied; no .env edit in Task 05D-4. */
final class VehicleManagementFeature
{
    public static function writesEnabled(): bool
    {
        return Env::get('TEST_VEHICLE_MANAGEMENT_APPROVED')==='true';
    }
}
