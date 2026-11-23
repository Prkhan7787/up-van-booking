-- Proposal only. Target: verified up_van_booking_test on 127.0.0.1:3306.
-- Compare against runtime SHOW GRANTS before approval. Do not apply automatically.
GRANT UPDATE (`requested_vehicle_id`, `travel_type`, `requester_name`, `requester_position`, `requester_affiliation`,
  `requester_phone`, `purpose`, `destination`, `additional_details`, `expense_source`, `expense_source_details`,
  `pickup_person_name`, `pickup_person_phone`, `pickup_location`, `pickup_at`, `dropoff_location`, `dropoff_at`,
  `coordinator_name`, `coordinator_phone`, `requested_start_at`, `requested_end_at`, `passenger_count`, `a4_request_data`)
ON `up_van_booking_test`.`booking_requests` TO 'up_van_auth_test'@'127.0.0.1';

GRANT INSERT (`booking_request_id`, `reviewed_by`, `reviewed_at`, `receipt_result`, `proposed_at`,
  `proposed_by`, `created_by`, `updated_by`)
ON `up_van_booking_test`.`booking_reviews` TO 'up_van_auth_test'@'127.0.0.1';

GRANT UPDATE (`receipt_result`, `proposed_at`, `proposed_by`, `updated_by`)
ON `up_van_booking_test`.`booking_reviews` TO 'up_van_auth_test'@'127.0.0.1';

GRANT INSERT (`booking_request_id`, `decided_by`, `authority_kind`, `authority_reference`, `outcome`,
  `reason`, `decided_at`, `returned_to_officer_at`)
ON `up_van_booking_test`.`approval_decisions` TO 'up_van_auth_test'@'127.0.0.1';
