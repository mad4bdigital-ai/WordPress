# Contract — Decommission, Export and Portability

Contract: mad4b.decommission-portability.v1

## Purpose

Allow a site, provider, feature family or entire Content OS deployment to be retired or moved without orphaning authority, evidence or user content.

## Decommission scopes

- provider adapter;
- workflow provider;
- AI/research provider;
- site;
- tenant/business;
- Content OS module;
- Host Connector;
- authority;
- entire MAD4B installation.

## Preflight

Inventory:
- active jobs;
- queued/DLQ work;
- artifacts/blobs;
- provider executions;
- credentials;
- grants/approvals;
- scheduled cron/workflows;
- publications;
- evidence/attestations;
- external callbacks/webhooks;
- storage/indexes.

## Quiesce

Stop new work, drain or cancel bounded in-flight work, and define treatment for late callbacks.

## ExportBundle

Portable bundle manifest includes:
- schema/contract versions;
- jobs/events;
- artifact metadata;
- blobs or external refs;
- lineage;
- writer profiles;
- context/source references;
- certification/evidence where exportable;
- content inventory/intent registry;
- checksums.

Secrets/private keys are excluded by default and require a separate secure migration process.

## Provider removal

Before provider removal:
- stop resolver selection;
- revoke/rotate credentials;
- disable incoming webhooks/MCP side channels;
- preserve required execution history/evidence;
- remap or retire workflows.

## Site removal

Site decommission defines:
- whether published WordPress content remains;
- which local MAD4B tables/files are removed;
- what audit/tombstone is retained;
- central registry cleanup;
- Host Connector cleanup;
- authority subject mappings/revocation.

## Import

Importer validates:
- bundle integrity;
- compatible contract versions;
- site/tenant remapping;
- collisions;
- missing external refs;
- data-classification constraints.

Import never silently recreates grants/Production authorization.

## Final verification

Decommission completes only after:
- no unexpected scheduled work;
- no active credentials/webhooks;
- no unresolved in-flight writes;
- required evidence retained/exported;
- final report generated.

## Tool execution decommission

Decommission inventory additionally includes:
- registered ToolOperation definitions;
- executor profiles;
- Host Runner deployments;
- runner queues/spools/leases;
- provider CLI/API credential bindings;
- SSH/recovery trust material;
- scheduled runner cron/service entries;
- local workspaces/package staging roots;
- tool-specific evidence retention.

Provider/channel removal MUST:
- stop resolver selection;
- reject new jobs;
- drain/cancel bounded work;
- revoke credentials;
- remove scheduled runners;
- quarantine or export pending/dead-letter work;
- preserve receipts required for audit.

Switching hosting providers SHOULD preserve semantic operation IDs and normalized result contracts; only adapter/profile mappings should change.
