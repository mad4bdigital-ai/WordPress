<?php
/**
 * Evidence-only cross-provider closure contract.
 *
 * This class is deliberately incapable of writing settings, issuing approvals,
 * silently changing grants or accepting submitted browser booleans as proof.
 * Registered MAD4B adapters are observed without calling the vendor.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MAD4B_SCP_Remediation_Coverage {
    const CONTRACT = 'mad4b.cross-provider-remediation-coverage.v1';
    const MAX_PROVIDERS = 40;

    public static function families() {
        return array( 'frontend_js', 'ajax_filters', 'visual_builder', 'dynamic_content',
            'multilingual', 'seo', 'forms', 'performance', 'integration', 'general' );
    }

    public static function provider_slug( $value, $empty_ok = true ) {
        return is_string( $value ) && strlen( $value ) <= 64
            && preg_match( $empty_ok ? '/^[a-z0-9_-]{0,64}$/D' : '/^[a-z0-9_-]{1,64}$/D', $value );
    }

    public static function validate_extensions( array $input ) {
        $family = isset( $input['issue_family'] ) ? $input['issue_family'] : 'frontend_js';
        if ( ! is_string( $family ) || ! in_array( $family, self::families(), true ) )
            return new WP_Error( 'mad4b_remediation_invalid_family', 'Invalid bounded issue family.' );
        foreach ( array( 'affected_provider', 'isolated_provider' ) as $key ) {
            if ( isset( $input[ $key ] ) && ! self::provider_slug( $input[ $key ] ) )
                return new WP_Error( 'mad4b_remediation_invalid_provider', 'Invalid provider identifier.' );
        }
        foreach ( array( 'provider_version_changed', 'isolation_test_passes',
            'isolation_restored_fails', 'include_provider_fleet' ) as $key ) {
            if ( isset( $input[ $key ] ) && ! is_bool( $input[ $key ] ) )
                return new WP_Error( 'mad4b_remediation_invalid_observation', 'Invalid observation type.' );
        }
        if ( isset( $input['browser_receipt_sha256'] ) &&
            ( ! is_string( $input['browser_receipt_sha256'] )
            || ! preg_match( '/^[a-f0-9]{64}$/D', $input['browser_receipt_sha256'] ) ) )
            return new WP_Error( 'mad4b_remediation_invalid_receipt', 'Malformed browser receipt digest.' );
        return true;
    }

    /** No dynamic calls to providers, vendor functions, or approval grants. */
    public static function inventory_snapshot( array $ids ) {
        $registry = class_exists( 'MAD4B_SCP_Adapter_Registry', false )
            ? MAD4B_SCP_Adapter_Registry::instance() : null;
        // Do not register defaults or invoke status() from a passive diagnostic.
        $adapters = is_object( $registry ) && method_exists( $registry, 'all' )
            ? $registry->all() : array();
        $out = array();
        foreach ( array_slice( array_values( array_unique( $ids ) ), 0, self::MAX_PROVIDERS ) as $id ) {
            if ( ! self::provider_slug( $id, false ) ) continue;
            $adapter = isset( $adapters[ $id ] ) ? $adapters[ $id ] : null;
            $read = array(); $write = array(); $reversible = array();
            if ( is_object( $adapter ) && method_exists( $adapter, 'ability_names' ) ) {
                $names = $adapter->ability_names();
                if ( is_array( $names ) ) {
                    $read = isset( $names['read'] ) && is_array( $names['read'] )
                        ? array_slice( array_values( $names['read'] ), 0, 32 ) : array();
                    foreach ( array( 'admin', 'content', 'write' ) as $lane )
                        if ( isset( $names[ $lane ] ) && is_array( $names[ $lane ] ) )
                            $write = array_merge( $write, array_slice( $names[ $lane ], 0, 32 ) );
                }
                if ( method_exists( $adapter, 'reversible_contracts' ) ) {
                    $raw = $adapter->reversible_contracts();
                    if ( is_array( $raw ) ) $reversible = array_keys( $raw );
                }
            }
            // Presence in an adapter catalog DOES NOT establish provider-native
            // ability registration, valid auth, settings access or write parity.
            $out[] = array(
                'provider_id' => $id,
                'adapter_registered' => is_object( $adapter ),
                'adapter_declared_read_abilities' => $read,
                'adapter_declared_mutation_abilities' => $write,
                'adapter_declared_reversible_abilities' => $reversible,
                'adapter_runtime_verified' => false,
                'native_provider_server_tools_list_verified' => false,
                'mutation_certified' => false,
                'automatic_write_allowed' => false,
            );
        }
        return $out;
    }

    public static function assess( array $input, array $candidates ) {
        $valid = self::validate_extensions( $input );
        if ( is_wp_error( $valid ) ) return $valid;
        $family = isset( $input['issue_family'] ) ? $input['issue_family'] : 'frontend_js';
        $affected = isset( $input['affected_provider'] ) ? $input['affected_provider'] : '';
        $isolated = isset( $input['isolated_provider'] ) ? $input['isolated_provider'] : '';
        $baseline = ! empty( $input['baseline_fails'] );
        $isolated_success = $baseline && ! empty( $input['isolation_test_passes'] );
        $isolation_repro = $isolated_success && ! empty( $input['isolation_restored_fails'] );
        $candidate_ids = array();
        foreach ( $candidates as $candidate )
            if ( is_array( $candidate ) && isset( $candidate['provider'] )
                && self::provider_slug( $candidate['provider'], false ) )
                $candidate_ids[] = $candidate['provider'];
        if ( '' !== $affected ) $candidate_ids[] = $affected;
        if ( '' !== $isolated ) $candidate_ids[] = $isolated;
        // A claimed isolated-provider test is a hypothesis, not a root cause
        // certificate. Never extrapolate it to a different plugin.
        $isolation = 'NOT_PROVIDED';
        if ( '' !== $isolated ) $isolation = $isolation_repro ? 'REPORTED_REPEATABLE_PROVIDER_ISOLATION'
            : ( $isolated_success ? 'REPORTED_SINGLE_PROVIDER_ISOLATION'
            : 'PROVIDER_ISOLATION_INCONCLUSIVE' );
        $origin = 'none';
        if ( ! empty( $input['browser_receipt_sha256'] ) ) $origin = 'client_supplied_digest_unverified';
        $matrix = array(
            'runtime_discovery' => 'read_only_adapter_inventory_and_native_ability_verification',
            'causal_test' => 'baseline_isolate_one_provider_restore_then_repeat',
            'native_provider_settings' => 'provider_verified_getter_required',
            'wordpress_abilities' => 'inspect_read_and_write_schemas_separately',
            'existing_mad4b_adapter' => 'reuse_only_certified_capabilities',
            'adapter_extension' => 'require_vendor_supported_API_contract_first',
            'mutation_plan' => 'allowlisted_exact_keys_with_expected_sha256_and_owner_approval',
            'mutation_apply' => 'canonical_NHI_approval_one_time_claim_Audit_no_bypass',
            'postcondition' => 'independent_fresh_readback_and_external_browser_acceptance',
            'rollback' => 'original_state_capture_CAS_rollback_and_independent_verify',
        );
        if ( ! empty( $input['include_provider_fleet'] ) && class_exists( 'MAD4B_SCP_Adapter_Registry', false ) ) {
            $registry = MAD4B_SCP_Adapter_Registry::instance();
            if ( is_object( $registry ) && method_exists( $registry, 'all' ) )
                $candidate_ids = array_merge( $candidate_ids, array_keys( $registry->all() ) );
        }
        $providers = self::inventory_snapshot( array_values( array_unique( $candidate_ids ) ) );
        $known = array();
        foreach ( $providers as $row ) $known[ $row['provider_id'] ] = $row;
        $recommended = array();
        foreach ( $providers as $row ) {
            $id = $row['provider_id'];
            $recommended[] = array(
                'provider' => $id,
                'next_action' => ! $row['adapter_registered']
                    ? 'discover_native_provider_capabilities_then_plan_adapter_extension'
                    : 'inspect_registered_read_and_reversible_contracts_then_verify_native_provider',
                'suggest_mutating_provider' => false,
                'root_cause_attributed' => false,
            );
        }
        $post_update = ! empty( $input['provider_version_changed'] )
            ? 'READ_CONTINUITY_REVALIDATE_CHANGED_PROVIDER_WRITE_REQUIRES_EXACT_RECERTIFICATION'
            : 'NO_VERSION_CHANGE_REPORTED';
        return array(
            'contract' => self::CONTRACT,
            'issue_family' => $family,
            'affected_provider' => $affected,
            'isolated_provider' => $isolated,
            'isolation_assessment' => $isolation,
            'isolation_not_independent_evidence' => true,
            'browser_receipt_state' => $origin,
            'cross_provider_mutation_prohibited' => true,
            'cause_confirmed' => false,
            'provider_inventory' => $providers,
            'provider_actions' => $recommended,
            'native_first_remediation_matrix' => $matrix,
            'post_update_continuity' => $post_update,
            'version_independent_read_check' => 'revalidate_actual_runtime_on_changed_provider_only',
            'known_fleet_families_to_check' => array( 'elementor', 'jetengine', 'jetsmartfilters',
                'wpml', 'rankmath', 'fluentforms', 'litespeed' ),
            'native_vendor_discovery_unverified_until_provider_probe' => true,
            'browser_acceptance_plan_ability' => 'mad4b/browser-acceptance-plan',
            'browser_acceptance_result_ability' => 'mad4b/browser-acceptance-result',
            'browser_acceptance_executed' => false,
            'provider_registration_observed' => class_exists( 'MAD4B_SCP_Adapter_Registry', false ),
            'grant_delta_automatically_approved' => false,
            'candidate_binding_automatically_replaced' => false,
            'owner_review_required_on_new_write_contracts' => true,
            'expected_sha256_required_for_any_mutation' => true,
            'independent_browser_acceptance_required' => true,
            'automatic_rollback_not_claimed' => true,
            'new_standalone_plugin_default_allowed' => false,
            'read_only' => true,
            'mutation_performed' => false,
        );
    }
}
