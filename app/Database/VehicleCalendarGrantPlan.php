<?php
declare(strict_types=1);
namespace App\Database;

/** Test-only vehicle calendar read plan; pending requests are not assigned to vehicles in current schema. */
final class VehicleCalendarGrantPlan
{
    public const VEHICLE_COLUMNS=['id','registration_number','label','passenger_capacity','is_active'];

    public static function evaluate(array $grants,bool $partialRevokes,bool $rolesResolved): array
    {
        return GrantVerifier::evaluateProfiles($grants,'up_van_booking_test',[
            'vehicle_calendar'=>self::rules(),
            'vehicle_calendar_province'=>self::rules(true,false),
            'vehicle_calendar_requested_vehicle'=>self::rules(false,true),
            'vehicle_calendar_province_requested_vehicle'=>self::rules(true,true),
            'approved_runtime_union'=>VehicleAdminGrantPlan::rules(true,true),
            'approved_runtime_union_province'=>VehicleAdminGrantPlan::rules(true,true,true),
            'approved_runtime_union_requested_vehicle'=>VehicleAdminGrantPlan::rules(true,true,false,true),
            'approved_runtime_union_province_requested_vehicle'=>VehicleAdminGrantPlan::rules(true,true,true,true),
        ],$partialRevokes,$rolesResolved);
    }

    public static function evaluateForSchema(array $grants,bool $partialRevokes,bool $rolesResolved,bool $provinceEnabled,bool $requestedVehicleEnabled): array
    {
        return GrantVerifier::evaluateProfiles($grants,'up_van_booking_test',[
            'vehicle_calendar_schema'=>self::rules($provinceEnabled,$requestedVehicleEnabled),
            'approved_runtime_union_schema'=>VehicleAdminGrantPlan::rules(true,true,$provinceEnabled,$requestedVehicleEnabled),
        ],$partialRevokes,$rolesResolved);
    }

    public static function rules(bool $provinceEnabled=false,bool $requestedVehicleEnabled=false): array
    {
        $rules=BookingCalendarGrantPlan::rules(true);
        $rules['tables']['vehicles']['SELECT']=self::VEHICLE_COLUMNS;
        if ($provinceEnabled) $rules['tables']['vehicles']['SELECT'][]='registration_province';
        if ($requestedVehicleEnabled) $rules['tables']['booking_requests']['SELECT'][]='requested_vehicle_id';
        return $rules;
    }

    public static function supplementalSql(): array
    {
        $account="'up_van_auth_test'@'127.0.0.1'";
        return [
            "GRANT SELECT (`registration_number`, `label`) ON `up_van_booking_test`.`vehicles` TO {$account}",
        ];
    }

    public static function supplementalProvinceSql(): string
    {
        return "GRANT SELECT (`registration_province`) ON `up_van_booking_test`.`vehicles` TO 'up_van_auth_test'@'127.0.0.1'";
    }

    public static function supplementalRequestedVehicleSql(): string
    {
        return "GRANT SELECT (`requested_vehicle_id`) ON `up_van_booking_test`.`booking_requests` TO 'up_van_auth_test'@'127.0.0.1'";
    }
}
