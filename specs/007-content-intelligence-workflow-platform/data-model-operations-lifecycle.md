# Data Model Addendum — Operations, Content Inventory and Lifecycle

## PolicyDecision
- decision_id
- tenant/site/environment
- capability/operation
- target identity
- matched policy refs/versions
- precedence chain
- decision
- reason_codes[]
- required_approval_policy_id optional
- decision_sha256
- created_at

## ApprovalPolicy / ApprovalDecision
ApprovalPolicy:
- policy_id/version
- operation/risk scope
- requester_may_approve
- approver roles
- quorum
- distinct-principal rule
- delegation/emergency policy
- TTL

ApprovalDecision:
- approval_id
- policy version
- requester
- approvers[]
- decision
- exact plan/target fingerprints
- expires_at/revoked_at
- evidence hash

## EvidenceAttestation
- evidence_id
- evidence_sha256
- signer_key_id
- signature
- issued_at/expires_at
- revocation state
- trust policy version

## SiteBootstrapSnapshot
- snapshot_id
- site_uuid
- runtime/profile identity
- observed_at
- inventory counts
- snapshot_sha256

## ContentInventoryItem
- content_id
- site_uuid
- locale/market
- object type/id
- public/canonical URL
- content fingerprint
- status/indexability
- topic/entity/intent signals
- SEO/media/link summaries
- observed timestamps

## IntentClaim
- intent_id
- site/locale/market
- intent fingerprint
- canonical owner content_id
- ownership strength
- supporting refs
- conflict state
- revision

## ArtifactBlob
- blob_id/sha256
- storage provider/class
- byte size
- encryption/classification metadata
- physical locator
- integrity state
- created_at

## ArtifactBlobRef
- artifact_id/version
- blob_id
- logical role
- retention/hold state

## RecomputePlan
- recompute_plan_id
- change event ref
- invalidated artifact/gate refs
- preserved refs + rationale
- ordered stages
- fan-out estimate
- cost estimate
- coalescing key
- plan_sha256

## PublicationEvidence
- publication_evidence_id
- PublishManifest ref
- origin/public URL observations
- rendered fingerprint
- SEO/canonical/robots/schema results
- language/hreflang results
- media/sitemap/cache results
- propagation state
- verdict
- evidence_sha256

## RightsRecord
- rights_record_id
- source/artifact/media ref
- rights class
- license/owner refs
- allowed uses
- attribution/modification/commercial flags
- territory/expiry
- review status
- evidence refs

## AIProcessingDecision
- decision_id
- provider/model
- input classifications
- region
- redaction/minimization result
- policy version
- decision
- evidence hash

## DoctorFinding / RepairPlan
DoctorFinding:
- finding_id
- site/job/provider/object
- severity
- reason
- evidence refs
- suggested repair ref

RepairPlan:
- plan_id
- exact targets/current fingerprints
- steps
- reversibility/recovery
- authority requirements
- plan_sha256

## DeadLetterItem
- dlq_id
- original work/event
- site/job/provider
- attempts
- last error class
- request/input hash
- checkpoint/provider execution ref
- replay safety
- first/last failure
- status

## ContractLifecycleRecord
- contract/capability/Skill ID
- current version
- introduced/deprecated/sunset metadata
- replacement
- usage inventory ref
- migration status

## TenantQuota / SchedulingClass
- scope
- resource class
- limit/window
- priority/weight
- reserved capacity
- current usage ref
- policy version

## LocalizationCluster
- cluster_id
- content identity
- locale variants
- source/master optional
- translation/transcreation relation
- canonical/hreflang policy

## LinkGraphEdge
- source content
- target content/entity/intent
- edge type
- locale
- anchor metadata
- observed/recommended state
- evidence

## EvalSuite / EvalRun
EvalSuite:
- suite/version
- owner
- fixture refs
- metrics/hard blockers
- thresholds
- contamination/reviewer policy

EvalRun:
- candidate identity
- suite version
- exact fixtures
- scores/findings
- baseline
- evaluator version
- run sha

## ErrorBudgetState
- SLO profile/objective
- window
- allowed/consumed budget
- burn rates
- current state
- last alert

## Experiment / Variant / Exposure
Experiment:
- hypothesis
- population
- metrics/guardrails
- assignment/stopping policy
- status

Variant:
- variant_id
- immutable artifact/config fingerprint

Exposure:
- experiment/variant
- pseudonymous unit/cohort
- timestamp
- outcome linkage policy

## UsageEvent
- usage_event_id
- tenant/site/job
- capability/stage/provider/model
- quantity/unit
- cost/currency
- rate-card version
- correlation/evidence
- usage sha

## ExportBundleManifest
- bundle_id
- scope
- schema/contract versions
- included object counts
- blob refs/hashes
- omitted secrets/external refs
- remapping requirements
- manifest_sha256
