# Data Model

## OperationContext
- operation_id
- operation_key
- mode: create|update|recovery|publish|undo
- environment
- scope
- post_id?
- post_type
- plan_sha256?
- bundle_sha256?
- pipeline_settings_sha256?
- approval_id?
- started_at
- hard_deadline_at

## OperationEvent
- event_id
- operation_id
- sequence
- event_type
- stage_id?
- iteration?
- elapsed_ms?
- outcome
- state_sha256_before?
- state_sha256_after?
- receipt_sha256?
- lock_status?
- recovery_required
- safe_metadata
- created_at

## JournalCheckpoint
Monotonic state enum:
planned < approval_bound < lock_acquired < mutation_started < post_written < meta_written < taxonomy_written < media_written < validation_started < accepted < publish_started < published_verified < receipt_consumed < completed

Terminal branches:
- compensated
- recovery_required

Fields:
- operation_id
- checkpoint
- sequence
- state_sha256?
- ownership_digest?
- created_at

## ProviderManifest
- provider_id
- version
- supports
- preconditions
- validation_capabilities
- repair_capabilities
- side_effects[]
- reversible_evidence_version
- certification
- max_elapsed_ms

## SideEffect
- id
- scope
- coverage: none|read_only|reversible|compensatable|irreversible|external
- resource_kind
- external_system?
- compensation_strategy?
- evidence_digest?

## TtlDecision
- operation_id
- purpose: acceptance|mutation_lock
- impact_class
- requested_seconds?
- selected_seconds
- min_seconds
- max_seconds
- refresh_at_seconds?
- hard_deadline_seconds
- policy_sha256

## SemanticDiff
- path
- kind: post_field|meta|taxonomy|featured_media|provider_effect
- before_present
- after_present
- before_summary
- after_summary
- redacted
- severity

## RecoveryCase
- recovery_id
- operation_id
- detected_checkpoint
- current_state_sha256
- expected_owned_state_sha256?
- classification
- blockers[]
- proposed_actions[]
- plan_sha256
- approval_requirement
- status: open|planned|applied|verified|closed|manual_required
