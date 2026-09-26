# Contract — Capability Traits and Provider Semantic Profiles

Contract: mad4b.capability-traits.v1

## Problem

A boolean capability such as workflow.execute does not prove two providers have equivalent semantics.

## CapabilityProfile

A provider capability declaration includes semantic traits such as:
- capability_id/version;
- idempotency model;
- cancellation: none|best_effort|verified;
- resume: none|checkpoint|native;
- durable_wait support;
- retry semantics: same_execution|new_execution|provider_defined;
- ordering guarantees;
- max runtime;
- max payload;
- callback model;
- execution-history retention;
- concurrency model;
- compensation support;
- local/remote execution;
- data residency/security traits;
- evidence strength.

## Resolver

Provider Resolver matches RequiredCapabilitySet against required trait constraints, not only capability presence.

Example:
required workflow.execute + durable_wait=true + cancel=verified
cannot select a provider that only supports fire-and-forget execute.

## Conformance

Conformance tests validate declared traits.
A provider failing a claimed trait loses eligibility for requirements needing that trait without necessarily losing unrelated capability eligibility.

## Plan binding

Selected CapabilityProfile fingerprint is part of the execution plan so changing semantic traits invalidates stale plans.

## Tool executor traits

Tool executor mappings extend semantic traits with:
- executor_kind;
- requires_wordpress_boot;
- local_or_remote;
- synchronous_or_async;
- interactive_tty;
- filesystem_zones;
- network_scope;
- secret_access_class;
- process_spawn_policy;
- timeout/output bounds;
- cancellation;
- idempotency;
- recovery availability;
- Production eligibility.

A semantic operation can require traits such as:
`requires_wordpress_boot=false`,
`interactive_tty=false`,
`recovery_available=true`.

Resolver selection is based on these traits plus certification; executable/CLI presence alone is insufficient.
