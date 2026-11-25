<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\AuthFeature;
use App\Core\AdminUsersUnavailableException;
use App\Database\ConnectionFactory;
use App\Repositories\PdoAdminUserRepository;
use App\Repositories\PdoMicrosoftIdentityRepository;

final class MicrosoftIdentityMappingFactory
{
    public static function create(): MicrosoftIdentityMappingService
    {
        if (!AuthFeature::testExecutionEnabled()) throw new AdminUsersUnavailableException();
        try {
            $db=ConnectionFactory::connect('auth_test');
            $resolved=\App\Database\GrantVerifier::currentUserGrants($db);
            $revokes=(bool)$db->query('SELECT @@GLOBAL.partial_revokes')->fetchColumn();
            $evaluation=\App\Database\BookingCapabilityGrantGuard::evaluate($resolved['grants'],\App\Database\MicrosoftIdentityGrantPlan::rules(),$revokes,$resolved['roles_resolved'],true);
            if ($evaluation['status']!=='PASS') throw new AdminUsersUnavailableException();
            return new MicrosoftIdentityMappingService(new PdoAdminUserRepository($db),new PdoMicrosoftIdentityRepository($db));
        } catch (\Throwable) { throw new AdminUsersUnavailableException(); }
    }
}
