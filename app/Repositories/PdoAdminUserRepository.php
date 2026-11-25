<?php
declare(strict_types=1);
namespace App\Repositories;

use App\Contracts\AdminUserRepository;
use App\Core\AdminUsersUnavailableException;
use App\Database\AdminUserGrantPlan;
use App\Database\GrantVerifier;
use App\Services\AuditLogger;
use PDO;

final class PdoAdminUserRepository implements AdminUserRepository
{
    private const FIELDS = 'id, username, email, display_name, is_active, auth_version, created_at';
    public readonly bool $driverCreationEnabled;

    public function __construct(private readonly PDO $db)
    {
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql' || $db->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION || $db->getAttribute(PDO::ATTR_EMULATE_PREPARES) !== false) {
            throw new AdminUsersUnavailableException();
        }
        $identity = $db->query('SELECT DATABASE() AS db_name, CURRENT_USER() AS account_name, @@GLOBAL.partial_revokes AS partial_revokes')->fetch(PDO::FETCH_ASSOC);
        if (($identity['db_name'] ?? null) !== 'up_van_booking_test' || ($identity['account_name'] ?? null) !== 'up_van_auth_test@127.0.0.1') throw new AdminUsersUnavailableException();
        $resolved = GrantVerifier::currentUserGrants($db);
        $evaluation=\App\Database\BookingCapabilityGrantGuard::evaluate($resolved['grants'],AdminUserGrantPlan::rules(),(bool)$identity['partial_revokes'],$resolved['roles_resolved'],true);
        if ($evaluation['status'] !== 'PASS') throw new AdminUsersUnavailableException();
        // The whole account already matched a reviewed union above.
        $driverGrant = GrantVerifier::evaluate($resolved['grants'], 'up_van_booking_test', AdminUserGrantPlan::driverCreationRules(), (bool)$identity['partial_revokes'], $resolved['roles_resolved']);
        $this->driverCreationEnabled = $driverGrant['missing_privileges'] === [];
    }

    private function roles(int $id): array
    {
        $q = $this->db->prepare('SELECT r.name FROM user_roles ur INNER JOIN roles r ON r.id=ur.role_id WHERE ur.user_id=:id ORDER BY r.name');
        $q->execute(['id'=>$id]);
        return $q->fetchAll(PDO::FETCH_COLUMN);
    }

    public function listUsers(string $search, string $role, int $page): array
    {
        $conditions = []; $params = [];
        if ($search !== '') {
            $conditions[] = "(u.username LIKE :username ESCAPE '!' OR u.email LIKE :email ESCAPE '!' OR u.display_name LIKE :display_name ESCAPE '!')";
            $pattern = '%'.strtr($search, ['!'=>'!!','%'=>'!%','_'=>'!_']).'%';
            $params = ['username'=>$pattern,'email'=>$pattern,'display_name'=>$pattern];
        }
        if ($role !== '') {
            $conditions[] = 'EXISTS (SELECT 1 FROM user_roles ur INNER JOIN roles r ON r.id=ur.role_id WHERE ur.user_id=u.id AND r.name=:role)';
            $params['role']=$role;
        }
        $q = $this->db->prepare('SELECT '.implode(', ',array_map(static fn(string $s): string=>'u.'.$s, explode(', ',self::FIELDS))).' FROM users u'.($conditions ? ' WHERE '.implode(' AND ',$conditions) : '').' ORDER BY u.id LIMIT :limit OFFSET :offset');
        foreach ($params as $key=>$value) $q->bindValue(':'.$key,$value,PDO::PARAM_STR);
        $q->bindValue(':limit',51,PDO::PARAM_INT); $q->bindValue(':offset',($page-1)*50,PDO::PARAM_INT); $q->execute();
        $rows=$q->fetchAll(PDO::FETCH_ASSOC); $hasNext=count($rows)>50; $rows=array_slice($rows,0,50);
        foreach ($rows as &$row) $row['roles']=$this->roles((int)$row['id']);
        return ['rows'=>$rows,'has_next'=>$hasNext,'page'=>$page];
    }

    public function findUser(int $id): ?array
    {
        $q=$this->db->prepare('SELECT '.self::FIELDS.' FROM users WHERE id=:id'); $q->execute(['id'=>$id]);
        $row=$q->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) return null;
        $row['roles']=$this->roles($id); return $row;
    }

    public function transaction(callable $operation): mixed
    {
        if ($this->db->inTransaction()) throw new \RuntimeException('Nested account transaction rejected.');
        $q=$this->db->prepare('SELECT GET_LOCK(:name, :seconds)');
        $q->execute(['name'=>'up_van_booking_test:first_admin_provision','seconds'=>5]);
        if ((int)$q->fetchColumn()!==1) throw new AdminUsersUnavailableException();
        try {
            $this->db->beginTransaction(); $result=$operation(); $this->db->commit(); return $result;
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $error;
        } finally {
            try { $q=$this->db->prepare('SELECT RELEASE_LOCK(:name)'); $q->execute(['name'=>'up_van_booking_test:first_admin_provision']); }
            catch (\Throwable) { /* Connection closure releases the advisory lock. */ }
        }
    }

    public function lockUsers(array $ids): void
    {
        $ids=array_values(array_unique($ids)); sort($ids,SORT_NUMERIC);
        $q=$this->db->prepare('SELECT id FROM users WHERE id IN ('.implode(',',array_fill(0,count($ids),'?')).') ORDER BY id FOR UPDATE');
        $q->execute($ids); $q->fetchAll(PDO::FETCH_COLUMN);
    }

    public function activeAdminCount(): int
    {
        $q=$this->db->prepare("SELECT COUNT(DISTINCT u.id) FROM users u INNER JOIN user_roles ur ON ur.user_id=u.id INNER JOIN roles r ON r.id=ur.role_id WHERE u.is_active=1 AND r.name='admin'");
        $q->execute(); return (int)$q->fetchColumn();
    }

    public function createUser(string $username, ?string $email, string $displayName, string $hash, ?string $nickname = null): int
    {
        $params = ['username'=>$username,'email'=>$email,'display_name'=>$displayName,'hash'=>$hash];
        if ($nickname === null) {
            $q=$this->db->prepare('INSERT INTO users (username,email,display_name,password_hash,is_active) VALUES (:username,:email,:display_name,:hash,1)');
        } else {
            if (!$this->driverCreationEnabled) throw new AdminUsersUnavailableException();
            $params['nickname'] = $nickname;
            $q=$this->db->prepare('INSERT INTO users (username,email,display_name,password_hash,is_active,nickname) VALUES (:username,:email,:display_name,:hash,1,:nickname)');
        }
        $q->execute($params);
        return (int)$this->db->lastInsertId();
    }

    public function setRoles(int $id, array $roles): void
    {
        $q=$this->db->prepare('DELETE FROM user_roles WHERE user_id=:id'); $q->execute(['id'=>$id]);
        foreach ($roles as $role) {
            $q=$this->db->prepare('INSERT INTO user_roles (user_id,role_id) SELECT :id,id FROM roles WHERE name=:role');
            $q->execute(['id'=>$id,'role'=>$role]);
            if ($q->rowCount()!==1) throw new \RuntimeException('Role reference is unavailable.');
        }
    }

    public function setStatus(int $id, bool $active): void
    {
        $q=$this->db->prepare('UPDATE users SET is_active=:active WHERE id=:id'); $q->execute(['active'=>(int)$active,'id'=>$id]);
    }

    public function bumpAuthVersion(int $id): void
    {
        $q=$this->db->prepare('UPDATE users SET auth_version=auth_version+1 WHERE id=:id'); $q->execute(['id'=>$id]);
        if ($q->rowCount()!==1) throw new \RuntimeException('Account version update failed.');
    }
    public function setPasswordHash(int $id, string $hash): void
    {
        $q=$this->db->prepare('UPDATE users SET password_hash=:hash WHERE id=:id');
        $q->execute(['hash'=>$hash,'id'=>$id]);
        if ($q->rowCount()!==1) throw new \RuntimeException('Account password update failed.');
    }

    public function audit(int $actor, string $action, int $target, array $metadata): void
    {
        (new AuditLogger($this->db))->record($actor,$action,'user',(string)$target,$metadata);
    }
}
