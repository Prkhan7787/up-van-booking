<?php
declare(strict_types=1);
namespace App\Modules\Booking;

use App\Core\AuthorizationException;
use App\Models\AuthenticatedPrincipal;
use App\Services\AuthorizationPolicy;

/** Reuses the existing month/quote policy, scoped by immutable vehicle ID. */
final class VehicleCalendarService
{
    public function __construct(private readonly VehicleCalendarRepository $repo) {}

    public function vehicles(AuthenticatedPrincipal $actor): array
    {
        $this->allow($actor);return $this->repo->activeVehicles();
    }
    public function vehicle(AuthenticatedPrincipal $actor,int $id): array
    {
        $this->allow($actor);
        if ($id<1) throw new \InvalidArgumentException('VEHICLE_ID_INVALID');
        $vehicle=$this->repo->activeVehicle($id);
        if ($vehicle===null || (int)$vehicle['id']!==$id || !(bool)$vehicle['is_active']) throw new \InvalidArgumentException('VEHICLE_NOT_ACTIVE');
        return $vehicle;
    }
    public function month(AuthenticatedPrincipal $actor,int $vehicleId,string $month): array
    {
        $vehicle=$this->vehicle($actor,$vehicleId);
        return (new BookingCalendarService($this->adapter($vehicleId,(int)$vehicle['passenger_capacity'])))->month($actor,$month);
    }
    public function quote(AuthenticatedPrincipal $actor,int $vehicleId,BookingCalendarWindow $window): array
    {
        $vehicle=$this->vehicle($actor,$vehicleId);
        return (new BookingCalendarService($this->adapter($vehicleId,(int)$vehicle['passenger_capacity'])))->quote($actor,$window);
    }
    private function adapter(int $vehicleId,int $capacity): BookingCalendarRepository
    {
        return new class($this->repo,$vehicleId,$capacity) implements BookingCalendarRepository {
            public function __construct(private VehicleCalendarRepository $repo,private int $vehicleId,private int $capacity) {}
            public function monthWindows(string $startUtc,string $endUtc): array {return $this->repo->monthWindows($this->vehicleId,$startUtc,$endUtc);}
            public function eligibleVehicles(?int $passengers): int {return $passengers===null || $passengers<=$this->capacity ? 1 : 0;}
            public function availableVehicles(BookingCalendarWindow $window): int {return ($window->passengers===null || $window->passengers<=$this->capacity) && $this->repo->available($this->vehicleId,$window) ? 1 : 0;}
            public function pendingRequests(BookingCalendarWindow $window): int {return $this->repo->pending($this->vehicleId,$window);}
        };
    }
    private function allow(AuthenticatedPrincipal $actor): void
    {
        if (!(new AuthorizationPolicy())->allows($actor->roles,'calendar.view')) throw new AuthorizationException();
    }
}
