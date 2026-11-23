<?php
declare(strict_types=1);
namespace App\Contracts;

/** The operation must reserve all policy buckets atomically or reserve none. */
interface LoginRateLimitStore
{
    /**
     * Serialize authentication for all supplied buckets. The callback returns true
     * only for valid credentials; failed outcomes alone increment the counters.
     * @param list<array{scope:string,digest:string,limit:int}> $buckets
     */
    public function attempt(array $buckets, int $now, int $windowSeconds, callable $authenticate): LoginRateLimitResult;
}
