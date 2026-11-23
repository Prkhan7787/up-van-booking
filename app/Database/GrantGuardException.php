<?php
declare(strict_types=1);
namespace App\Database;

/** Safe, structured failure from a reviewed privilege allowlist; never contains raw grant text. */
final class GrantGuardException extends SafetyException
{
    public function __construct(public readonly array $diagnostics,string $profile='001–005')
    {
        parent::__construct(match($profile){'006'=>'MIGRATION_006_GRANT_GUARD_FAILED','007'=>'MIGRATION_007_GRANT_GUARD_FAILED',default=>'Test migration account grants do not match the reviewed Migration 001–005 scope.'});
    }
}
