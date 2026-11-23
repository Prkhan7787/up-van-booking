-- REVIEW ONLY: live read-only inspection 2026-11-17; not executed.
-- 127.0.0.1:3306 / up_van_booking_test / UUID 77218fbd-d66d-11f1-9a0a-5c2886f057d5
-- Runtime: up_van_auth_test@127.0.0.1; account guard PASS; no unexpected privileges.
-- submitted_at and vehicle_assignments.id confirmed present via migrator metadata.
-- booking_status_history is not visible to runtime/migrator; user confirmed its existence with root SHOW COLUMNS.
-- Officer Read does not require booking_status_history privileges.
-- Existing audit INSERT and vehicles UPDATE(updated_by) are sufficient; not repeated.
-- Apply only separately approved capability blocks; Officer Edit block assumes earlier shared blocks.

-- officer_read (additional missing columns after preceding blocks)
GRANT SELECT (`submitted_at`) ON `up_van_booking_test`.`booking_requests` TO 'up_van_auth_test'@'127.0.0.1';

-- submission (additional missing columns after preceding blocks)
GRANT SELECT (`id`) ON `up_van_booking_test`.`vehicle_assignments` TO 'up_van_auth_test'@'127.0.0.1';
GRANT INSERT (`requested_vehicle_id`, `requester_id`, `request_number`, `application_date`, `travel_type`, `requester_name`, `requester_position`, `requester_affiliation`, `requester_phone`, `purpose`, `destination`, `additional_details`, `expense_source`, `expense_source_details`, `pickup_person_name`, `pickup_person_phone`, `pickup_location`, `pickup_at`, `dropoff_location`, `dropoff_at`, `coordinator_name`, `coordinator_phone`, `requested_start_at`, `requested_end_at`, `passenger_count`, `workflow_status`, `created_by`, `updated_by`) ON `up_van_booking_test`.`booking_requests` TO 'up_van_auth_test'@'127.0.0.1';
GRANT UPDATE (`workflow_status`, `version`, `updated_by`, `submitted_at`) ON `up_van_booking_test`.`booking_requests` TO 'up_van_auth_test'@'127.0.0.1';
GRANT INSERT (`booking_request_id`, `from_status`, `to_status`, `changed_by`, `booking_version`) ON `up_van_booking_test`.`booking_status_history` TO 'up_van_auth_test'@'127.0.0.1';

-- officer_edit (additional missing columns after preceding blocks)
GRANT UPDATE (`requested_vehicle_id`, `travel_type`, `requester_name`, `requester_position`, `requester_affiliation`, `requester_phone`, `purpose`, `destination`, `additional_details`, `expense_source`, `expense_source_details`, `pickup_person_name`, `pickup_person_phone`, `pickup_location`, `pickup_at`, `dropoff_location`, `dropoff_at`, `coordinator_name`, `coordinator_phone`, `requested_start_at`, `requested_end_at`, `passenger_count`) ON `up_van_booking_test`.`booking_requests` TO 'up_van_auth_test'@'127.0.0.1';
