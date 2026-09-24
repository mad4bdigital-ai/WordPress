# Feature Specification — Content Intelligence + Governed Workflow Platform

Feature ID: 007
Status: Draft specification
Base line at creation: integration/control-plane-rc59-20260924
Base exact SHA at creation: ab179816c03acb45751c1707eddee50d19178298
Parent PR: #47
Primary target: general MAD4B platform; ETG is the first validation profile

## Problem statement

MAD4B Site Control Plane already provides a strong governed execution kernel: MCP transport integration, identity, NHI grants, exact approvals, budgets, audit, rollback, provider certification, plugin lifecycle, filesystem/database surfaces, Developer planes, Dynamic Skills, Context Authority, and specialized WordPress adapters.

The next problem is not another control plane. The missing layer is a reusable operating system for long-running content work and provider orchestration:

- jobs with durable lifecycle;
- provider-neutral workflow mechanics;
- exact provider/version/capability certification;
- knowledge requirement resolution and context dispatch;
- writer-profile distillation;
- keyword/SERP/scrape research;
- competitive analysis and information-gain planning;
- blueprint, writing, factual/editorial/SEO QA;
- media/SEO/publishing orchestration;
- post-publish growth feedback;
- separate host-level authority when work leaves WordPress boundaries.

The architecture must remain useful beyond ETG, Bit Flows, Rank Math, Hostinger, or any one research vendor.

## Existing platform primitives to reuse

Feature 007 treats the following as existing platform dependencies, not new systems to rebuild:

- Official WordPress MCP Adapter integration.
- MAD4B MCP server surfaces and ChatGPT gateway.
- OAuth/resource bridge and subject identity.
- NHI/agent governance and exact grants.
- One-time approval tickets.
- Blast-radius budgets.
- Append-only/integrity audit mechanisms.
- Mutation envelopes and undo/rollback primitives.
- Plugin/provider discovery and lifecycle governance.
- Exact provider certification and runtime integrity.
- Filesystem read/write/patch within WordPress-authorized roots.
- Structured database read/update and isolated raw-SQL Breakglass.
- Developer filesystem/WP-CLI/execution and Developer Breakglass.
- Dynamic Skills.
- Context Authority and Google Drive source/asset registry.
- Existing Media, Rank Math, Elementor, JetEngine, JetSmartFilters, WooCommerce, Polylang, LiteSpeed and other adapters according to their certified scope.
- Generic operating-model state diff, operation plan, candidate-state and workflow compile primitives.
- Existing Workflow Provider facade and Bit Flows adapter.

## Goals

G-001 Make rc.59 lineage canonical before large platform expansion.
G-002 Generalize workflow execution through a WorkflowProvider contract.
G-003 Certify provider capabilities independently rather than treating a plugin version as all-or-nothing.
G-004 Add durable Content Jobs with independent state and stage machines.
G-005 Add typed artifact lineage as the backbone of Content Intelligence.
G-006 Reuse Context Authority to create job-specific ContextPacks.
G-007 Add reusable Writer Profiles rather than repeatedly injecting raw corpora.
G-008 Add provider-neutral keyword, SERP, search, and scraping contracts.
G-009 Add competitor selection, coverage matrices, and InformationGainPlan artifacts.
G-010 Add blueprint, writing, fact QA, editorial QA, SEO QA, media and publishing stages.
G-011 Reuse existing WordPress Media/SEO/content abilities for site mutations.
G-012 Add Host Connector as a separate authority plane only when needed.
G-013 Add cron governance as a reusable capability family.
G-014 Add post-publish growth signals after the publishing vertical slice is proven.
G-015 Keep all new behavior multi-site, multi-brand, multi-language, and multi-provider ready.

## Non-goals for the first implementation slice

NG-001 Replacing WordPress MCP Adapter.
NG-002 Forking upstream MCP Adapter to gain generic unrestricted access.
NG-003 Rebuilding existing filesystem/database/rollback/audit systems.
NG-004 Making Bit Flows the authority owner.
NG-005 Exposing Bit Flows MCP Server as a second direct privileged ChatGPT endpoint.
NG-006 Supporting every SEO plugin before Rank Math-based vertical slice works.
NG-007 Generic arbitrary PHP for unknown plugins.
NG-008 Production shell/eval.
NG-009 Full Growth/GSC optimization before reliable content publication exists.
NG-010 Automatic upgrades to whichever provider version is newest upstream.

## Actors and systems

### Operator
Human owner/admin approving high-impact changes and release gates.

### Agent
NHI-backed autonomous or assisted client operating through exact grants.

### Content Planner Skill
Builds job requirements, context needs, research plan and blueprint plan.

### Content Writer Skill
Consumes an approved blueprint, WriterProfile and ContextPack and produces bounded draft artifacts.

### QA Skills
Fact, editorial, SEO and final quality evaluators. They do not directly widen publishing authority.

### WorkflowProvider
Provider-neutral orchestration runtime. Bit Flows is the first implementation.

### ContextProvider
Supplies governed source/asset material. Google Drive through existing Context Authority is the first major implementation.

### ResearchProvider
Supplies keyword metrics, SERP results, web search, scraping, or specialized datasets.

### Site Provider
WordPress/core/plugin capability adapter used for governed mutations.

### HostConnector
Separate external authority for hosting/SSH/logs/OS operations.

## Core invariants

INV-001 MAD4B is governance owner; workflow providers are mechanics providers.
INV-002 WorkflowProvider execution cannot bypass MAD4B mutation capabilities.
INV-003 Provider certification is exact-package and capability-level.
INV-004 Unknown provider capability is unavailable, not best-effort.
INV-005 A reviewed plan is fingerprint-bound; changed plan or changed workflow fails closed.
INV-006 A Content Job has independent state and stage.
INV-007 Every material content stage emits or consumes typed artifacts.
INV-008 Every persisted artifact has tenant/site scope, type, version, lineage and fingerprint.
INV-009 ContextPack contains approved bounded references, not unrestricted source folders.
INV-010 WriterProfile is versioned and fingerprinted; raw writer corpus is not required on every job.
INV-011 Research facts preserve provider/source provenance and collection time.
INV-012 Model analysis never becomes first-party factual evidence implicitly.
INV-013 CAN_PLAN, CAN_WRITE, CAN_PUBLISH and CAN_ACTIVATE_PRODUCTION are distinct gates.
INV-014 Publish operations reuse existing governed site abilities where available.
INV-015 Host authority cannot be inferred from WordPress administrator or Developer authority.
INV-016 Production remains denied unless separately authorized.
INV-017 Site-specific configuration cannot leak into generic contracts.
INV-018 Upstream provider latest-version discovery is informational only.
INV-019 Job retries are idempotency-aware and cannot duplicate irreversible mutations silently.
INV-020 Cancellations stop future orchestration but do not erase audit/evidence history.
INV-021 Artifact replacement creates a new version; it does not mutate historical evidence in place.
INV-022 Provider-native MCP write paths must be disabled, isolated, or federated under the same authority.
INV-023 Release lineage divergence is a hard implementation gate.
INV-024 Stale research/context/blueprint evidence invalidates dependent gates according to dependency rules.

## Release-lineage requirements

REL-001 The closed PR #45 line and canonical PR #47 line MUST be compared from their merge base.
REL-002 Every unique PR #45 commit MUST be classified SUPERSEDED, EQUIVALENT, or REQUIRED.
REL-003 REQUIRED=0 is required before canonical merge unless required changes are deliberately ported.
REL-004 Semantic equivalence MUST be proven by code/contract behavior, not filenames alone.
REL-005 The reconciliation artifact MUST record commit SHA, intent, affected surfaces, replacement evidence, classification and reviewer.
REL-006 rc.59 exact-head staging acceptance MUST be rerun after the final canonical head is fixed.
REL-007 Feature 007 implementation PRs beyond spec-only work target the canonical rc.59 lineage after this gate closes.

## Workflow Provider requirements

WFP-001 Generic provider identity uses provider_id independent of plugin slug.
WFP-002 Provider operations are mapped to capability IDs, not raw methods.
WFP-003 Minimum read family: workflow.list, workflow.read, workflow.execution.read.
WFP-004 Execution uses workflow.execute and exact workflow fingerprint + plan fingerprint.
WFP-005 Lifecycle candidates: workflow.enable, workflow.disable.
WFP-006 Execution-control candidates: workflow.execution.retry, workflow.execution.cancel.
WFP-007 Definition candidates: workflow.definition.validate, workflow.definition.diff, workflow.create, workflow.update.
WFP-008 workflow.delete remains unavailable until certified recovery semantics exist.
WFP-009 Each operation declares risk, reversibility, authority requirements, provider prerequisites and evidence contract.
WFP-010 Provider-internal direct database writes are forbidden unless an explicit provider contract certifies them; default is public/runtime API.
WFP-011 Caller-supplied credentials are forbidden for ordinary calls.
WFP-012 Workflow provider native MCP server cannot be externally privileged without federation.
WFP-013 Provider status MUST expose installed version, active state, exact certification target, capability states and blockers.
WFP-014 Certification target is exact observed runtime package; it does not mean upstream latest.

## Provider certification requirements

PC-001 Certification record identifies provider, package source, version, package digest and critical-file identities where applicable.
PC-002 Capabilities have independent certification states.
PC-003 Standard states: UNKNOWN, SHADOW, READ_CERTIFIED, CANARY_VERIFIED, REVERSIBLE_VERIFIED, CERTIFIED, BLOCKED.
PC-004 Read eligibility and write eligibility are separate.
PC-005 Semantic delta is required when exact package differs from prior baseline.
PC-006 Security-sensitive surface changes trigger explicit review.
PC-007 Disposable runtime tests precede target-site mutation.
PC-008 Target-site runtime identity readback must match reviewed package identity.
PC-009 Certification evidence is immutable and linked to source/build/package identity.
PC-010 Downgrade is never implied solely because an older version is certified.
PC-011 A newer upstream version does not invalidate an exact installed certification automatically; policy may mark it upgrade-available, not unsafe.
PC-012 Certification promotion never creates grants or execution authority.

## Content Job requirements

CJ-001 ContentJob has stable public job_id.
CJ-002 Required fields include tenant/site, brand, language, country/market, content_type and subject/keyword.
CJ-003 Optional fields include writer_profile, research_depth, automation_level, target_post and scheduling intent.
CJ-004 State enum: NEW, QUEUED, RUNNING, WAITING_REVIEW, BLOCKED, FAILED, COMPLETED, CANCELLED.
CJ-005 Stage enum includes INTAKE, SITE_DISCOVERY, KNOWLEDGE_DISPATCH, KEYWORD_RESEARCH, SERP_RESEARCH, COMPETITOR_SELECTION, SCRAPING, COMPETITOR_ANALYSIS, INFORMATION_GAIN, BLUEPRINT, BLUEPRINT_QA, WRITING, FACT_QA, EDITORIAL_QA, MEDIA, SEO, FINAL_QA, DRAFT, SCHEDULING, PUBLISHING, POST_PUBLISH.
CJ-006 State transitions and stage transitions are separately validated.
CJ-007 Every transition emits a JobEvent with correlation ID and reason.
CJ-008 Job retry resumes from an explicit checkpoint/artifact version.
CJ-009 Job cancellation prevents future scheduled work but preserves evidence.
CJ-010 Job owner/authority scope cannot be changed by workflow mechanics.

## Artifact requirements

ART-001 Generic ContentArtifact envelope supports multiple artifact types.
ART-002 Required identity: artifact_id, job_id where applicable, tenant/site, artifact_type, version, sha256, created_at, producer, authority/source class.
ART-003 Artifact lineage contains parent artifact IDs and external source references.
ART-004 Content is immutable per version.
ART-005 Sensitive payload may be stored separately from normal status metadata.
ART-006 Artifact types initially include ContextPack, WriterProfileSnapshot, KeywordResearch, SERPSnapshot, CompetitorSelection, ScrapedPage, TopicCoverageMatrix, QuestionCoverageMatrix, EntityCoverageMatrix, EvidenceCoverageMatrix, UXCoverageMatrix, InformationGainPlan, ContentBlueprint, SectionPlan, ArticleDraft, FactLedger, EditorialQA, SEOQA, MediaManifest and PublishManifest.
ART-007 Artifact invalidation propagates to dependent gates.
ART-008 Artifact retention policy is configurable by type but audit identity is retained.

## Context requirements

CTX-001 Job Requirements Resolver maps job intent to required and conditional knowledge classes.
CTX-002 Knowledge Dispatcher queries Context Authority, never raw drive folders directly.
CTX-003 ContextPack contains only eligible reviewed source assets unless policy explicitly allows provisional context.
CTX-004 ContextPack records source_id, asset_id, version, authority/review state and fingerprint.
CTX-005 ContextPack is bounded by size/token/document policy.
CTX-006 Context changes invalidate downstream dependent artifacts according to dependency graph.
CTX-007 Context providers are pluggable; Google Drive is not hardcoded into job logic.
CTX-008 Site/brand/language matching rules are explicit.

## Writer Profile requirements

WR-001 WriterProfile has stable writer_profile_id and independent versions.
WR-002 Profile captures language, sentence structure, paragraph density, rhythm, opening style, argument style, narrative style, evidence usage, vocabulary, do rules and do-not rules.
WR-003 Profile references source assets used for distillation.
WR-004 Distillation emits profile_sha256 and model/process metadata.
WR-005 Job consumes a specific WriterProfile version.
WR-006 Updating a WriterProfile does not mutate completed jobs.
WR-007 Raw source corpus is not required at writing time if approved distilled profile is sufficient.

## Research requirements

RES-001 KeywordProvider, SERPProvider, SearchProvider and ScrapeProvider are separate generic contracts.
RES-002 A vendor may implement multiple research contracts.
RES-003 Research request includes market, language, query/topic, freshness and budget constraints.
RES-004 Research response records provider, collected_at, request fingerprint, source references, cost/usage metadata when available and normalized data.
RES-005 Raw provider payload can be retained as bounded evidence but normalized contract is authoritative for pipeline logic.
RES-006 Provider timeout/error/rate-limit states are explicit.
RES-007 Research retries are budget-aware and idempotency-aware.
RES-008 Scraping honors configured legal/robots/policy constraints.
RES-009 External data cannot create publishing authority.

## Competitive intelligence requirements

COMP-001 SERPSnapshot freezes the reviewed result set used by a job.
COMP-002 CompetitorSelection records inclusion/exclusion rationale.
COMP-003 ScrapedPage preserves URL, fetch time, canonical identity, extraction fingerprint and source class.
COMP-004 Coverage matrices are normalized artifacts, not embedded only in prompts.
COMP-005 InformationGainPlan must distinguish missing coverage, differentiation opportunity, first-party evidence opportunity and unsupported speculation.
COMP-006 A job can proceed with partial research only when policy explicitly permits degraded mode and records the limitation.

## Planning, writing and QA requirements

PLAN-001 ContentBlueprint defines search intent, audience, goals, outline, section objectives, evidence requirements, internal linking intent, CTA intent, structured-data intent and media needs.
PLAN-002 SectionPlan can be generated for long-form/parallel writing.
PLAN-003 CAN_PLAN requires required ContextPack and minimum research evidence.
WRITE-001 ArticleDraft references exact blueprint, writer profile and context versions.
WRITE-002 Writing may occur section-by-section but final assembly records lineage.
WRITE-003 CAN_WRITE requires approved blueprint state.
QA-001 FactLedger records claims, source support, confidence and unresolved items.
QA-002 EditorialQA evaluates clarity, redundancy, structure, brand/writer adherence and prohibited patterns.
QA-003 SEOQA evaluates search intent, coverage, metadata readiness, internal-link requirements and structured-data readiness.
QA-004 FinalQA combines hard blockers and advisory findings without averaging away hard failures.
QA-005 QA artifacts never publish directly.

## Publishing requirements

PUB-001 Publishing orchestration reuses existing MAD4B content/media/SEO provider abilities.
PUB-002 PublishManifest binds target site/post, content artifact SHA, media manifest SHA, SEO payload SHA and reviewed plan SHA.
PUB-003 CAN_PUBLISH requires successful hard gates and exact target-state preconditions.
PUB-004 Draft creation/update is distinct from public publication.
PUB-005 Scheduling is distinct from immediate publishing.
PUB-006 Read-after-write verification is required.
PUB-007 Provider/site mutations produce existing mutation/audit evidence.
PUB-008 Unsupported SEO providers remain fail-closed rather than falling back to arbitrary metadata writes.

## Host Connector requirements

HOST-001 HostConnector is not implemented as a hidden extension of WordPress filesystem.
HOST-002 Separate subject/agent/grant policy is required.
HOST-003 Capabilities are namespaced by host domain: host.files.*, host.logs.*, host.php.*, host.cron.*, host.process.*, host.backup.*, host.database.*, host.domain.*, host.ssh.*.
HOST-004 Read operations land before writes.
HOST-005 Production shell and arbitrary command execution remain blocked by default.
HOST-006 Every write declares target, expected state, rollback/recovery path and blast radius.
HOST-007 Provider adapters map generic host capabilities to Hostinger or future providers.
HOST-008 Cross-domain and cross-database authority is explicit.

## Cron requirements

CRON-001 Generic cron family: cron.list, cron.read, cron.health, cron.run, cron.schedule, cron.unschedule.
CRON-002 Read-only discovery is independent from mutation.
CRON-003 cron.run requires explicit event identity and budget.
CRON-004 schedule/unschedule require exact expected state and audit.
CRON-005 WordPress cron and host cron are separate providers under the same semantic family.

## Growth loop requirements

GROW-001 Post-publish telemetry is a later phase, not a prerequisite for MVP publishing.
GROW-002 Generic SearchPerformanceProvider supports query/page/country/device/date dimensions when available.
GROW-003 Growth artifacts include IndexStatus, SearchPerformanceSnapshot, ContentDecaySignal, CannibalizationSignal and RefreshRecommendation.
GROW-004 RefreshRecommendation creates a new job/version; it never rewrites published content silently.
GROW-005 Growth signals do not bypass editorial/publishing gates.

## Acceptance scenarios

### A1 Release lineage closes
Given PR #45 unique commits and PR #47 current line, when semantic reconciliation runs, every unique commit has evidence-backed classification and REQUIRED equals zero or required behavior has been ported and re-certified.

### A2 Exact provider mismatch fails closed
Given installed provider package X and certified baseline Y, when X != Y, write capability is blocked even if classes are present. Read capability follows its own certification policy.

### A3 Upstream newer version does not auto-upgrade
Given installed Bit Flows 1.29.0 and a newer upstream release, provider status may report update availability but certification remains bound to installed 1.29.0 until an explicit upgrade workflow is planned.

### A4 Workflow execution is plan-bound
Given an approved workflow plan, changing workflow definition or plan inputs invalidates expected fingerprints and execution is denied.

### A5 Workflow wait does not gain site authority
A workflow can wait for approval and then request a MAD4B capability, but the workflow provider itself cannot perform the site mutation without exact MAD4B authorization.

### A6 Content job survives interruption
A RUNNING job at WRITING can resume from approved blueprint/draft checkpoint without repeating completed research or duplicating publication mutations.

### A7 Context changes invalidate dependent artifacts
Replacing an authoritative brand asset creates a new ContextPack; dependent blueprint/write gates are marked stale according to dependency rules.

### A8 Writer profile reuse
Two jobs can consume the same approved WriterProfile version without attaching the original writer corpus to each job.

### A9 Research provider substitution
A SERP provider can be replaced without changing Content Job or blueprint schemas, provided it satisfies the generic SERP contract and is certified.

### A10 Publishing reuses existing authority
A PublishManifest causes mutations only through certified MAD4B site abilities and produces normal mutation/audit evidence.

### A11 Host operations remain separate
WordPress administrator authority alone cannot invoke host SSH, external database, or account-level operations.

### A12 Unknown plugin fails closed
Discovery of an unknown plugin exposes inventory and safe contract inspection only; no arbitrary mutation ability appears automatically.

## Success criteria

SC-001 No second privileged MCP side-channel is required for the vertical slice.
SC-002 A single content job can move intake → context → research → blueprint → writing → QA → draft through typed artifacts.
SC-003 Each stage can be inspected and retried without rerunning the entire pipeline.
SC-004 Provider replacement requires adapter/certification work, not domain-model rewrites.
SC-005 ETG-specific fields are represented through profile/configuration rather than generic schema changes.
SC-006 Production remains separately gated.
