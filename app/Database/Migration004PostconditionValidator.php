<?php
declare(strict_types=1);
namespace App\Database;

/** Pure metadata validation so MySQL INFORMATION_SCHEMA output can be regression-tested offline. */
final class Migration004PostconditionValidator
{
    /** @param list<array<string,mixed>> $columns */
    public static function assertRateLimitColumns(array $columns): void
    {
        $byName=[];
        foreach ($columns as $column) {
            if (!is_array($column)) continue;
            $normalized=[];
            foreach ($column as $key=>$value) $normalized[strtoupper((string)$key)]=$value;
            $columnName=$normalized['COLUMN_NAME']??null;
            if (!is_string($columnName) || trim($columnName)==='') continue;
            $byName[strtolower(trim($columnName))]=$normalized;
        }

        $expected=[
            'bucket_scope'=>['type'=>'varchar(16)','nullable'=>'NO','charset'=>'ascii','collation'=>'ascii_bin','default'=>null],
            'bucket_digest'=>['type'=>'binary(32)','nullable'=>'NO','default'=>null],
            'window_started_at'=>['type'=>'bigint unsigned','nullable'=>'NO','default'=>null],
            'attempt_count'=>['type'=>'smallint unsigned','nullable'=>'NO','default'=>'0'],
            'created_at'=>['type'=>'timestamp','nullable'=>'NO','default'=>'current_timestamp','extra'=>null],
            'updated_at'=>['type'=>'timestamp','nullable'=>'NO','default'=>'current_timestamp','extra'=>'on update current_timestamp'],
        ];

        foreach ($expected as $name=>$definition) {
            $column=$byName[$name]??null;
            if (!is_array($column)) self::fail($name,'COLUMN_NAME');
            if (self::normalizeType($column['COLUMN_TYPE']??null)!==$definition['type']) self::fail($name,'COLUMN_TYPE');
            if (strtoupper((string)($column['IS_NULLABLE']??''))!==$definition['nullable']) self::fail($name,'IS_NULLABLE');
            if (isset($definition['charset']) && self::nullableString($column['CHARACTER_SET_NAME']??null)!==$definition['charset']) self::fail($name,'CHARACTER_SET_NAME');
            if (isset($definition['collation']) && self::nullableString($column['COLLATION_NAME']??null)!==$definition['collation']) self::fail($name,'COLLATION_NAME');
            if (self::normalizeDefault($column['COLUMN_DEFAULT']??null)!==$definition['default']) self::fail($name,'COLUMN_DEFAULT');
            if (array_key_exists('extra',$definition)
                && self::normalizeExtra($column['EXTRA']??null)!==$definition['extra']) self::fail($name,'EXTRA');
        }
    }

    private static function normalizeType(mixed $value): ?string
    {
        if (!is_string($value)) return null;
        return strtolower((string)preg_replace('/\s+/', ' ', trim($value)));
    }

    private static function nullableString(mixed $value): ?string
    {
        return $value===null ? null : (is_string($value) ? strtolower(trim($value)) : (string)$value);
    }

    private static function normalizeDefault(mixed $value): ?string
    {
        if ($value===null) return null;
        $normalized=strtolower(trim((string)$value));
        $normalized=(string)preg_replace('/\s+/', '', $normalized);
        if (preg_match('/^current_timestamp(?:\(\d*\))?$/',$normalized)) return 'current_timestamp';
        return $normalized;
    }

    private static function normalizeExtra(mixed $value): ?string
    {
        if (!is_string($value) || trim($value)==='') return null;
        $normalized=strtolower((string)preg_replace('/\s+/', ' ', trim($value)));
        $normalized=(string)preg_replace('/current_timestamp(?:\(\d*\))?/','current_timestamp',$normalized);
        $normalized=trim((string)preg_replace('/\bdefault_generated\b/','',$normalized));
        return $normalized==='' ? null : $normalized;
    }

    private static function fail(string $column, string $attribute): never
    {
        throw new SafetyException('Migration 004 rate-limit column postcondition failed: '.$column.'.'.$attribute.'.');
    }
}
