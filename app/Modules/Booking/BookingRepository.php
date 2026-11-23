<?php
declare(strict_types=1);
namespace App\Modules\Booking;
interface BookingRepository
{
    /** Must reject nesting and roll back on any throwable, including audit failure. */
    public function transaction(callable $operation): mixed;
    /** Must acquire a row lock inside the active transaction. */
    public function lock(int $id): ?BookingState;
    /** Compare-and-swap WHERE id AND version. Return false unless exactly one row changed. */
    public function save(BookingState $before,BookingState $after): bool;
    /** Append-only status history; use the after version as unique history version. */
    public function appendHistory(int $actorId,BookingState $before,BookingState $after): void;
    /** Audit actor, target, action, before/after status/version and a server timestamp; no form secrets. */
    public function audit(string $event,int $actorId,BookingState $before,BookingState $after): void;
}
