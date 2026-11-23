-- Proposal only: allowing a rejected decision with no reason requires removing
-- this check. The existing reason column is nullable.
ALTER TABLE up_van_booking_test.approval_decisions DROP CHECK chk_decision_reason;
