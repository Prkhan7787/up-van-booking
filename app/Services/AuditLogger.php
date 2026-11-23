<?php
declare(strict_types=1);
namespace App\Services;
use PDO;
final class AuditLogger
{
    public function __construct(private PDO $db) {}
    /** Metadata must be explicitly allowlisted by caller; never include passwords, tokens or credentials. */
    public function record(?int $actorId, string $action, string $entityType, ?string $entityId, array $metadata = []): void
    {
        $query = $this->db->prepare('INSERT INTO audit_logs (user_id, action, entity_type, entity_id, metadata) VALUES (:user_id, :action, :entity_type, :entity_id, :metadata)');
        $query->execute(['user_id' => $actorId, 'action' => $action, 'entity_type' => $entityType, 'entity_id' => $entityId, 'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);
    }
}
