# MAD4B Platform Constitution — Feature 007

Feature: Content Intelligence + Governed Workflow Platform
Status: Draft constitutional contract
Base candidate at inception: ab179816c03acb45751c1707eddee50d19178298
Parent line: integration/control-plane-rc59-20260924
Ratified for this feature branch: 2026-09-24

## Purpose

This constitution governs Feature 007 and any implementation derived from it. The feature extends MAD4B Site Control Plane from a site-operation kernel into a reusable content-intelligence and workflow platform without creating a second authority plane.

The design MUST favor reusable contracts, capability families, provider adapters, deterministic artifacts, and explicit evidence over site-specific or vendor-specific shortcuts.

## Non-negotiable principles

### I. One governance owner

MAD4B remains the authority owner for identity, grants, approvals, budgets, provider certification, desired state, mutation policy, rollback, evidence, audit, release gates, and Production authorization.

Workflow engines, AI services, scrapers, search providers, hosting providers, and WordPress plugins are execution or information providers. They MUST NOT silently become alternate governance authorities.

### II. Generalize contracts, specialize adapters

Domain logic MUST target generic interfaces such as WorkflowProvider, ResearchProvider, ContextProvider, PublishingProvider, MediaProvider, SEOProvider, and HostConnector.

Vendor names such as Bit Flows, DataForSEO, Firecrawl, Rank Math, Elementor, Hostinger, or Google Drive MAY appear only in adapters, exact certification profiles, runtime evidence, or site profiles.

ETG is the first validation profile, not the architecture boundary.

### III. Exact package certification

Provider trust is bound to exact observed package identity and capability-level evidence. "Latest", "installed", "active", or "known provider" is never equivalent to certified.

A provider version MAY be certified for one capability and blocked for another.

Upstream being newer than the installed runtime is informational only and MUST NOT trigger automatic upgrade or downgrade.

### IV. Capability-level authority

Capabilities are the durable platform abstraction. Abilities, tools, REST routes, workflow nodes, plugin methods, and shell commands are implementation surfaces.

Each capability MUST declare:
- semantic identifier;
- risk class;
- read/write semantics;
- reversibility;
- provider prerequisites;
- approval requirements;
- evidence requirements;
- deterministic input/output contract;
- fail-closed blocker behavior.

### V. Workflow mechanics are not mutation authority

A WorkflowProvider MAY provide routing, conditions, waits, loops, retries, schedules, webhook mechanics, and execution history.

A WorkflowProvider MUST NOT gain permission to mutate governed site resources merely because a workflow reaches a mutation node.

Governed mutations execute through MAD4B capabilities and authority checks.

### VI. No privileged side channels

Independent MCP servers, direct provider APIs, raw plugin database writes, arbitrary PHP, shell execution, direct REST mutation, or hidden webhooks MUST NOT bypass the MAD4B authority plane.

Unknown privileged peer surfaces fail closed until explicitly certified, disabled, isolated, or federated.

### VII. Deterministic plans and immutable evidence

Plans, definitions, context packs, writer profiles, research snapshots, blueprints, drafts, QA results, publishing manifests, and release evidence MUST have stable identity and SHA-256 fingerprints when practical.

Execution MUST bind to the reviewed plan and relevant artifact fingerprints. Stale plans MUST fail closed.

### VIII. Artifact-first content intelligence

The Content Intelligence OS is an artifact pipeline, not one long prompt.

Every material stage emits a typed, versioned artifact with lineage:
input artifacts → deterministic or attributable transformation → output artifact → quality gate → next stage.

Raw source corpora SHOULD be distilled into bounded artifacts before repeated use.

### IX. Separate job state from job stage

Lifecycle state and processing stage are independent dimensions.

State answers whether a job is NEW, QUEUED, RUNNING, WAITING_REVIEW, BLOCKED, FAILED, COMPLETED, or CANCELLED.

Stage answers what the job is doing, such as INTAKE, RESEARCH, BLUEPRINT, WRITING, QA, MEDIA, SEO, PUBLISHING, or POST_PUBLISH.

No implementation may overload one field to represent both.

### X. Context is authority-aware

Context selection MUST be explicit, bounded, source-aware, versioned, and attributable.

A Content Job consumes ContextPack artifacts, not unrestricted Google Drive folders or arbitrary historical documents.

Context Authority remains the source of truth for source eligibility and human/AI review status.

### XI. Research evidence is separated from editorial inference

Search, SERP, scraped pages, keyword metrics, first-party sources, factual references, and model-generated analysis MUST preserve source type and provenance.

A model-generated statement MUST NOT be promoted to a factual source merely because it appears in a prior artifact.

### XII. Quality gates are explicit

Planning, writing, publishing, and Production rollout require named gates.

At minimum:
- CAN_PLAN
- CAN_WRITE
- CAN_PUBLISH
- CAN_SCHEDULE
- CAN_ACTIVATE_PRODUCTION

A later gate cannot silently override an earlier hard failure.

### XIII. Host authority is separate

WordPress filesystem/database authority is not hosting-account authority.

Host Connector capabilities for SSH, OS files, server logs, PHP configuration, backups, external databases, services, and multiple domains MUST use a separate connector, separate identity/grants, and environment policy.

Production shell/eval remains denied by default.

### XIV. Reuse existing Control Plane primitives

Feature 007 MUST reuse existing MAD4B identity, NHI, approvals, budgets, audit, rollback, filesystem, database, plugin lifecycle, provider certification, Skills, Context Authority, Media, SEO, Elementor, JetEngine, and other certified primitives.

Reimplementation requires an explicit incompatibility record.

### XV. Unknown providers fail closed

The default provider onboarding flow is:

Discovery → Contract Inspection → Safe Read → Capability Certification → Reversible Mutation → Promotion.

Unknown provider → arbitrary PHP or direct database writes is forbidden.

### XVI. Release lineage is part of correctness

Repository ancestry and semantic convergence are release gates.

Before rc.59 becomes canonical, the unique commits on the closed convergence line MUST be classified SUPERSEDED, EQUIVALENT, or REQUIRED, and REQUIRED MUST equal zero unless deliberately ported and re-certified.

No large Feature 007 runtime implementation is merged into master before this release-lineage gate closes.

### XVII. Backward-compatible evolution

Generic contracts MUST be versioned. Provider-specific extensions MUST not weaken generic invariants.

Schema changes MUST be additive or migration-backed, support rollback strategy where practical, and preserve old evidence for audit.

### XVIII. Multi-site and multi-business readiness

Identifiers MUST include explicit site/tenant scope where authority or data can cross site boundaries.

No global singleton assumption may prevent future use across multiple WordPress sites, brands, languages, countries, or business units.

### XIX. Observability without secret leakage

Every stage and provider call SHOULD produce bounded telemetry, reason codes, timings, correlation IDs, and evidence references.

Secrets, tokens, raw credentials, hidden prompt contents containing protected data, and unrestricted provider payloads MUST NOT be exposed in ordinary status surfaces.

### XX. Production remains separately authorized

Passing repository CI, staging tests, provider certification, or content QA never authorizes Production activation by itself.

Production authority remains an explicit, separately evidenced decision.

## Change boundary for Feature 007

Allowed specification paths:
- .specify/feature.json
- .specify/memory/constitution.md
- specs/007-content-intelligence-workflow-platform/**

Implementation paths are defined in plan.md and MUST be introduced phase-by-phase after Phase 0 release-lineage closure.

## Constitutional gate

Any implementation PR derived from Feature 007 MUST document:
1. which requirements it implements;
2. which contracts it changes;
3. which capabilities are added or widened;
4. whether authority surface changes;
5. rollback and evidence impact;
6. staging acceptance required;
7. whether Production remains denied.

Version: 1.0.0
