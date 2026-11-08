# Lifecycle original source reissue (review-only, not authorized for deployment)

## Context

This change proposes a narrow, staging-only administrative operation for the original Content Hub Queue. On 8 November 2026 the **live** Tours.dev Staging plugin is version `0.4.0-lifecycle-intelligence-staging`, while the source feature branch still declares `0.3.1-native-receipt-staging`. **Do not deploy this older branch or plugin wholesale over the live site.** Reconcile live changes and review an exact release candidate first.

The observed original Queue has 541 `runtime_resolve` tasks QUEUED. Read-only WordPress proof classifies 304 stale ContentJob revisions and 237 matching revisions requiring original policy Context Evidence checks. The exact unclaimed canary old two-task Batch is still ACTIVE, with zero attempts and no active leases. An original read-only plan (ContentJob revision 1 → 2) passed original Workflow Origin, Original Context Evidence, and Single Flight, but previous attempts to write this Queue and deploy an administrator handler were blocked by execution safety controls. No write or 313 receipt was produced.

## Review-only admin process

An authenticated Staging-only administrator must explicitly confirm a source-preimage SHA and WordPress nonce. The handler supports a single exact ContentJob INTAKE pair only. It requires original Content Hub and Site Control Plane permissions and unaltered source task/Single Flight identity, then uses **only** existing original Queue APIs:

1. **PAUSE** original batch; independently read back PAUSED state and zero attempts / leases.
2. **ENQUEUE** new batch for current ContentJob revision using original Workflow Origin, source-derived Context Evidence and original Single Flight reservation.
3. **VERIFY** new exact batch and two original source-bound tasks via the original Queue readback, with correct executor lanes and no prior execution.
4. **CANCEL** only the never-executed old batch; independently verify both tasks CANCELLED.

On any uncertain step, require reconciliation. Never resume stale original tasks, reuse old certificates, change Single Flight hashes in place, or forge a signed job. This code does not create a Native 305/306/312/313 grant or receipt. It registers no background replay or automatic job dispatch.

## Acceptance, CI and release guard

- Python source-only assertions cover action ordering, admin authority, nonce and preimage, independent source checks, no direct SQL writes, negative no-resume, no forged 313.
- Previous local Spec Kit tests **199/199** passed on the candidate; those are not a site execution proof.
- No Production source, ContentJob, Rate, WPML or WooCommerce mutation is part of this PR.
- Original live Source and GitHub feature branch have version drift; preserve current live 0.4 code and obtain source owner review, exact staging deployment gate and original release approval before applying.
- Final acceptance requires an **actual** original administrator reissue receipt, an original VPS Queue Claim and Finish pair, and separately an exact original signed 305→306→312→313 job and independent 313 receipt. A successful Queue execution cannot satisfy 313.

Local tracked review reference: `c7db6f3911e8710d6a314020d7c383bd2fe409ce`; no live site content changed as a result of this PR.
