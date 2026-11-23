-- Proposal only. Target: verified up_van_booking_test on MySQL 127.0.0.1:3306.
-- Existing schema_migrations 001–009 and runtime approval grant profile required.
GRANT SELECT (`booking_request_id`, `outcome`, `channel`)
ON `up_van_booking_test`.`booking_notifications` TO 'up_van_auth_test'@'127.0.0.1';

GRANT SELECT (`booking_request_id`, `outcome`)
ON `up_van_booking_test`.`approval_decisions` TO 'up_van_auth_test'@'127.0.0.1';

GRANT INSERT (`booking_request_id`, `outcome`, `channel`, `notified_at`, `notified_by`)
ON `up_van_booking_test`.`booking_notifications` TO 'up_van_auth_test'@'127.0.0.1';
