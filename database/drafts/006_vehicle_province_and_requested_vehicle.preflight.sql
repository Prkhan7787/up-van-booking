-- READ-ONLY planning checks for proposed Migration 006. Run only against up_van_booking_test.
SELECT DATABASE() AS current_database;
SELECT version, checksum, status, completed_statements, total_statements FROM schema_migrations WHERE version IN ('001_identity','002_booking_design','003_roles','004_auth_security','005_vehicle_registration_only') ORDER BY version;
SELECT version, checksum, status, completed_statements, total_statements FROM schema_migrations WHERE version='006_vehicle_province_and_requested_vehicle';
SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='up_van_booking_test' AND ((TABLE_NAME='vehicles' AND COLUMN_NAME IN ('id','registration_number','registration_province')) OR (TABLE_NAME='booking_requests' AND COLUMN_NAME IN ('requester_id','requested_vehicle_id','requested_start_at','requested_end_at','workflow_status')));
SELECT INDEX_NAME, COLUMN_NAME, SEQ_IN_INDEX, NON_UNIQUE FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA='up_van_booking_test' AND TABLE_NAME='vehicles' AND INDEX_NAME='uq_vehicle_registration_number' ORDER BY SEQ_IN_INDEX;
SELECT registration_number, COUNT(*) AS duplicate_count FROM vehicles GROUP BY registration_number HAVING COUNT(*)>1;
SELECT COUNT(id) AS vehicle_count FROM vehicles;
SELECT COUNT(id) AS booking_count FROM booking_requests;
SELECT COUNT(id) AS assignment_count FROM vehicle_assignments;
-- After 006 only: SELECT COUNT(*) AS referenced_requested_vehicle_count FROM booking_requests WHERE requested_vehicle_id IS NOT NULL;
