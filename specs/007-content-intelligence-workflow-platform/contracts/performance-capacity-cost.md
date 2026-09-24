# Contract — Performance, Capacity, Backpressure and Cost

Contract: mad4b.performance-capacity-cost.v1

## SLO profiles

Do not hardcode one universal target.

Each environment/use-case may define:
- API/tool p50/p95/p99 latency;
- job stage latency;
- workflow start latency;
- queue age;
- provider error rate;
- success/retry rate;
- publish verification latency;
- maximum artifact/payload size;
- DB query/time budget;
- memory/time ceiling;
- concurrency;
- availability target.

A release records the SLO profile used.

## Capacity

Capacity tests include expected and burst workload:
- concurrent ContentJobs;
- artifact writes;
- research calls;
- workflow executions;
- webhook callbacks;
- publishing operations.

## Backpressure

Queue depth, provider concurrency and external API quotas are bounded.
When saturated, system degrades explicitly instead of exhausting PHP workers/DB connections/memory.

## Database

Critical read/write paths are profiled for indexes and N+1/query amplification.
Large artifacts are not repeatedly loaded when metadata/reference is sufficient.

## Cache

Cache keys include site/tenant/version/policy dimensions required for correctness.
Invalidation rules are contract-tested.
Cache is never source of authority when stale data could widen permission.

## Cost

Cost budget may be defined:
- per job;
- per stage;
- per provider;
- per model;
- per day/site/tenant.

Before expensive fan-out, estimate/bound maximum calls where practical.

Budget exhaustion is a stable blocked state, not uncontrolled partial execution.

## Performance regression

Provider/model/schema changes that materially affect latency/cost trigger comparative benchmark evidence.
