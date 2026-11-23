<?php
declare(strict_types=1);
namespace App\Repositories;

use App\Contracts\AuthRepository;
use App\Models\AuthAccount;
use App\Models\AuthState;
use PDO;

/** Local-login persistence adapter. Every statement uses native PDO prepares. */
final class PdoAuthRepository implements AuthRepository
{
    public function __construct(private readonly PDO $db)
    {
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql'
            || $db->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION
            || $db->getAttribute(PDO::ATTR_EMULATE_PREPARES) !== false) {
            throw new \InvalidArgumentException('Auth repository requires native PDO MySQL prepared statements.');
        }
    }

    public function findLoginAccount(string $normalizedIdentifier): ?AuthAccount
    {
        $query = $this->db->prepare('SELECT id, is_active, password_hash FROM users WHERE username = :username LIMIT 1');
        $query->execute(['username'=>$normalizedIdentifier]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) return null;
        return new AuthAccount((int)$row['id'], (bool)$row['is_active'], is_string($row['password_hash']) ? $row['password_hash'] : null);
    }

    public function loadAuthState(int $userId): ?AuthState
    {
        $query = $this->db->prepare('SELECT u.id, u.is_active, u.auth_version, r.name AS role_name FROM users AS u LEFT JOIN user_roles AS ur ON ur.user_id = u.id LEFT JOIN roles AS r ON r.id = ur.role_id WHERE u.id = :user_id ORDER BY r.name');
        $query->execute(['user_id'=>$userId]);
        $rows = $query->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) return null;
        $first = $rows[0];
        $roles = [];
        foreach ($rows as $row) if (is_string($row['role_name'] ?? null)) $roles[] = $row['role_name'];
        return new AuthState((int)$first['id'], (bool)$first['is_active'], (int)$first['auth_version'], array_values(array_unique($roles)));
    }

    public function replacePasswordHash(int $userId, string $passwordHash): void
    {
        $query = $this->db->prepare('UPDATE users SET password_hash = :password_hash WHERE id = :user_id AND is_active = 1');
        $query->execute(['password_hash'=>$passwordHash,'user_id'=>$userId]);
        if ($query->rowCount() !== 1) throw new \RuntimeException('Password hash upgrade did not update exactly one account.');
    }
}
