<?php
declare(strict_types=1);
namespace App\Repositories;

use App\Contracts\DriverRegistrationRepository;
use App\Services\AuditLogger;
use PDO;

final class PdoDriverRegistrationRepository implements DriverRegistrationRepository
{
    public function __construct(private readonly PDO $db)
    {
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql'
            || $db->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION
            || $db->getAttribute(PDO::ATTR_EMULATE_PREPARES) !== false) {
            throw new \InvalidArgumentException('Driver registration requires native PDO MySQL prepared statements.');
        }
    }

    public function createDriverAccount(string $phoneUsername, string $realName, string $nickname, string $passwordHash): void
    {
        // PdoLoginRateLimitStore owns this transaction, so the account, role, audit and rate buckets commit atomically.
        if (!$this->db->inTransaction()) throw new \LogicException('Driver registration requires the rate-limit transaction.');
        $insert = $this->db->prepare('INSERT INTO users (username, email, display_name, nickname, password_hash, is_active, auth_version) VALUES (:username, NULL, :display_name, :nickname, :password_hash, 1, 1)');
        $insert->execute(['username'=>$phoneUsername,'display_name'=>$realName,'nickname'=>$nickname,'password_hash'=>$passwordHash]);
        $userId = (int)$this->db->lastInsertId();
        if ($userId < 1) throw new \RuntimeException('Driver account identity was not created.');

        $role = $this->db->prepare("INSERT INTO user_roles (user_id, role_id) SELECT :user_id, id FROM roles WHERE name = 'driver'");
        $role->execute(['user_id'=>$userId]);
        if ($role->rowCount() !== 1) throw new \RuntimeException('Driver role is unavailable.');

        (new AuditLogger($this->db))->record($userId, 'driver.account.registered', 'user', (string)$userId, ['role'=>'driver']);
    }
}
