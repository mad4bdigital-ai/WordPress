# Activation Autopilot and Task-Ready Convergence

## Goal

Activation must start a bounded, self-healing preparation lifecycle instead of requiring operators to manually discover the site, reconcile safe runtime state, and retry transient convergence failures.

The target operator experience is:

```
Activate plugin
→ connect the governed client
→ request a task
```

The Control Plane remains responsible for discovery, safe convergence, readback, and truthful blocker classification.

## Lifecycle

```
ACTIVATED
  → BOOTSTRAPPING
  → CONVERGING
  → READY
```

A true privileged or unsafe dependency is represented as a governed gate, not as a generic bootstrap failure.

Transient infrastructure failures must not become permanent manual gates.

## Automatic work

Activation may automatically:

- establish the existing safe connection/bootstrap surfaces;
- install or upgrade the Control Plane schema through the existing idempotent schema contract;
- reconcile MAD4B-managed Skill seeds and provider Skills when enabled;
- queue safe runtime convergence on Staging;
- retry bounded transient convergence lock/scheduling races;
- discover the existing site content model read-only;
- return partial bounded discovery evidence when an individual content item is unreadable;
- report provider and MCP peer risks before task execution.

## Explicit authority gates

Activation must not automatically:

- enable Production mutation;
- enable Breakglass or raw SQL;
- create Developer/Developer Breakglass authority;
- auto-certify third-party provider writes;
- widen grants merely because a new build exposes a new mutation;
- bypass exact approval or owner-bound reconciliation.

Reviewed stale exact Staging grants may be retired as authority narrowing inside the exact reconciliation transaction. Unknown stale grants remain fail-closed.

## Runtime identity

A complete exact on-disk runtime identity is sufficient to declare the local deployment phase ready when no cached release target exists.

Absence of a cached update manifest means update freshness is unknown. It must not falsely mean the currently loaded runtime identity is unknown.

## Transient recovery

The following convergence failures are retryable and bounded:

- `mad4b_runtime_convergence_busy`
- `mad4b_runtime_convergence_lock_failed`

Retry count is capped. Exhaustion becomes an explicit operator gate.

Cron scheduling is postcondition-based: if another request wins the scheduling race, the presence of the scheduled hook counts as success.

Activation checkpoint persistence is verified by readback before scheduling. If the checkpoint cannot be proven durable, Autopilot reports `checkpoint_persist_failed` and does not claim scheduled progress. A `pending_manual_resume` checkpoint with a concrete resume blocker projects `autopilot_state=gated`, never `bootstrapping` or `converging`.

## MCP peer truth

Write-runtime certification and mutation authorization must consume the same peer-governance truth.

If a write-capable or unreviewed MCP side channel exists, certification must report:

- `peer_inventory_ready=true` when inventory succeeded;
- `peer_write_side_channel_absent=false`;
- `peer_governance_safe=false`;
- blocker `mcp_write_side_channel_detected`.

Certification must never claim READY when the subsequent mutation guard would deterministically reject the same request.

## Existing-site discovery

A fresh installation is allowed to discover the site before a governed Site Profile exists. When the portable read-only connection is effective, discovery uses its deterministic origin-bound connection UUID as a non-authorizing observation identity. This identity never creates or substitutes a governed Site Profile and cannot enable write authority.

One malformed or provider-specific content item must not abort the full inventory snapshot.

The snapshot returns:

- all readable bounded items;
- bounded item-level error evidence;
- `complete=false`;
- blocker `inventory_items_partially_unreadable`.

Database query failure remains a hard structured error because inventory truth is then unknown.

## Exact grant convergence

Stale exact Staging allows are divided into two classes:

1. Reviewed stale pair: ability/provider pair remains in the code-reviewed reconciliation universe but is absent from the current runtime write inventory. It may be retired as monotonic authority narrowing.
2. Unknown stale pair: not in the reviewed universe. Reconciliation fails closed.

Stale retirement is not rolled back after later failure, because restoring stale authority would widen access again. Persisted authority is fail-closed until a fresh exact reconciliation succeeds.

## Production

Production remains observe/propose only for this activation autopilot. No Production mutation authority is introduced by this feature.
