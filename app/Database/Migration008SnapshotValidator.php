<?php
declare(strict_types=1);
namespace App\Database;

/** Exact Schema 007 -> 008 contract for the nullable A4 partial-intake snapshot. */
final class Migration008SnapshotValidator
{
    public const VERSION='008_partial_a4_request_snapshot';
    public const COLUMN='a4_request_data';

    public static function assertPreconditions(array $columns): void
    {
        if($columns!==[])throw new SafetyException('MIGRATION_008_COLUMN_ALREADY_PRESENT_OR_SCHEMA_DRIFT');
    }

    public static function assertPostconditions(array $columns): void
    {
        if($columns!==[['a4_request_data','json','YES',null]])throw new SafetyException('MIGRATION_008_COLUMN_POSTCONDITION');
    }
}
