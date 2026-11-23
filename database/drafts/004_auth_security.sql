-- Forward-only local authentication schema candidate. Not yet executed.
-- Execution still requires separate approval and the normal Migration Runner.
-- Existing users are intentionally left with username NULL so data can be
-- audited and backfilled before a later NOT NULL migration.

ALTER TABLE users
    ADD COLUMN username VARCHAR(50)
        CHARACTER SET ascii COLLATE ascii_general_ci NULL AFTER email,
    ADD COLUMN auth_version BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER is_active,
    ADD CONSTRAINT chk_users_username_format
        CHECK (username IS NULL OR REGEXP_LIKE(username, '^[A-Za-z0-9_.-]{3,50}$', 'c')),
    ADD UNIQUE KEY uq_users_username (username);

-- HMAC digests only: never persist raw usernames, email addresses, or IPs.
-- identifier and source scopes support distinct 5/account and 20/IP policies.
CREATE TABLE auth_login_buckets (
    bucket_scope VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    bucket_digest BINARY(32) NOT NULL,
    window_started_at BIGINT UNSIGNED NOT NULL,
    attempt_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (bucket_scope, bucket_digest),
    INDEX idx_auth_login_buckets_updated_at (updated_at),
    CONSTRAINT chk_auth_login_bucket_scope CHECK (bucket_scope IN ('identifier', 'source'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
