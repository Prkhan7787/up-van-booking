<?php
declare(strict_types=1);
namespace App\Contracts;

interface DriverRegistrationRepository
{
    /** Must run inside the rate-limit store's transaction and assign exactly the Driver role. */
    public function createDriverAccount(string $phoneUsername, string $realName, string $nickname, string $passwordHash): void;
}
