<?php
declare(strict_types=1);
namespace App\Repositories;

use App\Contracts\MicrosoftIdentityRepository;
use PDO;

final class PdoMicrosoftIdentityRepository implements MicrosoftIdentityRepository
{
    public function __construct(private readonly PDO $db)
    {
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql' || $db->getAttribute(PDO::ATTR_EMULATE_PREPARES)!==false
            || $db->getAttribute(PDO::ATTR_ERRMODE)!==PDO::ERRMODE_EXCEPTION) throw new \RuntimeException('MICROSOFT_MAPPING_PDO_REQUIRED');
        // An absent table or insufficient grants fails closed before any mutation.
        $db->query('SELECT user_id,tenant_id,object_id FROM microsoft_identities WHERE 1=0');
    }
    public function forUser(int $userId): ?array
    {
        $q=$this->db->prepare('SELECT user_id,tenant_id,object_id FROM microsoft_identities WHERE user_id=:id');$q->execute(['id'=>$userId]);
        $row=$q->fetch(PDO::FETCH_ASSOC);return is_array($row)?$row:null;
    }
    public function forIdentity(string $tenantId,string $objectId): ?array
    {
        $q=$this->db->prepare('SELECT user_id,tenant_id,object_id FROM microsoft_identities WHERE tenant_id=:tid AND object_id=:oid');$q->execute(['tid'=>$tenantId,'oid'=>$objectId]);
        $row=$q->fetch(PDO::FETCH_ASSOC);return is_array($row)?$row:null;
    }
    public function insert(int $userId,string $tenantId,string $objectId,int $actorId): void
    {
        if (!$this->db->inTransaction()) throw new \LogicException('MICROSOFT_MAPPING_TRANSACTION_REQUIRED');
        $q=$this->db->prepare('INSERT INTO microsoft_identities (user_id,tenant_id,object_id,created_by) VALUES (:id,:tid,:oid,:actor)');
        $q->execute(['id'=>$userId,'tid'=>$tenantId,'oid'=>$objectId,'actor'=>$actorId]);
    }
    public function remove(int $userId,string $tenantId,string $objectId): void
    {
        if (!$this->db->inTransaction()) throw new \LogicException('MICROSOFT_MAPPING_TRANSACTION_REQUIRED');
        $q=$this->db->prepare('DELETE FROM microsoft_identities WHERE user_id=:id AND tenant_id=:tid AND object_id=:oid');$q->execute(['id'=>$userId,'tid'=>$tenantId,'oid'=>$objectId]);
        if ($q->rowCount()!==1) throw new \RuntimeException('MICROSOFT_MAPPING_CHANGED');
    }
}
