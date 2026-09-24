# Contract — Fair Scheduling, Quotas and Control-Plane Availability

Contract: mad4b.fairness-local-autonomy.v1

## Purpose

Prevent one tenant/site/provider/job class from exhausting shared workers, APIs or budgets, while defining safe behavior when central services are unavailable.

## Scheduling classes

Work declares:
- tenant/site;
- priority class;
- job type;
- estimated cost;
- provider;
- deadline optional;
- concurrency key;
- fairness weight.

## Quotas

Configurable quotas:
- concurrent jobs;
- queued jobs;
- provider calls;
- model tokens/cost;
- artifact storage;
- workflow executions;
- publish mutations;
- Host operations.

Quota may be per tenant/site/provider/time window.

## Fairness

Scheduler SHOULD support weighted fair allocation and anti-starvation.

High-priority work may preempt queued low-priority work but MUST NOT bypass authority or hard quotas unless explicit emergency policy exists.

## Reservations

Critical operational lanes MAY reserve limited capacity for:
- incident repair;
- authority health;
- rollback;
- publication verification.

## Central service outage

Distinguish dependencies:
- central certification registry;
- external authority;
- central provider resolver/catalog;
- telemetry/analytics;
- site-local Control Plane.

For each dependency define:
- cached data allowed;
- TTL;
- reads allowed;
- writes allowed;
- new privilege/provisioning allowed;
- fail-open/closed rule.

## Default local autonomy

When central governance evidence is unavailable:
- already signed/unrevoked evidence MAY remain usable within explicit TTL if policy permits;
- no new broad grant/certification/promotion is created;
- high-risk Production/Host/Developer operations fail closed unless independent local policy explicitly authorizes and evidence is current;
- ordinary safe reads may continue.

## Recovery

On reconnection:
- refresh trust/revocation;
- reconcile queued decisions;
- detect stale local actions;
- emit drift evidence.

## Noisy-neighbor tests

- one tenant fills research queue;
- one provider rate-limits;
- one site creates massive artifact fan-out;
- emergency recovery lane remains available;
- low-weight tenant eventually receives service.
