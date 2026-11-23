<?php
declare(strict_types=1);
namespace App\Repositories;

use App\Contracts\LoginRateLimitResult;
use App\Contracts\LoginRateLimitStore;
use PDO;
use Throwable;

/** MySQL row locks serialize verification and count failed credentials only. */
final class PdoLoginRateLimitStore implements LoginRateLimitStore
{
    public function __construct(private readonly PDO $db)
    {
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql'
            || $db->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION
            || $db->getAttribute(PDO::ATTR_EMULATE_PREPARES) !== false) {
            throw new \InvalidArgumentException('Rate-limit store requires native PDO MySQL prepared statements.');
        }
    }

    /** @param list<array{scope:string,digest:string,limit:int}> $buckets */
    public function attempt(array $buckets, int $now, int $windowSeconds, callable $authenticate): LoginRateLimitResult
    {
        if ($now < 1 || $windowSeconds < 1 || count($buckets) !== 2) return LoginRateLimitResult::limited();
        foreach ($buckets as $bucket) {
            if (!in_array($bucket['scope'] ?? null, ['identifier', 'source'], true)
                || !is_string($bucket['digest'] ?? null) || strlen($bucket['digest']) !== 32
                || !is_int($bucket['limit'] ?? null) || $bucket['limit'] < 1) return LoginRateLimitResult::limited();
        }
        usort($buckets, static fn(array $a, array $b): int => [$a['scope'], $a['digest']] <=> [$b['scope'], $b['digest']]);

        $this->db->beginTransaction();
        try {
            $insert = $this->db->prepare('INSERT IGNORE INTO auth_login_buckets (bucket_scope,bucket_digest,window_started_at,attempt_count) VALUES (:scope,:digest,:started,0)');
            $select = $this->db->prepare('SELECT window_started_at,attempt_count FROM auth_login_buckets WHERE bucket_scope=:scope AND bucket_digest=:digest FOR UPDATE');
            $update = $this->db->prepare('UPDATE auth_login_buckets SET window_started_at=:started,attempt_count=:attempts WHERE bucket_scope=:scope AND bucket_digest=:digest');
            $states = [];
            foreach ($buckets as $bucket) {
                $insert->execute(['scope'=>$bucket['scope'],'digest'=>$bucket['digest'],'started'=>$now]);
                $select->execute(['scope'=>$bucket['scope'],'digest'=>$bucket['digest']]);
                $row = $select->fetch(PDO::FETCH_ASSOC);
                if (!is_array($row)) throw new \RuntimeException('Rate-limit bucket lock failed.');
                $started = (int)$row['window_started_at'];
                $attempts = (int)$row['attempt_count'];
                if ($now < $started || $now - $started >= $windowSeconds) {
                    $started = $now;
                    $attempts = 0;
                }
                $states[] = [$bucket, $started, $attempts];
            }
            foreach ($states as [$bucket, , $attempts]) {
                if ($attempts >= $bucket['limit']) {
                    $this->db->rollBack();
                    return LoginRateLimitResult::limited();
                }
            }

            $authenticated = $authenticate();
            if (!is_bool($authenticated)) throw new \UnexpectedValueException('Authentication callback must return a boolean.');
            if (!$authenticated) {
                foreach ($states as [$bucket, $started, $attempts]) {
                    $update->execute(['started'=>$started,'attempts'=>$attempts + 1,'scope'=>$bucket['scope'],'digest'=>$bucket['digest']]);
                }
            }
            $this->db->commit();
            return LoginRateLimitResult::completed($authenticated);
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $error;
        }
    }
}
