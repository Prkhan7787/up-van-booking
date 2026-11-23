<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\AuthFeature;
use App\Core\Env;
use App\Database\ConnectionFactory;
use App\Repositories\PdoDriverAccountLinkRepository;
use App\Repositories\PdoDriverRegistrationRepository;
use App\Repositories\PdoDriverScheduleRepository;
use App\Repositories\PdoLoginRateLimitStore;

final class DriverFeatureFactory
{
    public static function registration(): DriverRegistrationService
    {
        $db = self::connect();
        $secret = Env::get('AUTH_RATE_LIMIT_HMAC_KEY');
        if (strlen($secret) < 32) throw new \RuntimeException('Authentication rate-limit key is not configured.');
        return new DriverRegistrationService(new PdoDriverRegistrationRepository($db), new PdoLoginRateLimitStore($db, countSuccessfulAttempts: true), $secret, new PasswordHasher());
    }

    public static function schedule(): DriverScheduleService
    {
        return new DriverScheduleService(new PdoDriverScheduleRepository(self::connect()));
    }

    public static function accountLinks(): DriverAccountLinkService
    {
        return new DriverAccountLinkService(new PdoDriverAccountLinkRepository(self::connect()));
    }

    private static function connect(): \PDO
    {
        if (!AuthFeature::testExecutionEnabled()) throw new \RuntimeException('Test authentication execution is not approved.');
        return ConnectionFactory::connect('driver_auth_test');
    }
}
