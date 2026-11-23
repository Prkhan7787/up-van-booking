-- PROPOSAL ONLY. Do not execute without a separately reviewed controlled grant run.
-- MySQL account host restricts TCP source, while the CLI DSN and UUID guard pin port 3306.
-- Keep the generated password out of scripts, logs, and source control.
CREATE USER 'up_van_reset_maintenance'@'127.0.0.1' IDENTIFIED BY RANDOM PASSWORD;

GRANT SELECT (`id`, `workflow_status`, `version`, `deleted_at`, `deleted_by`, `deletion_reason`, `updated_by`), UPDATE (`deleted_at`, `deleted_by`, `deletion_reason`, `updated_by`, `version`) ON `up_van_booking_test`.`booking_requests` TO 'up_van_reset_maintenance'@'127.0.0.1';
GRANT SELECT (`id`, `booking_request_id`, `vehicle_id`, `driver_id`, `starts_at`, `ends_at`, `status`) ON `up_van_booking_test`.`vehicle_assignments` TO 'up_van_reset_maintenance'@'127.0.0.1';
GRANT SELECT (`id`, `is_active`) ON `up_van_booking_test`.`users` TO 'up_van_reset_maintenance'@'127.0.0.1';
GRANT SELECT (`id`, `name`) ON `up_van_booking_test`.`roles` TO 'up_van_reset_maintenance'@'127.0.0.1';
GRANT SELECT (`user_id`, `role_id`) ON `up_van_booking_test`.`user_roles` TO 'up_van_reset_maintenance'@'127.0.0.1';
GRANT SELECT (`booking_request_id`) ON `up_van_booking_test`.`approval_decisions` TO 'up_van_reset_maintenance'@'127.0.0.1';
GRANT SELECT (`booking_request_id`) ON `up_van_booking_test`.`booking_notifications` TO 'up_van_reset_maintenance'@'127.0.0.1';
GRANT SELECT (`booking_request_id`) ON `up_van_booking_test`.`booking_status_history` TO 'up_van_reset_maintenance'@'127.0.0.1';
GRANT SELECT (`id`), INSERT (`user_id`, `action`, `entity_type`, `entity_id`, `metadata`) ON `up_van_booking_test`.`audit_logs` TO 'up_van_reset_maintenance'@'127.0.0.1';
GRANT SELECT (`assignment_id`), SHOW VIEW ON `up_van_booking_test`.`calendar_events` TO 'up_van_reset_maintenance'@'127.0.0.1';
