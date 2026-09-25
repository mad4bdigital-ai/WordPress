# Contract — Remote Operation Parity and Future Discoverability

Contract: mad4b.remote-operation-parity.v1

## Purpose
Any operation that can reasonably be automated must not require a human to click a WordPress admin button, open a browser page manually, run a local command, or repeat an operator-only maintenance step merely because no remote execution path exists.

The platform MUST preserve human agency for decisions and approvals while removing human-only transport requirements.

## Rules

1. **Remote parity by default**
   - Every automation-eligible local operation MUST declare a governed remote counterpart.
   - A local Admin UI, WP-CLI command, browser step, provider console action, or maintenance control is a frontend, not the sole application service.
   - `manual_only=true` is a release blocker for automation-eligible operations.

2. **No generic remote-admin fallback**
   - Remote parity MUST use semantic abilities such as `managed-skills.reconcile` or `frontend.performance.sample`.
   - Generic shell, raw SQL, arbitrary PHP, arbitrary HTTP, arbitrary wp-admin action execution, and unrestricted filesystem execution are forbidden substitutes.

3. **Authority remains separate from transport**
   - A remote transport does not grant mutation authority.
   - Each remote operation declares its authority surface, environment policy, exact-build binding, provider/executor, and Production policy.
   - Bootstrap/convergence operations that must work before normal write authority MAY live on the bounded enrollment surface.

4. **Exact-build and environment binding**
   - Remote maintenance mutations MUST bind to current source commit, build fingerprint, and package manifest digest.
   - Stale build identity fails closed.
   - Operations marked `production_policy=deny` MUST fail closed outside Staging.

5. **Discoverability**
   - Every registered operation MUST publish:
     - feature_id
     - operation_id
     - capability_tags
     - remote_ability
     - status_ability when available
     - local_surface when one exists
     - authority_surface
     - executor
     - provider
     - remote_mode
     - production_policy
     - human_decision_required
     - remote parity readiness
   - Discovery MUST support search without prior knowledge of an ability name.

6. **Future feature onboarding**
   - New features, provider adapters, maintenance controls, browser acceptance operations, Host Runner operations, and addon capabilities MUST register their operations in the governed catalog.
   - CI MUST fail when an automation-eligible operation lacks remote parity or minimum discoverability metadata.

7. **Human decisions remain human**
   - A decision that is intentionally human-governed (approval, owner attestation, legal/policy acceptance, destructive confirmation) may remain human-required.
   - The transport around that decision should still be remotely observable and resumable.
   - `human_decision_required=true` must describe a decision boundary, not missing engineering.

8. **Executor replaceability**
   - WordPress-native execution, browser runner, Host Runner, provider API, and provider CLI may implement the same semantic operation.
   - Replacing the executor must not change the operation identity or silently widen authority.

## Initial registered parity operations
- Managed Skills reconciliation
- Frontend performance sample collection
- Admin-query performance index maintenance

These are reference implementations, not an exhaustive allowlist.
