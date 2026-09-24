# Contract — Tenant Isolation, Privacy and Retention

Contract: mad4b.tenant-privacy.v1

## Isolation

Every durable row/object/cache key/provider binding includes the necessary tenant/site scope.

Queries do not rely on caller convention alone; repository/service layers enforce scope.

Cross-tenant/site joins require an explicit governed aggregation capability.

## Negative isolation tests

At minimum:
- same artifact ID shape across two sites;
- same external subject under two issuers/sites;
- cache key collision;
- provider credential lookup;
- workflow callback with wrong site;
- Host Connector target mismatch;
- search/index lookup scoped to wrong site.

Expected result: no data disclosure or mutation.

## Data classification

Classify payloads:
- public;
- internal;
- confidential;
- credential/secret;
- personal data;
- audit/security evidence.

Classification determines storage, redaction, retention and export policy.

## Minimization

Store only data required for product/governance purpose.
Do not copy full source documents into every artifact when references/excerpts suffice.

## Retention

Retention profiles are versioned by artifact/evidence type.

Expiry of large payload may preserve:
- identity;
- hash;
- minimal provenance;
- deletion/tombstone reason
when audit policy requires it.

## Erasure/export

Privacy/legal deletion and export are explicit governed workflows.
Deletion must account for derived artifacts and caches without falsifying audit history.

## Encryption

Use platform/provider encryption at rest/in transit where available.
Application-level encryption is required only for fields/payloads whose threat model demands it and must have key-rotation design.
