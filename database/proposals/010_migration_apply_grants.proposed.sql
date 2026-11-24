-- DBA review only. Verify the approved up_van_booking_test instance before provisioning.
-- Keep the account locked until a separately authorised verification or Apply window.
-- The generated credential must be collected privately, without recording the result.
CREATE USER 'up_van_migration010_apply'@'127.0.0.1'
    IDENTIFIED BY RANDOM PASSWORD ACCOUNT LOCK;

GRANT ALTER, CREATE, INSERT, REFERENCES ON `up_van_booking_test`.`users` TO 'up_van_migration010_apply'@'127.0.0.1';
GRANT ALTER, CREATE, INSERT ON `up_van_booking_test`.`drivers` TO 'up_van_migration010_apply'@'127.0.0.1';
GRANT SELECT (name, description), INSERT (name, description)
    ON `up_van_booking_test`.`roles` TO 'up_van_migration010_apply'@'127.0.0.1';
GRANT SELECT (version, checksum, status, completed_statements, total_statements),
    INSERT (version, checksum, status, total_statements),
    UPDATE (status, completed_statements, finished_at, failure_code)
    ON `up_van_booking_test`.`schema_migrations` TO 'up_van_migration010_apply'@'127.0.0.1';
GRANT REFERENCES ON `up_van_booking_test`.`auth_login_buckets` TO 'up_van_migration010_apply'@'127.0.0.1';
GRANT REFERENCES ON `up_van_booking_test`.`vehicles` TO 'up_van_migration010_apply'@'127.0.0.1';
GRANT REFERENCES ON `up_van_booking_test`.`booking_requests` TO 'up_van_migration010_apply'@'127.0.0.1';
