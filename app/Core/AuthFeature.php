<?php
declare(strict_types=1);
namespace App\Core;

final class AuthFeature
{
    /** Test-only switch; no authentication request may touch MySQL while disabled. */
    public static function testExecutionEnabled(): bool
    {
        return Env::get('TEST_AUTH_EXECUTION_APPROVED') === 'true';
    }
}
