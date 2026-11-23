<?php
declare(strict_types=1);
namespace App\Database;

/** Proposed test-only privileges; this class never executes GRANT. */
final class VehicleAdminGrantPlan
{
    public const READ_COLUMNS=['id','registration_number','label','passenger_capacity','is_active','created_at','updated_at'];
    public const WRITE_COLUMNS=['registration_number','label','passenger_capacity','is_active','created_by','updated_by'];

    public static function evaluate(array $grants, bool $partialRevokes, bool $rolesResolved): array
    {
        return GrantVerifier::evaluateProfiles($grants,'up_van_booking_test',[
            'vehicles'=>self::rules(),
            'vehicles_booking_read'=>self::rules(true),
            'vehicles_vehicle_calendar'=>self::rules(true,true),
            'vehicles_province'=>self::rules(false,false,true),
            'vehicles_booking_read_province'=>self::rules(true,false,true),
            'vehicles_vehicle_calendar_province'=>self::rules(true,true,true),
            'vehicles_requested_vehicle'=>self::rules(true,true,false,true),
            'vehicles_province_requested_vehicle'=>self::rules(true,true,true,true),
        ],$partialRevokes,$rolesResolved);
    }

    /** Schema-aware capability check: new-column privileges are required only when those columns exist. */
    public static function evaluateForSchema(array $grants,bool $partialRevokes,bool $rolesResolved,bool $provinceEnabled,bool $requestedVehicleEnabled,bool $fuelEnabled=false): array
    {
        $profiles=[
            'vehicles_schema'=>self::rules(false,false,$provinceEnabled,false,$fuelEnabled),
            'vehicles_booking_schema'=>self::rules(true,false,$provinceEnabled,false,$fuelEnabled),
            'vehicles_calendar_schema'=>self::rules(true,true,$provinceEnabled,false,$fuelEnabled),
        ];
        // Booking's optional requested_vehicle_id is an additive profile only;
        // its absence must not make Vehicle Management fail.
        // Recognize this exact approved grant union without requiring or probing
        // Booking's optional column for vehicle CRUD.
        $profiles['vehicles_approved_booking_union']=self::rules(true,true,$provinceEnabled,true,$fuelEnabled);
        return GrantVerifier::evaluateProfiles($grants,'up_van_booking_test',$profiles,$partialRevokes,$rolesResolved);
    }

    public static function evaluateCapability(array $grants,bool $partialRevokes,bool $rolesResolved,bool $provinceEnabled,bool $fuelEnabled): array
    {
        $rules=self::rules(false,false,$provinceEnabled,false,$fuelEnabled);
        $requirements=['tables'=>[
            'vehicles'=>$rules['tables']['vehicles'],
            'vehicle_assignments'=>$rules['tables']['vehicle_assignments'],
            'audit_logs'=>['INSERT'=>$rules['tables']['audit_logs']['INSERT']],
        ]];
        $result=BookingCapabilityGrantGuard::evaluate($grants,$requirements,$partialRevokes,$rolesResolved,$fuelEnabled);
        $result['profile']=$result['account_guard']['profile'] ?? null;
        $result['role_privileges_resolved']=$result['account_guard']['role_privileges_resolved'];
        return $result;
    }

    public static function rules(bool $bookingRead=false,bool $vehicleCalendar=false,bool $provinceEnabled=false,bool $requestedVehicleEnabled=false,bool $fuelEnabled=false): array
    {
        $rules=$vehicleCalendar ? VehicleCalendarGrantPlan::rules() : (($bookingRead || $requestedVehicleEnabled) ? BookingCalendarGrantPlan::rules(true) : SystemLogGrantPlan::rules());
        $readColumns=self::READ_COLUMNS;$writeColumns=self::WRITE_COLUMNS;
        if($provinceEnabled){$readColumns[]='registration_province';$writeColumns[]='registration_province';}
        if($fuelEnabled){$readColumns[]='fuel_type';$writeColumns[]='fuel_type';}
        $updateColumns=['registration_number','label','passenger_capacity','is_active','updated_by'];
        if($provinceEnabled)$updateColumns[]='registration_province';
        if($fuelEnabled){$updateColumns[]='fuel_type';}
        $rules['tables']['vehicles']=[
            'SELECT'=>$readColumns,
            'INSERT'=>$writeColumns,
            'UPDATE'=>$updateColumns,
        ];
        if($requestedVehicleEnabled)$rules['tables']['booking_requests']['SELECT'][]='requested_vehicle_id';
        if (!$bookingRead && !$vehicleCalendar) $rules['tables']['vehicle_assignments']=['SELECT'=>['vehicle_id']];
        return $rules;
    }

    public static function supplementalSql(): array
    {
        $columns=static fn(array $items):string=>implode(', ',array_map(static fn(string $item):string=>'`'.$item.'`',$items));
        return [
            'GRANT SELECT ('.$columns(self::READ_COLUMNS).'), INSERT ('.$columns(self::WRITE_COLUMNS).'), UPDATE (`registration_number`, `label`, `passenger_capacity`, `is_active`, `updated_by`) ON `up_van_booking_test`.`vehicles` TO \'up_van_auth_test\'@\'127.0.0.1\'',
            "GRANT SELECT (`vehicle_id`) ON `up_van_booking_test`.`vehicle_assignments` TO 'up_van_auth_test'@'127.0.0.1'",
        ];
    }

    public static function supplementalProvinceSql(): array
    {
        return [
            "GRANT SELECT (`registration_province`) ON `up_van_booking_test`.`vehicles` TO 'up_van_auth_test'@'127.0.0.1'",
            "GRANT INSERT (`registration_province`), UPDATE (`registration_province`) ON `up_van_booking_test`.`vehicles` TO 'up_van_auth_test'@'127.0.0.1'",
        ];
    }

    public static function supplementalRequestedVehicleSql(): string
    {
        return "GRANT SELECT (`requested_vehicle_id`) ON `up_van_booking_test`.`booking_requests` TO 'up_van_auth_test'@'127.0.0.1'";
    }

    /** Proposal only, derived from verified missing privileges; never grants Booking columns. */
    public static function deltaSql(array $evaluation,bool $provinceEnabled=false,bool $requestedVehicleEnabled=false,bool $fuelEnabled=false): array
    {
        if (($evaluation['unexpected_privileges']??[])!==[] || !($evaluation['role_privileges_resolved']??false)) return [];
        $allowed=self::rules(true,true,$provinceEnabled,$requestedVehicleEnabled,$fuelEnabled)['tables'];
        $missing=[];
        foreach ($evaluation['missing_privileges']??[] as $item) {
            if (!preg_match('/^column\.(vehicles|vehicle_assignments|booking_requests)\.([a-z_]+):(SELECT|INSERT|UPDATE)$/D',$item,$m)) continue;
            if (!in_array($m[2],$allowed[$m[1]][$m[3]]??[],true)) continue;
            $missing[$m[1]][$m[3]][]='`'.$m[2].'`';
        }
        $sql=[];
        foreach ($missing as $table=>$privileges) {
            $clauses=[];
            foreach ($privileges as $privilege=>$columns) $clauses[]=$privilege.' ('.implode(', ',array_unique($columns)).')';
            $sql[]='GRANT '.implode(', ',$clauses).' ON `up_van_booking_test`.`'.$table.'` TO \'up_van_auth_test\'@\'127.0.0.1\'';
        }
        return $sql;
    }

    public static function supplementalFuelSql(): array
    {
        return ["GRANT SELECT (`fuel_type`), INSERT (`fuel_type`), UPDATE (`fuel_type`) ON `up_van_booking_test`.`vehicles` TO 'up_van_auth_test'@'127.0.0.1'"];
    }
}
