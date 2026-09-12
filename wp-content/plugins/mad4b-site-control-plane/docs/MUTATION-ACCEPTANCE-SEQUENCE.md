# MAD4B Mutation Acceptance Sequence

This document is the canonical operator/developer sequence for the governed Staging Mutation Acceptance cycle.

Scope: `staging.egypttourgates.com` only. Production and Breakglass remain fail-closed.

## Sequence map

```mermaid
sequenceDiagram
    autonumber
    participant C as ChatGPT / MCP client
    participant P as approval-plan
    participant H as Human approver
    participant A as Central authorization
    participant W as Governed write ability
    participant M as Mutation evidence store
    participant R as Live Acceptance Reconciler

    C->>P: Plan exact mutation
    P->>A: Resolve exact target + payload identity
    A-->>P: Pending candidate-bound ticket
    P-->>C: ticket_id / payload_sha256 / target_fingerprint

    C-->>H: Stop at human approval boundary
    H->>P: Approve reviewed pending ticket

    C->>A: Execute exact operation with one-time ticket
    A->>A: validate_exact + claim_exact
    A->>W: Execute once
    W->>M: Persist mutation + before/after evidence
    W-->>A: Verified result
    A->>A: Finalize ticket as used
    A-->>C: Mutation result

    C->>A: Replay the same used ticket once
    A-->>C: DENY mad4b_approval_replay_denied

    C->>P: Plan mad4b/mutation-undo
    Note over P,A: Undo authorization identity is canonicalized from mutation identity.
    Note over P,A: Operator reason text is audit metadata, not approval identity.
    P->>A: Resolve immutable undo target fingerprint
    A-->>P: New pending candidate-bound undo ticket

    C-->>H: Stop at second human approval boundary
    H->>P: Approve undo ticket

    C->>A: Execute mad4b/mutation-undo once
    A->>A: validate_exact + claim_exact
    A->>W: Restore recorded before-state
    W->>M: Persist recovery mutation + read-after-restore evidence
    W-->>A: Verified restored state
    A->>A: Finalize undo ticket as used
    A-->>C: Undo result

    C->>R: Read live-acceptance-status
    R->>M: Reconstruct execute → replay-denial → undo pair
    R-->>C: Mutation Acceptance PASS only if full exact-bound cycle is durable
```

## Canonical undo authorization identity

`mad4b/mutation-undo` is special because its human `reason` text may legitimately differ between planning and execution. That text must not change the approval target or canonical approval payload.

The authorization-facing input is normalized to:

```text
{
  mutation_id: <exact mutation id>,
  reason: "governed_mutation_undo"
}
```

The actual operator-provided reason is preserved request-locally and restored only for the mutation callback/audit intent.

The undo target fingerprint is bound to immutable mutation evidence:

```text
contract = mad4b.undo-authorization-target.v1
ability = mad4b/mutation-undo
provider = core
mutation_id
original_ability
original_provider
target_type
target_id
after_sha256
rollback_payload_sha256
```

This means changing the operator wording does not break a valid reviewed undo plan, while changing the referenced mutation or immutable after/rollback evidence changes the target fingerprint and fails closed.

## Human boundaries

The flow has two independent human approval boundaries:

1. The forward mutation ticket.
2. The undo ticket.

Approval of the first does not authorize replay, undo planning, undo approval, or undo execution.

## Required durable evidence

The acceptance cycle is complete only when the reconciler can prove all of the following for the same exact candidate/build:

- execution ticket exists and is candidate-bound exactly;
- forward mutation event exists;
- the one-time ticket replay was denied;
- undo ticket exists and is candidate-bound exactly;
- recovery mutation exists and points to the original mutation;
- restored state equals the recorded before-state;
- audit chain is valid;
- mutation/recovery pair is valid.

## Fail-closed rules

Never bypass or hand-supply a target fingerprint to work around a mismatch.

If the candidate SHA or build fingerprint changes, create a new approval plan. Old candidate-bound tickets are stale by design.

If current target state no longer matches the recorded after-state, automatic undo must refuse to overwrite newer work.

Production auto-enable, Breakglass auto-enable, raw SQL exposure, and wildcard grants are outside this acceptance path and must remain disabled/absent.
