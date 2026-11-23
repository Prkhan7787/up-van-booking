<?php
declare(strict_types=1);
namespace App\Models;

final class AuthenticatedPrincipal
{
    /** @param list<string> $roles */
    public function __construct(
        public readonly int $userId,
        public readonly int $authVersion,
        public readonly array $roles,
    ) {}
}
