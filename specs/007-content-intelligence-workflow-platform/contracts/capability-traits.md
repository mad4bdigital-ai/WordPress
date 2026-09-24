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
