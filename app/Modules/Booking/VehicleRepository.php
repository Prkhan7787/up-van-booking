<?php
declare(strict_types=1);
namespace App\Modules\Booking;

interface VehicleRepository
{
    public function supportsRegistrationProvince(): bool;
    public function supportsFuelDetails(): bool;
    public function list(int $limit,int $offset): array;
    public function find(int $id,bool $forUpdate=false): ?array;
    public function registrationExists(string $registration,int $exceptId=0): bool;
    public function hasAssignments(int $id): bool;
    public function transaction(callable $operation): mixed;
    public function insert(VehicleInput $input,int $actorId): int;
    public function update(int $id,VehicleInput $input,int $actorId): void;
    public function deactivate(int $id,int $actorId): void;
    public function audit(int $actorId,string $action,int $vehicleId,array $before,array $after): void;
}
