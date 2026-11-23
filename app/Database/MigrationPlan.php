<?php
declare(strict_types=1);
namespace App\Database;

final class MigrationPlan
{
    public const VEHICLE006=['version'=>'006_vehicle_province_and_requested_vehicle','file'=>'database/drafts/006_vehicle_province_and_requested_vehicle.sql','kind'=>'forward_test','sha256'=>'61c0f5b4fe152c83142d49ba81070a70df2dc352a9073302809cee9696827748','objects'=>[]];
    public const VEHICLE007=['version'=>'007_vehicle_fuel_details','file'=>'database/drafts/007_vehicle_fuel_details.sql','kind'=>'forward_test','sha256'=>'0622c9006ae5be29c583082618e3ec2ef130bd44f39d8373038634d8cc6b03e8','objects'=>[]];
    public const BOOKING008=['version'=>'008_partial_a4_request_snapshot','file'=>'database/drafts/008_partial_a4_request_snapshot.sql','kind'=>'forward_test','sha256'=>'33b6ecc03325835b1908271fa9086afdc63a4a488b18824758f12b15b5015233','objects'=>[]];
    public const BOOKING009=['version'=>'009_booking_soft_delete','file'=>'database/proposals/009_booking_soft_delete.sql','kind'=>'forward_test','sha256'=>'6320a509931878c13aac4fd07836a852d8759ac0a3df8f61ea2ae0cdedfc501d','objects'=>[]];
    private const PINNED = [
        '001_identity' => ['database/migrations/001_identity.sql','a8afc40e4e422b640caf40289dc92734f71f2bcb26f3141ccd8f7b81257464d8',4,'ddl',['roles','users','user_roles','audit_logs']],
        '002_booking_design' => ['database/migrations/002_booking_design.sql','298e8702b4c0480797bb3fd926e80374491abba7768560f8002cbc858be946aa',10,'ddl',['vehicles','drivers','booking_requests','booking_passengers','booking_reviews','approval_decisions','vehicle_assignments','booking_notifications','booking_status_history','calendar_events']],
        '003_roles' => ['database/seeds/003_roles.sql','43e89c65e36df2aa401182f2fefb6fa13289f7a3e9bbde32062057cb5b4495f2',1,'seed',[]],
        '004_auth_security' => ['database/drafts/004_auth_security.sql','d63494ce561d8e8de7eb6c1d793404a7b6c5179f3168719be37c844238966dd1',2,'forward',['auth_login_buckets']],
        '005_vehicle_registration_only' => ['database/drafts/005_vehicle_registration_only.sql','4a6cf9274da4b60543b67004b35b4a46cd816195c9dfb6f5aa361b503d578ae6',1,'forward_test',[]],
    ];

    public static function load(bool $include006=false,bool $include007=false,bool $include008=false,bool $include009=false): array
    {
        $root = dirname(__DIR__, 2);
        $manifest = require $root . '/database/manifest.php';
        if($include009)$include008=true;
        if($include006 || $include007 || $include008)$manifest[]=self::VEHICLE006;
        if($include007 || $include008)$manifest[]=self::VEHICLE007;
        if($include008)$manifest[]=self::BOOKING008;
        if($include009)$manifest[]=self::BOOKING009;
        $plan = [];
        $seen = [];
        foreach ($manifest as $entry) {
            self::assertManifestEntry($entry);
            $pin = self::pin($entry['version']);
            if (isset($seen[$entry['version']])) throw new SafetyException('Duplicate migration manifest entry.');
            $sql = file_get_contents($root . '/' . $entry['file']);
            if ($sql === false || !hash_equals($entry['sha256'], hash('sha256', $sql))) throw new SafetyException('Migration checksum mismatch; review before execution.');
            $entry['statements'] = SqlStatements::split($sql);
            if (count($entry['statements']) !== $pin[2]) throw new SafetyException('Migration statement count mismatch.');
            foreach ($entry['statements'] as $statement) self::assertStatementAllowed($entry['version'], $entry['kind'], $statement);
            $seen[$entry['version']] = true;
            $plan[] = $entry;
        }
        $versions=array_keys(self::PINNED);if($include006 || $include007 || $include008)$versions[]=self::VEHICLE006['version'];if($include007 || $include008)$versions[]=self::VEHICLE007['version'];if($include008)$versions[]=self::BOOKING008['version'];
        if($include009)$versions[]=self::BOOKING009['version'];
        if (array_keys($seen) !== $versions) throw new SafetyException('Migration sequence is incomplete or out of order.');
        return $plan;
    }

    public static function assertManifestEntry(array $entry): void
    {
        $version = $entry['version'] ?? null;
        $pin = is_string($version) ? self::pin($version) : null;
        if ($pin === null || ($entry['file'] ?? null) !== $pin[0] || ($entry['sha256'] ?? null) !== $pin[1]
            || ($entry['kind'] ?? null) !== $pin[3] || ($entry['objects'] ?? null) !== $pin[4]) {
            throw new SafetyException('Manifest entry is not pinned by the reviewed migration plan.');
        }
    }

    public static function assertStatementAllowed(string $version, string $kind, string $statement): void
    {
        if($version===self::BOOKING009['version'] && $kind==='forward_test') {
            $sql=preg_replace('/\s+/',' ',trim($statement));
            $expected='ALTER TABLE booking_requests ADD COLUMN deleted_at DATETIME(6) NULL, ADD COLUMN deleted_by BIGINT UNSIGNED NULL, ADD COLUMN deletion_reason VARCHAR(100) NULL, ADD CONSTRAINT fk_booking_deleted_by FOREIGN KEY (deleted_by) REFERENCES users(id), ADD CONSTRAINT chk_booking_deleted_fields CHECK ( (deleted_at IS NULL AND deleted_by IS NULL AND deletion_reason IS NULL) OR (deleted_at IS NOT NULL AND deleted_by IS NOT NULL AND deletion_reason IS NOT NULL AND CHAR_LENGTH(TRIM(deletion_reason)) > 0) )';
            if($sql!==$expected)throw new SafetyException('Migration 009 exact SQL rejected.');
            return;
        }
        if($version===self::VEHICLE006['version'] && $kind==='forward_test') {
            $sql=preg_replace('/\s+/',' ',trim($statement));
            $allowed=['ALTER TABLE vehicles ADD COLUMN registration_province VARCHAR(100) NULL AFTER registration_number','ALTER TABLE booking_requests ADD COLUMN requested_vehicle_id BIGINT UNSIGNED NULL AFTER requester_id, ADD KEY idx_booking_requested_vehicle (requested_vehicle_id), ADD CONSTRAINT fk_booking_requested_vehicle FOREIGN KEY (requested_vehicle_id) REFERENCES vehicles(id)'];
            if(!in_array($sql,$allowed,true))throw new SafetyException('Migration 006 exact SQL rejected.');
            return;
        }
        if($version===self::VEHICLE007['version'] && $kind==='forward_test') {
            $sql=preg_replace('/\s+/',' ',trim($statement));
            $expected='ALTER TABLE vehicles ADD COLUMN fuel_type VARCHAR(100) NULL AFTER passenger_capacity';
            if($sql!==$expected)throw new SafetyException('Migration 007 exact SQL rejected.');
            return;
        }
        if($version===self::BOOKING008['version'] && $kind==='forward_test') {
            $sql=preg_replace('/\\s+/',' ',trim($statement));
            if($sql!=='ALTER TABLE booking_requests ADD COLUMN a4_request_data JSON NULL')throw new SafetyException('Migration 008 exact SQL rejected.');
            return;
        }
        if ($version === '005_vehicle_registration_only' && $kind === 'forward_test') {
            $normalized = preg_replace('/\s+/', ' ', trim($statement));
            $expected = 'ALTER TABLE vehicles ADD UNIQUE KEY uq_vehicle_registration_number (registration_number), DROP INDEX uq_vehicle_registration, DROP COLUMN registration_province';
            if ($normalized !== $expected) throw new SafetyException('Migration 005 statement is outside its exact allowlist.');
            return;
        }
        if (preg_match('/\b(?:USE|DROP|TRUNCATE|GRANT|REVOKE|CREATE\s+DATABASE|CREATE\s+USER|FOREIGN_KEY_CHECKS)\b/i', $statement)) throw new SafetyException('Unsafe statement token rejected.');
        if (preg_match('/`[^`]+`\s*\.\s*`[^`]+`|\b(?:up_van_booking|up_van_booking_test|lost_found|mysql|performance_schema|information_schema|sys)\s*\./i', $statement)) throw new SafetyException('Cross-schema object reference rejected.');
        if ($version === '004_auth_security' && $kind === 'forward') {
            $normalized = preg_replace('/\s+/', ' ', trim($statement));
            $alter = "ALTER TABLE users ADD COLUMN username VARCHAR(50) CHARACTER SET ascii COLLATE ascii_general_ci NULL AFTER email, ADD COLUMN auth_version BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER is_active, ADD CONSTRAINT chk_users_username_format CHECK (username IS NULL OR REGEXP_LIKE(username, '^[A-Za-z0-9_.-]{3,50}$', 'c')), ADD UNIQUE KEY uq_users_username (username)";
            $create = "CREATE TABLE auth_login_buckets ( bucket_scope VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, bucket_digest BINARY(32) NOT NULL, window_started_at BIGINT UNSIGNED NOT NULL, attempt_count SMALLINT UNSIGNED NOT NULL DEFAULT 0, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, PRIMARY KEY (bucket_scope, bucket_digest), INDEX idx_auth_login_buckets_updated_at (updated_at), CONSTRAINT chk_auth_login_bucket_scope CHECK (bucket_scope IN ('identifier', 'source')) ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
            if ($normalized !== $alter && $normalized !== $create) throw new SafetyException('Forward migration statement is outside the exact reviewed allowlist.');
            return;
        }
        $valid = $kind === 'seed'
            ? (bool)preg_match('/^INSERT INTO roles\s*\(name, description\) VALUES\s*\(/s', $statement)
            : (bool)preg_match('/^CREATE (?:TABLE [a-z_]+\s*\(|SQL SECURITY INVOKER VIEW calendar_events AS\s+SELECT\b)/s', $statement);
        if (!$valid || $kind !== 'ddl' && $kind !== 'seed') throw new SafetyException('Statement is outside the reviewed migration allowlist.');
        if ($kind === 'ddl' && str_starts_with($statement, 'CREATE TABLE') && !str_contains($statement, 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci')) throw new SafetyException('Table engine/charset/collation mismatch.');
        if (preg_match('/\bALTER\b/i', $statement)) throw new SafetyException('ALTER is reserved for exact pinned forward migrations.');
    }

    public static function summary(bool $include006=false,bool $include007=false,bool $include008=false): array
    {
        return array_map(static fn(array $m): array => ['version'=>$m['version'], 'file'=>$m['file'], 'sha256'=>$m['sha256'], 'statements'=>count($m['statements']), 'objects'=>$m['objects']], self::load($include006,$include007,$include008));
    }

    private static function pin(string $version): ?array
    {
        if($version===self::BOOKING009['version'])return [self::BOOKING009['file'],self::BOOKING009['sha256'],1,'forward_test',[]];
        if($version===self::VEHICLE006['version'])return [self::VEHICLE006['file'],self::VEHICLE006['sha256'],2,'forward_test',[]];
        if($version===self::VEHICLE007['version'])return [self::VEHICLE007['file'],self::VEHICLE007['sha256'],1,'forward_test',[]];
        if($version===self::BOOKING008['version'])return [self::BOOKING008['file'],self::BOOKING008['sha256'],1,'forward_test',[]];
        return self::PINNED[$version]??null;
    }
}
