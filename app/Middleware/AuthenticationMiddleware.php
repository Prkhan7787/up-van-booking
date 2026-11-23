<?php
declare(strict_types=1);
namespace App\Middleware;

use App\Contracts\AuthEventLogger;
use App\Contracts\AuthRepository;
use App\Core\AuthenticationRequiredException;
use App\Core\SessionManager;
use App\Models\AuthenticatedPrincipal;

final class AuthenticationMiddleware
{
    public function __construct(
        private readonly AuthRepository $repository,
        private readonly SessionManager $sessions,
        private readonly AuthEventLogger $events,
    ) {}

    public function principal(): ?AuthenticatedPrincipal
    {
        $claims = $this->sessions->claims();
        if ($claims === null) return null;
        $state = $this->repository->loadAuthState($claims['user_id']);
        if ($state === null || !$state->active || $state->authVersion !== $claims['auth_version']) {
            $this->events->record('session.revoked', $claims['user_id']);
            $this->sessions->invalidateAuthentication();
            return null;
        }
        return new AuthenticatedPrincipal($state->userId, $state->authVersion, $state->roles);
    }

    public function requireAuthenticated(): AuthenticatedPrincipal
    {
        return $this->principal() ?? throw new AuthenticationRequiredException();
    }
}
