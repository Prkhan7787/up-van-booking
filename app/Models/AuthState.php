<?php
declare(strict_types=1);
namespace App\Models;

final class AuthState
{
    private const ROLES = ['requester', 'vehicle_officer', 'approver', 'admin'];

    /** @param list<string> $roles */
    public function __construct(
        public readonly int $userId,
        public readonly bool $active,
        public readonly int $authVersion,
        public readonly array $roles,
    ) {
        if ($userId < 1 || $authVersion < 0) throw new \InvalidArgumentException('Invalid authorization state.');
        foreach ($roles as $role) {
            if (!is_string($role) || !in_array($role, self::ROLES, true)) {
                throw new \InvalidArgumentException('Unknown role in authorization state.');
            }
        }
    }
}
