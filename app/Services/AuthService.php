<?php
declare(strict_types=1);
namespace App\Services;

use App\Contracts\AuthEventLogger;
use App\Contracts\AuthRepository;
use App\Core\AuthenticationException;
use App\Core\SessionManager;
use App\Models\AuthenticatedPrincipal;
use App\Models\AuthState;

final class AuthService
{
    private readonly string $dummyHash;

    public function __construct(
        private readonly AuthRepository $repository,
        private readonly LoginRateLimiter $rateLimiter,
        private readonly PasswordHasher $hasher,
        private readonly SessionManager $sessions,
        private readonly AuthEventLogger $events,
    ) {
        $this->dummyHash = $hasher->dummyHash();
    }

    public function login(string $identifier, string $password, string $sourceAddress, ?string $requiredRole = null): AuthenticatedPrincipal
    {
        if ($requiredRole !== null && !in_array($requiredRole, ['driver'], true)) throw new \InvalidArgumentException('Unsupported login role constraint.');
        $normalized = UsernamePolicy::normalize($identifier);
        $phone = $requiredRole === null ? PhoneNumberPolicy::normalize($identifier) : null;
        $state = null;
        $result = $this->rateLimiter->attempt($phone ?? $normalized, $sourceAddress, function () use ($identifier, $normalized, $phone, $password, $requiredRole, &$state): bool {
            $account = UsernamePolicy::isValid($identifier) ? $this->repository->findLoginAccount($normalized) : null;
            // An existing username takes priority when it also looks like a formatted phone number.
            if ($account === null && $phone !== null && $phone !== $normalized) {
                $account = $this->repository->findLoginAccount($phone);
            }
            $verified = $account?->verify($password, $this->hasher, $this->dummyHash)
                ?? $this->hasher->verify($password, $this->dummyHash);

            if (!$verified || $account === null || !$account->active) return false;

            $candidate = $this->repository->loadAuthState($account->id);
            if ($candidate === null || !$candidate->active || $candidate->userId !== $account->id) return false;
            if ($requiredRole !== null && $candidate->roles !== [$requiredRole]) return false;

            if ($account->requiresRehash($this->hasher) && PasswordPolicy::isValidForSetting($password)) {
                $this->repository->replacePasswordHash($account->id, $this->hasher->hash($password));
            }
            $state = $candidate;
            return true;
        });

        if (!$result->allowed) {
            $this->events->record('login.rate_limited', null);
            throw AuthenticationException::invalidCredentials();
        }
        if (!$result->authenticated || !$state instanceof AuthState) {
            $this->events->record('login.failure', null);
            throw AuthenticationException::invalidCredentials();
        }

        $this->sessions->establish($state->userId, $state->authVersion);
        try {
            $this->events->record('login.success', $state->userId);
        } catch (\Throwable $error) {
            $this->sessions->invalidateAuthentication();
            throw $error;
        }

        return new AuthenticatedPrincipal($state->userId, $state->authVersion, $state->roles);
    }

    /** Logout remains POST + CSRF at the route boundary; this service always destroys the session. */
    public function logout(): void
    {
        $claims = $this->sessions->claims();
        try {
            if ($claims !== null) $this->events->record('logout.success', $claims['user_id']);
        } finally {
            $this->sessions->destroy();
        }
    }
}
