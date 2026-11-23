<?php
declare(strict_types=1);
namespace App\Modules\Booking;

use App\Core\AuthorizationException;
use App\Models\AuthenticatedPrincipal;
use App\Services\AuthorizationPolicy;

/** Server-owned vehicle mutations; HTTP writes remain behind a closed test gate. */
final class VehicleManagementService
{
    public function __construct(private readonly VehicleRepository $repo) {}

    public function listing(AuthenticatedPrincipal $actor,int $page=1): array
    {
        $this->admin($actor);
        if ($page<1 || $page>10000) throw new \InvalidArgumentException('VEHICLE_PAGE_INVALID');
        return $this->repo->list(26,($page-1)*25);
    }

    public function supportsRegistrationProvince(AuthenticatedPrincipal $actor): bool
    {
        $this->admin($actor);
        return $this->repo->supportsRegistrationProvince();
    }

    public function supportsFuelDetails(AuthenticatedPrincipal $actor): bool
    {
        $this->admin($actor);
        return $this->repo->supportsFuelDetails();
    }

    public function create(AuthenticatedPrincipal $actor,VehicleInput $input): int
    {
        $this->admin($actor);
        $this->validateProvince($input);
        $this->validateFuel($input);
        return $this->repo->transaction(function()use($actor,$input):int {
            if ($this->repo->registrationExists($input->registrationNumber)) throw new \DomainException('VEHICLE_REGISTRATION_DUPLICATE');
            $id=$this->repo->insert($input,$actor->userId);
            $this->repo->audit($actor->userId,'vehicle.created',$id,[],self::snapshot($input,true));
            return $id;
        });
    }

    public function update(AuthenticatedPrincipal $actor,int $id,VehicleInput $input): void
    {
        $this->admin($actor);
        $this->validateProvince($input);
        $this->validateFuel($input);
        if ($id<1) throw new \InvalidArgumentException('VEHICLE_ID_INVALID');
        $this->repo->transaction(function()use($actor,$id,$input):void {
            $before=$this->repo->find($id,true);
            if ($before===null) throw new \DomainException('VEHICLE_NOT_FOUND');
            if ($this->repo->hasAssignments($id)) throw new \DomainException('VEHICLE_HAS_ASSIGNMENTS');
            if ($this->repo->registrationExists($input->registrationNumber,$id)) throw new \DomainException('VEHICLE_REGISTRATION_DUPLICATE');
            $this->repo->update($id,$input,$actor->userId);
            $this->repo->audit($actor->userId,'vehicle.updated',$id,self::rowSnapshot($before),self::snapshot($input,(bool)$before['is_active']));
        });
    }

    /** Retirement only: preserve every FK and historical document; no DELETE grant. */
    public function delete(AuthenticatedPrincipal $actor,int $id): void
    {
        $this->admin($actor);
        if($id<1)throw new \InvalidArgumentException('VEHICLE_ID_INVALID');
        $this->repo->transaction(function()use($actor,$id):void {
            $before=$this->repo->find($id,true);
            if($before===null)throw new \DomainException('VEHICLE_NOT_FOUND');
            if(!(bool)$before['is_active'])throw new \DomainException('VEHICLE_ALREADY_INACTIVE');
            $hasAssignments=$this->repo->hasAssignments($id);
            $this->repo->deactivate($id,$actor->userId);
            $stored=$this->repo->find($id,true);
            if($stored===null || (bool)$stored['is_active'])throw new \DomainException('VEHICLE_DELETE_NOT_APPLIED');
            $after=self::rowSnapshot($before);$after['is_active']=false;
            $after['deletion_mode']='soft';$after['has_assignments']=$hasAssignments;
            $this->repo->audit($actor->userId,'vehicle.deleted',$id,self::rowSnapshot($before),$after);
        });
    }

    private function admin(AuthenticatedPrincipal $actor): void
    {
        if (!(new AuthorizationPolicy())->allows($actor->roles,'vehicles.admin')) throw new AuthorizationException();
    }
    private function validateProvince(VehicleInput $input): void
    {
        if (!$this->repo->supportsRegistrationProvince() && $input->registrationProvince!==null) throw new \InvalidArgumentException('VEHICLE_PROVINCE_NOT_AVAILABLE');
        if ($this->repo->supportsRegistrationProvince() && $input->registrationProvince===null) throw new \InvalidArgumentException('VEHICLE_PROVINCE_REQUIRED');
    }
    private function validateFuel(VehicleInput $input): void
    {
        if(!$this->repo->supportsFuelDetails() && ($input->fuelType!==null))throw new \InvalidArgumentException('VEHICLE_FUEL_NOT_AVAILABLE');
    }
    private static function snapshot(VehicleInput $input,bool $active): array
    {
        return ['label'=>$input->label,'registration_number'=>$input->registrationNumber,'registration_province'=>$input->registrationProvince,'passenger_capacity'=>$input->passengerCapacity,'fuel_type'=>$input->fuelType,'is_active'=>$active];
    }
    private static function rowSnapshot(array $row): array
    {
        return ['label'=>(string)$row['label'],'registration_number'=>(string)$row['registration_number'],'registration_province'=>isset($row['registration_province'])?(string)$row['registration_province']:null,'passenger_capacity'=>(int)$row['passenger_capacity'],'fuel_type'=>isset($row['fuel_type'])?(string)$row['fuel_type']:null,'is_active'=>(bool)$row['is_active']];
    }
}
