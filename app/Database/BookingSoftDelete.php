<?php
declare(strict_types=1);
namespace App\Database;

use App\Core\Env;
use PDO;

final class BookingSoftDelete
{
    public const OFFICER_QUEUE_REASON = 'officer_queue_removal';
    public const READ_GRANTS = ['tables'=>['booking_requests'=>['SELECT'=>['deleted_at']]]];
    public const WRITE_GRANTS = ['tables'=>[
        'booking_requests'=>['UPDATE'=>['deleted_at','deleted_by','deletion_reason','updated_by','version']],
        'vehicle_assignments'=>['SELECT'=>['booking_request_id','status']],
    ]];

    public static function available(PDO $db): bool
    {
        $probe = RuntimeColumnProbe::inspect($db, 'booking_requests', 'deleted_at');
        if ($probe['status'] === 'PRESENT' && $probe['readable'] === true) return true;

        // MySQL can deny a column-scoped account before resolving an unknown column.
        // Once deployed, the persistent schema flag prevents silently exposing tombstones.
        if (!self::expected() && in_array($probe['status'], ['ABSENT', 'NOT_VISIBLE'], true)) {
            return false;
        }
        throw new \RuntimeException('BOOKING_SOFT_DELETE_SCHEMA_UNVERIFIED');
    }

    public static function expected(): bool
    {
        return Env::get('TEST_BOOKING_SOFT_DELETE_SCHEMA_ACTIVE') === 'true';
    }
}
