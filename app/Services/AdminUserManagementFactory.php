<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\AuthFeature;
use App\Core\AdminUsersUnavailableException;
use App\Database\ConnectionFactory;
use App\Repositories\PdoAdminUserRepository;

final class AdminUserManagementFactory
{
    public static function createTestService(): AdminUserManagementService
    {
        if (!AuthFeature::testExecutionEnabled()) throw new AdminUsersUnavailableException();
        // Repository verifies test identity and the complete reviewed Admin grant
        // plan before reading account data or executing any write.
        $repository = new PdoAdminUserRepository(ConnectionFactory::connect('auth_test'));
        return new AdminUserManagementService($repository,new PasswordHasher(),$repository->driverCreationEnabled);
    }
}
