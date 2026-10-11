<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Provider-neutral remote recovery option discovery, not authorization.
 *
 * If the primary runner, database test host or CI scheduler disappears, an
 * approved alternative may satisfy the SAME gate. Merely advertising a remote
 * ability, SSH connection or build receipt can never satisfy that gate.
 *
 * Only exact, reviewed semantic integrations can be selected. New operations
 * can appear in discovery but stay untrusted until separately certified.
 */
final class MAD4B_SCP_Recovery_Alternatives {
    const CONTRACT = 'mad4b.recovery-alternatives.v1';
    const DOMAINS = array( 'host_runner', 'database_recovery', 'candidate_delivery' );

    private static function route( $id, $label, $plan, $apply, $readback, $executor,
        $evidence, $authority, $notes, $external = false ) {
        return array(
            'id' => $id,
            'label' => $label,
            'plan_ability' => $plan,
            'apply_ability' => $apply,
            'readback_ability' => $readback,
            'executor' => $executor,
            'required_evidence' => $evidence,
            'authority_surface' => $authority,
            'independent_owner_approval_required' => true,
            'notes' => $notes,
            'separate_external_integration_required' => (bool) $external,
        );
    }

    /** Static policy anchors; ability registration is checked dynamically. */
    public static function routes() {
        return array(
            'host_runner' => array(
                self::route(
                    'enrolled_host_bridge', 'Existing enrolled signed Host Bridge',
                    'mad4b/host-operation-plan', 'mad4b/host-operation-apply',
                    'mad4b/host-operation-receipt', 'enrolled_host_runner',
                    array( 'fresh_nonce_bound_ed25519', 'pinned_public_key', 'exact_staging_binding',
                        'runner_profile', 'separately_approved_job', 'signed_receipt' ),
                    'mad4b-write', 'Preferred where current enrolled Host transport proves its identity.'
                ),
                self::route(
                    'host_reconnect', 'Recover existing Host Runner transport remotely',
                    'mad4b/host-doctor', 'mad4b/host-operation-requeue',
                    'mad4b/host-operation-status', 'host_reconnection_worker',
                    array( 'existing_host_identity', 'signed_fresh_nonce', 'no_new_trust_root',
                        'bounded_repair', 'post_reconnect_readback' ),
                    'mad4b-write', 'Requeue only previously authorized canonical work after state reconciliation.'
                ),
                self::route(
                    'external_host_agent', 'Independent managed staging host agent or SSH connector',
                    '', '', '', 'externally_authorized_host_agent',
                    array( 'separate_host_owner_authorization', 'host_target_exact_match',
                        'pinned_host_key', 'nonce_bound_existing_host_identity',
                        'independent_execution_readback' ),
                    'external_host_owner', 'SSH access is a transport option, not a substitute for the pinned Host identity signer.', true
                ),
            ),
            'database_recovery' => array(
                self::route(
                    'isolated_mariadb_worker', 'Disposable remote MariaDB crash/restart lab',
                    '', '', '', 'independent_isolated_mariadb_worker',
                    array( 'isolated_database_proof', 'exact_schema_and_engine_version',
                        'real_interrupted_multi_option_write', 'cross_request_race',
                        'cold_restart', 'before_after_row_hashes',
                        'independent_signed_receipt', 'existing_owner_approval' ),
                    'trusted_external_runner', 'Not implemented as a certified live Host executor. A PHP fixture is insufficient.', true
                ),
                self::route(
                    'managed_staging_clone', 'Provider-managed isolated staging database clone',
                    '', '', '', 'managed_database_clone',
                    array( 'separate_clone_not_site_db', 'exact_schema_engine_and_source',
                        'safe_fault_injection', 'restart_and_restore_readback',
                        'no_production_writes', 'signed_independent_receipt' ),
                    'trusted_host_or_database_provider', 'Never inject crash faults into live site or Production.', true
                ),
                self::route(
                    'host_bridge_db_probe', 'Native signed Host DB acceptance operation (future adapter)',
                    'mad4b/host-operation-capabilities', '',
                    'mad4b/host-operation-receipt', 'signed_enrolled_host_runner',
                    array( 'explicit_allowlisted_db_acceptance_operation', 'isolated_database',
                        'real_cross_request_crash', 'independent_signed_receipt' ),
                    'mad4b-write', 'Existing Host Bridge does not expose this allowlisted operation; do not assume it can run.', true
                ),
            ),
            'candidate_delivery' => array(
                self::route(
                    'github_certified_release', 'Normal immutable GitHub Actions release',
                    'mad4b/control-plane-selected-head-plan',
                    'mad4b/control-plane-selected-head-apply',
                    'mad4b/build-provenance-status', 'trusted_github_ci_and_staging_updater',
                    array( 'terminal_exact_head_ci_verdict', 'immutable_zip_sha256',
                        'signed_release_provenance', 'rollback_ready', 'installed_head_readback' ),
                    'mad4b-write', 'Queued GitHub jobs are NOT a pass.'
                ),
                self::route(
                    'signed_offline_staging', 'Owner-enrolled independent offline CI-outage signer',
                    'mad4b/staging-offline-attestor-enroll-plan',
                    'mad4b/control-plane-selected-head-apply',
                    'mad4b/build-provenance-status', 'trusted_independent_ed25519_signer',
                    array( 'five_native_test_gates', 'owner_enrolled_pinned_public_key',
                        'exact_head_signed_test_bundle', 'immutable_zip_manifest',
                        'nonexpired_signature', 'staging_only_approval',
                        'installed_manifest_and_rollback_readback' ),
                    'mad4b-write', 'Existing owner-signed CI-outage path; no weaker tests or GitHub branch protection bypass.'
                ),
                self::route(
                    'standalone_remote_builder', 'Owner-approved standalone external build worker',
                    'mad4b/standalone-build-plan', 'mad4b/standalone-build-request',
                    'mad4b/remote-operation-work-queue', 'enrolled_standalone_build_runner',
                    array( 'exact_head', 'trusted_external_worker', 'signed_build_receipt',
                        'separate_ci_or_offline_release_certification',
                        'immutable_artifact', 'post_install_readback' ),
                    'mad4b-admin', 'A signed build receipt alone is not a release or MariaDB acceptance.'
                ),
                self::route(
                    'wordpress_native_certified', 'WordPress native update from certified exact package',
                    'mad4b/control-plane-native-plan', 'mad4b/control-plane-native-apply',
                    'mad4b/control-plane-update-status', 'wordpress_native_upgrader',
                    array( 'equal_exact_source_and_newer_release', 'signed_certified_package',
                        'no_version_downgrade', 'rollback_snapshot', 'installed_sha_readback' ),
                    'mad4b-write', 'Only if the resolved certified release is the requested exact HEAD, never an older rc.95.'
                ),
            ),
        );
    }

    public static function required_ability_names() {
        $names = array();
        foreach ( self::routes() as $routes )
            foreach ( $routes as $route )
                foreach ( array( 'plan_ability', 'apply_ability', 'readback_ability' ) as $slot )
                    if ( '' !== $route[ $slot ] ) $names[ $route[ $slot ] ] = true;
        $names = array_keys( $names );
        sort( $names, SORT_STRING );
        return $names;
    }

    /**
     * Pure reduction of canonical installed ability registration. No caller
     * supplied proof can mark a route as authorized, certified, or executed.
     */
    public static function assess( $ability_mounts, $domain = 'all' ) {
        if ( ! is_array( $ability_mounts ) || ( 'all' !== $domain
            && ! in_array( $domain, self::DOMAINS, true ) ) )
            return new WP_Error( 'mad4b_recovery_alternatives_invalid_domain',
                'Only existing remote-recovery domains can be inspected.' );
        $result = array();
        foreach ( self::routes() as $group => $routes ) {
            if ( 'all' !== $domain && $group !== $domain ) continue;
            $candidates = array();
            foreach ( $routes as $route ) {
                $missing = array();
                foreach ( array( 'plan_ability', 'apply_ability', 'readback_ability' ) as $slot ) {
                    $ability = (string) $route[ $slot ];
                    if ( '' !== $ability && true !== ( $ability_mounts[ $ability ] ?? false ) )
                        $missing[] = $slot . '_not_mounted';
                }
                if ( '' === $route['apply_ability'] ) $missing[] = 'certified_executor_adapter_missing';
                if ( $route['separate_external_integration_required'] )
                    $missing[] = 'independent_provider_integration_not_certified';
                $missing = array_values( array_unique( $missing ) );
                $candidates[] = array(
                    'route_id' => $route['id'],
                    'label' => $route['label'],
                    'planner' => $route['plan_ability'],
                    'effectful_ability' => $route['apply_ability'],
                    'independent_readback' => $route['readback_ability'],
                    'executor' => $route['executor'],
                    'authority_surface' => $route['authority_surface'],
                    'remote_plan_mounted' => '' !== $route['plan_ability']
                        && true === ( $ability_mounts[ $route['plan_ability'] ] ?? false ),
                    'governed_apply_mounted' => '' !== $route['apply_ability']
                        && true === ( $ability_mounts[ $route['apply_ability'] ] ?? false ),
                    'independent_readback_mounted' => '' !== $route['readback_ability']
                        && true === ( $ability_mounts[ $route['readback_ability'] ] ?? false ),
                    'evidence_required' => $route['required_evidence'],
                    'missing_capabilities' => $missing,
                    'discovery_state' => $missing
                        ? 'INTEGRATION_OR_MOUNT_GAP'
                        : 'DISCOVERED_REQUIRES_LIVE_PROOF_AND_SEPARATE_APPROVAL',
                    'recommended_action' => $missing
                        ? 'discover_or_certify_exact_provider' : 'inspect_governed_plan_and_independent_receipts',
                    'route_may_be_planned_remotely' => '' !== $route['plan_ability']
                        && true === ( $ability_mounts[ $route['plan_ability'] ] ?? false ),
                    'complete_semantic_handoff_mounted' => empty( $missing ),
                    'external_executor_proven' => false,
                    'execution_authorized' => false,
                    'release_or_rollback_certified' => false,
                    'automatic_fallback_allowed' => false,
                    'separate_owner_approval_required' => true,
                    'notes' => $route['notes'],
                );
            }
            $suggested = '';
            foreach ( $candidates as $candidate ) {
                if ( $candidate['complete_semantic_handoff_mounted'] ) {
                    $suggested = $candidate['route_id'];
                    break;
                }
            }
            $result[ $group ] = array(
                'status' => 'ALTERNATIVES_DISCOVERED_NOT_CERTIFIED',
                'suggested_read_only_preflight_route' => $suggested,
                'full_semantic_handoff_mounted' => '' !== $suggested,
                'no_certified_route_yet' => true,
                'candidates' => $candidates,
                'no_downgrade_of_required_evidence' => true,
            );
        }
        return array( 'contract' => self::CONTRACT, 'state' => 'ALTERNATIVES_REQUIRING_PROOF',
            'domains' => $result, 'all_remote_steps_execution_certified' => false,
            'production_mutation_allowed' => false, 'raw_sql_or_shell_allowed' => false,
            'grants_created' => false, 'automated_write_dispatch' => false,
            'read_only' => true, 'mutation_performed' => false );
    }

    /**
     * Discover future certified add-on operation registrations without
     * treating their labels or callbacks as trusted equivalents. The owner
     * must separately review an exact trust/evidence contract before use.
     */
    public static function add_on_suggestions( $domain = 'all' ) {
        $terms = array(
            'host_runner' => array( 'host', 'runner', 'remote-executor' ),
            'database_recovery' => array( 'database', 'mariadb', 'rollback', 'crash-recovery' ),
            'candidate_delivery' => array( 'build', 'package', 'release', 'signed', 'ci' ),
        );
        if ( 'all' !== $domain && ! isset( $terms[ $domain ] ) ) return array();
        if ( ! class_exists( 'MAD4B_SCP_Remote_Operation_Parity', false ) ) return array();
        $registered = MAD4B_SCP_Remote_Operation_Parity::catalog();
        if ( ! is_array( $registered ) ) return array();
        $found = array();
        foreach ( $registered as $id => $item ) {
            if ( count( $found ) >= 48 ) break;
            if ( ! is_string( $id ) || ! preg_match( '/^[a-z0-9._-]{2,96}$/D', $id )
                || ! is_array( $item ) ) continue;
            $tags = is_array( $item['capability_tags'] ?? null ) ? $item['capability_tags'] : array();
            $text = strtolower( implode( ' ', array_slice( array_map( 'strval', $tags ), 0, 20 ) )
                . ' ' . $id . ' ' . (string) ( $item['provider'] ?? '' ) );
            foreach ( $terms as $group => $tokens ) {
                if ( 'all' !== $domain && $group !== $domain ) continue;
                $matched = false;
                foreach ( $tokens as $token ) {
                    if ( false !== strpos( $text, $token ) ) { $matched = true; break; }
                }
                if ( ! $matched ) continue;
                $ability = (string) ( $item['remote_ability'] ?? '' );
                if ( ! preg_match( '#^[a-z0-9_-]+/[a-z0-9_-]+$#D', $ability ) ) continue;
                $found[] = array(
                    'domain' => $group, 'registered_operation_id' => $id,
                    'provider' => sanitize_key( (string) ( $item['provider'] ?? '' ) ),
                    'ability' => $ability,
                    'registration_digest' => (string) ( $item['registration_digest'] ?? '' ),
                    'currently_discoverable' => ! empty( $item['remote_registered'] ),
                    'executor_reported_eligible' => ! empty( $item['execution_eligible'] ),
                    'evidence_equivalence_certified' => false,
                    'execution_authorized' => false,
                    'status' => 'UNTRUSTED_EQUIVALENCE_CANDIDATE',
                    'next_action' => 'review_exact_provider_contract_and_independent_evidence',
                );
            }
        }
        return $found;
    }

    /** Runtime discovery is limited to WordPress' registered native abilities. */
    public static function discover( $domain = 'all' ) {
        $mounts = array();
        foreach ( self::required_ability_names() as $name )
            $mounts[ $name ] = function_exists( 'wp_has_ability' )
                && (bool) wp_has_ability( $name );
        $result = self::assess( $mounts, $domain );
        if ( is_wp_error( $result ) ) return $result;
        $result['other_registered_provider_suggestions'] = self::add_on_suggestions( $domain );
        $result['dynamic_addons_are_not_cleared_as_equivalent'] = true;
        return $result;
    }
}
