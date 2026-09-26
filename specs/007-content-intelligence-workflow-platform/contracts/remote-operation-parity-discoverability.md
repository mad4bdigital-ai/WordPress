# Contract — Remote Operation Parity and Future Discoverability

Contract: mad4b.remote-operation-parity.v1
Catalog metadata version: 3

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
   - Each remote operation declares its authority surface, environment policy, exact-build binding, provider/executor, caller role, and Production policy.
   - `remote_caller_role` MUST be one of `operator`, `external_executor`, `owner`, or `system`; transport exposure must not silently change that role.
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
     - remote_caller_role
     - production_policy
     - human_decision_required
     - remote parity readiness
   - Discovery MUST support search without prior knowledge of an ability name.
   - Extension registrations MUST declare `registrar_id`, `source_plugin`, `trust_class`, and `remote_caller_role`; the catalog computes a stable registration digest that binds caller role as policy identity.
   - Rejected or incomplete registrations MUST be surfaced as findings rather than silently disappearing.
   - `remote_parity_ready` requires both a registered semantic ability and an available executor.

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
   - External browser acceptance MUST use an actual external browser executor. A PHP/server loopback request is not browser-runtime evidence.
   - Long-running maintenance such as schema/index DDL MUST be admitted as durable/scheduled work and MUST NOT keep an MCP or admin HTTP request open while executing.
   - Executor unavailability is an operational waiting/blocking state, not a reason to require a human-only transport step.

9. **Partial convergence and resume**
   - Multi-stage remote operations MUST persist stage/checkpoint state when an earlier stage may succeed before a later stage fails.
   - Retrying the same exact-build operation MUST be safe and resumable through idempotent stages.
   - A missing executor may leave durable work pending, but must not silently downgrade to manual-only execution.

10. **Registration trust**
   - Core and addon registrations MUST publish provenance metadata and a stable digest.
   - Unknown or incomplete registrations are non-authorizing and MUST be reported as rejected catalog entries.
   - Informational discovery metadata MUST never itself grant write authority.

## Initial registered parity operations
- Managed Skills reconciliation
- Frontend performance sample collection
- Admin-query performance index maintenance
- External browser work claim/completion with executor-scoped caller roles

These are reference implementations, not an exhaustive allowlist.
