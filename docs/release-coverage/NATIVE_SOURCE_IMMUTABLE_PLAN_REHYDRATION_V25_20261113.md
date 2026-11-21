# V25 — Original CAS Plan Stored Plain; Signed Binding Only in Memory

2026-11-13. Source-only; no WordPress, Original306/313 or Root Registry deployment.

The Native Source publisher already creates an original Company Code Release
CAS plan, then decorates its in-memory **draft** with `source_binding` for
review/approval. Persisting the enriched draft into the Original305 root plan
store violates the original `admission.checked_plan` canonical hash. Original306
already verifies the operation in an independently signed association, so
there is no reason to encode a second unsigned operation selector in the plan.

The V25 source host handler reads only the unmodified original plan from the
trusted CAS plan store; it refuses a stored `source_binding` or `operation_id`
field. Before checking an independent Original312 Claim or any Grant, it
rehydrates the ephemeral binding from the existing Original306 verified
`operation_association` first-party hook. The SHA-pinned source Preclaim module
must be enrolled and its local bytes must match the source manifest exactly.
A missing trust root, missing SHA pin, an unregistered operation, mismatched
manifest or expired/modified signature is a hard deny.

The reconstructed binding and plan are **never persisted** to the canonical
CAS store. All existing `publisher.check_plan`, `original_312_for_claim`,
Master Admin ticket and signed Broker grant checks remain in place; the
execution handler remains unavailable outside first-party installation.

Offline integration tests use independently signed temporary association
records and verify that the original immutable CAS plan remains unchanged,
that rehydration matches the existing source plan review contract, and that
missing, unsigned, expired or tampered operation associations cannot authorize
rehydration. V25 does not issue any signature, Grant, Claim or 313 receipt.

**Still HOLD:** The candidate `source.code.release` has no registered original
313 signed finalizer hook, first-party Original306 Worker is not upgraded,
association issuer trust is not enrolled, native Root policy and Central Write
Ability are not installed, Original313 V19 release guard has no signed source
approval, and existing plans on colliding contract need independent association
coverage. Do not attempt direct first-party Registry index writes or WP-CLI
simulation to bypass any of these gates.
