# Feature 007 — G7 Delivery (repository checkpoint)

G7 covers **T4036–T4055** and **T4061–T4065** (25 tasks). This record deliberately claims **PARTIAL**, not DONE. Repository-level implementation and hermetic denial tests cannot certify site-local Staging acceptance.

| Workstream | Repository safety foundation | Unproven acceptance |
|---|---|---|
| Ownership reconciliation | Sealed field lineage, human delta preservation, exact CAS/readback preflight | Native consumer integration, real operator Staging readback |
| Journal and Undo | Redacted verified history-only projection; signed execution/compensation receipt consistency preflight | Independently verified typed compensation and external effect reconciliation |
| Update acceptance | Site-bound pre/post generation and capability-graph comparison; conservative recertification blockers | Native acceptance receipt, rollback rehearsal, Skills/catalog/authority parity |
| Host readiness | Short-lived site-bound host observation; binary presence is never sandbox proof | Host-authorized operational isolation test and trusted attestation |
| Action Center | Existing Operator UI integration, private read abilities, non-authorizing L0-L5 view | Verified closed-loop remediation, attempt/rollback accounting and live browser acceptance |
| Measurement | Read-only existing metric bucket source and explicit denominator boundary | Receipt-bound, deduplicated eligible-workload denominator and real MTTR/cost/failure rates |

Exact runnable safety tests live in `wp-content/plugins/mad4b-site-control-plane/tests/g7-*.php` and are attached to the **Feature 007 G7 ownership safety** PHP 7.4/8.3 job. The `g7-native-receipt-baseline-runtime.php` hermetic integration fixture uses the actual Execution Receipt build/verify implementation (with a local signing adapter) and checks that the signed canonical readback stage matches the Last Managed snapshot + current restore/runtime binding; this is **not** a Staging signature, an operational readback or an authorized write. Exact-head CI results are external to this committed record; no success result is embedded or self-attested.

Undo must never be represented as completed from history alone, a typed receipt alone or caller-declared before/after hashes. The G7 Compensation Audit reports `caller_hash_claims_consistent=true` only as **unverified caller assertions**; `evidence_consistent=false`, `independent_evidence_verified=false`, `receipt_site_binding_verified=false`, `external_effects_verified=false`, and `undo_certified=false` remain mandatory until independently signed and site-bound native proofs exist. The actual original and compensating writes must be authorized through their existing typed executor, reconciled with durable receipts, verified current readback, and checked for irreversible external effects; absent proof remains **APPROVAL_REQUIRED / RECONCILIATION_REQUIRED**.

Update comparison likewise does not authorize promotion, bind grants, rewrite Skills, create approval, or issue a release acceptance receipt. A host with `prlimit` and `bwrap/unshare` only has candidate prerequisites, not a functioning isolated runner; installation and shell execution require separate external host governance.

Coverage percentage (including the 90–95% aspiration), intervention rate, MTTR and cost remain **unmeasured** without a signed exact-operation eligible-workload denominator. No metric bucket aggregate is treated as a verified rate.

The generated Feature 007 Task Ledger currently records **24 G7 tasks PARTIAL and T4062 OPEN** (no G7 task DONE). The extension validator enforces this boundary and checks the SHA-256/byte inventory of exact source, test and workflow files. This is a repository evidence classification, **not** Release Acceptance or a deployment receipt.

## Strict native receipt signature and bounded ownership values

A Last Managed baseline must not trust a Crypto_Profile adapter solely because it did not return WP_Error. Execution Receipt now requires an explicit detached-signature verification result with `valid === true`, matching `purpose`, `profile_id`, `kid`, and `signed_sha256`. The PHP integration fixture tests a falsely negative adapter response; real asymmetric signer/keyring and independently persisted readback remain live acceptance work.

Field-count bounds alone were insufficient: G7 limits nested snapshot values before canonicalization (16 levels, 2,048 nodes, 256 KiB total and 32 KiB/string). Objects, floats, resources, cyclic/deep structures and invalid UTF-8 fail closed. The size budget applies to the owned-field snapshot, not to the full WordPress post body. Consumers with larger serialized fields must define an explicit, reviewed normalized reference or fingerprint rather than silently dropping evidence.

## Snapshot identity and resource-budget objections

Last Managed must never derive an owned-field baseline from a snapshot with arbitrary extra metadata, another `target_fingerprint`, or a foreign `resource_id`. For this G7 contract only canonical keys `resource_id`, `revision`, `owner_revision`, `fields`, `owners`, `target_fingerprint`, and sealed `lineage_sha256` / `lineage_proof` are accepted. `target_fingerprint` is required to be bounded stable ASCII; it must match across last-managed/current/desired. The complete snapshot including its lineage envelope, not merely `fields`, is checked for recursive depth, JSON-compatible types and bounded nodes/bytes before canonical hashing. Policies are similarly bounded before conflict and merge logic. This is a fail-closed compatibility change: consumers with unknown metadata must separate that metadata from the canonical owned-field Snapshot and establish any new evidence through the governed native path, never silently drop it.

## Mandatory native Last Managed readback binding

The previous condition `Execution Receipt: signed + readback = PASS` was insufficient: a receipt for the same target could be attached to a different field/owner snapshot. The native typed consumer must now:

1. Read **the actual persisted snapshot** after the native CAS write, and retain its exact `resource_id`, revision, owner revision, fields, owners and target fingerprint. Neither a desired-state object nor a caller-provided copy is a readback.
2. Call `MAD4B_SCP_Ownership_Reconciliation::baseline_readback_material($persisted_snapshot, $current_binding)`. This produces the non-authorizing `mad4b.ownership-baseline-readback.v1` material carrying the exact canonical snapshot SHA-256.
3. Bind the payload to the current site/runtime/restore identity through `binding_sha256`. Supply the current validated site binding when calling `baseline_readback_material($persisted_snapshot, $current_binding)`; previous runtime/restore receipts cannot seed a new baseline even if all snapshot fields match. Place that exact material at `$result['readback']` before invoking the **existing** `MAD4B_SCP_Execution_Receipt::build(...)`. The signed `readback_reconciliation.evidence_sha256` must cover precisely the canonical `{'readback': material}` structure. Extra postcondition or reconciliation fields mixed into that stage cause fail-closed mismatch; record additional effects in their separately approved evidence pipeline.
4. Only after the original signed receipt is verified, the readback stage matches the snapshot digest, the target matches and the site/runtime binding remains current can `managed_baseline(...)` create sealed lineage. This does **not** make `plan(...)`, `commit_guard(...)` or `verify_readback(...)` execution authority: the native typed CAS/permissions/lease and signed terminal receipt remain independently mandatory.

Legacy receipts without snapshot-bound signed readback evidence are **not automatically promoted** into trusted Last Managed baseline. Their source must be re-established through a newly governed operation; do not invent or rewrite old readback receipts.

## Journal evidence provenance objection

A claimed `chain_valid=true` is not proof of a valid native journal read. The G7 history-only projection now requires both exact `mad4b.dynamic-operation-trace.v1` and `mad4b.dynamic-operation-status.v1` contracts, and read-only/non-mutation flags on both before trusting chain, sequence, identity and head continuity. An event with malformed `safe_metadata` is rejected rather than rendered as an empty record. This projection explicitly states `cryptographically_signed_history=false`: source hash-chain consistency is **not** a signed native execution receipt, actor authorization, external-effect proof or Undo eligibility.

## Outstanding gates and acceptance

- CI: PHP 7.4 and 8.3 G7 matrix and Spec Kit validator must run on an exact checkout and finish successfully. Queued jobs are not a PASS.
- T4062: do **not** route L2–L5 fixes through G7 read abilities. A reviewed native executor integration, exact plan/CAS/lease, original terminal receipt and independent post-write readback are still missing.
- Undo: separate real compensation under existing native authority plus external effects must be evidenced; matching before/after digests do not certify Undo.
- Update/host: Staging release/rollback acceptance receipts and an authorized functional no-network/prlimit isolation trial are external and missing.
- Metrics: count eligible **distinct operation IDs**, match authorized automatic terminal readback receipts and deduplicate retries; rate, MTTR, intervention and cost remain null until verified.

Operational runs, Production, grant changes, credential changes, host commands and merge are explicitly outside this repository-only checkpoint.
