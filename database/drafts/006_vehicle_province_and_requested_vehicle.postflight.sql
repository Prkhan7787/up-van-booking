-- READ-ONLY postflight template. Run only after a separately approved and successfully tracked Migration 006.
SELECT DATABASE() AS current_database;
SELECT version, checksum, status, completed_statements, total_statements FROM schema_migrations WHERE version='006_vehicle_province_and_requested_vehicle';
SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='up_van_booking_test' AND ((TABLE_NAME='vehicles' AND COLUMN_NAME='registration_province') OR (TABLE_NAME='booking_requests' AND COLUMN_NAME='requested_vehicle_id')) ORDER BY TABLE_NAME;
SELECT INDEX_NAME, COLUMN_NAME, SEQ_IN_INDEX, NON_UNIQUE FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA='up_van_booking_test' AND TABLE_NAME='booking_requests' AND INDEX_NAME='idx_booking_requested_vehicle' ORDER BY SEQ_IN_INDEX;
SELECT CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA='up_van_booking_test' AND TABLE_NAME='booking_requests' AND CONSTRAINT_NAME='fk_booking_requested_vehicle';
SELECT COUNT(*) AS legacy_vehicles_without_province FROM vehicles WHERE registration_province IS NULL;
SELECT COUNT(*) AS bookings_without_requested_vehicle FROM booking_requests WHERE requested_vehicle_id IS NULL;
SELECT registration_number, COUNT(*) AS duplicate_count FROM vehicles GROUP BY registration_number HAVING COUNT(*)>1;
-- Also verify Migration 005's uq_vehicle_registration_number still uniquely covers registration_number.
