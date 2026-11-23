-- SELECT only. Operator must connect explicitly to TCP 127.0.0.1:3307.
-- No SOURCE/DDL/account commands. Do not run against a default/3306 connection.
SELECT @@port AS port,@@server_uuid AS uuid,@@datadir AS datadir,
       DATABASE() AS database_name,CURRENT_USER() AS account,VERSION() AS server_version;
SELECT version,checksum,status,completed_statements,total_statements
FROM up_van_booking_test.schema_migrations ORDER BY version;
SELECT TABLE_NAME,COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,ORDINAL_POSITION
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA='up_van_booking_test' AND TABLE_NAME IN ('vehicles','booking_requests')
ORDER BY TABLE_NAME,ORDINAL_POSITION;
SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,CHARACTER_SET_NAME,COLLATION_NAME,
       CHARACTER_MAXIMUM_LENGTH,COLUMN_DEFAULT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA='up_van_booking_test' AND TABLE_NAME='users'
AND COLUMN_NAME IN ('username','auth_version') ORDER BY ORDINAL_POSITION;
SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,CHARACTER_SET_NAME,COLLATION_NAME,COLUMN_DEFAULT,EXTRA
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA='up_van_booking_test' AND TABLE_NAME='auth_login_buckets'
ORDER BY ORDINAL_POSITION;
SELECT TABLE_NAME,INDEX_NAME,COLUMN_NAME,SEQ_IN_INDEX,NON_UNIQUE
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA='up_van_booking_test'
AND ((TABLE_NAME='vehicles' AND INDEX_NAME IN ('uq_vehicle_registration','uq_vehicle_registration_number'))
OR (TABLE_NAME='booking_requests' AND INDEX_NAME='idx_booking_requested_vehicle')
OR (TABLE_NAME='users' AND INDEX_NAME='uq_users_username'))
ORDER BY TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX;
SELECT CONSTRAINT_NAME,COLUMN_NAME,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME
FROM information_schema.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA='up_van_booking_test' AND TABLE_NAME='booking_requests'
AND CONSTRAINT_NAME='fk_booking_requested_vehicle';
SELECT (SELECT COUNT(*) FROM up_van_booking_test.vehicles) AS vehicles,
       (SELECT COUNT(*) FROM up_van_booking_test.booking_requests) AS bookings,
       (SELECT COUNT(*) FROM up_van_booking_test.vehicle_assignments) AS assignments;
