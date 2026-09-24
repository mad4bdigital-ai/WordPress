# Contract — Schema and Contract Evolution

Contract: mad4b.schema-contract-evolution.v1

## Versioning

Every persisted or externally exchanged contract has:
- contract name;
- schema_version;
- compatibility policy.

Applies to:
- ContentArtifact payloads;
- ContentJob events;
- workflow bridge requests/results;
- provider observations/certifications;
- quality decisions;
- authority bindings;
- release evidence.

## Evolution strategy

Prefer expand → migrate/backfill → verify → contract.

Additive changes precede destructive removal.

Readers SHOULD tolerate unknown additive fields unless security semantics require strict rejection.

Writers emit one explicitly declared current version.

## Database migrations

Every migration declares:
- migration ID;
- prerequisite schema identity;
- forward operation;
- rollback or forward-fix strategy;
- expected locks/downtime;
- data volume assumption;
- preflight;
- post-migration verification;
- checksum/evidence.

Destructive migration requires backup/restore rehearsal or a proven forward-fix.

## Mixed-version operation

When rolling deployment can expose old/new code simultaneously, compatibility window is explicit.

No deployment may require an impossible atomic upgrade across independent nodes unless maintenance mode is intentionally entered.

## Artifact evolution

Old artifact versions remain readable for audit/replay according to retention policy.

A new schema never silently reinterprets old payload bytes under the same schema_version.

## Policy/reason-code stability

Stable reason codes are not repurposed to mean different failures.

Policy versions are stored with decisions.

## Migration tests

- fresh install;
- upgrade from supported previous schema;
- repeated migration idempotency;
- rollback/forward-fix path;
- partial failure recovery;
- large-data fixture;
- mixed-version reader/writer compatibility where applicable.
