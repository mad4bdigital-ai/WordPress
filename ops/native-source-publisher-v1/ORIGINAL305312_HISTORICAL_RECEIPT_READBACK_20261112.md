# Original305 / Original312 Legacy Plan Readback

Status: READ-ONLY HISTORICAL EVIDENCE, NOT CURRENT AUTHORITY.

## Original306 plan inventory

Installed root-owned first-party plan store: 16 plans. The existing first-party
worker indexes 16 commands without error: 15 on company.code.release and
one on company.code.release.receipt.reconcile. Reconciliation preview attributes
all 15 colliding plans uniquely to the original company.code.release Provider.
There is no candidate source-release Provider enrolled.

## Historical Host receipt correlation

The new original_finish_readonly_audit.py only reads the existing, root-private
Original313 Host receipt directory, the installed Original306 plan index,
a root-owned Broker *public* key, and the optional SQLite dispatch journal
opened in read-only mode.

A signed FINISH candidate must match all of:

- Exact registered Original306 command SHA and immutable plan target scope
- Host Job ID bound to the protected receipt filename
- Ed25519 signature verified against the existing root-policy Broker public key
- Historical issue/expiry span bounded to 120 seconds
- Exit code 0 and equal signed output/readback/expected hashes
- Root-only regular file in root-owned receipt directory
- Exactly one valid, nonconflicting historical finish per original command

It never reads a private signing key, starts WP-CLI, claims a Job, grants
permission, creates an Association, writes to WordPress, or invokes Original313.

Observed 2026-11-12:

- 16 original registered commands inspected
- 10 commands with valid historically signed Host FINISH evidence
- 0 invalid/conflicting matching receipts
- 6 without signed FINISH evidence in this selected receipt directory
- 0 matching commands in the separate SQLite dispatch events table
- All 10 historically signed finishes belong to company.code.release
- Of the other 6: 5 are company.code.release and 1 is receipt.reconcile

A *signed Host FINISH attempt* is not a new independent Original313 certificate.
Historical success hashes are not a fresh WordPress readback. No individual
Job's current Original305 state or Original312 eligibility has been verified.

## Pending original Job / Claim readback

The WordPress Original305 broker stores Job and Claim state in original WordPress
options. The signed Broker's WP-CLI admission slots were occupied during this
review. The audit deliberately did NOT run an additional WordPress read, bypass
the admission controller, or infer a current job state from historical files.

Required first-party readback, when admitted via the existing original channel:

1. Obtain a bounded authenticated read of the actual Original305 Job/Claim
   record for each exact command SHA; never take a raw caller-supplied Job ID.
2. Validate company/site UUID/environment/origin, exact command digest,
   original grant/transport, and the claimed/queued/completed state.
3. Validate the genuine original312 result against the same Job and scope,
   without refreshing, replaying, or extending a signed expired claim.
4. Keep stale, missing, mismatched, revoked or uncertain records in HOLD.
5. Preserve the 15 existing legacy Provider associations and all claims.
   Any migration attestation must come from the authorized independent issuer.

The release readiness audit now treats current Original305/312 state as an
explicit HOLD precondition, even if historical signed finishes exist.
