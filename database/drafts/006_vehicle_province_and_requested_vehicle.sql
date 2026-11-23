-- REVIEW CANDIDATE — forward-only Migration 006; explicit opt-in runner only; execution is not approved.
-- Preconditions: Migration 005 applied; exact uq_vehicle_registration_number on vehicles(registration_number);
-- no registration_province or requested_vehicle_id columns, no duplicate vehicle registration numbers,
-- target is exactly up_van_booking_test, and a verified external backup exists.
-- Existing vehicles and bookings intentionally remain NULL; no province or requested vehicle is inferred.
-- Preserve Migration 005's UNIQUE(registration_number). This migration does not change registration uniqueness.

ALTER TABLE vehicles
    ADD COLUMN registration_province VARCHAR(100) NULL AFTER registration_number;

ALTER TABLE booking_requests
    ADD COLUMN requested_vehicle_id BIGINT UNSIGNED NULL AFTER requester_id,
    ADD KEY idx_booking_requested_vehicle (requested_vehicle_id),
    ADD CONSTRAINT fk_booking_requested_vehicle
        FOREIGN KEY (requested_vehicle_id) REFERENCES vehicles(id);

-- Postconditions: nullable province column; nullable requested_vehicle_id with FK/index;
-- all pre-existing rows remain NULL in the newly added columns. DDL can partially apply;
-- inspect metadata and ledger manually before any retry. Never run this file twice.
