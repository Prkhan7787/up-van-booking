<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\AuthFeature;
use App\Database\ConnectionFactory;
use App\Modules\Booking\VehicleCalendarService;
use App\Repositories\PdoVehicleCalendarRepository;

final class VehicleCalendarFactory
{
    public static function create(): VehicleCalendarService
    {
        if (!AuthFeature::testExecutionEnabled()) throw new \RuntimeException('VEHICLE_CALENDAR_NOT_READY');
        return new VehicleCalendarService(new PdoVehicleCalendarRepository(ConnectionFactory::connect('auth_test')));
    }
}
