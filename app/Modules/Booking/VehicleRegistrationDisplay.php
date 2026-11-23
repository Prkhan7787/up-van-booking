<?php
declare(strict_types=1);
namespace App\Modules\Booking;

/** Formats registration text from a vehicle row for A4 and queue presentation. */
final class VehicleRegistrationDisplay
{
    public static function format(mixed $registrationNumber,mixed $registrationProvince): string
    {
        if(!is_string($registrationNumber) || trim($registrationNumber)==='')return '';
        $registrationNumber=trim($registrationNumber);
        $registrationProvince=is_string($registrationProvince)?trim($registrationProvince):'';
        return $registrationProvince===''?$registrationNumber:$registrationNumber.' '.$registrationProvince;
    }
}
