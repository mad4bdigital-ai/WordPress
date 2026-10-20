<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Read-only, environment-derived workflow guidance. No caller supplied
 * environment may override WordPress + the exact enrolled Site Profile.
 *
 * This never grants authority or runs a child write: the target's existing
 * Staging enrollment and Production exact-batch policies remain authoritative.
 */
final class MAD4B_SCP_Environment_Journey {
    const CONTRACT = 'mad4b.environment-guided-journey.v1';
    const KINDS = array(
        'diagnostics', 'derived_state', 'operational_repair',
        'write_authority', 'content_publish', 'plugin_update', 'deployment'
    );

    /**
     * Defense in depth: a nested Staging review is advisory only and must
     * remain exactly bound to this site's identity and one registered route.
     */
    private static function exact_staging_request( $request, $site_uuid ) {
        if ( ! is_array( $request ) || ! is_string( $site_uuid )
            || ( $request['operation_id'] ?? '' ) !== 'staging_write_authority_convergence'
            || ! isset( $request['input'] ) || ! is_array( $request['input'] )
            || ( $request['input']['expected_site_uuid'] ?? '' ) !== $site_uuid
            || ( $request['input']['confirmation'] ?? '' ) !== 'ENABLE GOVERNED STAGING WRITE AUTHORITY' )
            return false;
        foreach ( array( 'expected_registration_digest', 'expected_dispatch_policy_digest',
            'expected_input_schema_sha256' ) as $field )
            if ( ! isset( $request[ $field ] ) || ! is_string( $request[ $field ] )
                || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $request[ $field ] ) ) return false;
        foreach ( array( 'expected_plan_sha256', 'expected_profile_digest',
            'owner_review_digest' ) as $field )
            if ( ! isset( $request['input'][ $field ] ) || ! is_string( $request['input'][ $field ] )
                || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $request['input'][ $field ] ) ) return false;
        if ( ! isset( $request['input']['expected_source_commit_sha'] )
            || ! is_string( $request['input']['expected_source_commit_sha'] )
            || 1 !== preg_match( '/^[a-f0-9]{40}$/D', $request['input']['expected_source_commit_sha'] ) )
            return false;
        return true;
    }

    /** Pure reducer, deliberately usable without a running WordPress site. */
    public static function model( $environment_resolution, $identity, $operation_kind, $production_batch_active = false, $staging_guided = null ) {
        $resolution = is_array( $environment_resolution ) ? $environment_resolution : array();
        $identity = is_array( $identity ) ? $identity : array();
        $environment = isset( $resolution['effective_environment'] ) && is_string( $resolution['effective_environment'] )
            ? strtolower( $resolution['effective_environment'] ) : 'unknown';
        $kind = is_string( $operation_kind ) ? strtolower( $operation_kind ) : '';
        $base = array(
            'contract' => self::CONTRACT, 'environment' => $environment,
            'operation_kind' => $kind, 'state' => 'blocked',
            'next_action' => 'inspect_environment_and_enrollment',
            'next_read_ability' => 'mad4b/site-profile-status',
            'read_only' => true, 'mutation_performed' => false,
            'automatic_execution' => false, 'owner_decision_recorded' => false,
            'authority_granted' => false, 'production_mutation_allowed' => false,
            'developer_included' => false, 'breakglass_included' => false,
            'blind_retry_allowed' => false, 'prepared_request' => null,
            'approval_policy' => 'exact_operation_authority_required',
        );
        if ( ! in_array( $environment, array( 'local', 'development', 'staging', 'production' ), true ) )
            return $base + array( 'blockers' => array( 'unknown_environment' ) );
        if ( ! in_array( $kind, self::KINDS, true ) )
            return $base + array( 'blockers' => array( 'unsupported_operation_kind' ) );
        if ( ! empty( $resolution['wordpress_profile_mismatch'] )
            && empty( $resolution['profile_environment_authoritative'] ) )
            return $base + array( 'blockers' => array( 'wordpress_profile_environment_conflict' ) );
        if ( empty( $identity['configured'] ) || empty( $identity['enrolled'] )
            || empty( $identity['origin_match'] ) || empty( $identity['site_uuid'] ) )
            return $base + array( 'blockers' => array( 'exact_site_identity_unverified' ) );

        $base['state'] = 'readiness_discovery';
        $base['next_action'] = 'read_governed_operation_plan';
        $base['next_read_ability'] = 'mad4b/operation-route-plan';
        if ( 'diagnostics' === $kind ) {
            $base['state'] = 'read_only_ready';
            $base['next_action'] = 'collect_session_safe_diagnostics';
            $base['next_read_ability'] = 'mad4b/session-safe-diagnostics';
            $base['approval_policy'] = 'none_for_read_only';
        } elseif ( 'derived_state' === $kind ) {
            $base['state'] = 'bounded_plan_required';
            $base['next_action'] = 'inspect_derived_state_plan_and_limits';
            $base['next_read_ability'] = 'mad4b/operational-remediation-prepare';
        } elseif ( 'operational_repair' === $kind ) {
            $base['state'] = 'governed_operation_discovery';
            $base['next_action'] = 'discover_runtime_registered_repair_plan';
            $base['next_read_ability'] = 'mad4b/operation-route-discover';
        } elseif ( 'write_authority' === $kind && 'staging' === $environment ) {
            $base['next_read_ability'] = 'mad4b/enrollment-discover';
            $base['next_read_input'] = array( 'query' => 'staging write authority convergence' );
            $base['next_action'] = 'inspect_exact_owner_write_handoff';
            $base['state'] = 'staging_owner_review_path';
            $base['approval_policy'] = 'exact_owner_review_plus_oauth_step_up';
            if ( is_array( $staging_guided ) && isset( $staging_guided['state'] ) ) {
                $base['guided_handoff'] = $staging_guided;
                $base['state'] = (string) $staging_guided['state'];
                $base['next_action'] = isset( $staging_guided['next_action'] )
                    ? (string) $staging_guided['next_action'] : $base['next_action'];
                // A *read* endpoint can quote the exact bound dispatcher input,
                // but may never authorize it or execute it.
                if ( 'ready_for_exact_owner_decision' === $base['state'] ) {
                    if ( ! empty( $staging_guided['owner_decision_required'] )
                        && self::exact_staging_request(
                            $staging_guided['prepared_request'] ?? null, $identity['site_uuid']
                        ) )
                        $base['prepared_request'] = $staging_guided['prepared_request'];
                    else {
                        $base['state'] = 'invalid_staging_handoff';
                        $base['next_action'] = 'refresh_exact_enrollment_owner_review';
                    }
                }
            }
        } elseif ( 'production' === $environment && 'write_authority' === $kind ) {
            // A finite content/plugin batch is NOT a grant-reconciliation or
            // environment-identity approval, even when its policy is active.
            $base['state'] = 'separate_production_write_authority_review_required';
            $base['next_action'] = 'inspect_exact_production_write_authority_policy';
            $base['next_read_ability'] = 'mad4b/deployment-binding-production-status';
            $base['approval_policy'] = 'independent_exact_production_write_owner_approval';
        } elseif ( 'production' === $environment ) {
            $base['state'] = $production_batch_active ? 'exact_batch_scope_check_required' : 'production_owner_batch_required';
            $base['next_action'] = $production_batch_active
                ? 'verify_exact_batch_item_and_signed_test_certificate'
                : 'request_separate_production_owner_batch';
            $base['next_read_ability'] = 'mad4b/production-certification-plan';
            $base['approval_policy'] = 'production_exact_owner_batch_and_signed_test_certificate';
        } elseif ( 'write_authority' === $kind ) {
            $base['state'] = 'nonstaging_authority_policy_required';
            $base['next_action'] = 'inspect_native_environment_write_policy';
            $base['next_read_ability'] = 'mad4b/write-authority-status';
        } else {
            $base['state'] = 'exact_governed_plan_required';
            $base['next_action'] = 'discover_exact_site_operation_and_approval';
            $base['next_read_ability'] = 'mad4b/operation-route-plan';
        }
        return $base;
    }

    public static function read( $input = array() ) {
        $input = is_array( $input ) ? $input : array();
        $kind = isset( $input['operation_kind'] ) && is_string( $input['operation_kind'] )
            ? $input['operation_kind'] : 'diagnostics';
        if ( ! class_exists( 'MAD4B_SCP_Site_Profile' ) ) {
            return self::model( array(), array(), $kind );
        }
        $environment = MAD4B_SCP_Site_Profile::environment_resolution();
        $identity = array(
            'configured' => MAD4B_SCP_Site_Profile::configured(),
            'enrolled' => MAD4B_SCP_Site_Profile::origin_enrolled(),
            'origin_match' => MAD4B_SCP_Site_Profile::site_urls_match_enrollment(),
            'site_uuid' => MAD4B_SCP_Site_Profile::site_uuid(),
        );
        $valid = $identity['configured'] && $identity['enrolled'] && $identity['origin_match'];
        $guided = null;
        if ( $valid && 'staging' === ( $environment['effective_environment'] ?? '' )
            && 'write_authority' === $kind && class_exists( 'MAD4B_SCP_Enrollment_Dispatch' ) ) {
            // Source-registered single-intent Enrollment routing, not Write catalog.
            $discovered = MAD4B_SCP_Enrollment_Dispatch::discover( array(
                'query' => 'staging write authority convergence', 'limit' => 1
            ) );
            $item = isset( $discovered['operations']['staging_write_authority_convergence'] )
                ? $discovered['operations']['staging_write_authority_convergence'] : array();
            $guided = isset( $item['guided_handoff'] ) && is_array( $item['guided_handoff'] )
                ? $item['guided_handoff'] : null;
        }
        $production_batch_active = $valid && 'production' === ( $environment['effective_environment'] ?? '' )
            && class_exists( 'MAD4B_SCP_Production_Automation' )
            && MAD4B_SCP_Production_Automation::active();
        $result = self::model( $environment, $identity, $kind, $production_batch_active, $guided );
        $result['site_uuid'] = $valid ? (string) $identity['site_uuid'] : '';
        $result['site_profile_revision'] = $valid ? (int) MAD4B_SCP_Site_Profile::revision() : null;
        $result['production_batch_active'] = $production_batch_active;
        $result['source_of_environment'] = isset( $environment['effective_source'] )
            ? (string) $environment['effective_source'] : 'unknown';
        return $result;
    }
}
