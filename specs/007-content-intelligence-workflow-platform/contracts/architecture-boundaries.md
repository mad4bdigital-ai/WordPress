# Contract — Architecture Boundaries

Contract: mad4b.feature007.architecture-boundaries.v1

## Authority ownership

MAD4B owns:
- identity/NHI;
- grants/scopes;
- approvals;
- budgets;
- audit;
- rollback;
- provider certification;
- desired state;
- capability registry;
- quality/release gates;
- Production authorization.

Providers may own execution mechanics or source data, never platform authority.

## Generic/provider split

Generic domain:
- WorkflowProvider
- ContextProvider
- ResearchProvider
- SiteProvider
- PublishingProvider
- HostConnector
- SearchPerformanceProvider
- ContentJob
- ContentArtifact
- QualityGateDecision

Provider-specific adapters:
- Bit Flows
- Google Drive
- Rank Math
- Elementor
- JetEngine
- DataForSEO/Serper/etc.
- Firecrawl/etc.
- Hostinger/etc.

No vendor-specific field may become required in the generic ContentJob contract.

## Execution boundary

Workflow provider:
- route
- branch
- wait
- retry mechanics
- schedule
- webhook mechanics
- execution history

MAD4B:
- authorize
- certify
- approve
- mutate site/host state
- verify
- audit
- recover

A workflow step requesting a site mutation must call an exact MAD4B capability. It may not execute a hidden provider-local equivalent.

## Site/host boundary

WordPress authority covers only certified WordPress/plugin resources.

Host authority is separate for:
- outside-WP filesystem;
- OS/process/service state;
- SSH;
- hosting account;
- server logs;
- PHP/server config;
- backups;
- external databases/domains.

## Side-channel rule

Any independent write-capable MCP/API path that can mutate governed resources outside MAD4B is:
- disabled;
- isolated read-only;
- or explicitly federated.

Otherwise it is a hard blocker.

## Environment rule

Staging authority does not imply Production authority.
Production Developer shell/eval is denied by default.

## Governed Tool Execution Plane

The platform contains a generic Tool Execution Plane between semantic capabilities and concrete executors.

```text
Skill / Workflow / Operator
        ↓
semantic ToolOperation
        ↓
MAD4B authority + plan + policy
        ↓
Tool Executor Resolver
        ↓
WordPress service | WP-CLI | Host Runner | Provider API/CLI | Recovery Runner
```

The executor does not own authority and cannot reinterpret a semantic operation into a broader command.

### Frontend boundary

MCP, Admin UI, WP-CLI and standalone CLI are frontends. They MUST NOT implement independent authorization or mutation semantics.

### Host Runner boundary

Host Runner is outside the HTTP request lifecycle and MAY perform bounded host operations under a dedicated operating-system identity. It consumes signed/bound operation envelopes, not caller-provided command strings.

### Recovery boundary

Recovery Runner/transport is deliberately narrower than Host Execution. It remains capable of package/runtime health and known-good restore when WordPress is unavailable, but cannot inherit content publication or normal host-write authority.

### Provider channel boundary

Provider API, provider CLI, WordPress plugin integration and SSH are separate channels with separate certification. Side-channel rules apply to every write-capable channel.
