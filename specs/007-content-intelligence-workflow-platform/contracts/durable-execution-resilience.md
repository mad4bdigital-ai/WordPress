# Contract — Durable Execution, Resilience and Backpressure

Contract: mad4b.durable-execution.v1

## Work ownership

Long-running work uses a lease:
- work item/job ID;
- worker/owner ID;
- lease revision;
- acquired_at;
- expires_at;
- heartbeat_at.

Expired lease may be reclaimed only after durable state/readback proves the previous owner is no longer authoritative.

## Retry taxonomy

Errors are classified:
- TRANSIENT_NETWORK
- RATE_LIMITED
- PROVIDER_UNAVAILABLE
- AUTHENTICATION
- AUTHORIZATION_POLICY
- VALIDATION
- CONFLICT_STALE_STATE
- PERMANENT_PROVIDER
- SECURITY
- UNKNOWN

Retry is allowed only for configured retryable classes.

## Retry policy

Retry policy declares:
- max attempts;
- max elapsed time;
- exponential/other backoff;
- jitter;
- Retry-After support;
- per-provider and per-job retry budget.

AUTHORIZATION_POLICY, VALIDATION, SECURITY and unknown irreversible-state errors do not blindly retry.

## Crash recovery

After process/PHP/worker restart:
- RUNNING work with expired lease is inspected;
- external provider execution is read back before reissuing;
- irreversible step is never repeated without evidence it did not complete;
- resume uses explicit checkpoint/artifact revision.

## Circuit breakers and bulkheads

External providers have independent:
- concurrency limit;
- timeout;
- queue limit;
- circuit state;
- failure/latency window.

One failing provider cannot consume all workers or block unrelated capabilities.

## Backpressure

When queue/provider capacity is exceeded:
- accept/queue only within configured durable limits;
- return explicit saturation reason;
- pause lower-priority work;
- never create unbounded in-memory queues.

## Cancellation

Cancellation is a state transition, not deletion.

If provider supports cancel:
request governed cancel and verify.

If provider cannot cancel:
mark cancellation requested, stop downstream work and quarantine/ignore late outputs unless policy explicitly reconciles them.

## Multi-step side effects

Use saga/compensation semantics.

Every irreversible step declares:
- point of no return;
- compensation if available;
- recovery/readback;
- operator escalation if compensation is impossible.

## Fallback

Provider fallback is allowed only through Provider Resolver when:
- semantic capabilities are equivalent;
- required evidence/certification exists;
- data/residency/security policy permits;
- plan is regenerated if provider choice changes execution semantics.

Fallback never silently changes authority or output-quality contract.
