<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap/app.php';
ob_start();

use App\Contracts\AuthEventLogger;
use App\Contracts\AuthRepository;
use App\Contracts\LoginRateLimitStore;
use App\Core\AuthenticationException;
use App\Core\AuthorizationException;
use App\Core\Csrf;
use App\Core\ErrorReporter;
use App\Core\SessionCookiePolicy;
use App\Core\SessionManager;
use App\Middleware\AuthenticationMiddleware;
use App\Middleware\AuthorizationMiddleware;
use App\Models\AuthAccount;
use App\Models\AuthState;
use App\Services\AuthService;
use App\Services\AuthorizationPolicy;
use App\Services\LoginRateLimiter;
use App\Services\PasswordHasher;

function authCheck(bool $condition, string $label): void
{
    if (!$condition) throw new RuntimeException('FAIL: ' . $label);
    echo 'PASS: ' . $label . PHP_EOL;
}

final class MemoryAuthRepository implements AuthRepository
{
    public ?AuthAccount $account = null;
    public ?AuthState $state = null;
    public array $updatedHashes = [];
    public array $loginLookups = [];
    public function findLoginAccount(string $normalizedIdentifier): ?AuthAccount { $this->loginLookups[] = $normalizedIdentifier; return $this->account; }
    public function loadAuthState(int $userId): ?AuthState { return $this->state; }
    public function replacePasswordHash(int $userId, string $passwordHash): void { $this->updatedHashes[$userId] = $passwordHash; }
}

final class MemoryRateLimitStore implements LoginRateLimitStore
{
    public array $buckets = [];
    public function attempt(array $buckets, int $now, int $windowSeconds, callable $authenticate): App\Contracts\LoginRateLimitResult
    {
        $states = [];
        foreach ($buckets as $bucket) {
            $key = $bucket['scope'] . ':' . bin2hex($bucket['digest']);
            $state = $this->buckets[$key] ?? ['started_at'=>$now,'attempts'=>0];
            if ($now < $state['started_at'] || $now - $state['started_at'] >= $windowSeconds) $state = ['started_at'=>$now,'attempts'=>0];
            if ($state['attempts'] >= $bucket['limit']) return App\Contracts\LoginRateLimitResult::limited();
            $states[] = [$key,$state];
        }
        $authenticated = $authenticate();
        foreach ($states as [$key,$state]) {
            if (!$authenticated) $state['attempts']++;
            $this->buckets[$key] = $state;
        }
        return App\Contracts\LoginRateLimitResult::completed($authenticated);
    }
}

final class MemoryAuthEvents implements AuthEventLogger
{
    public array $events = [];
    public function record(string $event, ?int $actorId, array $metadata = []): void
    {
        if ($metadata !== []) throw new RuntimeException('Unsafe auth metadata in test double.');
        $this->events[] = [$event, $actorId, $metadata];
    }
}

function expectAuthorizationDenied(callable $operation): bool
{
    try { $operation(); } catch (AuthorizationException) { return true; }
    return false;
}

$hasher = new PasswordHasher();
authCheck(LoginRateLimiter::WINDOW_SECONDS === 900 && LoginRateLimiter::USERNAME_LIMIT === 5 && LoginRateLimiter::SOURCE_LIMIT === 20, 'Rate-limit defaults match 5 per username and 20 per IP in 15 minutes');
authCheck(App\Services\UsernamePolicy::isValid('Request.User-1') && App\Services\UsernamePolicy::normalize(' Request.User-1 ') === 'request.user-1', 'Username validation and case-insensitive normalization match policy');
authCheck(!App\Services\UsernamePolicy::isValid('ab') && !App\Services\UsernamePolicy::isValid('user name') && !App\Services\UsernamePolicy::isValid(str_repeat('a', 51)), 'Username rejects invalid length and characters');
authCheck(App\Services\PasswordPolicy::isValidForSetting('123456') && !App\Services\PasswordPolicy::isValidForSetting('12345'), 'Password setting requires at least six characters');
$shortPasswordRejected = false;
try { (new PasswordHasher())->hash('12345'); } catch (InvalidArgumentException) { $shortPasswordRejected = true; }
authCheck($shortPasswordRejected, 'PasswordHasher refuses newly set passwords shorter than six characters');
$hash = $hasher->hash('local-test-passphrase');
authCheck(password_get_info($hash)['algoName'] === 'argon2id', 'PasswordHasher creates Argon2id hashes');
authCheck($hasher->verify('local-test-passphrase', $hash), 'PasswordHasher verifies correct password');
authCheck(!$hasher->verify('wrong-passphrase', $hash), 'PasswordHasher rejects incorrect password');
$account = new AuthAccount(91, true, $hash);
authCheck(!str_contains(json_encode($account, JSON_THROW_ON_ERROR), $hash), 'Account serialization does not expose password hash');
$serializeRejected = false;
try { serialize($account); } catch (LogicException) { $serializeRejected = true; }
authCheck($serializeRejected, 'Authentication account cannot be serialized with its password hash');

$httpCookie = SessionCookiePolicy::params(['HTTPS' => 'off']);
$httpsCookie = SessionCookiePolicy::params(['HTTPS' => 'on']);
authCheck($httpCookie['httponly'] && $httpCookie['samesite'] === 'Lax' && !$httpCookie['secure'], 'Session cookie is HttpOnly, SameSite Lax on HTTP');
authCheck($httpsCookie['secure'] && $httpsCookie['httponly'], 'Session cookie Secure is enabled on HTTPS');
authCheck(SessionCookiePolicy::params([], true)['secure'], 'Session Secure override is honored');

$firstToken = Csrf::token();
$rotatedToken = Csrf::rotate();
authCheck($firstToken !== $rotatedToken && !Csrf::valid($firstToken) && Csrf::valid($rotatedToken), 'CSRF token rotation invalidates prior token');
authCheck(!Csrf::valid(null) && !Csrf::valid('invalid'), 'CSRF rejects missing and incorrect tokens');

$repository = new MemoryAuthRepository();
$repository->account = $account;
$repository->state = new AuthState(91, true, 4, ['requester']);
$store = new MemoryRateLimitStore();
$limiter = new LoginRateLimiter($store, str_repeat('h', 40));
$events = new MemoryAuthEvents();
$sessions = new SessionManager();
$service = new AuthService($repository, $limiter, $hasher, $sessions, $events);
$emailRejected = false;
try { $service->login('contact@example.test', 'local-test-passphrase', '127.0.0.1'); }
catch (AuthenticationException $error) { $emailRejected = $error->getMessage() === 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง'; }
authCheck($emailRejected && $sessions->claims() === null, 'Email with a correct password cannot authenticate and receives the generic username/password error');
authCheck($repository->loginLookups === [], 'Email identifiers are rejected before account lookup, even when the repository would return a valid account');
$_SESSION['pre_auth_marker'] = 'discard-on-login';
$oldSessionId = session_id();
$principal = $service->login(' Request.User-1 ', 'local-test-passphrase', '127.0.0.1');
authCheck($repository->loginLookups === ['request.user-1'], 'Valid username authenticates using normalized username only');
authCheck($principal->userId === 91 && session_id() !== $oldSessionId, 'Successful login regenerates session ID');
authCheck(!isset($_SESSION['pre_auth_marker']) && isset($_SESSION['csrf_token']), 'Login clears prior session state and rotates CSRF');
authCheck(!isset($_SESSION['auth']['roles']) && $_SESSION['auth']['auth_version'] === 4, 'Session stores minimal claims and no cached roles');
authCheck($events->events[array_key_last($events->events)][0] === 'login.success', 'Successful login records a safe event');

$authMiddleware = new AuthenticationMiddleware($repository, $sessions, $events);
$livePrincipal = $authMiddleware->principal();
authCheck($livePrincipal?->roles === ['requester'], 'Authenticated request loads current roles from repository');
$repository->state = new AuthState(91, true, 4, ['approver']);
authCheck($authMiddleware->principal()?->roles === ['approver'], 'Role changes take effect on the next request');
$repository->state = new AuthState(91, true, 5, ['approver']);
authCheck($authMiddleware->principal() === null && !isset($_SESSION['auth']), 'Changed auth_version revokes old session');
$sessions->establish(91, 5);
$repository->state = new AuthState(91, false, 5, ['approver']);
authCheck($authMiddleware->principal() === null && !isset($_SESSION['auth']), 'Suspended account is revoked on the next request');
$sessions->establish(91, 4, 1000);
authCheck($sessions->claims(2801) === null && !isset($_SESSION['auth']), 'Idle timeout expires the session');
$sessions->establish(91, 4, 1000);
authCheck($sessions->claims(29801) === null && !isset($_SESSION['auth']), 'Absolute timeout expires the session');
$sessions->establish(91, 4, 1000);
authCheck($sessions->claims(2800) === null, 'Idle timeout expires exactly at 30 minutes');
$sessions->establish(91, 4, 1000);
authCheck($sessions->claims(29800) === null, 'Absolute timeout expires exactly at 8 hours');

$policy = new AuthorizationPolicy();
$authorization = new AuthorizationMiddleware($policy);
authCheck($policy->allows(['admin'], 'booking.decision'), 'Admin has explicit decision permission');
authCheck(!$policy->allows(['requester'], 'users.manage'), 'Requester cannot manage users');
authCheck(!$policy->allows(['unknown_role'], 'users.manage') && !$policy->allows(['admin'], 'unknown.permission'), 'Unknown roles and permissions deny by default');
authCheck(expectAuthorizationDenied(fn() => $authorization->requirePermission(null, 'users.manage')), 'Unauthenticated access is denied');
authCheck(expectAuthorizationDenied(fn() => $authorization->requirePermission(new App\Models\AuthenticatedPrincipal(91, 4, ['requester']), 'users.manage')), 'Unauthorized role is denied');
authCheck((function () use ($authorization): bool { try { $authorization->requireOwner(new App\Models\AuthenticatedPrincipal(91,4,['requester']),91); return true; } catch (AuthorizationException) { return false; } })(), 'Owner can pass resource ownership guard');
authCheck(expectAuthorizationDenied(fn() => $authorization->requireOwner(new App\Models\AuthenticatedPrincipal(91,4,['requester']),92)), 'Another user resource is denied by ownership guard');

$badStore = new MemoryRateLimitStore();
$badLimiter = new LoginRateLimiter($badStore, str_repeat('s', 40), 900, 2, 3);
$badRepository = new MemoryAuthRepository();
$badRepository->account = $account;
$badRepository->state = new AuthState(91, true, 4, ['requester']);
$badEvents = new MemoryAuthEvents();
$badService = new AuthService($badRepository, $badLimiter, $hasher, $sessions, $badEvents);
$message = '';
for ($attempt = 0; $attempt < 2; $attempt++) {
    try { $badService->login('Sensitive.User@example.test', 'wrong-secret', '192.0.2.10'); }
    catch (AuthenticationException $error) { $message = $error->getMessage(); }
}
authCheck(!str_contains($message, 'Sensitive.User') && !str_contains($message, 'wrong-secret'), 'Login failure response contains no submitted credential');
authCheck(count($badStore->buckets) === 2, 'Rate limiting creates identifier and source buckets together');
authCheck(count(array_filter(array_keys($badStore->buckets), fn(string $key): bool => str_contains($key, 'Sensitive') || str_contains($key, '192.0.2.10'))) === 0, 'Rate-limit store keys are HMAC digests, not raw identifiers or IPs');
try { $badService->login('Sensitive.User@example.test', 'wrong-secret', '192.0.2.10'); }
catch (AuthenticationException) {}
authCheck(($badEvents->events[array_key_last($badEvents->events)][0] ?? '') === 'login.rate_limited', 'Blocked login is rate limited and logged without credentials');
authCheck(!$badLimiter->attempt('other', 'not-an-ip', static fn(): bool => false)->allowed, 'Invalid source address is denied');

$boundaryStore = new MemoryRateLimitStore();
$boundaryLimiter = new LoginRateLimiter($boundaryStore, str_repeat('b', 40));
$accepted = 0;
for ($i = 0; $i < 5; $i++) if ($boundaryLimiter->attempt('Limit.User', '198.51.100.'.($i+1), static fn(): bool => false, 1000 + $i)->allowed) $accepted++;
authCheck($accepted === 5 && !$boundaryLimiter->attempt('limit.user', '198.51.100.20', static fn(): bool => false, 1005)->allowed, 'Username policy accepts five failures and denies the sixth across IPs');
authCheck($boundaryLimiter->attempt('limit.user', '198.51.100.20', static fn(): bool => false, 1900)->allowed, 'Username rate limit resets at exact 15-minute window boundary');
$successOnly = new MemoryRateLimitStore();
$successLimiter = new LoginRateLimiter($successOnly, str_repeat('v', 40));
$success = $successLimiter->attempt('Valid.User', '203.0.113.40', static fn(): bool => true, 2000);
authCheck($success->authenticated && array_sum(array_column($successOnly->buckets, 'attempts')) === 0, 'Successful login does not increment failed-attempt counters');
$ipAccepted = 0;
for ($i = 0; $i < 20; $i++) if ($boundaryLimiter->attempt('person'.$i, '203.0.113.8', static fn(): bool => false, 3000 + $i)->allowed) $ipAccepted++;
authCheck($ipAccepted === 20 && !$boundaryLimiter->attempt('another-person', '203.0.113.8', static fn(): bool => false, 3020)->allowed, 'Source policy accepts twenty failures and denies the twenty-first');
$atomicSource = file_get_contents(dirname(__DIR__) . '/app/Repositories/PdoLoginRateLimitStore.php');
authCheck(is_string($atomicSource) && str_contains($atomicSource, 'beginTransaction()') && str_contains($atomicSource, 'FOR UPDATE') && str_contains($atomicSource, 'INSERT IGNORE') && str_contains($atomicSource, 'rollBack()'), 'PDO rate-limit adapter uses transactional row locking and rollback');
authCheck(is_string($atomicSource) && str_contains($atomicSource, 'usort($buckets') && str_contains($atomicSource, 'bool $countSuccessfulAttempts = false') && str_contains($atomicSource, 'if ($this->countSuccessfulAttempts || !$authenticated)') && !str_contains($atomicSource, '->query('), 'Rate-limit adapter serializes deterministically and defaults to counting failed Login attempts only');

$safeErrorRecord = ErrorReporter::safeRecord(str_repeat('a', 24));
authCheck(!str_contains($safeErrorRecord, 'password') && !str_contains($safeErrorRecord, 'DSN') && str_contains($safeErrorRecord, 'correlation_id'), 'Error record contains only a correlation ID');

$sessions->establish(91, 4);
$service->logout();
authCheck(session_status() === PHP_SESSION_NONE, 'Logout clears and destroys the session');

echo 'PASS: Authentication foundation offline security suite completed.' . PHP_EOL;
ob_end_flush();
