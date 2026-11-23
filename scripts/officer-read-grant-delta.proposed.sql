-- REVIEW ONLY. Execute only after explicit GRANT approval.
-- Target: 127.0.0.1:3306 / up_van_booking_test
-- UUID: 77218fbd-d66d-11f1-9a0a-5c2886f057d5
-- Live pregrant inspection: Account Guard PASS, no unexpected privileges.
-- Officer Read lacks only the following column privilege.
-- No Submission/Officer Edit grant or feature gate change is included.
GRANT SELECT (`submitted_at`) ON `up_van_booking_test`.`booking_requests` TO 'up_van_auth_test'@'127.0.0.1';
