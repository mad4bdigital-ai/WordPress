# Implementation Plan — Feature 007

## Strategy

Feature 007 lands as independent, reviewable slices. The first slice is governance/release work, not Content OS runtime mutation. Later slices reuse the existing Site Control Plane primitives and add only generic domain contracts.

Architecture:

ChatGPT / approved MCP client
→ Official WordPress MCP Adapter
→ MAD4B Site Control Plane
  → Identity / NHI / Grants / Approvals / Budgets / Audit / Rollback
  → Site Operations
  → Context Authority
  → Provider Certification
  → Dynamic Skills
  → Workflow Provider Contract
  → Content Intelligence OS
     → Jobs
     → Artifact Graph
     → Knowledge Dispatch
     → Writer Profiles
     → Research
     → Competitive Intelligence
     → Blueprint / Writing / QA
     → Media / SEO / Publishing / Growth
  → WorkflowProvider adapters
     → Bit Flows
     → future n8n
     → future native engine

Separate authority:
Host Connector → hosting / SSH / logs / OS / backups / external databases.

## Phase 0 — Canonicalize rc.59

Objective: close repository lineage and exact-head Staging acceptance before large runtime expansion.

Work:
1. Compare PR #45 and PR #47 from merge base.
2. Enumerate the 10 PR #45-only commits.
3. For each commit record intent, affected governance/security surfaces, current replacement code/tests, and classification:
   - SUPERSEDED
   - EQUIVALENT
   - REQUIRED
4. If REQUIRED exists, port semantics deliberately and add regression contracts. Do not blind-cherry-pick merge history.
5. Require REQUIRED=0.
6. Freeze final rc.59 SHA.
7. Build exact General Distribution package and provenance.
8. Deploy exact artifact to ETG Staging.
9. Read back source SHA, build fingerprint, package manifest digest, MCP Adapter version, stale/provenance state.
10. Run live acceptance.
11. Generate fresh Full Staging Authority status/plan and apply only the exact fresh plan if ready.
12. Verify candidate binding and write-runtime readiness.
13. Mark PR #47 Ready, merge using repository policy, verify master.

Exit gate: REL-CANONICAL=PASS.

## Phase 1 — Provider certification semantics

Objective: separate runtime observation, exact package identity, upstream update availability, and per-capability certification.

Extend existing provider certification structures where possible.

Required provider status:
- provider_id
- installed_version
- active
- observed_package_digest
- certified_version
- certified_package_digest
- package_match
- upstream_latest_version (optional, informational)
- upgrade_available
- capability_states
- hard_blockers
- mutation_performed=false

Capability states:
UNKNOWN → SHADOW → READ_CERTIFIED → CANARY_VERIFIED → REVERSIBLE_VERIFIED → CERTIFIED
with BLOCKED available from any state.

Read and write eligibility are independent.

Exit gate: installed-but-uncertified runtime is described accurately without implying downgrade or auto-upgrade.

## Phase 2 — Bit Flows exact-runtime recertification

Objective: certify the exact Bit Flows package deployed on target Staging, beginning with existing reads and run-flow.

Work:
- acquire exact package from governed source;
- archive/critical-file SHA-256;
- semantic delta against 1.24 baseline;
- inventory Flow, FlowNode, FlowHistory, FlowExecutor;
- inventory lifecycle, retry/cancel, definition APIs;
- inventory webhook/action-hook behavior;
- inventory native MCP routes/auth/write surface;
- disposable WordPress runtime;
- read tests for list/get/executions;
- harmless run-flow canary;
- verify exact flow SHA and exact plan SHA;
- verify global execution gate and per-flow allowlist;
- verify audit/readback;
- promote capabilities independently.

Generic operations:
- workflow.list
- workflow.read
- workflow.execution.read
- workflow.execute
- workflow.enable
- workflow.disable
- workflow.execution.retry
- workflow.execution.cancel
- workflow.definition.validate
- workflow.definition.diff
- workflow.create
- workflow.update
- workflow.delete

workflow.delete remains blocked until recovery semantics are proven.

Native MCP policy: disable external privilege, classify read-only, or federate under MAD4B. Unknown privileged side-channel is a blocker.

## Phase 3 — Content Job foundation

Objective: durable jobs, independent state/stage machines, immutable artifacts, lineage and quality gates.

Recommended module boundary: mad4b-site-control-plane/includes/content-os/ or equivalent internal module namespace.

New services:
- ContentJobRegistry
- ContentJobStateMachine
- ContentArtifactRegistry
- ContentArtifactGraph
- ContentQualityGates

Suggested additive tables:
- mad4b_content_jobs
- mad4b_content_job_events
- mad4b_content_artifacts
- mad4b_content_artifact_edges
- mad4b_writer_profiles

Read abilities first:
- mad4b/content-job-list
- mad4b/content-job-get
- mad4b/content-job-events
- mad4b/content-artifact-get
- mad4b/content-artifact-lineage
- mad4b/content-quality-gate-status

Governed writes:
- mad4b/content-job-create
- mad4b/content-job-transition
- mad4b/content-job-cancel

Exit gate: synthetic job can be created, queued, moved through stages, paused/retried/cancelled and inspected with immutable events/artifacts.

## Phase 4 — Knowledge Dispatcher and ContextPack

Components:
- JobRequirementsResolver
- KnowledgeClassRegistry
- KnowledgeDispatcher
- ContextPackBuilder
- ContextDependencyInvalidator

Generic knowledge classes:
- brand.core
- brand.positioning
- audience.primary
- voice.language
- seo.strategy
- product.knowledge
- service.knowledge
- destination.knowledge
- destination.blueprint
- pricing
- evidence
- legal.compliance
- writer.profile

Flow:
ContentJob → requirements → eligible Context Authority assets → bounded ContextPack → CAN_PLAN evaluation.

Exit gate: jobs never read arbitrary Drive folders directly.

## Phase 5 — Writer Profiles

Components:
- WriterProfileRegistry
- WriterProfileDistiller
- WriterProfileValidator

Flow:
eligible writer sources → distillation plan → process/model → immutable WriterProfileVersion → review → active version.

Exit gate: writing job references exact profile version/hash without resending raw corpus.

## Phase 6 — Research provider platform

Generic interfaces:
KeywordProvider:
- keyword.expand
- keyword.metrics.read
- keyword.related.read

SERPProvider:
- serp.snapshot

SearchProvider:
- search.web

ScrapeProvider:
- scrape.page

Every adapter exposes availability, non-secret auth status, request schema, normalization, rate/budget metadata, error taxonomy, evidence refs, and API/provider version when available.

Bit Flows may orchestrate calls/retries, but provider execution remains plan/evidence bound through MAD4B.

Exit gate: ETG research job works without vendor-specific fields in ContentJob.

## Phase 7 — Competitive intelligence

Pipeline:
SERPSnapshot
→ CompetitorSelection
→ ScrapedPages
→ Topic/Question/Entity/Evidence/UX coverage matrices
→ InformationGainPlan

Rules:
- selection rationale preserved;
- source freshness visible;
- scrape failure never silently becomes empty coverage;
- differentiation is analysis, not source fact;
- partial-research mode is explicit.

## Phase 8 — Blueprint, writing and QA

Pipeline:
ContextPack + WriterProfile + Research + InformationGain
→ ContentBlueprint
→ BlueprintQA
→ optional SectionPlans
→ ArticleDraft
→ FactLedger
→ EditorialQA
→ SEOQA
→ FinalQA

Suggested Skills:
- content-intake
- knowledge-dispatch
- research-plan
- competitive-analysis
- information-gain
- blueprint
- writer
- fact-check
- editorial-review
- seo-review
- final-review

Skills orchestrate contracts; they do not own credentials or bypass authority.

Exit gate: approved draft + QA bundle exists while CAN_PUBLISH remains false.

## Phase 9 — Media, SEO and WordPress draft

Reuse existing:
- media abilities;
- Rank Math abilities;
- governed content mutations;
- mutation envelopes/audit.

Pipeline:
approved ArticleDraft
→ MediaManifest
→ SEO intent
→ PublishManifest(create_draft/update_draft)
→ governed abilities
→ read-after-write
→ draft evidence.

Exit gate: verified WordPress draft on Staging, no direct provider DB writes, public publish still blocked.

## Phase 10 — Scheduling/publication

Separate capabilities:
- content.schedule
- content.publish

Require current CAN_PUBLISH, exact PublishManifest, exact target fingerprint, environment-aware approval, and post-write verification. Production remains separately authorized.

## Phase 11 — Host Connector

Separate external authority.

Initial read-only families:
- host.files.list/read
- host.logs.list/read
- host.php.status
- host.cron.list/health
- host.process.status
- host.backup.list/status
- host.database.list/status
- host.domain.list/status

Later writes require exact recovery contracts. Arbitrary Production shell stays outside ordinary catalog.

## Phase 12 — Cron governance

Generic family:
- cron.list
- cron.read
- cron.health
- cron.run
- cron.schedule
- cron.unschedule

WordPressCronProvider and HostCronProvider implement the same semantics with separate authority.

## Phase 13 — Provider completion backlog

Independent workstreams:
- WP All Import/Export behavioral certification
- JetSmartFilters deep query/provider/indexer operations
- Yoast governed writes
- SEOPress governed writes
- other form/provider adapters
- generic unknown-provider onboarding

## Phase 14 — Growth loop

SearchPerformanceProvider + artifacts:
- IndexStatus
- SearchPerformanceSnapshot
- ContentDecaySignal
- CannibalizationSignal
- RefreshRecommendation

RefreshRecommendation creates a new/revision ContentJob; no silent rewrite.

## CI strategy

Add contracts incrementally:
- release-lineage reconciliation
- provider certification states
- workflow provider abstraction
- content-job/state-stage
- artifact lineage
- context dispatcher
- writer profile
- research providers
- quality gates
- publishing integration
- host connector
- cron
- growth

## Rollback and flags

Schema changes are additive. New runtime features default off. Capabilities remain unmounted/ungranted until certified. Job/artifact evidence is append-only. Existing site mutation rollback is reused.

Suggested flags:
- MAD4B_CONTENT_OS_ENABLED
- MAD4B_WORKFLOW_PROVIDER_WRITES_ENABLED
- MAD4B_RESEARCH_PROVIDERS_ENABLED
- MAD4B_CONTENT_PUBLISHING_ENABLED
- MAD4B_HOST_CONNECTOR_ENABLED

Flags never replace grants/certification/approval.

## First vertical slice

ETG English informational content on Staging:
create job → resolve context → choose writer profile → keyword/SERP research → competitors → coverage → InformationGainPlan → blueprint → write → fact/editorial/SEO QA → media/SEO intent → verified WordPress draft → stop before public publication unless separately approved.


## Phase 15 — Multi-Authority hardening and live certification

- split Authority Registry into trust, advertisement, resource, subject-mapper and live-evidence dimensions;
- add issuer-bound external subject mapping instead of requiring WordPress numeric IDs in external subject syntax;
- add exact authority_resource_policy;
- add full REST filter-chain Local/External tests;
- add Multi-Authority Live Certification against exact ETG Staging candidate;
- add Local OAuth keyring/rotation;
- preserve exact current MCP Adapter until an official successor is exact-package certified.

Exit gate: MULTI_AUTHORITY_LIVE=PASS on exact rc.59 candidate before release trust is widened.

## Phase 16 — Dynamic provider certification

- implement workflow-provider-diagnostic.v1;
- compute ProviderArtifact and capability fingerprints;
- add structural discovery/security surface inventory;
- add artifact diff classifier;
- add evidence dependency graph/reuse;
- add permanent behavioral probe catalog;
- separate central exact-artifact certification from site runtime compatibility;
- support quarantine and selective capability invalidation.

Exit gate: Bit Flows exact installed artifact can be assessed without version-whitelist logic.

## Phase 17 — Provider Resolver and signed bridge

- define RequiredCapabilitySet;
- implement provider capability matrix;
- implement non-authorizing Provider Resolver;
- add signed/expiring/replay-resistant workflow bridge;
- keep Skills provider-neutral.

Exit gate: a fixture workflow requirement can choose an eligible provider using only semantic capability requirements and evidence.

## Phase 18 — Release rings and controlled autopromotion

- R0 disposable;
- R1 ETG Staging canary;
- R2 selected Staging;
- R3 general Staging eligibility;
- R4 Production eligibility;
- conditional promotion only from evidence/policy;
- demotion/quarantine on regression.

Production eligibility remains distinct from Production authorization.
