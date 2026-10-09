<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Governed, deterministic Context reconstruction state machine.
 *
 * Read-only. Every suggested write remains an independent governed ability
 * with its own authority, current content fingerprint, approval and readback.
 * A simulated scenario is never evidence or a permission for execution.
 */
final class MAD4B_SCP_Brand_Context_Reconstruction {
    const CONTRACT = 'mad4b.brand-context-reconstruction-plan.v1';
    const MAX_ATTEMPTS = 3;
    const MAX_ITEMS = 16;

    public static function scenarios() {
        return array(
            'live', 'missing_file', 'provider_unavailable', 'stale_version',
            'malformed_file', 'conflicting_authorities', 'insufficient_evidence',
            'assistant_unavailable', 'provider_timeout', 'repeated_failure',
            'draft_not_materialized', 'review_denied', 'receipt_drift',
            'asset_rights_unverified', 'source_scan_incomplete',
        );
    }

    /** Deterministic pure classifier: does not inspect external state or mutate. */
    public static function classify( $category, $observed, $scenario = 'live', $attempts = 0, $assistant_available = true ) {
        $category = sanitize_key( (string) $category );
        $scenario = sanitize_key( (string) $scenario );
        $attempts = max( 0, (int) $attempts );
        if ( ! in_array( $category, array( 'brand_strategy', 'tone_of_voice', 'editorial_guidelines' ), true ) ) {
            return new WP_Error( 'mad4b_brand_reconstruction_category_invalid', 'Category is not part of the required Brand Core.' );
        }
        if ( ! in_array( $scenario, self::scenarios(), true ) ) {
            return new WP_Error( 'mad4b_brand_reconstruction_scenario_invalid', 'Unsupported recovery scenario.' );
        }
        $observed = is_array( $observed ) ? $observed : array();
        $ready = ! empty( $observed['ready'] );
        $conflict = ! empty( $observed['conflict'] );
        $requires_owner = 'brand_strategy' === $category;
        $dependency_ok = ! array_key_exists( 'dependency_ready', $observed ) || ! empty( $observed['dependency_ready'] );
        $evidence_ok = ! empty( $observed['generation_ready'] );
        $writable = ! empty( $observed['writable_source'] );

        $state = 'BLOCKED';
        $reason = 'missing_authoritative_context';
        $action = 'collect_authoritative_source';
        $assistant_role = 'none';
        $mutation_eligible = false;
        $next_ability = 'context/review-queue';
        $human_required = true;

        // Real evidence remains primary. A simulation may only provide a
        // hypothetical diagnosis; it never authorizes a mutation.
        if ( 'live' === $scenario && $ready ) {
            $state = 'READY';
            $reason = 'approved_exact_current_context';
            $action = 'readback';
            $next_ability = 'context/brand-core-coverage';
            $human_required = false;
        } elseif ( $conflict || 'conflicting_authorities' === $scenario ) {
            $state = 'HUMAN_ARBITRATION';
            $reason = 'competing_brand_authority';
            $action = 'resolve_review_conflict';
        } elseif ( 'asset_rights_unverified' === $scenario ) {
            $state = 'RIGHTS_REVIEW';
            $reason = 'rights_evidence_missing';
            $action = 'obtain_external_usage_authorization';
            $next_ability = 'mad4b/external-source-rights-preflight';
        } elseif ( 'review_denied' === $scenario ) {
            $state = 'HUMAN_REVIEW';
            $reason = 'review_rejected_or_changes_requested';
            $action = 'address_review_notes_and_replan';
        } elseif ( $attempts >= self::MAX_ATTEMPTS || 'repeated_failure' === $scenario ) {
            $state = 'CIRCUIT_OPEN';
            $reason = 'bounded_retry_exhausted';
            $action = 'operator_recovery_and_fresh_evidence';
        } elseif ( in_array( $scenario, array( 'provider_unavailable', 'provider_timeout' ), true ) ) {
            $state = 'SOURCE_RECOVERY';
            $reason = 'provider_communication_unavailable';
            $action = 'reconnect_provider_before_recreating';
            $next_ability = 'context/provider-capabilities';
        } elseif ( in_array( $scenario, array( 'stale_version', 'receipt_drift' ), true ) ) {
            $state = 'RESCAN_REQUIRED';
            $reason = 'identity_or_content_drift';
            $action = 'rescan_reconcile_and_renew_exact_approval';
            $next_ability = 'context/source-scan-plan';
        } elseif ( 'malformed_file' === $scenario ) {
            $state = 'NORMALIZATION_REQUIRED';
            $reason = 'source_format_incomplete';
            $action = 'repair_format_and_rescan';
            $next_ability = 'context/source-scan-plan';
        } elseif ( 'draft_not_materialized' === $scenario ) {
            $state = 'MATERIALIZATION_RECONCILE';
            $reason = 'draft_exists_without_verified_provider_asset';
            $action = 'reconcile_existing_draft_before_retry';
            $next_ability = 'context/brand-core-convergence-plan';
        } elseif ( in_array( $scenario, array( 'source_scan_incomplete', 'missing_file' ), true ) && ! $requires_owner && $dependency_ok ) {
            $state = 'SOURCE_DISCOVERY';
            $reason = 'source_recovery_or_exact_empty_scan_required';
            $action = 'verify_complete_governed_source_scan_before_recreating';
            $next_ability = 'context/source-scan-plan';
        } elseif ( $requires_owner ) {
            // Strategy is never synthesized from arbitrary website/competitor
            // content into authoritative Brand Core.
            $state = 'OWNER_AUTHORITY_REQUIRED';
            $reason = 'strategy_not_generatable';
            $action = 'restore_or_supply_owner_approved_brand_strategy';
            $next_ability = 'context/review-queue';
        } elseif ( ! $dependency_ok ) {
            $state = 'WAIT_DEPENDENCY';
            $reason = 'prerequisite_brand_core_unapproved';
            $action = 'complete_upstream_authority_first';
            $next_ability = 'context/brand-core-coverage';
        } elseif ( ! $evidence_ok ) {
            $state = 'EVIDENCE_COLLECTION';
            $reason = 'generation_evidence_unverified';
            $action = 'collect_primary_evidence_and_quality_samples';
            $next_ability = 'context/brand-gap-plan';
            $assistant_role = $assistant_available ? 'researcher' : 'human_researcher';
        } elseif ( ! $writable ) {
            $state = 'DESTINATION_RECOVERY';
            $reason = 'no_governed_writable_destination';
            $action = 'reconnect_managed_context_destination';
            $next_ability = 'context/provider-capabilities';
        } elseif ( ! $assistant_available || 'assistant_unavailable' === $scenario ) {
            $state = 'HUMAN_DRAFT_PREPARATION';
            $reason = 'assistant_unavailable_or_not_certified';
            $action = 'prepare_draft_with_human_writer';
            $next_ability = 'context/brand-draft-preflight';
            $assistant_role = 'human_writer';
        } else {
            $state = 'DRAFT_PREPARATION';
            $reason = 'eligible_to_prepare_unapproved_draft';
            $action = 'synthesize_reviewable_draft';
            $next_ability = 'context/brand-draft-preflight';
            $assistant_role = 'writer';
            $mutation_eligible = false; // planning never grants a write
        }
        return array(
            'category' => $category,
            'state' => $state,
            'reason_code' => $reason,
            'suggested_action' => $action,
            'next_read_ability' => $next_ability,
            'assistant_role' => $assistant_role,
            'owner_decision_required' => $human_required,
            'mutation_eligible' => $mutation_eligible,
            'attempts' => $attempts,
            'retry_budget_remaining' => max( 0, self::MAX_ATTEMPTS - $attempts ),
            'automatic_approval' => false,
        );
    }

    /** Ordered dispatch recipe; each write needs its own external authorization. */
    public static function procedure_for_state( $state ) {
        $state = strtoupper( trim( (string) $state ) );
        $read = static function ( $ability, $condition = '' ) {
            return array( 'ability' => $ability, 'lane' => 'read', 'approval_required' => false, 'when' => $condition );
        };
        $write = static function ( $ability, $condition = '' ) {
            return array(
                'ability' => $ability, 'lane' => 'write',
                'approval_required' => true, 'exact_new_ticket_required' => true,
                'fresh_context_and_site_binding_required' => true,
                'independent_readback_required' => true, 'when' => $condition,
            );
        };
        switch ( $state ) {
            case 'READY':
                return array( $read( 'context/brand-core-coverage', 'verify_exact_approved_content_before_use' ) );
            case 'OWNER_AUTHORITY_REQUIRED':
            case 'HUMAN_ARBITRATION':
            case 'HUMAN_REVIEW':
                return array( $read( 'context/review-queue', 'owner_or_independent_review_decision' ) );
            case 'RIGHTS_REVIEW':
                return array( $read( 'mad4b/external-source-rights-preflight', 'independent_signed_rights_evidence_needed' ) );
            case 'SOURCE_RECOVERY':
            case 'DESTINATION_RECOVERY':
                return array(
                    $read( 'context/provider-capabilities', 'reconnect_exact_managed_source_first' ),
                    $read( 'context/source-scan-plan', 'only_after_provider_recovery' ),
                );
            case 'SOURCE_DISCOVERY':
                return array(
                    $read( 'context/provider-capabilities', 'verify_enrolled_source_health' ),
                    $read( 'context/source-scan-plan', 'establish_complete_governed_source_scan_and_exact_absence' ),
                    $write( 'context/source-scan-apply', 'only_for_exact_approved_complete_source_scan' ),
                    $read( 'context/brand-core-coverage', 'recheck_current_approved_source_before_draft' ),
                );
            case 'NORMALIZATION_REQUIRED':
            case 'RESCAN_REQUIRED':
                return array(
                    $read( 'context/source-scan-plan', 'exact_authority_and_source_fingerprint' ),
                    $write( 'context/source-scan-apply', 'only_after_exact_source_scan_approval' ),
                    $read( 'context/brand-core-coverage', 'fresh_post_scan_readback' ),
                );
            case 'WAIT_DEPENDENCY':
                return array( $read( 'context/brand-core-coverage', 'approved_upstream_category_required' ) );
            case 'EVIDENCE_COLLECTION':
                return array( $read( 'context/brand-gap-plan', 'primary_evidence_quality_and_researcher_review' ) );
            case 'HUMAN_DRAFT_PREPARATION':
            case 'DRAFT_PREPARATION':
                return array(
                    $read( 'context/brand-gap-plan', 'independent_evidence_review' ),
                    $read( 'context/brand-draft-preflight', 'writer_critic_separation_and_source_hash' ),
                    $write( 'context/brand-draft-create', 'approval_for_new_unapproved_draft_only' ),
                    $write( 'context/materialize-brand-draft', 'fresh_exact_materialization_approval' ),
                    $read( 'context/review-queue', 'different_reviewer_required' ),
                    $read( 'context/brand-core-coverage', 'after_independent_review_and_rescan' ),
                );
            case 'MATERIALIZATION_RECONCILE':
                return array(
                    $read( 'context/source-scan-plan', 'inspect_prior_idempotency_and_provider_receipts' ),
                    $write( 'context/reconcile-brand-materialization', 'only_when_prior_exact_checkpoint_proven' ),
                    $read( 'context/brand-core-coverage', 'verify_exact_restored_state' ),
                );
            case 'CIRCUIT_OPEN':
                return array( $read( 'context/review-queue', 'human_reset_and_fresh_evidence_only' ) );
            default:
                return array();
        }
    }

    public static function plan( $input = array() ) {
        $input = is_array( $input ) ? $input : array();
        $scenario = isset( $input['scenario'] ) ? sanitize_key( (string) $input['scenario'] ) : 'live';
        if ( ! in_array( $scenario, self::scenarios(), true ) ) {
            return new WP_Error( 'mad4b_brand_reconstruction_scenario_invalid', 'Unsupported recovery scenario.' );
        }
        $attempts = isset( $input['attempts'] ) ? max( 0, min( 100, (int) $input['attempts'] ) ) : 0;
        // Caller-provided availability cannot turn an uncertified assistant
        // into a trusted writer. Live runtime certification is independent.
        $assistant_requested = ! array_key_exists( 'assistant_available', $input ) || ! empty( $input['assistant_available'] );
        $assistant_status = class_exists( 'MAD4B_SCP_Skill_Runtime_Certification' )
            ? MAD4B_SCP_Skill_Runtime_Certification::current_status() : array();
        $assistant_certified = is_array( $assistant_status ) && ! empty( $assistant_status['ready'] )
            && empty( $assistant_status['historical_evidence_only'] );
        $assistant_available = $assistant_requested && $assistant_certified;
        $convergence = MAD4B_SCP_Brand_Context_Builder::convergence_plan( array(
            'include_authoritative_content' => false,
            'include_rendered_frontend' => false,
        ) );
        if ( is_wp_error( $convergence ) ) return $convergence;
        $coverage = MAD4B_SCP_Context_Authority::brand_core_coverage();
        if ( is_wp_error( $coverage ) ) return $coverage;
        $actions = isset( $convergence['actions'] ) && is_array( $convergence['actions'] ) ? $convergence['actions'] : array();
        $by_category = array();
        foreach ( $actions as $item ) {
            if ( is_array( $item ) && isset( $item['category'] ) ) $by_category[ $item['category'] ] = $item;
        }
        // A complete authoritative scan is required before a missing file
        // can be treated as eligible for recreation. Status is observed, not
        // inferred from the existence of a writable Drive destination.
        $source_scan_complete = false;
        if ( method_exists( 'MAD4B_SCP_Context_Authority', 'sources' ) ) {
            $governed_count = 0;
            $incomplete_count = 0;
            foreach ( MAD4B_SCP_Context_Authority::sources() as $source ) {
                if ( ! is_array( $source ) || 'governed' !== ( isset( $source['mode'] ) ? (string) $source['mode'] : '' ) ) continue;
                ++$governed_count;
                if ( empty( $source['last_scan_complete'] ) || 'ready' !== ( isset( $source['status'] ) ? (string) $source['status'] : '' ) ) ++$incomplete_count;
            }
            $source_scan_complete = $governed_count > 0 && 0 === $incomplete_count;
        }
        $matrix = array();
        foreach ( MAD4B_SCP_Brand_Context_Builder::expected_categories() as $category => $label ) {
            $observed = isset( $coverage['coverage'][ $category ] ) && is_array( $coverage['coverage'][ $category ] ) ? $coverage['coverage'][ $category ] : array();
            $action = isset( $by_category[ $category ] ) ? $by_category[ $category ] : array();
            $observed['generation_ready'] = 'ready_to_create' === ( isset( $action['state'] ) ? $action['state'] : '' );
            $observed['writable_source'] = ! empty( $convergence['writable_sources'] );
            $observed['dependency_ready'] = 'brand_strategy' === $category
                || ( ! empty( $coverage['coverage']['brand_strategy']['ready'] )
                    && ( 'editorial_guidelines' !== $category || ! empty( $coverage['coverage']['tone_of_voice']['ready'] ) ) );
            // Derive real repair states from exact Context Authority per-asset
            // reasons; scenario input is only an explicit non-authorizing test.
            $diagnosed = $scenario;
            if ( 'live' === $scenario && empty( $observed['ready'] ) && empty( $observed['conflict'] ) ) {
                $reasons = array();
                foreach ( isset( $observed['observed_assets'] ) && is_array( $observed['observed_assets'] ) ? $observed['observed_assets'] : array() as $asset ) {
                    if ( ! is_array( $asset ) ) continue;
                    foreach ( isset( $asset['reasons'] ) && is_array( $asset['reasons'] ) ? $asset['reasons'] : array() as $reason ) {
                        $reasons[] = (string) $reason;
                    }
                }
                $blockers = isset( $action['blockers'] ) && is_array( $action['blockers'] ) ? $action['blockers'] : array();
                foreach ( $blockers as $blocker ) {
                    if ( 0 === strpos( (string) $blocker, 'authority_read_failed:' ) ) $diagnosed = 'provider_unavailable';
                }
                if ( 'live' === $diagnosed && in_array( 'content_incomplete', $reasons, true ) ) $diagnosed = 'malformed_file';
                if ( 'live' === $diagnosed && in_array( 'generation_evidence_stale', $reasons, true ) ) $diagnosed = 'stale_version';
                if ( 'live' === $diagnosed && in_array( 'review_not_exactly_bound', $reasons, true ) ) $diagnosed = 'receipt_drift';
                if ( 'live' === $diagnosed && empty( $observed['observed_assets'] ) && ! $source_scan_complete
                    && ! empty( $observed['dependency_ready'] ) ) $diagnosed = 'source_scan_incomplete';
            }
            $result = self::classify( $category, $observed, $diagnosed, $attempts, $assistant_available );
            if ( is_wp_error( $result ) ) return $result;
            $result['observed_scenario'] = $diagnosed;
            $result['detected_from_live_evidence'] = 'live' === $scenario && 'live' !== $diagnosed;
            $result['label'] = $label;
            $result['live_ready'] = ! empty( $observed['ready'] );
            $result['recovery_procedure'] = self::procedure_for_state( $result['state'] );
            $result['plan_blockers'] = isset( $action['blockers'] ) && is_array( $action['blockers'] ) ? array_values( $action['blockers'] ) : array();
            $matrix[] = $result;
        }
        $basis = array(
            'contract' => self::CONTRACT,
            'state' => 'live' === $scenario ? 'evidence_bound_plan' : 'simulation_only',
            'scenario' => $scenario,
            'scenario_is_hypothetical' => 'live' !== $scenario,
            'registry_revision' => isset( $convergence['registry_revision'] ) ? (int) $convergence['registry_revision'] : 0,
            'authority_manifest_fingerprint' => isset( $convergence['authority_manifest_fingerprint'] ) ? (string) $convergence['authority_manifest_fingerprint'] : '',
            'convergence_plan_sha256' => isset( $convergence['plan_sha256'] ) ? (string) $convergence['plan_sha256'] : '',
            'assistant_declared_available' => $assistant_requested,
            'assistant_certification_verified' => $assistant_certified,
            'assistant_effectively_available' => $assistant_available,
            // Managed Skills readiness proves the catalog/runtime; it does
            // not prove exact Agent/ability authorization or role separation.
            'assistant_exact_mutation_grant_verified' => false,
            'assistant_can_execute_writes' => false,
            'assistant_writer_reviewer_independence_verified' => false,
            'assistant_roles' => array( 'evidence_researcher', 'draft_writer', 'independent_critic', 'policy_reviewer' ),
            'assistant_fallback' => 'human_without_automatic_approval',
            'review_separation_of_duties' => true,
            'source_preference' => array( 'current_approved_asset', 'verified_provider_restore', 'source_rescan', 'new_unapproved_draft' ),
            'idempotency_basis' => 'site_uuid_category_evidence_digest_registry_revision',
            'recover_before_recreate' => true,
            'retry_limit_per_stage' => self::MAX_ATTEMPTS,
            'retry_counter_source' => 'untrusted_request_advisory',
            'retry_counter_persisted' => false,
            'retry_budget_enforced_by_planner' => false,
            'execution_requires_authoritative_retry_journal' => true,
            'governed_source_scan_complete' => $source_scan_complete,
            'states' => $matrix,
            'transitions' => array(
                array( 'from' => 'SOURCE_RECOVERY', 'on' => 'provider_recovered', 'to' => 'RESCAN_REQUIRED' ),
                array( 'from' => 'SOURCE_DISCOVERY', 'on' => 'complete_governed_scan_verified', 'to' => 'EVIDENCE_COLLECTION' ),
                array( 'from' => 'RESCAN_REQUIRED', 'on' => 'exact_evidence_verified', 'to' => 'EVIDENCE_COLLECTION' ),
                array( 'from' => 'WAIT_DEPENDENCY', 'on' => 'upstream_owner_approved', 'to' => 'EVIDENCE_COLLECTION' ),
                array( 'from' => 'EVIDENCE_COLLECTION', 'on' => 'independent_quality_pass', 'to' => 'DRAFT_PREPARATION' ),
                array( 'from' => 'DRAFT_PREPARATION', 'on' => 'fresh_write_approval', 'to' => 'MATERIALIZATION_RECONCILE' ),
                array( 'from' => 'MATERIALIZATION_RECONCILE', 'on' => 'provider_readback_match', 'to' => 'HUMAN_REVIEW' ),
                array( 'from' => 'HUMAN_REVIEW', 'on' => 'owner_or_delegated_review_approved', 'to' => 'RESCAN_REQUIRED' ),
                array( 'from' => 'RESCAN_REQUIRED', 'on' => 'coverage_exact_ready', 'to' => 'READY' ),
                array( 'from' => 'CIRCUIT_OPEN', 'on' => 'human_reset_with_new_evidence', 'to' => 'RESCAN_REQUIRED' ),
            ),
            'next_write_abilities' => array( 'context/brand-draft-create', 'context/materialize-brand-draft', 'context/reconcile-brand-materialization' ),
            'writes_require_separate_approval' => true,
            'production_mutation_authorized' => false,
            'synthetic_authority_allowed' => false,
            'automated_rights_approval_allowed' => false,
            'read_only' => true,
            'authorizing' => false,
            'mutation_performed' => false,
        );
        $basis['plan_sha256'] = hash( 'sha256', wp_json_encode( $basis, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
        return $basis;
    }
}
