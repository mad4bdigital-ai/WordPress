# Data Model Addendum — Critical Kernel State and Trust

## AggregateStateDescriptor
- aggregate_type
- aggregate_id
- authoritative_revision
- current_state/stage where applicable
- current artifact refs
- consistency status
- updated_at

Events are not the current-state authority; they reference aggregate revision.

## WorkLease
- work_id
- worker_id
- lease_epoch
- acquired_at
- heartbeat_at
- expires_at
- expected_aggregate_revision
- status

Constraint:
successful authoritative worker writes require lease_epoch >= current accepted fencing epoch.

## ExecutionGuardDecision
- guard_id
- plan_sha256
- target expected-state fingerprint
- policy snapshot SHA
- grant revision/fingerprint
- approval policy/decision revisions
- provider artifact/capability fingerprint
- subject/authority binding revision
- environment/profile revision
- kill-switch revision
- rights/data-flow decision refs
- verdict
- reason_codes
- decision_sha256
- evaluated_at

## GateDefinition
- gate_id
- dependencies[]
- environment applicability
- evidence requirements[]
- output state/evidence
- bootstrap transition refs[]
- owner
- version

## BootstrapTransition
- transition_id
- from_state
- to_state
- exact operation
- rationale
- prerequisites/evidence
- one_shot/bounded-use
- expires_at optional
- required postconditions
- recovery
- audit policy

## CapabilityProfile
- provider_id
- capability_id/version
- idempotency model
- cancel/resume/durable-wait traits
- retry semantics
- ordering
- max runtime/payload
- callback model
- concurrency model
- locality/data-residency/security traits
- evidence-strength traits
- profile_sha256

## IntentRelation
- relation_id
- intent_id
- content_id
- site/locale/market
- role
- confidence
- evidence refs
- valid_from
- valid_to
- source
- revision

## PublicationFingerprintSet
- publication evidence ID
- normalizer version
- content fingerprint
- SEO fingerprint
- structure fingerprint
- link fingerprint
- media fingerprint
- ignored volatile selectors/fields
- required dimension verdicts

## OfflineAuthorizationDecision
- dependency/service
- operation risk class
- cached evidence refs
- evidence ages
- effective maximum offline window
- verdict
- evaluated_at

## AuditClassRecord
- record/event ID
- class: audit_evidence | domain_event | operational_telemetry
- tenant/site
- retention profile
- sensitivity
- attestation optional
- archive tier

## DataFlowDecision
- decision_id
- data classification
- processor/provider
- origin/target region
- storage/processing purpose
- policy revision
- redaction/minimization result
- decision
- evidence SHA

## SupportedRuntimeProfile
- profile_id
- PHP/WP/DB class
- MCP adapter/protocol
- provider/plugin identities
- object-cache/cron/worker mode
- OAuth profile
- host constraints
- status
- compatibility evidence refs

## ArchitectureAdmissionDecision
- proposal ID
- candidate new core contract/abstraction
- qualifying reason class
- evidence/runtime incident/provider/compliance refs
- disposition: CORE | ADR | BACKLOG | OPTIONAL_EXTENSION
- reviewer
- decided_at
