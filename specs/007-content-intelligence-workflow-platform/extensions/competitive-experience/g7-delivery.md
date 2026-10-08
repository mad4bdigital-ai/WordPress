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

Current Feature 007 task ledger statuses are preserved until the full Spec Kit generator and acceptance evidence are reconciled together; this checkpoint is documentation of work in progress, not a task closure override.
