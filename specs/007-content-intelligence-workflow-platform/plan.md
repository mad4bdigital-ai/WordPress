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

## Phase 11 — Governed Tool Execution + Host Connector

Separate external authority plus one provider-neutral execution plane.

### 11A — Semantic operation registry

Define ToolOperationDefinition and normalized operation families.

Minimum read-only operations:
- schema.diagnostics.read
- runtime.status.read
- runtime.provenance.verify
- package.integrity.verify
- filesystem.inventory.read/hash
- host.logs.list/read/tail
- host.php.status
- host.cron.list/health
- host.process.status
- host.backup.list/status
- host.database.status/integrity
- host.domain.status
- disk.capacity.read
- provider.runtime.read

No generic shell/command-string operation is admitted.

### 11B — Canonical CLI

Implement thin frontends over shared services:
- `wp mad4b ...` for WordPress-local operations;
- optional standalone `mad4b` CLI for WordPress-independent recovery/host operations.

Machine-readable discovery/output is required.

MCP, CLI and Admin UI MUST call the same operation service.

### 11C — Tool Executor profiles and resolver

Executor types:
- WordPress service
- WP-CLI
- Host Runner
- provider API
- provider CLI
- bounded SSH
- Recovery Runner

Resolver is deterministic and non-authorizing. It cannot fall back to broader privilege.

### 11D — Host Runner

Implement durable non-interactive execution:
- signed/bound job envelope;
- queue;
- lease + fencing epoch;
- idempotency;
- timeout/output/resource budgets;
- readback;
- receipt;
- DLQ;
- crash recovery.

Host Runner accepts semantic jobs only, never caller-provided shell text.

### 11E — Authority and security

Separate:
- Host Read
- Host Write
- Host Execution
- Host Execution Breakglass
- Recovery
- Production Host Authority

WordPress Write/Developer/Full Staging Authority cannot imply Host Execution.

Implement path zones, canonical real-path checks, symlink/zip-slip/TOCTOU defenses, secret handles, network policy and output redaction.

### 11F — Provider adapter validation

Use Hostinger as first validation profile while preserving generic contracts.

Discover/certify independently:
- WordPress/plugin integration;
- provider API;
- provider CLI;
- account-local WP-CLI;
- account-local Host Runner;
- bounded SSH/recovery path where available.

A brand name never implies capability availability.

### 11G — Write/recovery expansion

Only after read path certification:
- plugin.package.stage/apply/rollback;
- host.files.patch;
- cron.schedule/unschedule;
- cache purge;
- backup.create/restore;
- bounded database migration/repair;
- provider runtime reconcile.

Every write requires plan/apply/readback and recovery semantics.

Arbitrary Production shell, arbitrary `wp eval`, arbitrary PHP and generic raw SQL remain outside ordinary catalog.

### 11H — Cross-adapter acceptance

Prove:
- one read diagnostic is equivalent through MCP + CLI;
- one read executes through Host Runner;
- one semantic operation maps to two different executor adapters with identical normalized semantics;
- one reversible write proves plan/apply/readback/rollback;
- shell/path injection is denied;
- runner crash/lease fencing works;
- minimal Recovery Runner works while WordPress/plugin boot is deliberately unavailable.

### 11I — WordPress Host Bridge

Expose generic abilities:
- host-operation-capabilities
- host-operation-plan
- host-operation-apply
- host-operation-status
- host-operation-cancel
- host-operation-receipt
- host-doctor

WordPress may execute a certified WordPress-native/provider-plugin mapping directly or enqueue an exact HostRunnerJob. It never accepts caller-provided host shell.

Execution evidence distinguishes:
- submission/control location;
- authoritative execution location;
- exact executor instance/profile.

A queued WordPress request MUST report WordPress only as the submission location and Host Runner/provider channel as the authoritative execution location. Location changes are material plan dependencies.

Queue backend is profile-driven: DB queue, protected spool or external broker. Recovery cannot rely solely on an in-band queue.

### 11J — Runner bootstrap and enrollment

Provide at least one terminal-independent first-install route for a supported hosting profile.

Bootstrap flow:
- acquire/verify attested RunnerPackage;
- resolve certified bootstrap channel;
- create exact bootstrap plan;
- install only into dedicated runner zone;
- register exact wake-up cron/service profile;
- enroll with one-time target-bound token;
- verify runner identity/package/root/runtime profile;
- consume/revoke bootstrap credential;
- keep runner write-ineligible until normal executor certification/authority passes.

Preferred channel order is provider capability driven, not vendor hardcoded:
provider API/plugin → bounded WordPress package/filesystem bootstrap → certified provider CLI/SSH bootstrap → manual operator fallback.

Bootstrap is a bounded BootstrapTransition and cannot create generic shell or standing Host Execution authority.

Acceptance proves at least one supported profile can install/enroll the runner without interactive hosting terminal.

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


## Phase 19 — Spec isolation and compatibility

- keep Feature 007 metadata local to `specs/007-content-intelligence-workflow-platform/feature.json`;
- preserve repository-global `.specify/feature.json` semantics for Feature 001;
- validate cross-feature spec isolation in CI;
- verify Feature 001 CI remains green for Feature 007 spec-only changes;
- keep Feature 007 validation independent from mutation of another feature's metadata.

Exit gate: Feature 007 and Feature 001 specification/CI contracts remain isolated and independently valid.

## Phase 20 — Correctness and durable execution foundation

- add canonical serialization/fingerprint versioning;
- define transaction boundaries for ContentJob/Event/Artifact updates;
- implement optimistic revision/expected-state checks;
- add idempotency registry and inbox/outbox for external callbacks;
- implement worker lease/heartbeat/checkpoint recovery;
- define retry taxonomy/budgets/backoff;
- add provider bulkheads/circuit breakers/backpressure;
- add saga/compensation metadata for multi-step side effects.

Exit gate: concurrency + duplicate delivery + crash/failure injection preserve state and effect-once behavior.

## Phase 21 — Security, data and supply-chain hardening

- formal threat model and trust-zone tests;
- SSRF/DNS-rebinding/private-network controls;
- prompt/source injection isolation;
- output sanitization/path/archive safety;
- provider/package provenance + dependency/SBOM evidence;
- secret binding/rotation/redaction;
- tenant/site isolation tests;
- data classification/minimization/retention/deletion;
- policy drift + kill switches.

Exit gate: applicable high-risk surfaces pass negative security/isolation tests.

## Phase 22 — Quality, performance and recovery engineering

- versioned AI/content eval suites by language/content type;
- model/prompt/Skill regression gates;
- SLO/capacity/cost profiles;
- load/backpressure tests;
- schema migration and mixed-version compatibility tests;
- PHP/WP/DB/MCP/provider compatibility matrix;
- backup/restore and disaster-recovery rehearsal;
- property/fuzz/fault-injection/mutation-testing for critical paths.

Exit gate: release has evidence for every applicable hard quality gate in quality-model.md.


## Phase 23 — Policy resolution, approval governance and evidence trust

- implement deterministic Policy Resolution Engine and precedence chain;
- model ApprovalPolicy with requester/approver separation, quorum, delegation, expiry and emergency handling;
- add evidence-attestation signer/trust-root/revocation lifecycle;
- bind reusable certification evidence to signed exact identities;
- add tamper, stale-policy, self-approval and revoked-attestation negative tests.

Exit gate: QGOVERNANCE=PASS; local mutable flags cannot forge reusable capability/release trust.

## Phase 24 — Existing-site bootstrap and content intent ownership

- build read-only SiteBootstrapSnapshot;
- normalize existing content/SEO/media/link/language inventory;
- backfill historical content as observed state, not generated Feature 007 artifacts;
- create Intent Registry and collision outcomes;
- add incremental refresh/reconciliation;
- block inappropriate CREATE_NEW when existing canonical intent owner should be updated/consolidated.

Exit gate: first Content Job starts from a reconciled site/content state.

## Phase 25 — Artifact platform and incremental recomputation

- implement backend-neutral ArtifactStore;
- content-addressable immutable blobs + integrity verification;
- storage classes, quotas, dedup, retention and garbage collection;
- optional rebuildable RetrievalIndex;
- dependency-edge classes and RecomputePlanner;
- fan-out guard, coalescing, freshness and cycle protection.

Exit gate: source/model/policy changes recompute the minimum valid subgraph without artifact corruption or runaway fan-out.

## Phase 26 — Publication verification, rights and AI data processing

- add origin/public/rendered publication verification and propagation state;
- verify canonical/robots/schema/hreflang/media/sitemap/cache expectations;
- define cache/CDN purge as a governed capability;
- add RightsRecord and attribution/takedown/similarity policy;
- add AI DataProcessingProfile with classification, residency, retention/training/logging constraints;
- enforce provider/model fallback against the same processing policy.

Exit gate: public content is verified beyond database state and publication inputs have acceptable rights/data-processing decisions.

## Phase 27 — Operator control, Doctor, DLQ and provider lifecycle

- add human Operator Control Center read model;
- governed approve/retry/cancel/pause/repair/quarantine/bulk actions;
- read-only Doctor + bounded RepairPlan;
- Dead-Letter Queue and safe replay;
- shared Provider Conformance Suite;
- contract/capability/Skill deprecation and sunset lifecycle.

Exit gate: common failures can be diagnosed/recovered without database surgery or unrestricted Breakglass.

## Phase 28 — Multi-site fairness, local autonomy, localization, accessibility and link graph

- weighted/fair scheduling, quotas, reservations and anti-starvation;
- explicit behavior when central certification/authority/catalog services are unavailable;
- local cached-evidence TTL and reconnection reconciliation;
- LocalizationCluster and translation/transcreation lineage;
- accessibility QA profiles;
- Internal Link Graph and recommendation/verification.

Exit gate: one tenant/provider cannot monopolize the platform and multilingual/content relationships remain correct under central outages.

## Phase 29 — Evaluation operations, alerts, experiments and economic ledger

- formal EvalRegistry/gold fixture governance and human calibration;
- error-budget/burn-rate alerting with owners/runbooks/escalation;
- governed experimentation with immutable variants and SEO safety;
- UsageLedger, budgets, provider-cost reconciliation and optional chargeback.

Exit gate: quality/operations/economics are observable and experiments cannot bypass publication governance.

## Phase 30 — Decommission and portability

- scoped decommission inventory;
- quiesce/drain/cancel semantics;
- ExportBundle manifest/checksums;
- provider credential/webhook/MCP cleanup;
- site/tenant/provider removal paths;
- import compatibility/remapping/collision checks;
- final no-in-flight/no-active-secret verification.

Exit gate: MAD4B/provider/site can be safely moved or retired without orphaned authority or lost evidence.


## Phase 31 — Baseline, root trust and recovery

- enforce current PR base as Feature HEAD ancestor in CI;
- perform semantic impact review when baseline changes critical authority/runtime files;
- externalize Control Plane release provenance/attestation;
- separate trust roles for OAuth, evidence, release and recovery;
- define and canary the minimal out-of-band Recovery Plane;
- prove recovery of a deliberately disabled/broken Control Plane path.

Exit gate: BASELINE_CURRENT + QROOTTRUST pass.

## Phase 32 — Authoritative state and fenced execution

- declare aggregate-authoritative state and projection/event semantics;
- implement consistency checker;
- define Control Plane ↔ Execution Worker boundary;
- add monotonic lease fencing tokens;
- bind worker writes to fencing epoch and aggregate revision;
- add ambiguous-external-outcome reconciliation before retries;
- test zombie worker, duplicate delivery and worker crash.

Exit gate: QEXECUTIONMODEL pass.

## Phase 33 — Commit guard, gate liveness and operating modes

- bind approvals/execution to material dependency snapshot;
- implement final Execution Commit Guard;
- implement approval invalidation classes;
- compile critical hard gates into DAG;
- validate no cycles and terminal-state reachability;
- implement bounded BootstrapTransition registry;
- expose minimal unsatisfied blocker set;
- define ENTERPRISE_MULTI_OPERATOR, SINGLE_OWNER_HARDENED and EMERGENCY_RECOVERY behavior.

Exit gate: QLIVENESS + governance commit-safety pass.

## Phase 34 — Semantic/provider/privacy/data-flow hardening

- introduce provider CapabilityProfile traits and resolver constraints;
- move Intent Registry to many-to-many role/confidence model;
- enforce privacy-safe content addressing/dedup scope;
- implement normalized semantic PublicationFingerprintSet;
- formalize AI provenance-vs-regeneration semantics and holdout eval partitions;
- adopt SupportedRuntimeProfiles and pairwise/risk-based compatibility;
- configure risk-classed OfflineAuthorizationWindow policies;
- separate AuditEvidence/DomainEvents/OperationalTelemetry;
- generalize data-flow/residency policy beyond AI.

Exit gate: QSEMANTICS pass for the Critical Kernel.

## Phase 35 — Formal critical-state proof and architecture freeze

- model-test authority/approval/commit-guard invariants;
- model-test lease/fencing/idempotency invariants;
- model-test provider certification/release-ring/quarantine invariants;
- verify liveness/reachability as well as denial safety;
- freeze Critical Kernel admission policy;
- execute one exact ETG Staging vertical-slice proof;
- record redesigns discovered by runtime evidence before wider implementation.

Exit gate: CRITICAL_KERNEL_VERTICAL_SLICE_VERIFIED.


## Phase 36 — Unified implementation closure

Objective: convert all known remaining work into one governed closure program and drive the first real ETG Content Intelligence vertical slice to terminal evidence without confusing maturity backlog with kernel blockers.

Execution order:
1. Apply and independently read back the reviewed `master` repository ruleset.
2. Reconcile the legacy task ledger into DONE/PARTIAL/OPEN/DEFERRED with exact evidence references.
3. Separate the reviewed repository parent from the deployable runtime release; recapture trusted runtime/root evidence only after runtime-affecting changes.
4. Prepare and verify the protected backup root, current-runtime backup receipt, independent disable/restore path and known-good restore preconditions.
5. Certify the exact installed Bit Flows 1.29.0 artifact, capability traits and privileged-side-channel policy.
6. Close live Multi-Authority, Policy Resolution Engine, gate-liveness and operating-mode blockers.
7. Implement existing-site bootstrap + Intent Registry.
8. Complete the now-started ContentJob domain runtime proof, then implement Artifact Registry/Store/lineage and authoritative consistency checks.
9. Implement Knowledge Dispatcher, ContextPack and immutable WriterProfile binding.
10. Implement normalized research + competitive-intelligence evidence.
11. Implement Blueprint → ArticleDraft → FactLedger → Editorial/SEO/Final QA.
12. Execute one governed WordPress draft mutation with exact PublishManifest/readback/rollback.
13. Verify semantic origin/public state.
14. Execute Operator/Doctor/reconciliation + Recovery Plane live drill and formal critical-state proof.
15. Execute the exact ETG Staging vertical slice and emit the terminal gate only from the linked evidence chain.

Parallel maturity lanes:
- Provider Resolver/rings/dynamic certification breadth.
- Rights/AI-data governance beyond admitted kernel requirements.
- Cron/provider breadth.
- Growth, fairness, localization, accessibility and link graph.
- Eval operations, experimentation and usage ledger.
- Decommission/portability.

These lanes remain tracked and required for platform maturity but do not automatically block the first vertical slice.

Exit gates:
- `repository_governance_enforced`
- `protected_backup_recovery_ready`
- `provider_exact_certified`
- `execution_ledger_reconciled`
- `critical_kernel_vertical_slice_verified`

No Phase 36 action grants Production authorization.


Bulk closure hardening lane:
- enforce `mad4b.feature007-bulk-closure-hardening.v1` as machine-readable CI policy;
- treat mutation-without-durable-evidence as `MUTATED_BUT_EVIDENCE_UNCERTAIN`, never as success or a blind retry;
- execute permanent fault fixtures for lease loss, zombie workers, duplicate/replay, crash-after-side-effect, provider uncertainty, readback and rollback failure;
- centralize filesystem/process confinement and execute path/symlink/archive/shell/executable injection negatives;
- minimize runner bootstrap/enrollment to one exact attested package, one exact scheduler/service entry and one single-use target-bound enrollment;
- prove protected backup integrity plus corrupt/interrupted restore behavior;
- prove out-of-band recovery with WordPress/plugin/control-plane unavailable;
- prove cross-executor semantic parity and execution-location truthfulness;
- strengthen gate liveness so every blocker has a terminal closure path;
- close the lane only through one full request→plan→approval→execution→readback→durable receipt→rollback evidence chain.

Architecture Freeze remains active: this lane closes implementation/safety gaps and MUST NOT become a vehicle for new documentation-only abstractions.
