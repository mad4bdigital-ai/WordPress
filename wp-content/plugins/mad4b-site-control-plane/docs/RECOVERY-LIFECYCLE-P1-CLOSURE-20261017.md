# MAD4B Recovery Lifecycle — P1 implementation and operational boundaries
Date: 2026-10-17
Base: PR #396, exact head `604ae29ab9f9bc82a90464af357ab7aec871f8e3`

## Outcome
Source-only remediation for three verified P1 defects. The changes preserve the existing WordPress Control Plane; no new plugin, no unconditional writer, no deployment, no new authority, and no Production changes.

## 1. Owner intent: persist → readback → audit → signed commit marker
Previously `mad4b/staging-write-owner-reviewed` was recorded **before** the signed intent was saved. A successful audit line could therefore predate an unsuccessful option write.

Now:
1. Require the already-established explicit Staging owner, site identity, exact plan review and operational audit availability.
2. Persist a short-lived signed intent without granting any mutation.
3. Read back the exact receipt.
4. Commit the success Audit event only after a matching readback.
5. Persist a separate site-HMAC-signed audit completion marker, keyed to the receipt.
6. Require the valid completion marker in `claim()` and the UI status presenter.

If persistence, Audit or marker storage fails, no receipt is claimable. `OWNER_INTENT_AUDIT_UNCONFIRMED` is a distinct non-authorizing UI state. Repeated attestation with the **same current verified intent** returns the already verified receipt without refreshing its TTL or creating a redundant audit. Claimed receipts are still one-shot and never replayed. Legacy receipts without the new marker are readable as diagnostic evidence but intentionally **not** valid for a fresh write claim after upgrade.

## 2. Owner read vs OAuth authority Step-Up
`mad4b/staging-write-owner-review` and `mad4b/staging-write-owner-reconciliation-diagnose` are read-only, require the **same explicitly enrolled Staging owner**, a normal verified OAuth bearer, the exact ChatGPT client and OAuth subject, but do **not** require the mutation Step-Up scope.

`mad4b/staging-write-owner-attest`, exact Convergence Apply and irreversible provider-specific execution remain separately governed. Reading diagnostics never generates approvals, consumes claims, broadens grants or exposes Developer/Breakglass.

When the approval plan is blocked, the diagnostic still inspects the exact read-only plan identity and bounded hard blockers (if available). The UI supports a logged-in local WordPress owner review without requiring the remote OAuth bearer; remote owner read is never anonymous.

## 3. No unsafe readback handoffs
An action labelled `readback_registered` cannot be assumed to be safe. `MAD4B_SCP_Operational_Remediation::verified_readback_ability` requires actual `wp_get_ability` registration and explicit metadata:
- `meta.annotations.readonly === true`;
- `meta.mcp.surface === 'read'`;
- a strictly bounded ability name.

Unknown, unmounted, mislabelled or mutating abilities return `READBACK_CONTRACT_UNVERIFIED` and read-only discovery, never the requested writer.

## 4. Mandatory CI coverage
The existing Feature 007 Pre-Staging Hybrid Audit workflow now explicitly executes the newly added runtime fixtures:
- `native-first-root-cause-runtime.php`
- `cross-provider-remediation-runtime.php`
- `litespeed-optimization-hook-runtime.php`
- `operator-primary-next-step-runtime.php`
- `staging-write-owner-intent-runtime.php`

Owner test covers persistence failure, audit failure, commit-marker failure, TTL-safe idempotence, legacy/missing marker, wrong client, missing Step-Up for mutation, Production denial, local/remote digest drift and blocked-plan diagnosis. Readback test covers a mutating registered ability disguised as a status read and an unmounted ability. The G8 workflow-source delivery digest was refreshed exactly; all other manifest bindings remain untouched.

## 5. Acceptance before deployment
These are deterministic **source tests**, not Staging or Production runtime acceptance. Before a release:
1. New exact-head GitHub CI, including security fixtures, all complete successfully.
2. Owner reviews the exact stacked PR head and package provenance. Do not infer approval from this document.
3. Rebuild a signed/reproducible package from that exact head; verify G1–G9 and installation identity.
4. On **ETG Staging and All Royal Egypt Staging independently**, verify MCP read-tool discovery, owner read without mutation Step-Up, exact HMAC receipt, matching plan fields across Admin and OAuth, and that a fake/expired/unaudited receipt cannot apply.
5. With **new explicit owner authorization**, execute an allowed reversible test and read back Write grants, package Candidate Binding and browser outcomes. Do not repeat an indeterminate Apply; first reconcile the claim, Audit and runtime via a new read request.
6. Run protected rollback acceptance for a failed postcondition and confirm no Production or cross-tenant mutation.
7. Only then finalize Staging certification, followed by independent Production readiness and authorization if separately requested.

## P2 / future platform work (not falsely claimed solved)
- Fully durable operation journal with per-operation idempotency, postcondition receipts and recoverable crash boundaries across each provider writer.
- Cross-plugin bounded mutation/undo support for LiteSpeed and remaining provider settings.
- Browser E2E attestation integrated with QA, and true live owner/OAuth/candidate-binding parity on both Staging sites.
- Post-upgrade autonomous reconciliation constrained by exact provider capability delta and approval policy.

No owner action was performed by this PR. No live site mutations or new releases were made.
