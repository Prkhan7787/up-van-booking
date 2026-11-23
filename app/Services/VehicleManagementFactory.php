<?php
declare(strict_types=1);
namespace App\Services;

use App\Database\ConnectionFactory;
use App\Modules\Booking\VehicleManagementService;
use App\Repositories\PdoVehicleRepository;

final class VehicleManagementFactory
{
    public static function create(): VehicleManagementService
    {
        return new VehicleManagementService(new PdoVehicleRepository(ConnectionFactory::connect('auth_test')));
    }
}
