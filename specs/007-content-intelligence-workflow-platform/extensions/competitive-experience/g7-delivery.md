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

Exact runnable safety tests live in `wp-content/plugins/mad4b-site-control-plane/tests/g7-*.php` and are attached to the **Feature 007 G7 ownership safety** PHP 7.4/8.3 job. Exact-head CI results are external to this committed record; no success result is embedded or self-attested.

Undo must never be represented as completed from history alone, a typed receipt alone or caller-declared before/after hashes. The actual original and compensating writes must be authorized through their existing typed executor, reconciled with durable receipts, verified current readback, and checked for irreversible external effects; absent proof remains **APPROVAL_REQUIRED / RECONCILIATION_REQUIRED**.

Update comparison likewise does not authorize promotion, bind grants, rewrite Skills, create approval, or issue a release acceptance receipt. A host with `prlimit` and `bwrap/unshare` only has candidate prerequisites, not a functioning isolated runner; installation and shell execution require separate external host governance.

Coverage percentage (including the 90–95% aspiration), intervention rate, MTTR and cost remain **unmeasured** without a signed exact-operation eligible-workload denominator. No metric bucket aggregate is treated as a verified rate.

The generated Feature 007 Task Ledger currently records **24 G7 tasks PARTIAL and T4062 OPEN** (no G7 task DONE). The extension validator enforces this boundary and checks the SHA-256/byte inventory of exact source, test and workflow files. This is a repository evidence classification, **not** Release Acceptance or a deployment receipt.

## Mandatory native Last Managed readback binding

The previous condition `Execution Receipt: signed + readback = PASS` was insufficient: a receipt for the same target could be attached to a different field/owner snapshot. The native typed consumer must now:

1. Read **the actual persisted snapshot** after the native CAS write, and retain its exact `resource_id`, revision, owner revision, fields, owners and target fingerprint. Neither a desired-state object nor a caller-provided copy is a readback.
2. Call `MAD4B_SCP_Ownership_Reconciliation::baseline_readback_material($persisted_snapshot)`. This produces the non-authorizing `mad4b.ownership-baseline-readback.v1` material carrying the exact canonical snapshot SHA-256.
3. Place that exact material at `$result['readback']` before invoking the **existing** `MAD4B_SCP_Execution_Receipt::build(...)`. The signed `readback_reconciliation.evidence_sha256` must cover precisely the canonical `{'readback': material}` structure. Extra postcondition or reconciliation fields mixed into that stage cause fail-closed mismatch; record additional effects in their separately approved evidence pipeline.
4. Only after the original signed receipt is verified, the readback stage matches the snapshot digest, the target matches and the site/runtime binding remains current can `managed_baseline(...)` create sealed lineage. This does **not** make `plan(...)`, `commit_guard(...)` or `verify_readback(...)` execution authority: the native typed CAS/permissions/lease and signed terminal receipt remain independently mandatory.

Legacy receipts without snapshot-bound signed readback evidence are **not automatically promoted** into trusted Last Managed baseline. Their source must be re-established through a newly governed operation; do not invent or rewrite old readback receipts.

## Outstanding gates and acceptance

- CI: PHP 7.4 and 8.3 G7 matrix and Spec Kit validator must run on an exact checkout and finish successfully. Queued jobs are not a PASS.
- T4062: do **not** route L2–L5 fixes through G7 read abilities. A reviewed native executor integration, exact plan/CAS/lease, original terminal receipt and independent post-write readback are still missing.
- Undo: separate real compensation under existing native authority plus external effects must be evidenced; matching before/after digests do not certify Undo.
- Update/host: Staging release/rollback acceptance receipts and an authorized functional no-network/prlimit isolation trial are external and missing.
- Metrics: count eligible **distinct operation IDs**, match authorized automatic terminal readback receipts and deduplicate retries; rate, MTTR, intervention and cost remain null until verified.

Operational runs, Production, grant changes, credential changes, host commands and merge are explicitly outside this repository-only checkpoint.
