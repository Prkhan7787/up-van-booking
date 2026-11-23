-- PROPOSAL ONLY. Not registered with a runner and not approved for execution.
-- Target exclusively up_van_booking_test on 3306; verify UUID and backup before approval.
-- Needed to preserve partially entered dates/times and existing editable A4 fields.
-- Existing normalized window columns remain both NULL or both valid timestamps.
-- NULL snapshots preserve all existing records and their rendering fallback.
-- Snapshot never contains Section 3; typed Section 2/driver data is not assignment evidence.
ALTER TABLE booking_requests ADD COLUMN a4_request_data JSON NULL;
