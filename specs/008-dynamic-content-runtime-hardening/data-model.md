# Data Model

## OperationIdentity
- operation_key — stable intent/idempotency key
- operation_id — unique execution attempt
- operation_binding_sha256 — exact immutable execution binding

## OperationContext
identity, mode, environment, scope, post_id?, post_type, plan_sha256?, bundle_sha256?, pipeline_settings_sha256?, policy_sha256?, approval_id?, started_at, heartbeat_at, lock_expires_at?, hard_deadline_at, stale_after.

## OperationEvent
event_id, operation_id, sequence, event_type, checkpoint?, lifecycle_state, terminal_outcome?, stage_id?, iteration?, elapsed_ms?, state digests, receipt_sha256?, lock_status?, safe_metadata, previous_event_sha256, event_sha256, created_at.

## Checkpoint
Optional event marker such as planned, approval_bound, lock_acquired, mutation_started, post_written, meta_write_completed, taxonomy_write_completed, media_write_completed, validation_started, acceptance_verified, publish_started, publish_verified, receipt_consumed, compensation_started, compensation_verified.

## LifecycleState
planned | running | validating | repairing | accepting | publishing | recovering | completed | terminal_failed

## TerminalOutcome
success | compensated | recovery_required | manual_required | rejected_before_start

## JournalHead
operation_id, latest_sequence, latest_event_sha256, lifecycle_state, terminal_outcome?, heartbeat_at, updated_at.

## ProviderManifest
provider_id, provider_contract_version, implementation_version, existing_registry_identity, supports, preconditions, validation_capabilities, repair_capabilities, verifier_capabilities, side_effects[], reversible_evidence_version, certification, soft_elapsed_budget_ms, enforceable_deadline_supported.

## SideEffect
id, effect_scope, ownership(core|provider|external|shared), reversibility(reversible|irreversible|unknown), compensation_support(none|best_effort|verified), externality(local|remote|external_system), resource_kind, verification_method, evidence_digest?.

## Impact
impact_level low|medium|high plus impact_flags publication|schema_change|external_side_effect|irreversible|recovery|provider_mutation.

## TtlDecision
purpose, tier short|standard|long, selected_seconds, refresh_threshold_seconds?, hard_deadline_seconds, policy_sha256.

## SemanticDiffEntry
path, kind, changed, presence flags, before/after sha256, lengths, optional bounded previews, redacted, severity.

## RecoveryCase
recovery_id, operation identities/binding, journal_head_sha256, detected checkpoint, lifecycle_state, current_state_sha256, provider_state_digest, pipeline_settings_sha256, policy_sha256, environment, blockers, actions, plan_sha256, generated_at, expires_at, approval_requirement, status.
