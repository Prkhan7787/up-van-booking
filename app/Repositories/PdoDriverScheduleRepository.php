<?php
declare(strict_types=1);
namespace App\Repositories;

use App\Contracts\DriverScheduleRepository;
use PDO;

final class PdoDriverScheduleRepository implements DriverScheduleRepository
{
    public function __construct(private readonly PDO $db)
    {
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql'
            || $db->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION
            || $db->getAttribute(PDO::ATTR_EMULATE_PREPARES) !== false) {
            throw new \InvalidArgumentException('Driver schedule requires native PDO MySQL prepared statements.');
        }
    }

    public function isLinked(int $userId): bool
    {
        $query = $this->db->prepare('SELECT id FROM drivers WHERE user_id = :user_id AND is_active = 1 LIMIT 1');
        $query->execute(['user_id'=>$userId]);
        return $query->fetchColumn() !== false;
    }

    public function listOwnAssignments(int $userId): array
    {
        $query = $this->db->prepare("SELECT a.id AS assignment_id, a.starts_at, a.ends_at, a.status AS assignment_status, v.label AS vehicle_label, b.pickup_location, b.dropoff_location FROM drivers d INNER JOIN vehicle_assignments a ON a.driver_id = d.id INNER JOIN booking_requests b ON b.id = a.booking_request_id INNER JOIN vehicles v ON v.id = a.vehicle_id WHERE d.user_id = :user_id AND d.is_active = 1 AND a.status IN ('active','completed') AND b.workflow_status IN ('assigned','completed') ORDER BY a.starts_at DESC, a.id DESC");
        $query->execute(['user_id'=>$userId]);
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findOwnAssignment(int $userId, int $assignmentId): ?array
    {
        $query = $this->db->prepare("SELECT a.id AS assignment_id, a.booking_request_id, a.starts_at, a.ends_at, a.status AS assignment_status, v.label AS vehicle_label, v.registration_number, b.pickup_location, b.dropoff_location FROM drivers d INNER JOIN vehicle_assignments a ON a.driver_id = d.id INNER JOIN booking_requests b ON b.id = a.booking_request_id INNER JOIN vehicles v ON v.id = a.vehicle_id WHERE d.user_id = :user_id AND d.is_active = 1 AND a.id = :assignment_id AND a.status IN ('active','completed') AND b.workflow_status IN ('assigned','completed') LIMIT 1");
        $query->execute(['user_id'=>$userId,'assignment_id'=>$assignmentId]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public function listPassengersForOwnAssignment(int $userId, int $assignmentId): array
    {
        $query = $this->db->prepare("SELECT p.display_name, p.affiliation FROM drivers d INNER JOIN vehicle_assignments a ON a.driver_id = d.id INNER JOIN booking_requests b ON b.id = a.booking_request_id INNER JOIN booking_passengers p ON p.booking_request_id = b.id WHERE d.user_id = :user_id AND d.is_active = 1 AND a.id = :assignment_id AND a.status IN ('active','completed') AND b.workflow_status IN ('assigned','completed') ORDER BY p.id");
        $query->execute(['user_id'=>$userId,'assignment_id'=>$assignmentId]);
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }
}
