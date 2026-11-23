-- Task 05D-2: read-only inspection of up_van_booking_test only.
-- Run with mysql --database=up_van_booking_test and an interactive password prompt.
SELECT DATABASE() AS current_database, CURRENT_USER() AS current_account, VERSION() AS mysql_version;

SELECT version, status, completed_statements, total_statements
FROM `up_van_booking_test`.`schema_migrations`
WHERE version IN ('001_identity','002_booking_design','003_roles','004_auth_security')
ORDER BY version;

SELECT TABLE_NAME, TABLE_TYPE, ENGINE, TABLE_COLLATION
FROM INFORMATION_SCHEMA.TABLES
WHERE TABLE_SCHEMA='up_van_booking_test'
  AND TABLE_NAME IN ('vehicles','booking_requests','vehicle_assignments','audit_logs')
ORDER BY TABLE_NAME;

SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA='up_van_booking_test'
  AND TABLE_NAME IN ('vehicles','booking_requests','vehicle_assignments')
ORDER BY TABLE_NAME, ORDINAL_POSITION;

SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME
FROM INFORMATION_SCHEMA.STATISTICS
WHERE TABLE_SCHEMA='up_van_booking_test'
  AND TABLE_NAME IN ('vehicles','booking_requests','vehicle_assignments')
ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX;

SELECT TABLE_NAME, CONSTRAINT_NAME, CONSTRAINT_TYPE
FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
WHERE CONSTRAINT_SCHEMA='up_van_booking_test'
  AND TABLE_NAME IN ('vehicles','booking_requests','vehicle_assignments')
ORDER BY TABLE_NAME, CONSTRAINT_NAME;

SELECT TABLE_NAME, CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA='up_van_booking_test'
  AND TABLE_NAME IN ('vehicles','booking_requests','vehicle_assignments')
  AND REFERENCED_TABLE_NAME IS NOT NULL
ORDER BY TABLE_NAME, CONSTRAINT_NAME, ORDINAL_POSITION;

SELECT COUNT(*) AS vehicle_count,
       COALESCE(SUM(is_active=1),0) AS active_vehicle_count,
       COALESCE(SUM(is_active=0),0) AS inactive_vehicle_count
FROM `up_van_booking_test`.`vehicles`;

SELECT registration_number, COUNT(*) AS copies
FROM `up_van_booking_test`.`vehicles`
GROUP BY registration_number
HAVING COUNT(*)>1
ORDER BY copies DESC, registration_number;

SELECT registration_number, registration_province, COUNT(*) AS copies
FROM `up_van_booking_test`.`vehicles`
GROUP BY registration_number, registration_province
HAVING COUNT(*)>1
ORDER BY copies DESC, registration_number, registration_province;

SELECT MIN(registration_number) AS example_registration, COUNT(*) AS copies
FROM `up_van_booking_test`.`vehicles`
GROUP BY LOWER(TRIM(registration_number))
HAVING COUNT(*)>1
ORDER BY copies DESC, example_registration;

SELECT COUNT(*) AS sample_registration_matches
FROM `up_van_booking_test`.`vehicles`
WHERE registration_number=(CONVERT(0xE0B881E0B8822031323334 USING utf8mb4) COLLATE utf8mb4_unicode_ci);

SELECT COUNT(*) AS booking_request_count,
       COALESCE(SUM(workflow_status IN ('submitted','under_review','pending_approval')),0) AS pending_request_count
FROM `up_van_booking_test`.`booking_requests`;

SELECT COUNT(*) AS assignment_count,
       COUNT(DISTINCT vehicle_id) AS referenced_vehicle_count,
       COALESCE(SUM(status='active'),0) AS active_assignment_count
FROM `up_van_booking_test`.`vehicle_assignments`;

SELECT v.id, v.registration_number, COUNT(a.id) AS assignment_count,
       COALESCE(SUM(a.status='active'),0) AS active_assignment_count
FROM `up_van_booking_test`.`vehicles` v
JOIN `up_van_booking_test`.`vehicle_assignments` a ON a.vehicle_id=v.id
GROUP BY v.id, v.registration_number
ORDER BY v.id;

SELECT action, COUNT(*) AS event_count
FROM `up_van_booking_test`.`audit_logs`
WHERE entity_type='vehicle'
GROUP BY action
ORDER BY action;

SHOW GRANTS FOR 'up_van_auth_test'@'127.0.0.1';
SELECT @@GLOBAL.mandatory_roles AS mandatory_roles;
SELECT FROM_USER, FROM_HOST, TO_USER, TO_HOST, WITH_ADMIN_OPTION
FROM `mysql`.`role_edges`
WHERE FROM_USER='up_van_auth_test' AND FROM_HOST='127.0.0.1'
ORDER BY TO_USER, TO_HOST;
