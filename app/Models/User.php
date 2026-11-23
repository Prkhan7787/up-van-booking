<?php
declare(strict_types=1);
namespace App\Models;
use PDO;
final class User
{
    public function __construct(private PDO $db) {}
    public function findById(int $id): ?array
    {
        $query = $this->db->prepare('SELECT id, email, display_name, is_active FROM users WHERE id = :id');
        $query->execute(['id' => $id]);
        return $query->fetch() ?: null;
    }
}
