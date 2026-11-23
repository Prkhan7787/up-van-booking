<?php
declare(strict_types=1);
namespace App\Contracts;

interface DriverScheduleRepository
{
    public function isLinked(int $userId): bool;
    public function listOwnAssignments(int $userId): array;
    public function findOwnAssignment(int $userId, int $assignmentId): ?array;
    public function listPassengersForOwnAssignment(int $userId, int $assignmentId): array;
}
