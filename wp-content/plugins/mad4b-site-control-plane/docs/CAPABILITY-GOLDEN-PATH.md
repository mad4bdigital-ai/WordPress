# MAD4B Capability Golden Path

PR #236 is frozen for release closure. A new capability family must use a separate PR.

## 1. Define
Define a provider-neutral semantic contract, risk class, inputs, outputs, authority boundary, resource identity, failure semantics and reversibility requirements. Do not start from a vendor API shape.

## 2. Register
Register the capability in the canonical operation/capability registry. Metadata is descriptive and non-authorizing. Adapter or admin metadata must not become authority truth.

## 3. Certify
Bind exact provider/runtime identity, supported versions, traits, evidence contracts, release ring and fail-closed behavior. Unknown or stale certification is ineligible.

## 4. Plan
Create an immutable exact plan that binds target state, policy/profile fingerprints, provider certification, budgets, approvals and expected postconditions. Authority-relevant drift must stale the plan.

## 5. Execute
Execute only through a governed execution plane with idempotency, lease/fencing, timeout/resource budgets, no caller shell strings, bounded egress and no authority widening from transport success.

## 6. Evidence
Emit durable, bounded, versioned evidence and receipts. Separate repository, live Staging and Production evidence. Missing receipts never prove that no mutation occurred.

## 7. Reconcile
Read back authoritative state, verify postconditions, reconcile uncertain work before retry, and route failures to repair/recovery paths.

## Release-closure rules
- PR #236 accepts defect fixes, test hardening, evidence binding, Staging certification, release-readiness work, documentation accuracy and maintainability decomposition only.
- New capability families, phases, provider-product features or business-domain behavior require a separate PR.
- The task-ledger DONE/total ratio is not a Production-readiness metric.
- Production readiness is profile-specific; Production authorization is a separate explicit action.
- Live backup, restore, recovery, vertical-slice and machine-ingress evidence cannot be closed by repository metadata.
