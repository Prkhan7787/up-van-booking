<?php
declare(strict_types=1);
namespace App\Database;

use PDOException;

/** Carries only safe numeric diagnostics; never retains or exposes a driver message. */
final class SafeDatabaseException extends SafetyException
{
    public function __construct(
        public readonly string $sqlState,
        public readonly ?int $nativeCode,
        public readonly bool $duringConnect = false,
    ) {
        parent::__construct('Database connection or verification failed; sensitive details are withheld.');
    }

    public static function fromPdo(PDOException $error, bool $duringConnect = false): self
    {
        $info = $error->errorInfo ?? [];
        $sqlState = isset($info[0]) && is_string($info[0]) ? $info[0] : (string)$error->getCode();
        if (!preg_match('/\A[A-Z0-9]{5}\z/i', $sqlState)) $sqlState = 'UNKNOWN';
        $native = isset($info[1]) && is_numeric($info[1]) ? (int)$info[1] : null;
        return new self($sqlState, $native, $duringConnect);
    }
}
