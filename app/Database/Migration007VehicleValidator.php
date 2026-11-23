<?php
declare(strict_types=1);
namespace App\Database;

/** Exact Schema 006 -> 007 contract; new fuel data stays nullable for existing vehicles. */
final class Migration007VehicleValidator
{
    public const VERSION='007_vehicle_fuel_details';
    public const CHECK='chk_vehicle_fuel_efficiency_positive';

    public static function assertPreconditions(array $vehicleColumns,array $fuelColumns,array $fuelChecks): void
    {
        $expected=['id','registration_number','registration_province','label','passenger_capacity','is_active','created_by','updated_by','created_at','updated_at'];
        if($vehicleColumns!==$expected)throw new SafetyException('MIGRATION_007_BASE_SCHEMA_DRIFT');
        if($fuelColumns!==[])throw new SafetyException('MIGRATION_007_COLUMN_ALREADY_PRESENT');
        if($fuelChecks!==[])throw new SafetyException('MIGRATION_007_CHECK_ALREADY_PRESENT');
    }

    public static function assertPostconditions(array $fuelColumns,array $fuelChecks): void
    {
        if($fuelColumns!==[['fuel_type','varchar(100)','YES']] || $fuelChecks!==[])throw new SafetyException('MIGRATION_007_COLUMN_POSTCONDITION');
    }
}
