<?php
declare(strict_types=1);
namespace App\Modules\Booking;

/** Runtime precondition across applied Migration 005 and optional Migration 006. */
final class VehicleSchemaValidator
{
    /** @param list<array{0:string,1:string,2:int,3:int}> $indexes */
    public static function assertRegistrationOnly(int $provinceColumnCount, array $indexes): void
    {
        if ($provinceColumnCount < 0 || $provinceColumnCount > 1) throw new \RuntimeException('VEHICLE_SCHEMA_NOT_READY');
        $expected=[['uq_vehicle_registration_number','registration_number',1,0]];
        if ($indexes !== $expected) throw new \RuntimeException('VEHICLE_UNIQUE_NOT_READY');
    }
}
