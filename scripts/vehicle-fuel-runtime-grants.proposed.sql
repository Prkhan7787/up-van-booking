-- PROPOSAL ONLY, after Migration 007 and schema-aware Grant Guard support.
-- Exact target: up_van_booking_test. No global, DDL or Booking write privileges.
GRANT SELECT (`fuel_type`),
      INSERT (`fuel_type`),
      UPDATE (`fuel_type`)
ON `up_van_booking_test`.`vehicles` TO 'up_van_auth_test'@'127.0.0.1';
