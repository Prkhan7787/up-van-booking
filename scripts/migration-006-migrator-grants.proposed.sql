-- PROPOSAL ONLY. Not executed. Administrator approval and current SHOW GRANTS review required.
-- Target: up_van_booking_test only; assumes existing reviewed database CREATE
-- and vehicles ALTER/INSERT remain present. Do not add those grants again.
-- Use bin/db-migration-006.php, which selects migration_test_006 explicitly.
GRANT ALTER, INSERT ON `up_van_booking_test`.`booking_requests`
TO 'up_van_test_migrator'@'127.0.0.1';
GRANT REFERENCES ON `up_van_booking_test`.`vehicles`
TO 'up_van_test_migrator'@'127.0.0.1';
