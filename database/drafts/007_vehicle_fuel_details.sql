-- Forward-only draft 007. Approval required; do not execute directly.
-- Existing vehicles keep all original values; fuel type starts NULL.
ALTER TABLE vehicles
    ADD COLUMN fuel_type VARCHAR(100) NULL AFTER passenger_capacity;
