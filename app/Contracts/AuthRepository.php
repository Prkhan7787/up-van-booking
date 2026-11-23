<?php
declare(strict_types=1);
namespace App\Contracts;

use App\Models\AuthAccount;
use App\Models\AuthState;

/** Persistence contract shared by in-memory offline fixtures and the PDO adapter. */
interface AuthRepository
{
    public function findLoginAccount(string $normalizedIdentifier): ?AuthAccount;

    /** Must read is_active, auth_version and current role names from storage on every request. */
    public function loadAuthState(int $userId): ?AuthState;

    public function replacePasswordHash(int $userId, string $passwordHash): void;
}
