# Contract — Observability and Evidence

Contract: mad4b.observability-evidence.v1

## Correlation
Every job/workflow/provider/mutation path carries a correlation_id.

Where applicable also record:
- job_id
- artifact_id/version
- workflow plan SHA
- provider_id
- capability_id
- site_uuid
- NHI/actor public ID
- mutation ID
- approval ID
- build/source/package identity

## Event classes
- job transition
- artifact produced/invalidated
- provider request/result
- workflow execution
- quality-gate decision
- capability authorization decision
- mutation attempt/result/readback
- rollback/undo
- release/certification decision

## Metrics
Bounded metrics should include:
- stage duration
- provider latency
- retries
- rate-limit events
- token/usage/cost when available
- artifact sizes
- QA blocker counts
- publish verification duration
- failure/recovery counts

## Secret handling
Never expose in ordinary logs/status:
- OAuth/bearer/API secrets;
- raw passwords;
- unrestricted provider payloads containing secrets/PII;
- hidden system prompts;
- rollback payloads where policy marks them sensitive.

## Evidence integrity
Important evidence is:
- immutable or append-only;
- fingerprinted;
- tied to exact source/runtime identity;
- addressable from gate decisions.

## Explainability
Every fail-closed status provides:
- stable reason code;
- affected capability/stage/gate;
- evidence refs;
- non-secret next action.

A generic "blocked" state without reason is insufficient.
