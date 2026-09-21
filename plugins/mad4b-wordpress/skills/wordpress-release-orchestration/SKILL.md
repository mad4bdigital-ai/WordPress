---
name: wordpress-release-orchestration
description: Orchestrate a governed WordPress release from desired state through provider workflow planning, plugin lifecycle preflight, exact authority, execution, verification, evidence, and rollback without making the Skill itself an execution authority.
---

Use this skill when the user asks to prepare, repair, validate, release, or operationalize a WordPress change that spans more than one governed capability or provider.

1. Resolve the exact Site Profile, environment, candidate/build identity, and desired-state contracts before planning any mutation.
2. Read existing fresh evidence first. Reuse evidence that is still bound to the same candidate, provider identity, target fingerprint, and contract; invalidate only dependent evidence when an observed input changed.
3. Build the operation DAG before execution. Separate read/diagnose, plan, authority, mutate, verify, rollback, and release gates.
4. For asynchronous workflow mechanics, read `mad4b/workflow-provider-status` and create a non-authorizing `mad4b/workflow-plan`. Treat Bit Flows as an execution provider, never as the source of truth or policy engine.
5. Never infer missing workflow-provider capabilities. If create, enable, disable, retry, or cancel is unavailable or uncertified, return the exact blocker and keep the operation fail-closed.
6. Before activating or deactivating a WordPress plugin, run `mad4b/plugin-lifecycle-plan`. Require dependency safety, protected-plugin checks, lifecycle allowlisting, an exact state fingerprint, and an eligible plan.
7. Bind every write to the exact underlying MAD4B ability, provider, target fingerprint, expected-current-state guard, NHI grant, budget, and one-time approval required by the policy engine. A workflow plan or Skill decision never substitutes for authority.
8. Execute only the narrowest certified capability. Do not write provider databases directly and do not substitute filesystem, raw SQL, or internal implementation access for a bounded provider capability.
9. Immediately perform provider readback and semantic verification. Record before/after fingerprints, plan digest, provider identity, candidate/build identity, verification result, and evidence dependencies.
10. If verification fails, use the certified rollback capability or inverse operation when available. If rollback is unavailable or provider identity drifted, stop and surface the unresolved state rather than improvising recovery.
11. Recompute only release gates whose dependencies changed. Production activation, SEO publication, or other irreversible/high-impact release gates remain independently authorized.
12. Return a delivery record containing desired state, observed state, drift, operation plan, authority result, execution receipts, verification, rollback readiness, stale evidence, blockers, and the next safe action.
