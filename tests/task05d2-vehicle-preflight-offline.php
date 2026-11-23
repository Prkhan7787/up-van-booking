<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/cli.php';

$sql=file_get_contents(dirname(__DIR__).'/scripts/task05d2-vehicle-preflight.sql');
if (!is_string($sql)) throw new RuntimeException('READONLY_SQL_MISSING');
$checks=0;
$check=static function(bool $condition,string $label)use(&$checks):void {
    if (!$condition) throw new RuntimeException('Read-only preflight check failed: '.$label);
    $checks++;
};
$commands=preg_replace('/^\s*--.*$/m','',$sql);
$statements=array_filter(array_map('trim',explode(';',$commands)));
foreach ($statements as $statement) $check((bool)preg_match('/^(SELECT|SHOW)\s/i',$statement),'only SELECT or SHOW statement');
$check(!preg_match('/\b(INSERT|UPDATE|DELETE|ALTER|CREATE|DROP|TRUNCATE|GRANT|REVOKE|REPLACE|CALL|USE)\b\s+(?:INTO\s+|TABLE\s+|DATABASE\s+|USER\s+|ON\s+|FROM\s+)?`?up_van_booking_test`?/i',$commands),'no database mutation statement');
$check(!str_contains($commands,'up_van_booking`') && !str_contains($commands,'lost_found'),'no other project schema reference');
foreach (['`up_van_booking_test`.`vehicles`','`up_van_booking_test`.`booking_requests`','`up_van_booking_test`.`vehicle_assignments`','INFORMATION_SCHEMA.COLUMNS','INFORMATION_SCHEMA.STATISTICS','INFORMATION_SCHEMA.KEY_COLUMN_USAGE','schema_migrations','SHOW GRANTS FOR','mysql`.`role_edges'] as $needle) $check(str_contains($commands,$needle),'preflight includes required metadata or count');
$check(str_contains($commands,'GROUP BY registration_number') && str_contains($commands,'GROUP BY registration_number, registration_province'),'both duplicate definitions inspected');
$check(str_contains($commands,'sample_registration_matches') && str_contains($commands,'0xE0B881E0B8822031323334'),'fixture registration collision checked without SQL encoding ambiguity');
$migration=file_get_contents(dirname(__DIR__).'/database/migrations/002_booking_design.sql');
$check(is_string($migration) && str_contains($migration,'CREATE TABLE vehicles (') && str_contains($migration,'COLLATE=utf8mb4_unicode_ci'),'vehicle table collation verified against Migration 002');
$check(str_contains($commands,'registration_number=(CONVERT(0xE0B881E0B8822031323334 USING utf8mb4) COLLATE utf8mb4_unicode_ci)'),'converted sample uses the vehicle column collation without changing stored data');
$check(!preg_match('/registration_number\s*=\s*CONVERT\s*\(/i',$commands),'no uncollated converted string comparison remains');
echo 'PASS: '.$checks.' Task 05D-2 read-only SQL static checks; no database connection.'.PHP_EOL;
