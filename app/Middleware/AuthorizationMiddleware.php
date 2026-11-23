<?php
declare(strict_types=1);
namespace App\Middleware;

use App\Core\AuthorizationException;
use App\Models\AuthenticatedPrincipal;
use App\Services\AuthorizationPolicy;

final class AuthorizationMiddleware
{
    public function __construct(private readonly AuthorizationPolicy $policy) {}

    public function requirePermission(?AuthenticatedPrincipal $principal, string $permission): void
    {
        if ($principal === null || !$this->policy->allows($principal->roles, $permission)) {
            throw new AuthorizationException();
        }
    }

    /** Resource ownership guard for owner-scoped reads and updates (IDOR defense). */
    public function requireOwner(?AuthenticatedPrincipal $principal, int $ownerUserId): void
    {
        if ($principal === null || $ownerUserId < 1 || $principal->userId !== $ownerUserId) {
            throw new AuthorizationException();
        }
    }
}
