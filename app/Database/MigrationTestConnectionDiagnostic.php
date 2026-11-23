<?php
declare(strict_types=1);
namespace App\Database;

/** Safe stage/error classification shared by the Windows read-only diagnostic CLI and offline tests. */
final class MigrationTestConnectionDiagnostic
{
    public static function configFailureCode(string $safeMessage): string
    {
        return match ($safeMessage) {
            'Database target rejected.' => 'CONFIG_TARGET_MISMATCH',
            'MySQL account does not match connection purpose.' => 'CONFIG_ACCOUNT_MISMATCH',
            'MySQL account host must match 127.0.0.1.' => 'CONFIG_HOST_MISMATCH',
            'Invalid database port.' => 'CONFIG_PORT_INVALID',
            'Dedicated project credentials are not configured.' => 'CONFIG_PASSWORD_MISSING',
            'PDO MySQL driver is unavailable.' => 'CONFIG_DRIVER_MISSING',
            default => 'CONFIG_REJECTED',
        };
    }

    public static function pdoFailureCode(string $sqlState, ?int $nativeCode): string
    {
        if ($nativeCode === 1045 && in_array(strtoupper($sqlState), ['28000', 'HY000'], true)) return 'AUTHENTICATION_FAILED';
        return 'PDO_CONNECT_FAILED';
    }

    public static function identityFailureCode(string $database, string $currentUser): ?string
    {
        if ($database !== MigrationTarget::TEST) return 'DATABASE_IDENTITY_MISMATCH';
        if ($currentUser !== 'up_van_test_migrator@127.0.0.1') return 'HOST_ACCOUNT_MISMATCH';
        return null;
    }

    public static function identityMessageCode(string $safeMessage): string
    {
        return match ($safeMessage) {
            'Authenticated MySQL account does not match connection purpose.' => 'HOST_ACCOUNT_MISMATCH',
            'Connected database target mismatch.' => 'DATABASE_IDENTITY_MISMATCH',
            default => 'IDENTITY_CHECK_FAILED',
        };
    }

    public static function grantFailureCode(string $safeMessage): string
    {
        return $safeMessage === 'Test migration account grants do not match the reviewed Migration 001–005 scope.'
            ? 'GRANT_GUARD_FAILED'
            : 'GRANT_CHECK_FAILED';
    }
}
