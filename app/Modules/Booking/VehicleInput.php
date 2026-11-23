<?php
declare(strict_types=1);
namespace App\Modules\Booking;

/** Vehicle data with an optional province during the Migration 005 -> 006 transition. */
final readonly class VehicleInput
{
    private function __construct(
        public string $label,
        public string $registrationNumber,
        public ?string $registrationProvince,
        public int $passengerCapacity,
        public ?string $fuelType,
    ) {}

    public static function fromArray(array $data): self
    {
        foreach (['label'=>150,'registration_number'=>30] as $key=>$limit) {
            $value=$data[$key] ?? null;
            if (!is_string($value) || $value!==trim($value) || $value==='' || mb_strlen($value,'UTF-8')>$limit) throw new \InvalidArgumentException('VEHICLE_FIELD_INVALID');
        }
        $province=$data['registration_province'] ?? '';
        if (!is_string($province) || $province!==trim($province) || mb_strlen($province,'UTF-8')>100) throw new \InvalidArgumentException('VEHICLE_PROVINCE_INVALID');
        if ($province!=='' && mb_stripos($data['registration_number'],$province,0,'UTF-8')!==false) throw new \InvalidArgumentException('VEHICLE_PROVINCE_DUPLICATED_IN_REGISTRATION');
        $capacity=$data['passenger_capacity'] ?? null;
        if (!is_string($capacity) || !ctype_digit($capacity) || (int)$capacity<1 || (int)$capacity>65535) throw new \InvalidArgumentException('VEHICLE_CAPACITY_INVALID');
        $fuelType=$data['fuel_type']??'';
        if(!is_string($fuelType))throw new \InvalidArgumentException('VEHICLE_FUEL_INVALID');
        $fuelType=trim($fuelType);
        if($fuelType!=='' && !FuelTypeCatalog::contains($fuelType))throw new \InvalidArgumentException('VEHICLE_FUEL_TYPE_INVALID');
        return new self($data['label'],$data['registration_number'],$province===''?null:$province,(int)$capacity,$fuelType===''?null:$fuelType);
    }
}
