-- PROPOSAL ONLY. Execute only after separate approval in the VERIFIED 3307 root session.
-- UUID 37e4d222-dda2-11f1-b503-5c2886f057d5; datadir C:/Users/User/mysql-restore-006-data.
-- MySQL 8.4.9 FK creation requires parent TABLE REFERENCES, not REFERENCES(id).
-- No global privilege, DROP, GRANT OPTION, runtime account or other table scope.
GRANT REFERENCES ON `up_van_booking_test`.`vehicles`
TO 'up_van_test_migrator'@'127.0.0.1';
