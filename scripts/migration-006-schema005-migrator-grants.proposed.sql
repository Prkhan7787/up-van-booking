-- REVIEW ONLY. No automatic execution. New restore-instance account must exist first.
-- Connection must be verified as TCP 127.0.0.1:3307, UUID
-- 37e4d222-dda2-11f1-b503-5c2886f057d5, datadir C:/Users/User/mysql-restore-006-data,
-- database up_van_booking_test, and clean Schema 005. SQL cannot pin a TCP port.
-- This file's database CREATE statement is ONLY for @@GLOBAL.partial_revokes=0.
-- If partial_revokes=1, replace only its schema qualifier with `up_van_booking_test`.*.
-- Approval required. No CREATE USER, secret, DROP, global privilege or GRANT OPTION.
GRANT CREATE ON `up\_van\_booking\_test`.*
TO 'up_van_test_migrator'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE ON `up_van_booking_test`.`schema_migrations`
TO 'up_van_test_migrator'@'127.0.0.1';
GRANT INSERT, ALTER ON `up_van_booking_test`.`users`
TO 'up_van_test_migrator'@'127.0.0.1';
GRANT SELECT ON `up_van_booking_test`.`auth_login_buckets`
TO 'up_van_test_migrator'@'127.0.0.1';
GRANT SELECT (`id`, `registration_number`, `label`, `passenger_capacity`, `is_active`,
 `created_by`, `updated_by`, `created_at`, `updated_at`), INSERT, ALTER, REFERENCES
ON `up_van_booking_test`.`vehicles`
TO 'up_van_test_migrator'@'127.0.0.1';
GRANT SELECT (`id`), INSERT, ALTER ON `up_van_booking_test`.`booking_requests`
TO 'up_van_test_migrator'@'127.0.0.1';
GRANT SELECT (`id`) ON `up_van_booking_test`.`vehicle_assignments`
TO 'up_van_test_migrator'@'127.0.0.1';
