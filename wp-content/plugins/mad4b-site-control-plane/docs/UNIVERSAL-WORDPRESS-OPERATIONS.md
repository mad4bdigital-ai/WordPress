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
