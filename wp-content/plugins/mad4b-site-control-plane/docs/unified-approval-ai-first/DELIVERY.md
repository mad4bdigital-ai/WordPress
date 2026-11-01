# Integration delivery status — AI-first policy correction 0.2.1 — 25 October 2026

**Architecture implemented:** Unified Approval & Agent Authorization as a **module of MAD4B Control Plane**, not a separate WordPress plugin. Seven exact core include files, bootstrap integration installer with backup and Local-only safety check, read-only original gate/queue bridges, optional provider interface registry (no providers authorized), and updated regression tests.

**Checks completed in isolated working environment:** PHP syntax for the seven includes; 37/37 policy/security contract checks; 32/32 HTTP mock lifecycle checks covering enforced AI-first hybrid sequencing, legacy-policy read-only normalization, human preemption rejection, original AI-only and human-only behavior, and 12/12 core smoke/security checks. Synthetic installer dry-run/apply/duplicate-prevention tests are separate. These tests are not live Local WordPress acceptance.

**Not performed on the real site:** VPS Local plugin tree modification, real WordPress admin acceptance, actual authenticated background REST traffic under these **new** core classes, Staging/Production deployment or original GitHub branch commit/PR. Previous root shell and GitHub mutation attempts were blocked by tool safety while read operations were available. No claim of operational certification is made.

**Ownership:** Original Agent Registry, Context Authority, Impact Policy, Approval Tickets, audit integrity and source-owner receipts continue to own their own gates. Content reviews grant no execution rights. New optional extension registry never authenticates or executes anything itself.

**Remaining steps:** reconcile the current Local Lab source and parallel PR #511, deactivate standalone pilot if installed, apply the core merge using an authorized Local deployment path, test all real WordPress REST operations and original owners, add repository CI and code review, then separately accept Staging. Implement and certify optional machine-workload and managed-browser providers independently before enabling either in production.

## Correction details

- Original `human_and_ai` default updated from generic `all_required` to explicit `ai_then_human`.
- `PENDING_AI` must be followed by a verified original-owner AI receipt; only then can `WAITING_HUMAN` be entered.
- A human approval before AI is denied even for custom `all_required` or `risk_adaptive` policies.
- New configuration no longer accepts `human_then_ai` and `either_authorized`; old stored experimental rules are projected as the stricter `ai_then_human` **without updating options or fabricating human consent**. Previously opened reviews with stale policy hashes must be reopened and reviewed again.
- Original `ai_only` and `human_only` still follow their distinct one-stage paths.

**Deployment status:** The correction is in this distributable source patch only; it has not been applied to the real Local WordPress installation, pushed to GitHub, or deployed to Staging/Production.
