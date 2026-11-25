-- DBA REVIEW ONLY. Separate account from application, read-only preflight and Driver 010.
-- Never execute automatically. Keep locked outside an explicitly approved apply window.
CREATE USER 'up_van_migration011_apply'@'127.0.0.1' IDENTIFIED BY RANDOM PASSWORD ACCOUNT LOCK;
GRANT CREATE ON up_van_booking_test.microsoft_identities TO 'up_van_migration011_apply'@'127.0.0.1';
GRANT REFERENCES ON up_van_booking_test.users TO 'up_van_migration011_apply'@'127.0.0.1';
GRANT SELECT (version,checksum,status,completed_statements,total_statements),
      INSERT (version,checksum,status,total_statements),
      UPDATE (status,completed_statements,finished_at,failure_code)
ON up_van_booking_test.schema_migrations TO 'up_van_migration011_apply'@'127.0.0.1';
