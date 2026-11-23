<?php
declare(strict_types=1);
namespace App\Contracts;

final class LoginRateLimitResult
{
    private function __construct(public readonly bool $allowed, public readonly bool $authenticated) {}

    public static function limited(): self { return new self(false, false); }
    public static function completed(bool $authenticated): self { return new self(true, $authenticated); }
}
