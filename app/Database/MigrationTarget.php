<?php
declare(strict_types=1);
namespace App\Database;

final class MigrationTarget
{
    public const PRODUCTION = 'up_van_booking';
    public const TEST = 'up_van_booking_test';

    public static function assertKnown(string $target): void
    {
        if (!in_array($target, [self::PRODUCTION, self::TEST], true)) {
            throw new SafetyException('Migration target rejected.');
        }
    }

    public static function fromApplyFlags(array $flags): string
    {
        return match ($flags) {
            ['--apply', '--confirm=' . self::PRODUCTION] => self::PRODUCTION,
            ['--apply', '--confirm=' . self::TEST] => self::TEST,
            default => throw new SafetyException('Usage: --plan OR --apply --confirm=up_van_booking[_test].'),
        };
    }

    public static function connectionPurpose(string $target): string
    {
        self::assertKnown($target);
        return $target === self::PRODUCTION ? 'migration' : 'migration_test';
    }

    public static function expectedMigrator(string $target): string
    {
        self::assertKnown($target);
        return $target === self::PRODUCTION ? 'up_van_migrator' : 'up_van_test_migrator';
    }
}
