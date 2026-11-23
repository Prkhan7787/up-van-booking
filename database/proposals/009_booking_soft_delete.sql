-- Proposal only. Review before applying to a verified test database.
-- Existing requests remain visible because the new columns start as NULL.
-- The queue stores the server-owned reason officer_queue_removal.
-- After applying the schema and read grants, set TEST_BOOKING_SOFT_DELETE_SCHEMA_ACTIVE=true
-- in each runtime before enabling the delete gate. Keep the schema flag and read grants
-- active when disabling future writes. Validate the calendar and normal booking reads first.
ALTER TABLE booking_requests
    ADD COLUMN deleted_at DATETIME(6) NULL,
    ADD COLUMN deleted_by BIGINT UNSIGNED NULL,
    ADD COLUMN deletion_reason VARCHAR(100) NULL,
    ADD CONSTRAINT fk_booking_deleted_by FOREIGN KEY (deleted_by) REFERENCES users(id),
    ADD CONSTRAINT chk_booking_deleted_fields CHECK (
        (deleted_at IS NULL AND deleted_by IS NULL AND deletion_reason IS NULL)
        OR (deleted_at IS NOT NULL AND deleted_by IS NOT NULL
            AND deletion_reason IS NOT NULL AND CHAR_LENGTH(TRIM(deletion_reason)) > 0)
    );

-- Proposed runtime delta after schema review:
-- GRANT SELECT (deleted_at), UPDATE (deleted_at, deleted_by, deletion_reason)
-- ON up_van_booking_test.booking_requests TO 'up_van_auth_test'@'127.0.0.1';
-- GRANT SELECT (booking_request_id)
-- ON up_van_booking_test.vehicle_assignments TO 'up_van_auth_test'@'127.0.0.1';
