# Dynamic Source Provider Enrollment: Legacy Plan Preservation

Status: **READ-ONLY PROPOSAL / HOLD**. No Original306 or Original313 issuer
was invoked. No Job, approval, Grant, association or receipt was created.

## Observed on 12 November 2026

The installed root-owned Original306 Registry has three enrolled providers.
Its plan store contains 16 JSON CAS plans: 15 share the source candidate's
company-code-release plan contract, and one is an independent receipt-
reconciliation plan. Existing read-only worker indexing succeeds for all 16:
15 map to the original company.code.release and one to receipt reconciliation.

Two independent read-only tools now cover this migration:

1. plan_store_compatibility_audit.py counts the potential contract collision
   dynamically against all candidate manifests and installed providers.
   It reports 15 existing plans requiring reconciliation before activation.
2. legacy_plan_migration_preview.py uses the installed first-party Registry
   and original admission.checked_plan to identify the unique prior provider
   of each colliding plan. All 15 are attributable to the same original
   company.code.release provider; zero are ambiguous. The preview returns
   unsigned association **requests**, not authority.

The result is an inventory of preserved origin and intended provider, NOT
verification that each pending Original305 Job remains unclaimed or eligible.
That requires a separate authorized readback of actual Job state and claims.

## Deployment-safe migration protocol (not executed)

1. Keep all existing providers active and preserve their 306/312/313
   capabilities. Do not delete or rewrite plan records, Original305 Jobs,
   reservations, approvals or their historical signed receipts.
2. Prior to registering a source-family provider sharing an old plan
   contract, reconcile each colliding stored plan against its original
   Original305 Job and first-party signed grant/claim, including expiry,
   target Site UUID, manifest digest, state and reservation. Hold on
   ambiguous or missing relations. No inferred or auto-issued grant.
3. Ask the independent first-party issuer to attest each existing
   provider-to-plan association under the approved policy, with exact
   plan SHA, original provider and manifest SHA, site and environment.
   This is an authenticated migration decision, not a new Job or
   retroactive approval. Each attestation needs auditable provenance.
4. Verify signed sidecar readback before the worker changes indexing.
   The source-family index MUST resolve each plan to exactly one enrolled
   provider; it must retain the original command SHA. Compare the full
   signed digest table before and after migration, checking all plans.
5. Install the reconciled source+worker code and root policy only through
   the first-party approved deployment channel. Canary on a separate
   Source plan after existing legacy index parity is proven. Independently
   verify Original306/312/313, including production environment evidence.
6. On any drift, revoke/disable the candidate source enrollment through
   its authorized channel without modifying existing claims or jobs.
   Keep legacy providers and signed receipts intact. Do not reset claim
   reservations just to force retry of an uncertain mutation.

## Hard stop conditions

- An original plan cannot be validated with its original provider.
- More than one previously installed provider can plausibly own a plan.
- A signed association is missing, expired, altered, untrusted or mismatched.
- Original worker source and installed binary/script are not reconciled.
- Existing original command hashes differ before/after candidate rollout.
- A Production evidence payload does not satisfy its original313 schema.
- Root issuer, master approval, Broker Grant or independent readback is absent.
- Executor cross-source mapping or derived changed_count gates remain open.

The candidate discovery is fully dynamic, not a fixed list of operation
names. The first-party policy and trusted Registry define its scope.

## Historical Original305/312 evidence qualification

A separate read-only cryptographic audit matched the installed Original306
commands against existing Broker-signed historical Host FINISH records:
10 of the 15 company.code.release plans have valid signed FINISH attempts,
5 of those 15 do not have one in the selected receipt directory. The one
receipt-reconciliation plan also has no matching FINISH in that directory.
This does not classify current Job state or Original312 eligibility, and
does not reissue claims. Details:
ORIGINAL305312_HISTORICAL_RECEIPT_READBACK_20261112.md.

## Corrected Grant uniqueness and lifecycle (2026-11-12)

An independent historical Broker Grant audit found 13 correctly signed but
expired Grants covering **10 distinct plans**, not 13 plans. Five of the 15
plans have no matching Grant file in this root-only inventory. Two plans
have multiple historical Grants; this is not permission to renew or replay
the same Job. See ORIGINAL305_GRANT_LIFECYCLE_READBACK_20261112.md.

The pure original_job_lifecycle_preview.py defines fail-closed lifecycle
classification for future authorized Job/Claim snapshots. It is not a
running WordPress reader or a substitute for Original305/312 signed evidence.
