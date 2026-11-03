-- OFFLINE MIGRATION ONLY. Never run automatically or in Production.
-- Keep operational issuance disabled, back up the authority ledger.
ALTER TABLE mad4b_authority_permits
  ADD COLUMN approval_mode VARCHAR(24) NOT NULL DEFAULT 'independent_actors' AFTER plan_sha256,
  ADD COLUMN owner_actor_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL AFTER approval_mode,
  ADD COLUMN owner_enrollment_receipt_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL AFTER owner_actor_sha256;
-- Owner grant enrollment/revocation must come from independently authenticated
-- authority registry, never a callback that unconditionally returns true.
