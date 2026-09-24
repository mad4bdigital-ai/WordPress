# Contract — Gate DAG, Bootstrap Transitions and Liveness

Contract: mad4b.gate-liveness.v1

## Gate graph

Every hard gate declares:
- gate_id;
- dependencies[];
- produced evidence/state;
- environment applicability;
- bootstrap exception/transition if any;
- blocking reason codes.

The hard dependency graph MUST be acyclic after explicit bootstrap transitions are modeled.

## Liveness

Security fail-closed is insufficient by itself.
The platform MUST prove that a legal path exists from an initial state to each intended terminal operational state.

Examples:
- unenrolled → enrolled;
- provider unknown → read-certified → write-certified;
- candidate unbound → bound;
- artifact R0 → R1 canary;
- ContentJob NEW → verified DRAFT.

## BootstrapTransition

A bootstrap transition contains:
- transition_id;
- from_state;
- to_state;
- exact operation;
- why normal steady-state authority cannot yet exist;
- temporary prerequisite/evidence;
- one-shot or bounded-use semantics;
- expiry;
- postconditions;
- mandatory audit;
- rollback/recovery.

Bootstrap authority MUST be narrower than the steady-state authority it establishes.

## Cycle detection

CI validates:
- no unknown gate dependency;
- no hard cycles;
- every required terminal state has a path from a declared bootstrap/root state;
- no bootstrap transition recursively depends on its own postcondition.

## Minimal blocker set

Gate evaluation emits:
- decisive_blockers[];
- secondary_blockers[];
- minimal_unsatisfied_set[];
- next_safe_actions[];

This improves operator recovery without changing authority.

## No hidden exceptions

Ad-hoc code branches such as "if first run, allow" are forbidden unless represented by a versioned BootstrapTransition contract.
