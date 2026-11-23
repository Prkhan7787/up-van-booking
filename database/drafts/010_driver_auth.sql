-- DRAFT ONLY — do not execute without schema/grant review and separate approval.
-- Target: up_van_booking_test after identity, booking, role seed and auth-security
-- prerequisites (including users.username/auth_version and auth_login_buckets).
-- No automatic driver/account matching, backfill or assignment creation is performed.
-- Phone identity is stored as users.username in canonical Thai local form (0xxxxxxxxx).
-- Existing usernames and all four existing roles remain unchanged.
-- MySQL DDL implicitly commits; any partial failure requires inspection, not blind retry.

ALTER TABLE users
    MODIFY COLUMN email VARCHAR(254) NULL,
    ADD COLUMN nickname VARCHAR(80) NULL AFTER display_name;

ALTER TABLE drivers
    ADD COLUMN user_id BIGINT UNSIGNED NULL AFTER contact_phone,
    ADD UNIQUE KEY uq_drivers_user_id (user_id),
    ADD CONSTRAINT fk_drivers_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT;

INSERT INTO roles (name, description)
VALUES ('driver', 'พนักงานขับรถ');
