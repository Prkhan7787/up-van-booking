<?php
declare(strict_types=1);
namespace App\Contracts;

interface DriverAccountLinkRepository
{
    public function listUnlinkedDrivers(): array;
    public function listUnlinkedDriverAccounts(): array;
    public function link(int $actorUserId, int $driverId, int $accountUserId, int $actorAuthVersion): void;
}
