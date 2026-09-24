# Contract — Dependency Invalidation and Incremental Recompute

Contract: mad4b.incremental-recompute.v1

## Purpose

Use artifact lineage to recompute only the minimum affected subgraph after source, policy, model or provider changes.

## Dependency classes

Each dependency edge declares:
- HARD_CONTENT;
- HARD_POLICY;
- SOFT_CONTEXT;
- QUALITY_ONLY;
- OBSERVATIONAL;
- NON_INVALIDATING_REFERENCE.

## Change event

A change includes:
- changed subject/artifact/policy/provider/model;
- old fingerprint;
- new fingerprint;
- change class;
- timestamp;
- reason/evidence.

## Recompute planner

Produces:
- invalidated artifacts/gates;
- preserved artifacts with rationale;
- earliest affected stages;
- recompute order;
- expected fan-out;
- estimated provider/cost budget;
- coalescing key;
- plan_sha256.

Plan is non-authorizing.

## Minimality

If a WriterProfile changes, unrelated SERPSnapshot need not be recomputed.
If SERP research changes, a reusable WriterProfile remains valid.
If a hard brand/context source changes, blueprint/draft/QA may become stale.
If only observability metadata changes, content artifacts remain valid.

## Coalescing

Multiple changes inside a configured debounce window MAY coalesce into one recompute plan to avoid thrash.

## Fan-out guard

If invalidation would affect more than configured job/artifact thresholds, require review or staged execution.

## Freshness

Dependencies can have max-age/freshness policy.
Staleness can invalidate a gate without deleting the underlying artifact.

## Loop prevention

Recompute-triggering events carry causation/correlation IDs.
A derived artifact update cannot recursively schedule itself indefinitely.

## Tests

- single leaf change;
- root context change;
- model version change;
- provider research refresh;
- policy change;
- coalesced changes;
- huge fan-out;
- dependency cycle detection/handling.
