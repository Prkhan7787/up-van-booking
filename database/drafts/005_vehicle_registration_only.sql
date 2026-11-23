-- Forward-only, test-database-only migration.
-- Preconditions: exact up_van_booking_test identity; migration 001-004 applied;
-- no duplicate registration_number under vehicles' existing collation;
-- old province column and uq_vehicle_registration index have their Migration 002 definitions.
-- MySQL ALTER TABLE implicitly commits; runner records a dirty ledger on partial failure.
ALTER TABLE vehicles
    ADD UNIQUE KEY uq_vehicle_registration_number (registration_number),
    DROP INDEX uq_vehicle_registration,
    DROP COLUMN registration_province;
