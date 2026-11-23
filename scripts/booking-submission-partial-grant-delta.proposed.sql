-- REVIEW ONLY. Not executed; do not run before separate approval.
-- Verified 2026-11-18: 127.0.0.1:3306 / up_van_booking_test
-- UUID 77218fbd-d66d-11f1-9a0a-5c2886f057d5 / up_van_auth_test@127.0.0.1
-- Existing audit INSERT, vehicle mutex UPDATE and Officer Read grants are not duplicated.
-- No Officer Edit/Approval permission and no gate change.
-- REQUIRES approved/applied Draft008 and verified JSON column before execution.
-- Supersedes the legacy 38-column file for partial-A4 Submission.
GRANT SELECT (`id`) ON `up_van_booking_test`.`vehicle_assignments` TO 'up_van_auth_test'@'127.0.0.1';
GRANT SELECT (`a4_request_data`) ON `up_van_booking_test`.`booking_requests` TO 'up_van_auth_test'@'127.0.0.1';
GRANT INSERT (`requested_vehicle_id`, `requester_id`, `request_number`, `application_date`, `travel_type`, `requester_name`, `requester_position`, `requester_affiliation`, `requester_phone`, `purpose`, `destination`, `additional_details`, `expense_source`, `expense_source_details`, `pickup_person_name`, `pickup_person_phone`, `pickup_location`, `pickup_at`, `dropoff_location`, `dropoff_at`, `coordinator_name`, `coordinator_phone`, `requested_start_at`, `requested_end_at`, `passenger_count`, `workflow_status`, `created_by`, `updated_by`, `a4_request_data`) ON `up_van_booking_test`.`booking_requests` TO 'up_van_auth_test'@'127.0.0.1';
GRANT UPDATE (`workflow_status`, `version`, `updated_by`, `submitted_at`) ON `up_van_booking_test`.`booking_requests` TO 'up_van_auth_test'@'127.0.0.1';
GRANT INSERT (`booking_request_id`, `from_status`, `to_status`, `changed_by`, `booking_version`) ON `up_van_booking_test`.`booking_status_history` TO 'up_van_auth_test'@'127.0.0.1';
