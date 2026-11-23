<?php
declare(strict_types=1);
namespace App\Modules\Booking;

/** Persisted identity and workflow state only; no client roles or proposed resource assignment. */
final readonly class BookingState
{
    public const STATUSES=['draft','submitted','under_review','pending_approval','awaiting_assignment','awaiting_rejection_notice','assigned','completed','closed_rejected','cancelled'];
    public function __construct(public int $id,public int $ownerId,public string $status,public int $version)
    {
        if ($id<1 || $ownerId<1 || $version<1 || $version>4294967295 || !in_array($status,self::STATUSES,true)) throw new \InvalidArgumentException('Invalid booking state.');
    }
    public function next(string $status): self {return new self($this->id,$this->ownerId,$status,$this->version+1);}
}
