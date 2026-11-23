<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\AuthFeature;
use App\Core\AuthRuntime;
use App\Core\Env;
use App\Core\SessionManager;
use App\Database\ConnectionFactory;
use App\Middleware\AuthenticationMiddleware;
use App\Middleware\AuthorizationMiddleware;
use App\Repositories\PdoAuthRepository;
use App\Repositories\PdoLoginRateLimitStore;

final class AuthServiceFactory
{
    /** Creates an explicitly gated Test DB runtime; this task never invokes it against MySQL. */
    public static function createTestRuntime(bool $driver = false): AuthRuntime
    {
        if (!AuthFeature::testExecutionEnabled()) throw new \RuntimeException('Test authentication execution is not approved.');
        $hmacSecret = Env::get('AUTH_RATE_LIMIT_HMAC_KEY');
        if (strlen($hmacSecret) < 32) throw new \RuntimeException('Authentication rate-limit key is not configured.');

        $db = $driver ? ConnectionFactory::connect('driver_auth_test') : ConnectionFactory::connect('auth_test');
        if ((string)$db->query('SELECT DATABASE()')->fetchColumn() !== 'up_van_booking_test'
            || (string)$db->query('SELECT CURRENT_USER()')->fetchColumn() !== 'up_van_auth_test@127.0.0.1') {
            throw new \RuntimeException('Test authentication database identity mismatch.');
        }
        $repository = new PdoAuthRepository($db);
        $events = new DatabaseAuthEventLogger(new AuditLogger($db));
        $hasher = new PasswordHasher();
        $sessions = new SessionManager();
        $auth = new AuthService($repository, new LoginRateLimiter(new PdoLoginRateLimitStore($db), $hmacSecret), $hasher, $sessions, $events);

        return new AuthRuntime(
            $auth,
            new AuthenticationMiddleware($repository, $sessions, $events),
            new AuthorizationMiddleware(new AuthorizationPolicy()),
        );
    }
}
