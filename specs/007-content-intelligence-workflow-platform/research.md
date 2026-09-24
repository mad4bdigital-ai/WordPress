# Research — Feature 007

## Research objective

Validate which planned components already exist in rc.59, identify true gaps, and choose abstractions that avoid rebuilding platform primitives or coupling the new domain layer to one vendor.

## Baseline findings

### R1 — rc.59 is the correct development lineage, but it is not canonical yet

At feature inception the active integration head is ab179816c03acb45751c1707eddee50d19178298 on PR #47. The default master line is older. A prior rc.59 convergence PR #45 is closed/unmerged and has a divergent tail.

Decision:
- Feature 007 specification may branch from the current rc.59 integration head.
- Large runtime implementation waits for semantic reconciliation and canonical merge.
- The spec branch targets the integration line, not master.

### R2 — PR #45 semantic reconciliation is mandatory

The PR #45 head b5d697f05a939d87ca0b2ede1a08eb9e15f97312 and PR #47 head ab179816... diverge from merge base f43ec3be....

Observed compare state at spec creation:
- PR #47 line ahead of PR #45 by 469 commits.
- PR #47 line behind PR #45 by 10 commits.

Unique PR #45 commits:
1. 0313e03aad8ee081c770ff9a31016202979e59e2 — transplant Developer and Full Staging Authority lineage
2. a533de8ae815e78fe43f9a1b74d8db909272dfb9 — developer impact policy
3. 5ea91a70cc5750689f29ed4571bdab53149f5cfb — plugin package and AI review grant authority
4. b0f2c8a6a0849303df94f7f4b84c1a42ef3d4d9d — rc.59 convergence release marker
5. 93f99fbf5a5d9b94339b39b478f56376f8924912 — OAuth developer resources
6. 218bf660bd7a4d24ed2a4086640ea73a1ed9c9d6 — developer planes + hotpath convergence
7. 173985efded8f53279e864bcc48f4236562c0897 — runtime build evidence
8. 8775f56cc25cf66e6c22b091a3af2bc00e57ad00 — nine-server topology docs
9. ced93ea7148f4b8adc74499cd0df42fdf350c4ba — live acceptance handoff
10. b5d697f05a939d87ca0b2ede1a08eb9e15f97312 — convergence merge

Decision:
Do not cherry-pick blindly. Produce semantic classifications with replacement evidence. Only REQUIRED changes are ported.

### R3 — Existing Control Plane is already the kernel

Repository/runtime review shows existing primitives for:
- identity and OAuth;
- NHI grants;
- approvals;
- budgets;
- audit;
- rollback;
- filesystem;
- structured database;
- raw SQL Breakglass;
- Developer runtime and Developer Breakglass;
- provider discovery/certification;
- plugin lifecycle/package governance;
- Dynamic Skills;
- Context Authority;
- Google Drive context ingestion;
- WordPress/plugin adapters.

Decision:
Feature 007 adds a domain layer and generic provider contracts. It does not create another kernel.

### R4 — Workflow provider abstraction already exists

Current rc.59 contains:
- config/workflow-provider-contracts.json;
- MAD4B_SCP_Workflow_Providers;
- Bit Flows adapter;
- generic workflow plan with immutable plan digest;
- capability-aware provider readiness;
- workflow compiler behavior separating workflow-provider mechanics from MAD4B mutation executor.

Current Bit Flows mappings include:
- list flows;
- get flow;
- execution history;
- run flow.

Current unavailable operations include create, enable, disable, retry and cancel.

Decision:
Extend the existing provider contract. Do not add an independent "MAD4B Bit Flows Bridge" authority path.

### R5 — Capability-level certification already exists

Current provider capability contracts include Bit Flows read and execution capabilities.

Decision:
Generalize capability status semantics and extend the catalog rather than add a second certification system.

### R6 — Exact certified Bit Flows baseline is older than the observed runtime

Repository certification profile currently pins Bit Flows 1.24.0. The observed Staging runtime supplied for this work is 1.29.0.

Therefore:
- 1.29.0 is not trusted merely because it is installed.
- Downgrade is not the default remedy.
- Recertification must inspect the exact 1.29.0 package and capability behavior.

### R7 — "latest upstream" and "certified runtime" must be separated

Public WordPress.org changelog research on 2026-09-24 shows Bit Flows 1.30.0 released 2026-09-20, while the target runtime discussed for certification is 1.29.0.

This proves that a field named target_certified_version must mean exact reviewed runtime candidate, not latest public release.

Decision:
Use independent facts:
- installed_version;
- observed_package_digest;
- certified_version;
- upstream_latest_version (informational);
- upgrade_available;
- capability certification states.

No automatic upgrade or downgrade follows from these facts alone.

### R8 — Bit Flows 1.28+ introduces MCP-server side-channel risk

Public changelog states that Bit Flows became an MCP server in 1.28 and added authenticated webhook/IP-restriction behavior. 1.29 added webhook-response and array transformation features.

Decision:
Recertification MUST inventory provider-native MCP exposure and ensure it cannot become an independent privileged write path outside MAD4B governance.

Preferred topology:
ChatGPT → official WordPress MCP Adapter → MAD4B → WorkflowProvider adapter → Bit Flows runtime.

### R9 — Google Drive ingestion exists; job-specific dispatch does not

Existing Context Authority provides source/asset registry, normalization, quality/review state, and lineage.

Missing:
- job requirements resolver;
- knowledge-class selection;
- job-specific bounded ContextPack;
- dependency invalidation into content artifacts.

Decision:
Build a provider-neutral Knowledge Dispatcher over Context Authority.

### R10 — Content Intelligence domain objects are missing

No durable ContentJob/state-stage/artifact pipeline currently represents the complete proposed domain.

Decision:
Introduce ContentJob plus generic immutable ContentArtifact envelope. Prefer fewer generic tables plus typed payload contracts over one database table per artifact type.

### R11 — Writer Profiles should be distilled artifacts

Repeatedly attaching large writer corpora is inefficient and weakens deterministic job identity.

Decision:
Distill source assets into versioned WriterProfile artifacts. Jobs reference exact versions.

### R12 — Research vendors must be substitutable

Keyword, SERP, general web search and page extraction have different semantics.

Decision:
Use four interfaces:
- KeywordProvider
- SERPProvider
- SearchProvider
- ScrapeProvider

One vendor may implement multiple interfaces. Domain jobs depend only on normalized outputs.

### R13 — Competitive intelligence should be explicit artifacts

Prompt-only competitor analysis is difficult to inspect, compare, invalidate or reproduce.

Decision:
Persist SERPSnapshot, CompetitorSelection, ScrapedPage and coverage matrices, then produce InformationGainPlan.

### R14 — Existing media and SEO adapters should be reused

Rank Math and media abilities already exist under MAD4B authority.

Decision:
The content pipeline emits MediaManifest and SEOQA/metadata intent, then invokes existing governed capabilities. It does not write plugin tables directly.

### R15 — Unknown plugin support must remain staged

A goal like "control anything in WordPress" must not mean arbitrary PHP.

Decision:
Unknown provider path remains:
Discovery → Contract Inspection → Safe Read → Mutation Certification → Reversible Adapter.

### R16 — WP Import/Export and deep JetSmartFilters are separate provider-completion work

They are relevant to broad site automation but are not prerequisites for the first Content Intelligence vertical slice.

Decision:
Track them as later capability-certification workstreams, not blockers for Content Job MVP.

### R17 — Host operations require a separate connector

WordPress PHP filesystem scope and Developer planes do not represent hosting-account authority.

Decision:
Create a generic HostConnector contract and provider adapters. Keep it out of the first content MVP unless a concrete job requires host-level behavior.

### R18 — Cron should be a generic semantic family

WordPress cron and host cron have overlapping concepts but different providers and authority.

Decision:
Define generic cron capabilities and provider implementations, with read first and mutation later.

### R19 — Growth feedback is post-MVP

Search performance and content decay require published content and stable identities.

Decision:
Define contracts now, implement after publication vertical slice.

## Generalization decisions

1. Use semantic capability IDs that survive provider replacement.
2. Use generic artifact envelope with type-specific schemas.
3. Use tenant/site scoping everywhere authority or evidence may cross site boundaries.
4. Separate source facts from model analysis.
5. Separate plan generation from execution authority.
6. Separate orchestration state from business-domain job state.
7. Separate provider runtime observation from provider certification.
8. Separate installed version from upstream available version.
9. Separate WordPress authority from host authority.
10. Separate research freshness from artifact version.
