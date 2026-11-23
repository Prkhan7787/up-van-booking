<?php
declare(strict_types=1);
namespace App\Repositories;

use App\Contracts\DriverAccountLinkRepository;
use App\Services\AuditLogger;
use PDO;

final class PdoDriverAccountLinkRepository implements DriverAccountLinkRepository
{
    public function __construct(private readonly PDO $db)
    {
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql'
            || $db->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION
            || $db->getAttribute(PDO::ATTR_EMULATE_PREPARES) !== false) {
            throw new \InvalidArgumentException('Driver linking requires native PDO MySQL prepared statements.');
        }
    }

    public function listUnlinkedDrivers(): array
    {
        $query = $this->db->query('SELECT id, staff_code, display_name FROM drivers WHERE is_active = 1 AND user_id IS NULL ORDER BY display_name, id');
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    public function listUnlinkedDriverAccounts(): array
    {
        $query = $this->db->query("SELECT u.id, u.username, u.display_name, u.nickname FROM users u WHERE u.is_active = 1 AND EXISTS (SELECT 1 FROM user_roles ur INNER JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = u.id AND r.name = 'driver') AND (SELECT COUNT(*) FROM user_roles ur WHERE ur.user_id = u.id) = 1 AND NOT EXISTS (SELECT 1 FROM drivers d WHERE d.user_id = u.id) ORDER BY u.display_name, u.id");
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    public function link(int $actorUserId, int $driverId, int $accountUserId, int $actorAuthVersion): void
    {
        if ($this->db->inTransaction()) throw new \LogicException('Nested Driver account linking is rejected.');
        // Same advisory lock and ascending user-row order as Admin account mutations.
        $lock = $this->db->prepare('SELECT GET_LOCK(:name, :seconds)');
        $lock->execute(['name'=>'up_van_booking_test:first_admin_provision','seconds'=>5]);
        if ((int)$lock->fetchColumn() !== 1) throw new \RuntimeException('DRIVER_LINK_LOCK_UNAVAILABLE');
        try {
            $this->db->beginTransaction();
            $ids = array_values(array_unique([$actorUserId, $accountUserId]));
            sort($ids, SORT_NUMERIC);
            $locked = $this->db->prepare('SELECT id FROM users WHERE id IN ('.implode(',', array_fill(0, count($ids), '?')).') ORDER BY id FOR UPDATE');
            $locked->execute($ids);
            $locked->fetchAll(PDO::FETCH_COLUMN);
            $actor = $this->db->prepare('SELECT is_active, auth_version FROM users WHERE id = :id');
            $actor->execute(['id'=>$actorUserId]);
            $current = $actor->fetch(PDO::FETCH_ASSOC);
            $actorRoles = $this->db->prepare('SELECT r.name FROM user_roles ur INNER JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = :id ORDER BY r.name FOR SHARE');
            $actorRoles->execute(['id'=>$actorUserId]);
            if (!is_array($current) || !(bool)$current['is_active'] || (int)$current['auth_version'] !== $actorAuthVersion
                || !array_intersect(['admin','vehicle_officer'], $actorRoles->fetchAll(PDO::FETCH_COLUMN))) {
                throw new \App\Core\AuthorizationException();
            }
            $account = $this->db->prepare('SELECT id, is_active FROM users WHERE id = :id FOR UPDATE');
            $account->execute(['id'=>$accountUserId]);
            $user = $account->fetch(PDO::FETCH_ASSOC);
            if (!is_array($user) || !(bool)$user['is_active']) throw new \DomainException('DRIVER_ACCOUNT_NOT_AVAILABLE');

            $roles = $this->db->prepare('SELECT r.name FROM user_roles ur INNER JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = :id ORDER BY r.name FOR SHARE');
            $roles->execute(['id'=>$accountUserId]);
            if ($roles->fetchAll(PDO::FETCH_COLUMN) !== ['driver']) throw new \DomainException('DRIVER_ACCOUNT_ROLE_MISMATCH');

            $driver = $this->db->prepare('SELECT id FROM drivers WHERE id = :id AND is_active = 1 AND user_id IS NULL FOR UPDATE');
            $driver->execute(['id'=>$driverId]);
            if ($driver->fetchColumn() === false) throw new \DomainException('DRIVER_RECORD_NOT_AVAILABLE');

            $update = $this->db->prepare('UPDATE drivers SET user_id = :user_id WHERE id = :driver_id AND user_id IS NULL AND is_active = 1');
            $update->execute(['user_id'=>$accountUserId,'driver_id'=>$driverId]);
            if ($update->rowCount() !== 1) throw new \DomainException('DRIVER_LINK_NOT_APPLIED');

            (new AuditLogger($this->db))->record($actorUserId, 'driver.account.linked', 'driver', (string)$driverId, ['user_id'=>$accountUserId,'verification'=>'staff_confirmed']);
            $this->db->commit();
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $error;
        } finally {
            try {
                $release = $this->db->prepare('SELECT RELEASE_LOCK(:name)');
                $release->execute(['name'=>'up_van_booking_test:first_admin_provision']);
            } catch (\Throwable) { /* Connection closure releases the named lock. */ }
        }
    }
}
