# Universal WordPress Operations Framework — rc.83

rc.83 consolidates existing governed WordPress capabilities behind a provider-neutral discovery and planning layer. It does **not** add a generic remote shell, arbitrary operation IDs, arbitrary package URLs, raw SQL, approval bypass, or Production mutation.

## Goals

1. A client asks for a semantic operation such as update, install, activate, deactivate, import, export, provider reconciliation, or Skills reconciliation.
2. `mad4b/wordpress-operation-discover` resolves universal WordPress semantics to the exact registered planner and executor. The pre-existing `mad4b/operation-discover` Remote Operation Parity surface remains unchanged.
3. Plugin operations may use `mad4b/plugin-transaction-plan` to normalize install/replace/update/activate/deactivate planning.
4. Dependency and certification impact is projected before mutation.
5. Existing exact planners/executors remain the authority boundary.
6. After a disconnected or uncertain write, `mad4b/operation-resume-status` reads durable idempotency state and requires reconciliation before retry when effect is uncertain.

## Contracts

- `mad4b.operation-registry.v1`
- `mad4b.operation-discovery.v1`
- `mad4b.plugin-transaction-plan.v1`
- `mad4b.dependency-impact-graph.v1`
- `mad4b.provider-transport-registry.v1`
- `mad4b.operation-resume-status.v1`

## Provider transport isolation

Provider MCP/REST transport descriptors move to `config/provider-transport-registry.json`. Unknown routes/callbacks are not auto-suppressed or allowlisted; they remain visible to fail-closed Peer Governance.

Internal retention is explicit per descriptor. A retained route must match its declared provider and HTTP method before internal dispatch.

## Multisite lifecycle

Plugin lifecycle plans now include `activation_scope: site|network`. `site_active` is derived from the site `active_plugins` option rather than WordPress's effective `is_plugin_active()` result, while `network_active` is read separately. Site-scoped lifecycle mutation fails closed while network activation controls the plugin. Network mutations require `manage_network_plugins`.

## Durable reconnect semantics

A disconnected request is never treated as evidence that a mutation failed. Resume status reports one of the safe client actions:

- completed → consume the recorded receipt;
- pending and not expired → reconnect without replay;
- pending and expired → reconcile provider/runtime state before retry;
- released after verified no-effect reconciliation → re-plan, then retry.

The resume endpoint never replays mutation and never exposes stored result payloads.

## Compatibility

Existing abilities remain in place. rc.83 adds orchestration on top of them rather than replacing their authorization, approval, plan-digest, package-integrity, rollback, readback, or certification rules.

## Declarative dynamic pipelines

The operation registry is also the source of truth for semantic aliases, ChatGPT read projection and pipeline profiles. A pipeline profile can add or reorder reviewed stages without changing the mutation executor, but the registry fails closed unless the profile preserves the mandatory order:

`planner → authorization → executor → verification`

Supported stage types are bounded to read checks, planning, authorization, execution, verification and reconciliation. The read-only `mad4b/operation-pipeline-compile` ability resolves conditions and exact stage bindings, emits a `pipeline_sha256`, and never runs a mutation stage. Arbitrary callbacks and arbitrary stage execution remain disabled.

## Dynamic provider trust ladder

Installed plugins are classified from runtime evidence into L0 inventory, L1 generic lifecycle planning, L2 governed read, L3 certified reversible write candidate and L4 functionally ready certified governed candidate. Unknown plugins cannot become write-capable merely because they are installed or active. Adapter availability, side-channel isolation, reversible contracts and provider certification are required evidence, and the matrix never creates authority or automatically enables mutation.

## Default provider autopilot

Dynamic provider autopilot is enabled by default.

For Staging, development and local environments the effective mode is `shadow_auto`. Discovery automatically generates an in-memory adapter candidate and a shadow provider identity certification proposal for uncovered plugins. These candidates are deterministic and fingerprinted but are not written to disk or registered as executable adapters.

For Production the effective mode is always `observe_propose_only`.

Default automatic behavior:
- generate adapter candidate: enabled
- shadow provider certification: enabled
- materialize generated PHP: disabled
- register generated adapter: disabled
- write certification: disabled
- create authority: disabled
- enable mutation: disabled

Automatic candidate generation and shadow identity certification stop at L1/lifecycle. L2/read requires a registered runtime adapter with at least one bounded read ability and clear side-channel governance. L3/L4 additionally require governed promotion with reversible contracts, certification evidence, functional acceptance and the normal authorization boundary.
