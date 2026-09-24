# Coverage Audit — Feature 007 vs. Supplied Architecture Inputs

Audit date: 2026-09-24
Feature branch baseline: spec/007-content-intelligence-workflow-platform-20260924
PR: #54
Purpose: prove that every architecture concern supplied during planning is either fully represented in Feature 007 or explicitly tracked as a gap with a contract/task/gate.

## Status legend
- COVERED — explicit contract + plan/task coverage exists.
- PARTIAL — architecture direction exists but important semantics/tests are missing.
- MISSING — supplied requirement was not represented in the Spec Kit before this audit.
- DEFERRED — intentionally later phase with contract but no first-slice implementation.

## 1. rc.59 canonicalization and release lineage

Input concerns:
- master is behind rc.59;
- PR #45 closed/unmerged line diverges from PR #47;
- 10 unique #45 commits require semantic reconciliation;
- repository CI is not equivalent to live Staging acceptance;
- exact artifact/provenance must be read back on ETG Staging;
- Full Staging Authority plans/approvals are SHA-bound and stale across candidate changes.

Before audit: COVERED.

Feature 007 coverage:
- contracts/release-lineage.md
- plan Phase 0
- tasks T0001–T0028
- runbook sections A/B
- references/current-platform-baseline.md

Acceptance:
- UNKNOWN=0
- REQUIRED=0
- exact final rc.59 SHA frozen
- exact package deployed
- live Staging provenance matches
- fresh authority plan applied/read back
- PR #47 canonicalized before large runtime implementation.

## 2. Repository-ready vs Staging-certified vs Production-ready

Input concern:
Passing 57/57 repository checks and disposable Live Acceptance does not prove real ETG Staging or Production readiness.

Before audit: PARTIAL.

Required model:
- repository_ready
- disposable_certified
- live_staging_certified
- production_eligible
- production_authorized

These states MUST be independently evidenced. A later state cannot be inferred from an earlier one.

New coverage:
- contracts/multi-authority-live-certification.md
- contracts/release-rings.md
- contracts/observability-and-evidence.md

## 3. Multi-authority OAuth architecture

Input concerns:
- Local, External and Hybrid authorities already exist;
- trust policy and advertisement policy are currently coupled;
- resource policy is too implicit;
- External configured state is reported too strongly as runtime readiness;
- Local and External subject formats differ;
- Local OAuth Developer/Breakglass exposure should be narrower by default;
- authority outage/fallback must be explicit.

Before audit: MISSING/PARTIAL.

Required generalized AuthorityDescriptor:
- authority_id
- authority_type
- configured
- trusted
- advertised
- resource_policy
- subject_mapper_id
- runtime_verified
- last_live_verified_at
- last_live_verification_ref
- issuer
- metadata/JWKS evidence
- environment policy

Modes become policy outcomes rather than one overloaded local/external/hybrid switch.

New coverage:
- contracts/multi-authority-registry.md
- contracts/multi-authority-live-certification.md
- data-model-authority-certification.md
- tasks Phase 15.

## 4. External subject → enrolled WordPress principal mapping

Input concern:
An External JWT may be cryptographically valid but fail later because current Subject User Bridge expects user:<numeric-wp-id>, while external platforms may emit tenant:<tenant>:user:<platform-id> or other stable subjects.

Before audit: MISSING.

Required mapping key:
issuer + normalized external_sub + site_uuid
→ enrolled WordPress principal / NHI binding.

Rules:
- external subject never needs to equal a WordPress primary key;
- mapping is exact, site-scoped and issuer-bound;
- unknown/multiple mappings fail closed;
- changing mapping is governed/audited;
- subject from authority A cannot resolve under B;
- mapping is tested through the complete REST filter chain.

New coverage:
- contracts/subject-mapping.md
- data-model-authority-certification.md
- tasks T1507–T1514.

## 5. Full REST OAuth filter-chain E2E

Input concern:
Unit-style calls to Subject Gate and Resource Bridge can miss failures in:
priority 0 Subject Gate
→ priority 1 Resource Bridge
→ priority 2 Subject User Bridge
→ permission callback
→ MCP handler.

Before audit: MISSING.

New hard test:
Real REST/MCP request through the entire filter chain using Local and External tokens, including negative subject/issuer/resource/scope cases.

New coverage:
- contracts/multi-authority-live-certification.md
- contracts/behavioral-probe-catalog.md

## 6. Authority readiness truthfulness

Input concern:
External authority may be configured while DNS/AS/JWKS is unavailable.

Before audit: MISSING.

Required fields:
configured != trusted != advertised != runtime_verified.

Status must never label external runtime live solely because config is present.

Live probes run as explicit certification evidence, not hidden network requests inside ordinary status.

New coverage:
- contracts/multi-authority-registry.md
- contracts/multi-authority-live-certification.md

## 7. Trust vs advertisement policy

Input concern:
A resource may trust multiple AS instances without advertising all of them to clients.

Before audit: MISSING.

Policy dimensions:
- trusted_authorities[]
- advertised_authorities[]
- primary_authority
- standby_authorities[]
- authority_resource_policy

Example target:
Production may trust External + Local, advertise External as primary, retain Local as standby/recovery.

New coverage:
- contracts/multi-authority-registry.md

## 8. Authority-resource isolation

Input concern:
Local AS should not automatically inherit Developer/Breakglass authority merely because it is trusted.

Before audit: PARTIAL.

Required:
authority_resource_policy maps each authority to exact protected resources. Default Local policy can remain ChatGPT + Enrollment, while Developer/Breakglass require explicit opt-in or External governed authority.

New coverage:
- contracts/multi-authority-registry.md
- tasks Phase 15.

## 9. Local OAuth key lifecycle

Input concern:
Current single RSA key is strong but lacks current/previous/next overlap rotation.

Before audit: MISSING.

Required lifecycle:
- current
- next
- previous
- publish current+next/previous overlap
- switch signer
- wait token TTL + skew + JWKS cache
- retire old
- audit/recovery
- stable restart persistence

New coverage:
- contracts/oauth-key-lifecycle.md
- tasks T1520–T1527.

## 10. MCP protocol evolution

Input concern:
MCP Adapter 0.6.1 is a 2025-11-25 baseline; future official adapter releases may implement 2026-07-28 semantics.

Before audit: MISSING.

Policy:
- keep exact certified 0.6.1 while required;
- track official adapter release;
- certify exact package hash;
- run dual-protocol compatibility regression where relevant;
- do not install upstream trunk directly on managed sites;
- do not claim latest-protocol compliance before certification.

New coverage:
- contracts/mcp-protocol-evolution.md
- tasks T1530–T1536.

## 11. Multi-Authority Live Certification

Input concern:
Highest-priority pre-merge gate should combine exact provenance, Local canary, External canary, subject mapping, cross-authority isolation and real MCP handshake.

Before audit: MISSING.

New contract verdict:
mad4b.multi-authority-live-certification.v1

A PASS requires all mandatory probes against exact deployed Staging candidate; disposable-only evidence cannot satisfy the live gate.

New coverage:
- contracts/multi-authority-live-certification.md
- tasks T1540–T1554.

## 12. Existing Bit Flows integration

Input:
bit_pi provider and flow execution already exist; do not build a second bridge.

Before audit: COVERED.

Coverage:
- contracts/workflow-provider.md
- architecture-boundaries.md
- plan Phase 2.

## 13. Workflow Provider Diagnostic

Input:
Need one broad read-only diagnostic:
mad4b.workflow-provider-diagnostic.v1

It must capture artifact identity, tree/critical-file hashes, schema/migration version, source, structural capabilities and dangerous security surfaces.

Before audit: PARTIAL.

New coverage:
- contracts/workflow-provider-diagnostic.md
- tasks T1601–T1610.

## 14. Artifact + Capability + Behavioral certification

Input:
Version alone must stop being the authority gate. Exact artifact, capability contract, behavior, security invariants, rollback and canary evidence decide capability eligibility.

Before audit: PARTIAL/COVERED foundation.

Existing coverage:
- contracts/provider-certification.md

New advanced coverage:
- contracts/dynamic-provider-certification.md
- capability fingerprints
- structural compatibility
- behavioral probes
- QUARANTINED state
- environment class
- dependency-aware reuse.

## 15. Capability fingerprint

Input:
Each capability should have its own fingerprint based on contract + structural implementation + behavior/schema/error/recovery dependencies.

Before audit: MISSING.

Required:
capability_fingerprint != provider/package fingerprint.

A provider update may invalidate only impacted capabilities.

New coverage:
- contracts/dynamic-provider-certification.md
- data-model-authority-certification.md

## 16. Dynamic certification lifecycle

Input lifecycle:
UNKNOWN
→ DISCOVERED
→ STRUCTURALLY_COMPATIBLE
→ READ_CERTIFIED
→ SHADOW
→ CANARY_VERIFIED
→ REVERSIBILITY_VERIFIED
→ WRITE_CERTIFIED
→ ACTIVE
with QUARANTINED.

Before audit: PARTIAL.

New coverage:
- contracts/dynamic-provider-certification.md

The older simpler states remain compatibility aliases until implementation migration is complete.

## 17. Artifact diff classifier

Input classifications:
- NO_RUNTIME_CHANGE
- ADDITIVE
- BEHAVIORAL_CHANGE
- SECURITY_RELEVANT_CHANGE
- SCHEMA_CHANGE
- EXECUTION_ENGINE_CHANGE
- UNKNOWN_CRITICAL_CHANGE

Before audit: MISSING.

Purpose:
Determine affected capability dependency subgraph and required probes instead of full provider invalidation on every version.

New coverage:
- contracts/dynamic-provider-certification.md
- tasks T1620–T1628.

## 18. Behavioral certification probes

Input:
Structural/file comparison is insufficient.

Required permanent probes include:
- deterministic execute success;
- controlled failure;
- retry behavior;
- duplicate-trigger behavior;
- disabled-flow behavior;
- timeout/error surfacing;
- exact flow selection;
- input/output preservation;
- no duplicate execution;
- logging/evidence.

Historical provider bugs become permanent regression probes.

Before audit: PARTIAL.

New coverage:
- contracts/behavioral-probe-catalog.md

## 19. Bit Flows historical security/behavior probes

Input:
Run Code, native MCP server, webhook/network behavior, Action Hook isolation and paused-execution semantics are security or correctness surfaces.

Before audit: PARTIAL.

Permanent probes:
- trigger_isolation_test
- paused_execution_state_test
- direct_mcp_privilege_test
- arbitrary_php_exposure_test
- outbound_network_policy_test
- webhook_authentication_test

New coverage:
- contracts/behavioral-probe-catalog.md

## 20. Run Code / arbitrary PHP

Input:
Bit Flows Run Code must never be exposed through ordinary workflow capability.

Before audit: COVERED in principle, not explicit enough.

Policy:
- DENIED in normal workflow provider profile;
- optional Developer/Breakglass-only integration requires a separate spec/certification;
- preferred default is no mapping.

New coverage:
- contracts/workflow-provider-diagnostic.md
- contracts/behavioral-probe-catalog.md

## 21. Native Bit Flows MCP side-channel

Input:
Do not connect ChatGPT directly to Bit Flows MCP as a parallel privileged transport.

Before audit: COVERED.

Extended requirement:
diagnostic explicitly classifies native MCP as suppressed, read-only, federated or blocking.

## 22. Signed/bound workflow bridge requests

Input:
MAD4B ↔ workflow provider webhook/custom-app payloads must be plan/site/candidate bound, expiring and replay-resistant.

Before audit: MISSING.

Required envelope:
- contract
- workflow_id/ref
- plan_sha256
- site_uuid
- candidate/source/build identity when relevant
- expires_at
- nonce/jti
- request_sha256
- signature/MAC or authenticated channel binding
- allowed callback/result contract.

Generic webhook execute-any is forbidden.

New coverage:
- contracts/signed-workflow-bridge.md

## 23. Generic Workflow Provider capability matrix

Input:
Skills should request semantic requirements, not Bit Flows.

Before audit: COVERED/PARTIAL.

Add semantic mechanics:
- workflow.discover/read/validate
- execution.start/read/retry/cancel
- trigger.webhook/schedule/action_hook
- mechanics.delay
- mechanics.branch
- mechanics.iterator
- mechanics.repeater
- http.request where separately governed
- definition export/import where supported.

New coverage:
- contracts/provider-resolver.md
- expanded diagnostic/certification contracts.

## 24. Provider Resolver

Input:
Select among Bit Flows, n8n, native or future engines based on required capabilities, certification, environment, risk, cost, locality and performance.

Before audit: MISSING.

Provider selection must be deterministic/explainable and non-authorizing.

New coverage:
- contracts/provider-resolver.md
- data-model-authority-certification.md
- tasks Phase 17.

## 25. Evidence dependency graph and reuse

Input:
Full recertification on every version is too expensive. Evidence declares depends_on implementation trees/schema/contract versions. Unchanged dependencies can reuse prior evidence after lightweight smoke.

Before audit: MISSING.

Rules:
- reuse is explicit and fingerprint-bound;
- UNKNOWN dependency change invalidates affected evidence;
- reused evidence records origin and lightweight recheck;
- no reuse across different artifact SHA unless dependency proof permits it.

New coverage:
- contracts/dynamic-provider-certification.md
- data-model-authority-certification.md

## 26. Central Provider Certification Registry / cache

Input:
A globally reviewed exact artifact should be reusable across sites, followed by site-runtime compatibility probes.

Before audit: MISSING.

Required distinction:
Global artifact/capability certification
+
site compatibility evidence
=
site capability eligibility.

Site probe may include PHP, WordPress, DB, permissions, conflicts, cron, object cache and required extension state.

New coverage:
- contracts/dynamic-provider-certification.md
- contracts/release-rings.md

## 27. Release rings

Input:
Ring 0 disposable
→ Ring 1 ETG Staging/canary
→ Ring 2 selected staging
→ Ring 3 general staging eligible
→ Ring 4 production eligible.

Before audit: MISSING.

New coverage:
- contracts/release-rings.md

Production eligible != Production authorized.

## 28. Conditional autopromotion

Input:
Allow automated certification promotion when trusted source, artifact scan, impact analysis, affected probes, rollback and canary all pass. Never auto-certify on SemVer alone.

Before audit: MISSING.

New coverage:
- contracts/release-rings.md
- contracts/dynamic-provider-certification.md

Core rule:
SemVer = optimization/risk signal.
Evidence = certification authority.

## 29. Content Intelligence OS

Input:
Content Jobs, state/stage, Knowledge Dispatcher, Writer Profiles, Research, competitors, Information Gain, Blueprint, Writing, QA, Media, SEO, Publishing, Growth.

Before audit: COVERED.

Coverage:
- spec.md
- data-model.md
- content-job.md
- context-dispatch.md
- research-provider.md
- artifact-and-quality-gates.md
- publishing.md
- cron-and-growth.md
- plan phases 3–14.

## 30. Google Drive Context vs Knowledge Dispatcher

Input:
Reuse existing Context Authority; do not reimplement Drive ingestion.

Before audit: COVERED.

## 31. Writer Profile distillation

Input:
Versioned profile, source lineage, do/don't/style fields, no raw corpus every article.

Before audit: COVERED.

## 32. Research Provider abstraction

Input:
KeywordProvider, SERPProvider, SearchProvider, ScrapeProvider.

Before audit: COVERED.

## 33. Competitive Intelligence artifacts

Input:
SERPSnapshot, selections, scraped pages, coverage matrices, InformationGainPlan.

Before audit: COVERED.

## 34. Blueprint/Writing/QA

Input:
ContentBlueprint, SectionPlan, ArticleDraft, FactLedger, EditorialQA, SEOQA, MediaManifest, PublishManifest and CAN_PLAN/CAN_WRITE/CAN_PUBLISH.

Before audit: COVERED.

## 35. Existing Media/Rank Math primitives

Input:
Reuse current governed adapters.

Before audit: COVERED.

## 36. Unknown plugins

Input:
Discovery → Contract Inspection → Safe Read → Mutation Certification → Reversible Adapter.

Before audit: COVERED.

## 37. WP All Import/Export

Input:
dry-run, rollback, artifact ingest, operation receipt, post-execution reconciliation.

Before audit: COVERED as later provider-completion work.

## 38. JetSmartFilters deep operations

Input:
query/provider/indexer contracts + reversible execution.

Before audit: COVERED as later provider-completion work.

## 39. Host Connector

Input:
Separate authority for outside-WP files, logs, PHP config, cron/process, SSH, backups, databases/domains.

Before audit: COVERED.

## 40. Cron control

Input:
list/get/read/health/run/schedule/unschedule, WordPress and host providers separated.

Before audit: COVERED.

## 41. Growth loop

Input:
Search performance, index status, decay, cannibalization, refresh queue/recommendation.

Before audit: COVERED/DEFERRED.

## 42. Skills

Input:
Skills should orchestrate generic contracts, never become a custom authority bypass.

Before audit: COVERED.

## 43. Observability/evidence

Input:
Correlation IDs, fingerprints, source/build/package identity, reason codes, bounded telemetry and no secret leakage.

Before audit: COVERED, expanded by dynamic certification and authority live evidence.

## 44. Final coverage verdict

After this audit and the contracts added with it:

Fully represented architecture families:
- rc.59 lineage/release governance
- multi-authority OAuth policy and live certification
- subject/principal mapping
- OAuth key lifecycle/protocol evolution
- WorkflowProvider abstraction
- dynamic artifact/capability/behavioral certification
- Provider Resolver
- evidence reuse/dependency graph
- release rings/autopromotion
- Bit Flows security and regression probes
- signed workflow bridge
- Content Intelligence OS
- Context/Writer/Research/Competitive Intelligence
- publishing/site operations
- generic plugin onboarding
- Host Connector
- cron
- Growth
- Skills
- observability/evidence

Implementation is NOT complete. This document only means the supplied architecture is now explicitly represented and traceable in the Spec Kit.

The first execution priorities remain:
1. Multi-Authority/subject-mapping live gate on current rc.59.
2. PR #45/#47 semantic reconciliation.
3. exact-head ETG Staging acceptance and canonical rc.59.
4. dynamic provider-certification foundation.
5. Bit Flows exact-artifact diagnostic/recertification.
6. then Content Job vertical slice.


## 45. Quality architecture hardening beyond supplied functional scope

Deep review identified additional engineering concerns required for a robust long-lived platform even though they were not all explicit in the supplied notes.

Added as normative contracts:
- correctness/consistency/idempotency;
- durable execution/retries/backpressure;
- schema/contract evolution;
- security threat model;
- supply chain/secrets;
- source trust and AI/LLM evaluation;
- tenant/privacy/retention;
- performance/capacity/cost;
- disaster recovery/operability;
- verification/testing strategy;
- policy drift/kill switches;
- cross-feature specification isolation.

Key decisions:
- no false exactly-once claim across external providers;
- optimistic concurrency and expected-state checks;
- durable inbox/outbox for asynchronous provider boundaries;
- worker leases and crash recovery;
- bounded retry/cost/queue behavior;
- explicit trust zones and untrusted-source instruction isolation;
- artifact/dependency provenance and secret minimization;
- AI artifacts record model/prompt/Skill/input lineage;
- SLOs are profile-driven instead of arbitrary universal numbers;
- restore evidence is required before durable/irreversible Production eligibility;
- quality is evaluated as independent hard gates, not one averaged score.

## 46. Cross-feature Spec Kit regression discovered and fixed

The first Feature 007 metadata implementation overwrote repository-global .specify/feature.json.
Existing Feature 001 CI uses that file as an executable contract and failed with missing target_version.

Resolution:
- restore the existing global Feature 001 metadata unchanged;
- place Feature 007 machine-readable metadata inside Feature 007 directory;
- add spec-isolation contract and CI compatibility task.

This incident is permanent design evidence that shared repository metadata is an owned compatibility surface.


## 47. Second-order long-lived platform gap closure

A second-order architecture review asked what would fail only after MAD4B expands from one Staging site and a few providers to a long-lived multi-site/multi-business platform.

The review identified and now specifies all of the following previously missing or partial concerns:

1. Policy precedence/conflict resolution.
2. Separation of duties, self-approval, quorum, delegation and emergency approval.
3. Signed/revocable evidence attestation for cross-site trust.
4. Existing-site bootstrap/backfill.
5. Normalized content inventory.
6. URL/search-intent ownership registry before content creation.
7. Backend-neutral ArtifactStore and content-addressed blobs.
8. Retrieval index abstraction without making a vector DB authoritative.
9. Incremental dependency invalidation/recompute.
10. Publication verification beyond WordPress database state.
11. Cache/CDN propagation distinction.
12. Content rights/licensing/attribution/takedown.
13. AI provider data-processing/residency policy.
14. Human Operator Control Center.
15. Read-only Doctor + bounded RepairPlan.
16. Dead-Letter Queue/poison-work replay.
17. Shared semantic Provider Conformance Suite.
18. Contract/capability/Skill deprecation and sunset.
19. Fair scheduling, tenant quotas and noisy-neighbor isolation.
20. Central-vs-site outage/local-autonomy model.
21. Localization/translation/transcreation/RTL/hreflang model.
22. Accessibility QA.
23. Internal Link Graph.
24. Eval Registry/gold-fixture/threshold governance.
25. SLO error budgets, alerts and escalation.
26. Experimentation/variant attribution with SEO safety.
27. Usage ledger, budgets and optional chargeback.
28. Decommission/export/import/credential and callback cleanup.

These are represented by dedicated contracts, Phase 23–30 tasks and traceability rows. They are specification coverage, not runtime completion.

## 48. Priority impact

P0 architectural closure before autonomous publishing at scale:
- policy resolution + separation of duties;
- evidence attestation;
- site bootstrap/inventory/intent;
- artifact store;
- incremental recompute;
- publication verification;
- rights and AI data-processing.

P1 operational closure before broad multi-site use:
- operator/Doctor/DLQ;
- provider conformance/deprecation;
- fair scheduling/local autonomy;
- localization/accessibility/link graph;
- eval registry/error budgets/alerts.

P2 maturity:
- experimentation;
- chargeback;
- full portability automation.

Decommission/export semantics are designed early even if implementation is later because storage and identity choices become difficult to reverse after production data accumulates.
