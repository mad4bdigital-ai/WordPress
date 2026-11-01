# MAD4B Unified Governed Lifecycle Policy v1

Implemented on Staging in Lifecycle Orchestrator 0.3.0-unified-policy-staging.

## Workflow
Intake/event → Context resolution → Capability discovery → Dependency graph / plan → Source-owned execution → Exact readback → Recovery and bounded reconciliation → Governed release.

This is a dependency graph, not a linear blocking sequence. Independent research and analysis can remain READY even when exact native write approval is unavailable.

## Runtime states
- READY: a step is eligible to start; it has not necessarily executed.
- RUNNING: reserved for source-owned executor progress evidence.
- RECOVERING: transient infrastructure issue; only bounded readiness rechecks, not blind mutation replay.
- PARTIAL: some independent work can proceed while other steps are pending.
- BLOCKED_AUTHORITY: original native authority or verified approval missing.
- BLOCKED_DEPENDENCY: upstream Ability or completed prerequisite evidence missing.
- NEEDS_RECONCILIATION: commit/readback/rollback outcome uncertain; inspect exact durable state.
- VERIFIED: source-owned exact result readback; never inferred from capability discovery.

## Authority rules
- Real WordPress administrator or agent identity preserved; company proxy represents an enrolled company but never impersonates its owner.
- Registration/discovery of an Ability never grants native provider execution permission.
- Exact target authority, CAS, Journal, owner consent where required, and source verification stay with the native Provider.
- Recovery isolates failures; none of these actions executes a provider mutation or authorizes Production.
- No generic privileged executor is added.

## Interfaces
- WordPress Tools → MAD4B Lifecycle at /wp-admin/tools.php?page=mad4b-unified-lifecycle-policy
- GET /wp-json/mad4b/lifecycle/v1/policy (admin authentication required).
- Read-only Ability: mad4b/lifecycle-policy-plan.
- Existing lifecycle-resolve includes dependency_graph, workflow_state and independent ready and pending step IDs.
- Lifecycle-status stores compact diagnostic state; the full graph is resolved only on demand.

## Acceptance & outstanding
The SEO, Media, Content and Company plans are tested with real Staging targets. Expected safe outcomes: native writes blocked, plan/read steps ready; no fake VERIFIED or fresh native Rank Math score. WPCLI Busy is RECOVERING; identity permission is BLOCKED_AUTHORITY; uncertain commit is NEEDS_RECONCILIATION; missing dependency is BLOCKED_DEPENDENCY. No blind write retry.

The remaining native Rank Math provider certification, Media Journal identity handoff, true provider execution and results, Elementor full HTML coverage, final GitHub release approval, and Production acceptance are not closed by this policy implementation.
