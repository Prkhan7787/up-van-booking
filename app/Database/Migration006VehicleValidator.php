<?php
declare(strict_types=1);
namespace App\Database;

/** Offline-testable, fail-closed contract for the proposed forward-only Migration 006. */
final class Migration006VehicleValidator
{
    public static function assertPreconditions(array $vehicleColumns,array $vehicleIndexes,array $bookingColumns,array $bookingForeignKeys,array $duplicateRegistrations): void
    {
        $expected=['id','registration_number','label','passenger_capacity','is_active','created_by','updated_by','created_at','updated_at'];
        if($vehicleColumns!==$expected)throw new \RuntimeException('MIGRATION_006_VEHICLE_SCHEMA_DRIFT');
        if($vehicleIndexes!==[['uq_vehicle_registration_number','registration_number',1,0]])throw new \RuntimeException('MIGRATION_006_VEHICLE_INDEX_DRIFT');
        if(in_array('requested_vehicle_id',$bookingColumns,true))throw new \RuntimeException('MIGRATION_006_ALREADY_OR_PARTIALLY_APPLIED');
        if(in_array('fk_booking_requested_vehicle',$bookingForeignKeys,true))throw new \RuntimeException('MIGRATION_006_FOREIGN_KEY_ALREADY_EXISTS');
        if($duplicateRegistrations!==[])throw new \RuntimeException('MIGRATION_006_DUPLICATE_REGISTRATION');
    }

    public static function assertPostconditions(array $provinceColumn,array $requestedVehicleColumn,array $bookingIndexes,array $bookingForeignKeys): void
    {
        if($provinceColumn!==['registration_province','varchar(100)','YES'])throw new \RuntimeException('MIGRATION_006_PROVINCE_POSTCONDITION');
        if($requestedVehicleColumn!==['requested_vehicle_id','bigint unsigned','YES'])throw new \RuntimeException('MIGRATION_006_REQUESTED_VEHICLE_POSTCONDITION');
        if($bookingIndexes!==[['idx_booking_requested_vehicle','requested_vehicle_id',1,1]])throw new \RuntimeException('MIGRATION_006_BOOKING_INDEX_POSTCONDITION');
        if($bookingForeignKeys!==[['fk_booking_requested_vehicle','requested_vehicle_id','vehicles','id']])throw new \RuntimeException('MIGRATION_006_BOOKING_FK_POSTCONDITION');
    }
}
