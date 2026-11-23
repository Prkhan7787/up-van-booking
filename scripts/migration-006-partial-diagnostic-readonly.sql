-- READ ONLY. In an explicitly pinned root TCP 127.0.0.1:3307 session only.
-- First verify identity manually. No GRANT, DDL, ledger changes or recovery.
SELECT @@port AS port,@@server_uuid AS uuid,@@datadir AS datadir,
       DATABASE() AS database_name,CURRENT_USER() AS account,VERSION() AS version;
SELECT version,checksum,status,completed_statements,total_statements,failure_code
FROM up_van_booking_test.schema_migrations ORDER BY version;
SHOW GRANTS FOR 'up_van_test_migrator'@'127.0.0.1';
SELECT TABLE_NAME,COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_KEY,COLLATION_NAME
FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='up_van_booking_test'
AND ((TABLE_NAME='vehicles' AND COLUMN_NAME IN ('id','registration_province'))
 OR (TABLE_NAME='booking_requests' AND COLUMN_NAME IN ('id','requested_vehicle_id')));
SELECT TABLE_NAME,ENGINE,TABLE_COLLATION FROM information_schema.TABLES
WHERE TABLE_SCHEMA='up_van_booking_test' AND TABLE_NAME IN ('vehicles','booking_requests');
SELECT TABLE_NAME,CONSTRAINT_NAME,COLUMN_NAME,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME
FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA='up_van_booking_test'
AND TABLE_NAME='booking_requests' AND REFERENCED_TABLE_NAME IS NOT NULL;
SELECT TABLE_NAME,CONSTRAINT_NAME,CONSTRAINT_TYPE FROM information_schema.TABLE_CONSTRAINTS
WHERE CONSTRAINT_SCHEMA='up_van_booking_test' AND CONSTRAINT_NAME='fk_booking_requested_vehicle';
SELECT TABLE_NAME,INDEX_NAME,COLUMN_NAME,SEQ_IN_INDEX,NON_UNIQUE
FROM information_schema.STATISTICS WHERE TABLE_SCHEMA='up_van_booking_test'
AND ((TABLE_NAME='vehicles' AND INDEX_NAME IN ('PRIMARY','uq_vehicle_registration_number'))
 OR (TABLE_NAME='booking_requests' AND INDEX_NAME='idx_booking_requested_vehicle'));
SELECT NAME,ENABLED FROM performance_schema.setup_consumers
WHERE NAME IN ('events_statements_history','events_statements_history_long');
-- Do NOT select SQL_TEXT or MESSAGE_TEXT: return only original ALTER error codes.
SELECT THREAD_ID,EVENT_ID,MYSQL_ERRNO,RETURNED_SQLSTATE
FROM performance_schema.events_statements_history_long
WHERE EVENT_NAME='statement/sql/alter_table' AND MYSQL_ERRNO<>0
AND SQL_TEXT LIKE '%requested_vehicle_id%' ORDER BY TIMER_START DESC LIMIT 5;
SELECT ID,FOR_NAME,REF_NAME,N_COLS,TYPE FROM information_schema.INNODB_FOREIGN
WHERE FOR_NAME='up_van_booking_test/booking_requests';
