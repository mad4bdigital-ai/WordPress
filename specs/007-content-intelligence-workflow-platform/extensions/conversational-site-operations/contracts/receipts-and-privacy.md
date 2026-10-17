# Proof, History and Privacy Receipts

A redacted result records operation ID, tenant/site+source+schema, actor role/issuer, exact input fingerprint (nonsecret only), approval reference, idempotency, step outcome, independently observed postcondition, errors class, undo support and timestamp. A tool 200 or callback true is NOT VERIFIED. SUCCEEDED needs a current exact independent readback; COMPENSATED needs a second post-compensation readback.

Receipt states: PLANNED, DENIED, EXPIRED, AUTHORIZED, RUNNING, VERIFYING, SUCCEEDED, PARTIAL, UNCERTAIN, FAILED, COMPENSATING, COMPENSATED. Uncertain cannot be automatically rerun. History views enforce current read permission, even for past values, and redact sensitive meta/secrets. Low-entropy value hashes can leak data, so use appropriate non-correlatable privacy-safe commitments or no hash. Retention, deletion, eDiscovery and export follow existing tenant policy.
