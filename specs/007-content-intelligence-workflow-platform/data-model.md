# Data Model — Feature 007

Schema version: `11`

## Modeling principles

1. Stable public IDs are opaque strings/UUID-like identifiers; database auto IDs are internal.
2. Tenant/site scope is explicit on durable domain records.
3. Large or sensitive payloads are not duplicated into status tables.
4. Artifacts are immutable by version.
5. State transitions are append-only events.
6. Provider/runtime observations are facts; certification is a separate decision.
7. Generic tables carry typed payloads rather than creating one table for every content artifact.

## Schema v11 additive intent authority

Schema v11 adds the site-level `IntentRelation` authority store. The table is the
authoritative current/version history for intent ownership across a site, locale and
market. Job-scoped `intent_registry` ContentArtifacts are immutable evidence
snapshots/projections of that authority and MUST NOT become a second current-state
authority source.

The migration is additive, preserves all v10 Artifact/Durable Execution tables, does
not widen authority, and remains forward-fix only.

## Core entities

### ContentJob

Purpose: durable unit of content work.

Fields:
- id — internal bigint
- job_id — stable public identifier, unique
- tenant_id — nullable/default tenant for current single-site mode
- site_uuid — required governed site identity
- brand_id — logical brand/profile identifier
- subject — topic/keyword/problem statement
- primary_keyword — nullable
- language — BCP-47-like normalized value
- country — normalized market/country code
- content_type — article, landing_page, destination, product, category, guide, update, custom
- writer_profile_id — nullable stable profile ID
- writer_profile_version — nullable
- research_depth — light, standard, deep, custom
- automation_level — assisted, review_gated, autonomous_staging
- state — lifecycle enum
- stage — processing-stage enum
- target_post_type — nullable
- target_post_id — nullable
- desired_publish_at — nullable
- current_artifact_id — nullable
- quality_status — unknown, pass, fail, warning, stale
- last_error_code — nullable
- last_error_summary — nullable bounded string
- job_revision — optimistic integer
- created_by_nhi / created_by_user
- created_at / updated_at / completed_at / cancelled_at

Constraints:
- job_id unique.
- site_uuid required.
- state/stage values normalized from registries.
- completed/cancelled jobs cannot silently return to running; restart creates a new run/checkpoint event.

### ContentJobEvent

Purpose: append-only state/stage history.

Fields:
- event_id
- job_id
- sequence
- event_type
- previous_state / new_state
- previous_stage / new_stage
- reason_code
- correlation_id
- actor_type / actor_id
- plan_sha256 nullable
- artifact_id nullable
- provider_id nullable
- metadata_json bounded
- created_at
- entry_sha256 / previous_entry_sha256 where audit chain is used

### ContentArtifact

Purpose: generic immutable artifact envelope.

Fields:
- id
- artifact_id — stable ID
- job_id — nullable for reusable artifacts such as WriterProfile
- tenant_id
- site_uuid
- artifact_type
- schema_version
- artifact_version
- payload_location or bounded payload_json
- payload_sha256
- sensitivity_class
- authority_class — first_party, provider_observation, external_source, model_analysis, operator_decision, derived
- producer_type — skill, provider, operator, system
- producer_id
- process_version
- invalidated_at nullable
- invalidation_reason nullable
- created_at

Unique:
- artifact_id + artifact_version
- immutable payload per version

### ArtifactEdge

Purpose: explicit lineage/dependency graph.

Fields:
- parent_artifact_id/version
- child_artifact_id/version
- relation — derived_from, selected_from, verifies, invalidates, replaces, summarizes, cites
- required — boolean
- created_at

### ExternalSourceRef

Purpose: normalized source evidence without forcing every source into ContentArtifact payload.

Fields:
- source_ref_id
- provider_id
- source_type — url, drive_asset, search_result, api_record, wordpress_object, uploaded_file
- canonical_locator
- source_version/etag/hash nullable
- collected_at
- authority/review status
- metadata_json bounded

## Reusable domain artifacts

### ContextPack payload

- job_requirements_sha256
- source_assets[]
  - source_id
  - asset_id
  - asset_version
  - knowledge_class
  - review_state
  - authority
  - fingerprint
  - excerpt/content_location
- required_classes[]
- missing_required_classes[]
- conditional_classes[]
- dispatcher_version
- generated_at
- context_pack_sha256

### WriterProfile

Stored as reusable profile + immutable versions.

WriterProfile identity:
- writer_profile_id
- tenant/site or global scope
- display_name
- language
- status

WriterProfileVersion artifact payload:
- sentence_structure
- paragraph_density
- rhythm
- opening_style
- argument_style
- narrative_style
- evidence_usage
- vocabulary_preferences
- do_rules[]
- dont_rules[]
- source_asset_refs[]
- distillation_process
- profile_sha256

### KeywordResearch

- market
- language
- seed_terms[]
- normalized keyword records
- provider/source refs
- collected_at
- freshness policy
- request_sha256

### SERPSnapshot

- query
- market
- language
- device/location assumptions
- collected_at
- provider
- result records
- features/answer surfaces when available
- snapshot_sha256

### CompetitorSelection

- serp_snapshot_id
- selected[]
- excluded[]
- rationale codes
- operator overrides
- selection_sha256

### ScrapedPage

- source URL
- canonical URL
- collected_at
- http/result metadata
- extraction policy
- extracted content location
- extracted_sha256
- source authority class

### Coverage matrices

Common envelope:
- matrix_type
- dimensions
- rows/items
- source artifact IDs
- analysis process/version
- matrix_sha256

Types:
- TopicCoverageMatrix
- QuestionCoverageMatrix
- EntityCoverageMatrix
- EvidenceCoverageMatrix
- UXCoverageMatrix

### InformationGainPlan

- baseline coverage refs
- differentiation opportunities
- missing questions/entities/topics
- first-party evidence opportunities
- unsupported areas to avoid
- recommended priorities
- plan_sha256

### ContentBlueprint

- content intent
- target audience
- objective
- primary/secondary topics
- outline
- section objectives
- evidence requirements by section
- internal-link requirements
- CTA intent
- structured-data intent
- media requirements
- forbidden claims/gaps
- source artifact refs
- blueprint_sha256

### SectionPlan

- blueprint_id/version
- section_id
- purpose
- inputs
- required evidence
- constraints
- target length/range optional
- dependencies
- section_plan_sha256

### ArticleDraft

- blueprint ref
- writer profile ref
- context pack ref
- research refs
- sections/content location
- draft_revision
- draft_sha256

### FactLedger

Records:
- claim_id
- claim text or hash/location
- claim type
- supporting source refs
- support status
- confidence
- review notes
- hard_blocker boolean

### EditorialQA

- draft ref
- checks
- hard_failures[]
- warnings[]
- style/profile adherence
- redundancy/clarity findings
- qa_sha256

### SEOQA

- draft ref
- search intent fit
- topic/question/entity coverage
- title/meta recommendations
- internal-link readiness
- schema intent
- index/publish blockers
- qa_sha256

### MediaManifest

- draft/blueprint refs
- media needs
- selected/generated media refs
- alt/caption/placement intent
- rights/source metadata where applicable
- manifest_sha256

### PublishManifest

- site_uuid
- target_post_type/id
- operation mode — create_draft, update_draft, schedule, publish
- exact article draft ref/sha
- media manifest ref/sha
- SEO payload/ref/sha
- expected target state fingerprint
- reviewed operation plan sha
- manifest_sha256

## Provider model

### ProviderRuntimeObservation

Facts:
- provider_id
- provider_kind
- installed_version
- active
- package_digest when available
- runtime_symbols/features
- native MCP exposure
- observed_at
- observation_sha256

### ProviderCertification

Decision:
- provider_id
- package identity
- certification revision
- overall status
- evidence bundle ref
- approved_at/by
- invalidated_at/reason

### CapabilityCertification

- provider_certification_id
- capability_id
- state
- read_eligible
- write_eligible
- reversible
- evidence refs
- blockers[]
- certified_at

Important:
ProviderRuntimeObservation does not grant capability eligibility.

## Workflow model

### WorkflowDefinitionRef

Provider-neutral reference:
- provider_id
- provider_workflow_ref
- workflow_sha256
- title/label
- enabled_state
- observed_at

### WorkflowPlan

- provider_id
- operation
- workflow ref
- capability_id
- risk
- provider certification ref
- expected workflow sha
- input template sha
- authority requirements
- plan_sha256
- non_authorizing=true

### WorkflowExecutionRef

- provider execution/history ID
- workflow ref
- workflow plan sha
- initiated correlation ID
- status
- started/finished timestamps
- result/evidence refs

## Quality gate model

### QualityGateDecision

- gate_id
- job_id
- gate_type — CAN_PLAN, CAN_WRITE, CAN_PUBLISH, CAN_SCHEDULE, CAN_ACTIVATE_PRODUCTION
- decision — pass, fail, pending, stale
- hard_blockers[]
- warnings[]
- required_artifact_refs[]
- evaluated_artifact_fingerprint
- policy_version
- decided_at
- decision_sha256

No averaged score may convert a hard blocker into pass.

## Host connector model

### HostTarget

- host_target_id
- provider_id
- environment
- account/site/domain scope
- allowed roots/resources
- status

### HostCapabilityPlan

- host_target_id
- capability_id
- target locator
- expected state fingerprint
- desired change
- reversible flag
- recovery plan ref
- approval requirements
- plan_sha256

Host identities/grants are not inherited from ContentJob ownership.

## Growth model

Artifacts:
- IndexStatus
- SearchPerformanceSnapshot
- ContentDecaySignal
- CannibalizationSignal
- RefreshRecommendation

RefreshRecommendation references a published content identity and proposes a new ContentJob or revision job. It does not mutate published content directly.

## Suggested schema implementation

Prefer additive tables:
- {prefix}mad4b_content_jobs
- {prefix}mad4b_content_job_events
- {prefix}mad4b_artifacts
- {prefix}mad4b_artifact_edges
- {prefix}mad4b_writer_profiles

Provider certification tables should reuse existing provider certification storage where possible rather than duplicate it.

Large artifact bodies may use existing bounded storage or files/object storage with payload_location + hash. The database stores identity and integrity metadata.

## Indexes

ContentJob:
- unique(job_id)
- (site_uuid, state, stage)
- (brand_id, language, country)
- (target_post_id)
- (updated_at)

ContentArtifact:
- unique(artifact_id, artifact_version)
- (job_id, artifact_type, created_at)
- (site_uuid, artifact_type)
- (payload_sha256)

ContentJobEvent:
- unique(job_id, sequence)
- (correlation_id)
- (created_at)

ArtifactEdge:
- (parent_artifact_id, parent_version)
- (child_artifact_id, child_version)

## Retention and deletion

- Job and audit identities are retained according to governance policy.
- Large research/scrape payloads may expire while preserving hashes and source metadata.
- Deleting a source artifact does not rewrite historical fingerprints.
- Privacy/legal deletion flows require dedicated policy and tombstone evidence.
