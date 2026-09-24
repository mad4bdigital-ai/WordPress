# Contract — Correctness, Consistency and Idempotency

Contract: mad4b.correctness-consistency.v1

## Transaction invariants

For durable local state, a logical transition that changes ContentJob state/stage and emits its corresponding JobEvent MUST be atomic.

If an artifact becomes the current artifact of a job, the job reference and artifact identity/edge MUST not expose a half-committed state.

Where the database supports transactions, transaction boundaries are explicit. Where an external side effect is involved, use durable intent/evidence rather than pretending one distributed transaction exists.

## Optimistic concurrency

Mutable aggregate roots carry revision/version numbers.

Every write plan includes expected_revision or equivalent expected-state fingerprint.

Stale revision:
- no mutation;
- stable conflict reason code;
- fresh read required.

No last-write-wins for authority, job state, publishing state or provider certification.

## Idempotency

Every externally retryable write has an idempotency key scoped by:
- site/tenant;
- capability;
- logical operation;
- target identity.

Repeated same key + same request hash returns the original result/evidence where safe.

Same key + different request hash is a hard conflict.

Idempotency retention is longer than the maximum retry/replay horizon of the operation.

## Delivery semantics

Do not claim exactly-once across networks/providers unless the provider contract proves it.

Default:
at-least-once delivery + effect-once mutation through idempotency/deduplication/readback.

## Inbox/outbox

For asynchronous external execution:
- durable outbox records intent before delivery;
- inbox records accepted provider callback/event IDs;
- duplicate event IDs are ignored or return prior result;
- callback ordering is checked when order matters.

## Canonical hashing

Fingerprints use canonical serialization:
- stable field ordering;
- normalized UTF-8/Unicode policy;
- explicit null handling;
- normalized booleans/numbers;
- UTC timestamps with declared precision;
- no volatile fields unless intentionally part of identity.

Canonicalization version is recorded with the hash.

## Time

Persist absolute timestamps in UTC.
Use monotonic timers for durations/timeouts when available.
Security expiry allows only configured bounded clock skew.

## Referential integrity

No orphan job-current-artifact, artifact edge, approval, provider-certification or release-ring references.

Deletion/retention uses tombstones or preserved hashes where audit history requires identity.

## Concurrency tests

At minimum:
- two writers same job revision;
- duplicate workflow callback;
- retry after network timeout where provider may have succeeded;
- cancel racing with execute;
- publish racing with target edit;
- provider certification invalidation racing with execution.
