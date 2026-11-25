-- REVIEW DRAFT ONLY. Separate DBA approval required. Do not execute automatically.
-- Supplemental to the existing reviewed Admin grants, after schema approval.
-- Login lookups need only SELECT(user_id,tenant_id,object_id).
-- INSERT/DELETE below are needed exclusively by Admin link/unlink operations.
GRANT SELECT (user_id,tenant_id,object_id),
      INSERT (user_id,tenant_id,object_id,created_by), DELETE
ON up_van_booking_test.microsoft_identities TO 'up_van_auth_test'@'127.0.0.1';
