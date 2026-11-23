<?php
declare(strict_types=1);
namespace App\Contracts;

interface AuthEventLogger
{
    /** Event names and metadata must be allowlisted; never include credentials, identifiers, IPs or tokens. */
    public function record(string $event, ?int $actorId, array $metadata = []): void;
}
