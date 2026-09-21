# MAD4B Unified WordPress Workflow and Plugin Lifecycle Operating Model

## Status

This document is the canonical implementation plan for governed WordPress orchestration across Site Control Plane capabilities, reusable Skills, provider adapters, workflow providers, plugin lifecycle operations, evidence and release gates.

The architecture is deliberately site-neutral. ETG can consume it as a Site Profile and contract binding, but no core workflow capability may depend on an ETG hostname, post ID, template ID, taxonomy name or business-specific rule.

## 1. Architectural invariants

1. Skills interpret intent and orchestrate. They are never a mutation authority.
2. Contracts define desired state and invariants.
3. Plans describe the exact operation graph and are immutable once approved.
4. Policy and authority decide whether a plan may execute.
5. Capabilities are bounded deterministic operations.
6. Provider adapters own provider-specific implementation.
7. Workflow providers own timing, routing, retries and asynchronous mechanics only.
8. Evidence proves what was inspected, authorized, changed and verified.
9. Site Profiles contain tenant-specific bindings and feature configuration.
10. Production authority is independent from Staging authority.
11. Unknown provider capability means unavailable, never inferred support.
12. Direct provider database writes are forbidden when a provider-level contract is expected.
13. Breakglass is never a substitute for a missing normal capability.

## 2. Target architecture

```text
Human intent
    |
    v
Reusable Skills
    |
    v
Desired State + Contracts
    |
    v
Workflow / Operation Planner
    |
    v
Immutable plan digest
    |
    v
Policy + Authority
    |
    +-------------------------+
    |                         |
    v                         v
Direct MAD4B capability    Workflow Provider
                              |
                              | timing / route / retry / wait
                              v
                         MAD4B capability
    |                         |
    +------------+------------+
                 v
          Provider Adapter
                 |
                 v
              Runtime
                 |
                 v
             Evidence
                 |
                 +----> dependency invalidation / Skills
```

The workflow provider never bypasses the MAD4B capability layer.

## 3. Capability layers

### 3.1 Core capabilities

Core capabilities are provider-independent bounded operations such as:

- site/runtime discovery;
- plugin lifecycle planning;
- plugin activation/deactivation through governed WordPress APIs;
- content reads and bounded writes;
- authority and approval planning;
- evidence and audit reads.

### 3.2 Provider adapters

Provider adapters implement exact provider primitives and expose only certified operations. Examples:

- Elementor;
- JetEngine;
- JetSmartFilters;
- Rank Math;
- WPML / translation bridge;
- WooCommerce;
- LiteSpeed;
- Bit Flows;
- WP All Import / Export.

A provider being installed or active does not certify every operation.

### 3.3 Workflow providers

A workflow provider is replaceable infrastructure for orchestration mechanics.

Provider-neutral contract:

```text
workflow/list
workflow/get
workflow/create
workflow/enable
workflow/disable
workflow/execute
workflow/execution-status
workflow/retry
workflow/cancel
```

Each operation may independently be:

```text
UNAVAILABLE
DISCOVERED
STRUCTURALLY_COMPATIBLE
READ_CERTIFIED
CANARY_WRITE
REVERSIBLE_VERIFIED
WRITE_CERTIFIED
ACTIVE
```

The contract is not satisfied merely because the provider plugin is active.

## 4. Bit Flows role

Bit Flows is the first workflow provider implementation.

MAD4B owns:

- desired state;
- workflow plan;
- plan digest;
- policy;
- NHI grants;
- exact approval;
- provider capability certification;
- execution budget;
- mutation authority;
- evidence;
- rollback authority;
- production release gates.

Bit Flows may own:

- delay;
- routing;
- conditional branches;
- iterator/repeater behavior;
- scheduling;
- queueing;
- webhook waits;
- notifications;
- low-risk SaaS integration mechanics;
- execution history where certified.

Bit Flows must not own:

- candidate binding;
- Site Profile authority;
- production activation decisions;
- raw provider database writes;
- filesystem or raw SQL bypass;
- caller-selected credentials;
- MAD4B approval decisions.

### Current provider mapping

```text
list             -> bitflows/list-flows          READ
get              -> bitflows/get-flow            READ
execution-status -> bitflows/get-executions      READ
execute          -> bitflows/run-flow            HIGH_RISK_WRITE

create            UNAVAILABLE pending certification
enable            UNAVAILABLE pending certification
disable           UNAVAILABLE pending certification
retry             UNAVAILABLE pending certification
cancel            UNAVAILABLE pending certification
```

Unavailable operations remain explicit in the contract so Skills and UIs can report capability gaps instead of inventing provider behavior.

## 5. WordPress plugin lifecycle contract

### 5.1 Read-only plan

Every activation/deactivation begins with:

```text
mad4b/plugin-lifecycle-plan
```

The plan captures:

- exact plugin file;
- plugin name/version;
- active and network-active state;
- plugin main-file SHA-256;
- deterministic state SHA-256;
- `Requires Plugins` dependencies;
- active dependents;
- protected-plugin status;
- global lifecycle gate state;
- operation allowlist state;
- blockers;
- plan SHA-256.

The planner does not mutate and creates no authority.

### 5.2 Write operations

Existing governed operations remain the execution primitives:

```text
mad4b/plugin-activate
mad4b/plugin-deactivate
```

Every lifecycle write must include:

- plugin file;
- expected active state;
- expected state SHA-256;
- reason;
- exact NHI grant;
- exact provider/server binding;
- one-time approval when policy requires it;
- budget reservation;
- audit availability.

The expected state SHA binds the operation to the reviewed plugin version, main file and lifecycle state instead of only the active boolean.

### 5.3 Dependency safety

Activation fails closed when a declared required plugin is:

- missing;
- inactive.

Deactivation fails closed when:

- the target is the Site Control Plane;
- the target is the MCP Adapter;
- policy marks the target protected;
- another active plugin declares the target in `Requires Plugins`.

This does not attempt to infer undeclared dependencies. Providers with important implicit dependencies should add them to the protected policy or an explicit provider dependency contract.

### 5.4 Readback evidence

After a lifecycle mutation, the Control Plane reads the plugin state again and records:

- plan SHA-256;
- before state SHA-256;
- after state SHA-256;
- readback verification result;
- network-wide state where applicable.

A mutation is not reported as successful when readback does not match the requested state.

## 6. Desired and observed state model

All future multi-provider automation should converge on:

```text
Desired State
    |
Observed State
    |
    v
Semantic Diff
    |
    v
Operation Plan
    |
    v
Authority
    |
    v
Execution
    |
    v
Verified State
```

Desired state belongs in contracts and Site/Feature Profiles, not in procedural Skills.

Observed state must include provider identity and enough exact fingerprints to detect drift.

## 7. Operation plan contract

A multi-step operation plan should include at minimum:

```text
contract
site_uuid
environment
candidate/build identity
desired_state_digest
observed_state_digest
operations[]
affected_objects[]
provider_bindings[]
reversible_operations[]
destructive_operations[]
cross_provider_operations[]
evidence_dependencies[]
plan_sha256
```

Approval must bind to the immutable `plan_sha256` and target fingerprints.

Changing a target, operation, provider identity or expected state invalidates the approval.

## 8. Unified release DAG

The default release workflow is:

```text
Resolve Site Profile
    |
Resolve exact candidate/build
    |
Load desired-state contracts
    |
Reuse fresh evidence
    |
Inspect stale/missing observed state
    |
Compute semantic drift
    |
Generate immutable operation plan
    |
Capability certification preflight
    |
Policy + authority preflight
    |
Human approval when required
    |
Execute bounded capabilities
    |
Provider readback
    |
Semantic verification
    |
Browser/runtime acceptance where required
    |
Rollback on certified verification failure
    |
Commit evidence
    |
Recalculate dependent release gates
    |
Release authorization
```

Async waits, retries or notification branches may be handed to a workflow provider, but every mutation returns through the exact MAD4B ability.

## 9. Evidence dependency graph

Evidence is keyed by:

- site;
- environment;
- candidate/build;
- provider identity;
- capability;
- object/target;
- contract;
- observed fingerprint;
- time.

Examples:

```text
Browser acceptance
  depends on:
  - runtime identity
  - semantic acceptance
  - template parity

SEO publication
  depends on:
  - browser acceptance
  - translation readiness
  - SEO provider readiness
  - result-count parity
  - content readiness

Production activation
  depends on:
  - publication readiness
  - performance/rate-limit evidence
  - rollback readiness
  - exact production authority
```

When an input changes, invalidate only dependent evidence.

## 10. Skills model

Skills remain small and composable.

Recommended reusable skills:

- wordpress-change-safety;
- wordpress-release-orchestration;
- elementor-template-reconciliation;
- multilingual-content-audit;
- seo-publication-readiness;
- browser-runtime-validation;
- provider-version-drift-diagnosis;
- production-rollout-governance.

`wordpress-release-orchestration` is the coordinating Skill. It consumes provider status, lifecycle plans, operation plans, authority and evidence. It does not implement provider mutation.

## 11. Site-specific configuration

A site should normally require configuration, not framework code.

Example shape:

```yaml
site: example-site

features:
  filtered_archives:
    provider: jet-engine
    query: archive_query

templates:
  archive:
    canonical_language: en
    canonical_template: 123

translation:
  provider: wpml

seo:
  provider: rank-math

workflow:
  provider: bitflows
```

IDs and business-specific concepts belong in Site/Feature Profiles or domain providers.

## 12. Capability certification policy

Certification is per capability, not per plugin.

Example:

```text
Bit Flows
  flow.read              READ_CERTIFIED
  flow.execute           candidate/high-risk gate
  workflow.create        UNAVAILABLE
  workflow.enable        UNAVAILABLE
  workflow.disable       UNAVAILABLE
  workflow.retry         UNAVAILABLE
  workflow.cancel        UNAVAILABLE
```

Promotion requires evidence appropriate to the operation. A static class or database table match is not enough to authorize a write.

## 13. Bit Flows recertification sequence

For the installed Bit Flows runtime:

1. prove exact plugin identity and version;
2. identify public/provider-supported contracts for every operation;
3. verify required capabilities and permission checks;
4. certify non-secret read operations;
5. prove deterministic workflow fingerprinting;
6. run disposable canary execution;
7. prove execution evidence/readback;
8. identify reversible behavior for enable/disable where provider-supported;
9. certify retry/cancel semantics independently;
10. only then map create/enable/disable/retry/cancel into the Workflow Provider contract.

Do not promote operations by writing Bit Flows internal tables.

## 14. Plugin lifecycle rollout sequence

For plugin activation/deactivation:

1. inventory target plugin;
2. create lifecycle plan;
3. resolve dependencies/dependents;
4. verify target is not protected;
5. verify lifecycle global gate;
6. verify exact allowlist;
7. bind expected state SHA-256;
8. create exact approval plan;
9. execute governed lifecycle ability;
10. verify readback;
11. record evidence;
12. invalidate provider/runtime evidence affected by lifecycle change;
13. recertify dependent providers before further writes.

## 15. Release gates

### Repository gate

Required:

- PHP lint 7.4 / 8.1 / 8.3;
- workflow-provider contract test;
- plugin-lifecycle governance contract test;
- dynamic Skills contract;
- existing Control Plane contract guards;
- no arbitrary command/PHP execution primitive;
- no default-server public MCP exposure.

### Disposable runtime gate

Required:

- Control Plane activates with zero implicit authority;
- explicit Site Profile enrollment required;
- planning abilities remain read-only;
- protected plugin deactivation plan fails closed;
- lifecycle plan is deterministic;
- provider-uncertified workflow writes remain unavailable;
- write surface still requires exact authority.

### Staging gate

Required before enabling lifecycle or workflow writes:

- exact candidate bound;
- provider identity verified;
- Site Profile exact-origin match;
- write authority reconciled;
- capability certification current;
- exact lifecycle/workflow plan available;
- rollback/readback path defined;
- acceptance evidence current.

### Production gate

Production never inherits Staging authority.

Require a separate exact production Site Profile, candidate binding, production confirmation, plan approval, rollback readiness, current evidence and production-specific acceptance policy.

## 16. Current implementation delivered by this change

Implemented:

- provider-neutral Workflow Provider contract;
- Bit Flows as the first replaceable execution-provider mapping;
- explicit unavailable lifecycle/retry operations instead of inferred support;
- read-only workflow provider status;
- deterministic workflow plans with SHA-256;
- generic semantic identity map separated from physical provider IDs;
- machine-readable generic ownership model;
- declarative Site/Feature bundle validation;
- deterministic Desired State / Observed State semantic diff;
- generic immutable operation plan with exact plan_sha256 and authority-binding metadata;
- evidence dependency graph and transitive invalidation planner;
- declarative invariant evaluator;
- explicit candidate state machine that prevents bootstrap/write-runtime circular dependency;
- read-only workflow DAG compiler that routes mechanics to workflow providers while preserving MAD4B mutation authority;
- dependency-aware plugin lifecycle planner;
- exact plugin lifecycle state fingerprint;
- required/dependent plugin checks;
- protected plugin checks;
- lifecycle write binding to expected state SHA-256;
- lifecycle post-mutation readback evidence;
- workflow, lifecycle and operating-model abilities projected to read/ChatGPT surfaces;
- reusable release orchestration Skill;
- portable Skill mirror;
- CI contract/runtime guards;
- explicit operating-model coverage matrix.

Not certified by this change:

- Bit Flows create;
- Bit Flows enable/disable;
- Bit Flows retry/cancel;
- activation of the MAD4B Bit Flows Bridge against an uncertified provider-public contract;
- direct provider-internal database mutation;
- automatic production release.

Those stay fail-closed until provider-specific behavioral certification is supplied.

The inherited PR #15 ability `elementor/set-etg-dynamic-tag` remains a documented compatibility exception for the exact ETG repair lineage. New generic operating-model code contains no ETG hostname, post/template ID, taxonomy or business-domain identifier. The compatibility alias must be migrated after #15 without breaking exact rollback/readback semantics.

## 17. Remaining implementation/certification waves

### Wave A — close exact provider evidence

- recertify the exact installed Bit Flows runtime capability-by-capability;
- map only public/provider-supported lifecycle operations;
- add disposable behavioral tests for each promoted capability;
- keep unsupported operations explicit and unavailable.

### Wave B — promote provider-public Bit Flows bridge

- implement the external/addon bridge only after public hooks/API/Custom App surfaces are verified;
- keep MAD4B as source of truth, policy and authority;
- use Bit Flows only for wait/retry/routing/scheduling/integration mechanics;
- never use direct provider database mutation as a substitute.

### Wave C — persist richer evidence-graph projections

- connect existing signed/provider/runtime receipts to the generic dependency graph;
- add freshness/expiry query APIs where evidence source semantics support them;
- keep invalidation dependency-scoped rather than pipeline-wide.

### Wave D — domain migrations and reusable reconcilers

- migrate inherited ETG-specific compatibility primitives out of generic adapters after #15 settles;
- bind template reconciliation to semantic roles and ownership contracts;
- continue promoting Browser assertion schemas without making the browser/provider an authority.

## 18. Definition of done

The model is complete when:

- adding a new site mostly means Site Profile + contracts;
- adding a provider means adapter + capability certification;
- adding a workflow provider means implementing the same provider-neutral contract;
- Skills contain reasoning and orchestration, not provider internals;
- every write has an exact plan/target/current-state authority chain;
- every successful mutation has readback evidence;
- every high-risk failure has a known rollback or explicit unresolved-state receipt;
- stale evidence is invalidated by dependency rather than rerunning the entire system;
- no production decision is implicit;
- no ETG-specific identifier is required by the generic core.
