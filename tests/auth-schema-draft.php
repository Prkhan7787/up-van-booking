<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$draft = file_get_contents($root . '/database/drafts/004_auth_security.sql');
if ($draft === false) throw new RuntimeException('Draft migration missing.');
$checks = [
    'forward-only users alteration' => preg_match('/ALTER TABLE users\b/i', $draft) === 1,
    'nullable username for legacy backfill' => preg_match('/username VARCHAR\(50\)[\s\S]*?NULL AFTER email/i', $draft) === 1,
    'case-insensitive ASCII username collation' => str_contains($draft, 'CHARACTER SET ascii COLLATE ascii_general_ci'),
    'username format constraint' => str_contains($draft, 'chk_users_username_format') && str_contains($draft, "'^[A-Za-z0-9_.-]{3,50}$'"),
    'username uniqueness' => str_contains($draft, 'UNIQUE KEY uq_users_username (username)'),
    'auth version default' => str_contains($draft, 'auth_version BIGINT UNSIGNED NOT NULL DEFAULT 1'),
    'persistent scoped limiter store' => str_contains($draft, 'CREATE TABLE auth_login_buckets') && str_contains($draft, "('identifier', 'source')"),
    'HMAC digest binary storage' => str_contains($draft, 'bucket_digest BINARY(32)'),
    'no raw identifiers or addresses columns' => !preg_match('/\b(username|email|ip_address|source_address)\b\s+(?:VARCHAR|CHAR|TEXT)/i', substr($draft, strpos($draft, 'CREATE TABLE auth_login_buckets'))),
    'no forbidden destructive or privilege statements' => !preg_match('/\b(?:DROP|TRUNCATE|CREATE\s+DATABASE|CREATE\s+USER|GRANT|REVOKE|USE)\b/i', $draft),
    'no Lost & Found references' => stripos($draft, 'lost_found') === false,
    '004 registered as a pinned migration candidate' => in_array('004_auth_security', array_column(require $root . '/database/manifest.php', 'version'), true),
];
$manifest = require $root . '/database/manifest.php';
foreach ($manifest as $entry) {
    $contents = file_get_contents($root . '/' . $entry['file']);
    $checks['existing migration checksum ' . $entry['version']] = $contents !== false && hash_equals($entry['sha256'], hash('sha256', $contents));
}
foreach ($checks as $label => $passed) {
    echo ($passed ? 'PASS: ' : 'FAIL: ') . $label . PHP_EOL;
    if (!$passed) exitCode(1);
}
echo 'PASS: Draft schema static checks completed; no database was contacted.' . PHP_EOL;

function exitCode(int $code): never { exit($code); }
