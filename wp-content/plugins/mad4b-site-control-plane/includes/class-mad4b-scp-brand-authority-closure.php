<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Read-only, site-neutral operational diagnosis for Brand Context recovery.
 * A diagnostic NEVER repairs OAuth, converges grants or approves a brand file.
 */
final class MAD4B_SCP_Brand_Authority_Closure {
    const CONTRACT = 'mad4b.brand-authority-operational-closure.v1';

    public static function summarize( $facts ) {
        $facts = is_array( $facts ) ? $facts : array();
        $google = isset( $facts['google'] ) && is_array( $facts['google'] ) ? $facts['google'] : array();
        $write = isset( $facts['write'] ) && is_array( $facts['write'] ) ? $facts['write'] : array();
        $context = isset( $facts['context'] ) && is_array( $facts['context'] ) ? $facts['context'] : array();
        $brand = isset( $facts['brand'] ) && is_array( $facts['brand'] ) ? $facts['brand'] : array();
        $registered = array( 'brand_strategy', 'tone_of_voice', 'editorial_guidelines' );
        $missing = array();
        foreach ( $registered as $category ) {
            if ( empty( $brand['coverage'][ $category ]['ready'] ) ) $missing[] = $category;
        }
        $provider_code = isset( $google['refresh_failure_provider_code'] ) ? sanitize_key( (string) $google['refresh_failure_provider_code'] ) : '';
        $invalid_grant = ! empty( $google['refresh_failed'] )
            && in_array( $provider_code, array( 'invalid_grant', 'invalid_client', 'unauthorized_client' ), true );
        $google_ok = ! empty( $google['connected'] ) && ! empty( $google['read_available'] )
            && empty( $google['refresh_failed'] ) && empty( $google['reconnect_required'] );
        $stale_count = isset( $write['stale_allow_grants_count'] ) ? max( 0, (int) $write['stale_allow_grants_count'] ) : 0;
        $missing_grants = isset( $write['exact_grants_missing_count'] ) ? max( 0, (int) $write['exact_grants_missing_count'] ) : 0;
        $write_ok = ! empty( $write['current_ready'] )
            && ! empty( $write['effective_ready'] )
            && 0 === $stale_count && 0 === $missing_grants
            && isset( $write['write_tool_count'], $write['exact_grants_existing'] )
            && (int) $write['write_tool_count'] === (int) $write['exact_grants_existing'];
        $staging = ! empty( $facts['exact_staging'] );
        $blockers = array();
        $actions = array();
        if ( ! $staging ) { $blockers[] = 'exact_staging_required'; $actions[] = 'verify_exact_site_profile'; }
        if ( ! $google_ok ) {
            $blockers[] = $invalid_grant ? 'google_reconsent_required' : 'google_connection_not_ready';
            $actions[] = $invalid_grant ? 'reconnect_site_owned_google_oauth' : 'inspect_site_google_connection';
        }
        if ( ! $write_ok ) {
            $blockers[] = $stale_count ? 'stale_write_grants_present' : 'write_authority_not_current';
            $actions[] = 'review_fresh_exact_write_convergence_plan';
        }
        if ( empty( $context['ready'] ) ) { $blockers[] = 'context_registry_not_ready'; $actions[] = 'inspect_governed_source_scan'; }
        if ( $missing ) { $blockers[] = 'three_category_brand_review_incomplete'; $actions[] = 'scan_and_review_exact_brand_assets'; }
        // A source inventory snapshot, an exact owner review receipt and a build
        // identity-bound Skills checkpoint are separate independent acceptances.
        // Do not infer them from a connected Google token or 101 grants.
        return array(
            'contract' => self::CONTRACT,
            'site' => array( 'exact_staging' => $staging ),
            'google' => array(
                'state' => $google_ok ? 'ready' : ( $invalid_grant ? 'owner_reconsent_required' : 'unavailable' ),
                'provider_code' => $invalid_grant ? $provider_code : '',
                'network_refresh_retry_allowed' => false,
                'site_oauth_reconnect_must_be_interactive' => $invalid_grant,
            ),
            'write' => array( 'current_ready' => $write_ok, 'stale_grants' => $stale_count, 'missing_grants' => $missing_grants,
                'reconcile_without_new_exact_approval' => false ),
            'brand' => array( 'ready' => empty( $missing ) && ! empty( $brand['ready'] ),
                'missing_categories' => $missing, 'owner_approval_inferred' => false ),
            'source_scan_readback_certified' => false,
            'skills_build_checkpoint_certified' => false,
            'provider_behavioral_closure_certified' => false,
            'release_and_browser_acceptance_certified' => false,
            'blockers' => array_values( array_unique( $blockers ) ),
            'next_actions' => array_values( array_unique( $actions ) ),
            'ready_for_publication' => false,
            'production_authorized' => false,
            'approval_issued' => false,
            'mutation_performed' => false,
            'read_only' => true,
        );
    }
}
