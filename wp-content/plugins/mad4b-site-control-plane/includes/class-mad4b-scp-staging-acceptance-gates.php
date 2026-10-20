<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Read-only, nonauthorizing summary of independent Staging acceptance gates.
 * No external browser claim, mutation receipt or cache concurrency probe is
 * manufactured from an HTTP 200, a registered provider, or a healthy drop-in.
 */
final class MAD4B_SCP_Staging_Acceptance_Gates {
    const CONTRACT = 'mad4b.staging-acceptance-gates.v1';

    public static function collect() {
        $skills = class_exists( 'MAD4B_SCP_Skill_Runtime_Certification' )
            ? MAD4B_SCP_Skill_Runtime_Certification::current_status() : array();
        $browser = class_exists( 'MAD4B_SCP_Browser_Acceptance_Core' )
            ? MAD4B_SCP_Browser_Acceptance_Core::capabilities() : array();
        $cache = class_exists( 'MAD4B_SCP_Object_Cache_Diagnostics' )
            ? MAD4B_SCP_Object_Cache_Diagnostics::status() : array();
        // Existence of a mounted Ability is discovery, not write certification.
        $abilities = array();
        foreach ( array( 'mad4b/acceptance-target-status', 'mad4b/acceptance-target-provision',
            'mad4b/content-update-post', 'mad4b/mutation-undo', 'rankmath/status' ) as $name )
            $abilities[ $name ] = function_exists( 'wp_has_ability' ) && wp_has_ability( $name );
        return self::reduce( array( 'skills' => $skills, 'browser' => $browser,
            'cache' => $cache, 'abilities' => $abilities ) );
    }

    public static function reduce( array $facts ) {
        $skills = isset( $facts['skills'] ) && is_array( $facts['skills'] ) ? $facts['skills'] : array();
        $browser = isset( $facts['browser'] ) && is_array( $facts['browser'] ) ? $facts['browser'] : array();
        $cache = isset( $facts['cache'] ) && is_array( $facts['cache'] ) ? $facts['cache'] : array();
        $abilities = isset( $facts['abilities'] ) && is_array( $facts['abilities'] ) ? $facts['abilities'] : array();
        $skill_ready = true === ( $skills['ready'] ?? null )
            && 'ready' === (string) ( $skills['state'] ?? '' )
            && empty( $skills['blockers'] );
        // Do not downgrade a new live certification because a separate persisted
        // build checkpoint is historical. Nor can saved READY prove current live.
        $live_source = (string) ( $skills['persistence'] ?? '' );
        $skill_current = $skill_ready && 'historical_evidence_only' !== $live_source
            && false !== ( $skills['build_identity_current'] ?? null );
        $browser_provider_count = max( 0, (int) ( $browser['provider_count'] ?? 0 ) );
        $browser_discovery = true === ( $browser['site_discovery']['discovery_complete'] ?? null );
        $backend_hint = (string) ( $cache['implementation_hint'] ?? 'unknown' );
        $backend_mode = (string) ( $cache['ajax_rate_limit_mode'] ?? 'unknown' );
        $cache_proofs = array();
        foreach ( array( 'cross_request_proven', 'atomic_increment_proven', 'ttl_expiration_proven',
            'multi_node_shared_storage_proven', 'connection_proven' ) as $key )
            $cache_proofs[ $key ] = true === ( $cache[ $key ] ?? null );
        $rate_certified = ! in_array( false, $cache_proofs, true );
        // Registration of Rank Math's read surface cannot authorize SEO write.
        $rankmath_read_mounted = true === ( $abilities['rankmath/status'] ?? null );
        return array(
            'contract' => self::CONTRACT, 'read_only' => true, 'authorizing' => false,
            'mutation_performed' => false, 'production_authorized' => false,
            'full_operational_acceptance' => false,
            'skills' => array(
                'state' => $skill_current ? 'PASS_LIVE_RUNTIME' : 'NOT_CURRENTLY_VERIFIED',
                'live_runtime_ready' => $skill_current,
                'reconcile_required' => $skill_current ? false : null,
                'historical_checkpoint_is_independent' => true,
                'next_action' => $skill_current ? 'none' : 'read_current_skills_runtime_certification',
            ),
            'browser' => array(
                'state' => 'EXTERNAL_EXECUTOR_RECEIPT_MISSING',
                'provider_count' => $browser_provider_count,
                'site_discovery_complete' => $browser_discovery,
                'external_executor_claim_verified' => false,
                'provider_checkpoint_entered_verified' => false,
                'browser_runtime_parity_verified' => false,
                'expired_unclaimed_task_is_javascript_failure' => false,
                'blind_resubmit_allowed' => false,
                'next_action' => $browser_provider_count && $browser_discovery
                    ? 'verify_external_executor_identity_claim_and_task_handoff'
                    : 'configure_and_verify_external_browser_executor',
            ),
            'acceptance_target' => array(
                'state' => 'INDEPENDENT_ISOLATED_TARGET_READBACK_REQUIRED',
                'status_ability_mounted' => true === ( $abilities['mad4b/acceptance-target-status'] ?? null ),
                'provision_ability_mounted' => true === ( $abilities['mad4b/acceptance-target-provision'] ?? null ),
                'exact_staging_target_verified' => false,
                'owner_approval_inferred' => false,
                'next_action' => 'read_exact_target_status_then_plan_separate_approved_provision',
            ),
            'mutation_round_trip' => array(
                'state' => 'APPLY_VERIFY_UNDO_RECEIPTS_MISSING',
                'update_ability_mounted' => true === ( $abilities['mad4b/content-update-post'] ?? null ),
                'undo_ability_mounted' => true === ( $abilities['mad4b/mutation-undo'] ?? null ),
                'apply_verified' => false, 'after_state_readback_verified' => false,
                'undo_verified' => false, 'final_state_restored_verified' => false,
                'automatic_content_write_allowed' => false,
                'next_action' => 'exact_target_scoped_approval_apply_verify_separate_undo_approval',
            ),
            'rate_limiting' => array(
                'state' => $rate_certified ? 'ALL_SUPPLIED_PROOFS_PRESENT_REQUIRES_INDEPENDENT_ATTESTATION'
                    : 'CROSS_REQUEST_CONCURRENCY_PROOFS_MISSING',
                'cache_mode_hint' => $backend_mode, 'implementation_hint' => $backend_hint,
                'persistent_cache_reported' => true === ( $cache['persistent_object_cache_reported'] ?? null ),
                'object_cache_dropin_present' => true === ( $cache['object_cache_dropin_present'] ?? null ),
                'proofs' => $cache_proofs,
                'multi_worker_or_node_acceptance_certified' => false,
                'next_action' => 'run_authorized_cross_request_ttl_parallel_worker_and_node_probe',
            ),
            'seo_write' => array(
                'state' => 'PROVIDER_WRITE_CERTIFICATION_REQUIRED',
                'rankmath_read_mounted' => $rankmath_read_mounted,
                'rankmath_write_certified' => false,
                'write_authorization_inferred_from_read' => false,
                'next_action' => 'collect_exact_rankmath_write_certification_and_rollback_evidence',
            ),
            'http_rest_success_is_browser_acceptance' => false,
            'semantic_direct_ajax_success_is_browser_acceptance' => false,
            'required_independent_gates' => array(
                'external_browser_executor_signed_receipt',
                'isolated_staging_target_exact_owner_approval',
                'apply_after_state_undo_final_state_receipts',
                'cross_request_parallel_and_ttl_cache_evidence',
                'rankmath_write_provider_certification',
                'browser_seo_wpml_and_performance_evidence',
            ),
        );
    }
}
