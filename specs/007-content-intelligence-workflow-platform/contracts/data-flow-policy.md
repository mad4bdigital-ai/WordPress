# Contract — Data Flow, Residency and Processor Policy

Contract: mad4b.data-flow-policy.v1

## Scope

Data governance applies beyond AI providers to:
- ArtifactStore;
- backups;
- telemetry/audit;
- RetrievalIndex/vector search;
- WorkflowProviders;
- Research/Scrape providers;
- Host Connector;
- central certification/management services;
- AI/model providers.

## DataFlowPolicy

For each data class/tenant/site:
- allowed processors/providers;
- origin region;
- allowed storage/processing regions;
- cross-region transfer policy;
- retention;
- encryption requirements;
- indexing permissions;
- backup locations;
- external subprocessor policy;
- deletion/export obligations.

## Flow decision

Before sending/storing data at a processor:
classification + destination profile + tenant/site policy
→ ALLOW | REDACT_THEN_ALLOW | LOCAL_ONLY | REQUIRE_APPROVAL | DENY.

## Derived data

Embeddings, indexes, summaries and telemetry derived from sensitive source inherit a declared sensitivity classification and deletion relationship.

## Provider Resolver

Resolver treats data-flow constraints as hard requirements.

## Evidence

Data-processing decisions reference policy version and processor identity without persisting raw secrets.
