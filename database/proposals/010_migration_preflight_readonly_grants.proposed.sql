-- Draft only. Do not execute automatically.
-- Requires a separately provisioned up_van_migration_preflight@127.0.0.1 account.
-- This draft grants SELECT only and intentionally contains no CREATE USER, password,
-- DDL, DML, GRANT OPTION, or production target.
-- The runtime verifier rejects any additional privilege or assigned/active role.

GRANT SELECT (`version`, `checksum`, `status`, `completed_statements`, `total_statements`)
ON `up_van_booking_test`.`schema_migrations`
TO 'up_van_migration_preflight'@'127.0.0.1';

-- Narrow ID SELECT grants make schema metadata visible to existing 004–010
-- INFORMATION_SCHEMA checks; they permit reading only the listed ID columns.
GRANT SELECT (`id`) ON `up_van_booking_test`.`users` TO 'up_van_migration_preflight'@'127.0.0.1';
GRANT SELECT (`id`) ON `up_van_booking_test`.`drivers` TO 'up_van_migration_preflight'@'127.0.0.1';
GRANT SELECT (`name`, `description`) ON `up_van_booking_test`.`roles` TO 'up_van_migration_preflight'@'127.0.0.1';
GRANT SELECT (`bucket_scope`) ON `up_van_booking_test`.`auth_login_buckets` TO 'up_van_migration_preflight'@'127.0.0.1';
GRANT SELECT (`id`) ON `up_van_booking_test`.`vehicles` TO 'up_van_migration_preflight'@'127.0.0.1';
GRANT SELECT (`id`) ON `up_van_booking_test`.`booking_requests` TO 'up_van_migration_preflight'@'127.0.0.1';
