# Contract — Audit, Telemetry and Retention Architecture

Contract: mad4b.audit-telemetry.v1

## Separation

AuditEvidence:
- authority/security/compliance relevant;
- append-only/immutable according to policy;
- strong identity/retention;
- may be attested.

OperationalTelemetry:
- metrics/traces/debug logs;
- optimized for observability;
- may be sampled/aggregated/expired;
- never authoritative for grants or certification.

BusinessDomainEvents:
- durable events required to explain aggregate/domain transitions;
- retention follows domain/audit policy.

These categories MUST NOT be conflated into one infinite log.

## Hot/cold tiers

Policy may define:
- hot searchable audit;
- cold archive;
- summarized telemetry;
- security hold.

## Write amplification

High-volume telemetry cannot share the same mandatory synchronous path if it risks blocking critical authoritative state.
Critical audit receipt requirements remain explicit.

## Partitioning

Implementations SHOULD partition/index by site/tenant/time/event class as scale requires.

## Redaction

Sensitive fields are structured/redacted before telemetry persistence.
Audit that must preserve sensitive evidence uses stronger classified storage rather than uncontrolled logging.

## Retention

Retention policy is explicit per class.
Compaction of telemetry never alters authoritative evidence/domain history.
