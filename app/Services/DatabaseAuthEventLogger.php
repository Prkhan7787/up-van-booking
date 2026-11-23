<?php
declare(strict_types=1);
namespace App\Services;

use App\Contracts\AuthEventLogger;

/** Adapter around the existing audit service; only fixed event codes are accepted. */
final class DatabaseAuthEventLogger implements AuthEventLogger
{
    private const EVENTS = ['login.success', 'login.failure', 'login.rate_limited', 'logout.success', 'session.revoked'];

    public function __construct(private readonly AuditLogger $audit) {}

    public function record(string $event, ?int $actorId, array $metadata = []): void
    {
        if (!in_array($event, self::EVENTS, true) || $metadata !== []) {
            throw new \InvalidArgumentException('Authentication event rejected.');
        }
        $this->audit->record($actorId, 'auth.' . $event, 'auth', $actorId === null ? null : (string)$actorId, []);
    }
}
