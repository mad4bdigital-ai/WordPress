<?php
/**
 * Read-only, provider-neutral root-cause and native-first remediation planner.
 * Evidence is caller-reported and NEVER authorizes a mutation or an approval.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MAD4B_SCP_Root_Cause_Resolver {
    const CONTRACT = 'mad4b.root-cause-native-first.v1';
    const ABILITY = 'mad4b/root-cause-remediation-plan';
    const MAX_ACTIVE = 12;

    public static function boot() {
        add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_ability' ), 38 );
    }

    public static function register_ability() {
        if ( ! function_exists( 'wp_register_ability' ) ) return;
        if ( function_exists( 'wp_has_ability' ) && wp_has_ability( self::ABILITY ) ) return;
        wp_register_ability( self::ABILITY, array(
            'label' => 'MAD4B Root Cause and Native-first Remediation Plan',
            'description' => 'Read-only differential diagnosis and missing-capability report. Never executes a mutation.',
            'category' => 'mad4b-read',
            'execute_callback' => array( __CLASS__, 'inspect' ),
            'permission_callback' => array( __CLASS__, 'can_read' ),
            'input_schema' => array(
                'type' => 'object', 'additionalProperties' => false,
                'properties' => array(
                    'error_code' => array( 'type' => 'string', 'enum' => array( 'wp_not_defined', 'script_order', 'frontend_filters_failed', 'other' ) ),
                    'baseline_fails' => array( 'type' => 'boolean' ),
                    'optimization_bypass_passes' => array( 'type' => 'boolean' ),
                    'optimization_restored_fails' => array( 'type' => 'boolean' ),
                    'script_owner' => array( 'type' => 'string', 'maxLength' => 64, 'pattern' => '^[a-z0-9_-]*$' ),
                    'active_optimizers' => array( 'type' => 'array', 'maxItems' => self::MAX_ACTIVE,
                        'items' => array( 'type' => 'string', 'maxLength' => 64 ) ),
                    'native_read_verified' => array( 'type' => 'boolean' ),
                    'native_write_verified' => array( 'type' => 'boolean' ),
                    'adapter_read_verified' => array( 'type' => 'boolean' ),
                    'adapter_write_verified' => array( 'type' => 'boolean' ),
                    'rollback_verified' => array( 'type' => 'boolean' ),
                ),
                'required' => array( 'error_code', 'baseline_fails', 'optimization_bypass_passes' ),
            ),
            'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
            'meta' => array(
                'public' => false, 'show_in_rest' => false,
                'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'read' ),
                'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
            ),
        ) );
    }

    public static function can_read() {
        return class_exists( 'MAD4B_SCP_Policy' ) && MAD4B_SCP_Policy::can_read();
    }

    private static function bad( $code ) {
        return new WP_Error( 'mad4b_root_cause_' . $code, 'A bounded, exact evidence schema is required for read-only diagnosis.' );
    }

    public static function inspect( $input = array() ) {
        if ( ! self::can_read() ) return self::bad( 'permission_denied' );
        return self::assess( $input );
    }

    /** Pure, deterministic planning; all booleans originate from client observations. */
    public static function assess( $input ) {
        if ( ! is_array( $input ) ) return self::bad( 'invalid_input' );
        $allowed = array( 'error_code', 'baseline_fails', 'optimization_bypass_passes',
            'optimization_restored_fails', 'script_owner', 'active_optimizers',
            'native_read_verified', 'native_write_verified', 'adapter_read_verified',
            'adapter_write_verified', 'rollback_verified' );
        if ( array_diff( array_keys( $input ), $allowed ) ) return self::bad( 'unknown_field' );
        if ( ! isset( $input['error_code'] ) || ! in_array( $input['error_code'],
            array( 'wp_not_defined', 'script_order', 'frontend_filters_failed', 'other' ), true ) )
            return self::bad( 'invalid_error' );
        foreach ( array( 'baseline_fails', 'optimization_bypass_passes' ) as $key ) {
            if ( ! array_key_exists( $key, $input ) || ! is_bool( $input[ $key ] ) )
                return self::bad( 'missing_comparison' );
        }
        foreach ( array( 'optimization_restored_fails', 'native_read_verified',
            'native_write_verified', 'adapter_read_verified', 'adapter_write_verified',
            'rollback_verified' ) as $key )
            if ( isset( $input[ $key ] ) && ! is_bool( $input[ $key ] ) )
                return self::bad( 'invalid_boolean' );
        $owner = isset( $input['script_owner'] ) ? $input['script_owner'] : '';
        if ( ! is_string( $owner ) || ! preg_match( '/^[a-z0-9_-]{0,64}$/D', $owner ) )
            return self::bad( 'invalid_owner' );
        $optimizers = isset( $input['active_optimizers'] ) ? $input['active_optimizers'] : array();
        if ( ! is_array( $optimizers ) || count( $optimizers ) > self::MAX_ACTIVE )
            return self::bad( 'invalid_optimizers' );
        $normalized = array();
        foreach ( $optimizers as $optimizer ) {
            if ( ! is_string( $optimizer ) || ! preg_match( '/^[a-z0-9_-]{1,64}$/D', $optimizer ) )
                return self::bad( 'invalid_optimizer' );
            $normalized[ $optimizer ] = true;
        }
        $differential = $input['baseline_fails'] && $input['optimization_bypass_passes'];
        $repeatable = $differential && ! empty( $input['optimization_restored_fails'] );
        $litespeed = isset( $normalized['litespeed'] );
        $candidates = array();
        if ( '' !== $owner && 'other' !== $owner )
            $candidates[] = array( 'provider' => $owner, 'reason' => 'reported_script_owner_not_verified' );
        if ( $differential && $litespeed && 'litespeed' !== $owner )
            $candidates[] = array( 'provider' => 'litespeed',
                'reason' => 'reported_optimization_bypass_differential_not_provider_isolation' );
        $multiple = count( $normalized ) > 1;
        $diagnosis = ! $input['baseline_fails'] ? 'NO_REPRODUCED_FAILURE'
            : ( $repeatable ? 'REPORTED_REPEATABLE_DIFFERENTIAL'
            : ( $differential ? 'REPORTED_SINGLE_DIFFERENTIAL' : 'CAUSE_UNRESOLVED' ) );
        $native_read = ! empty( $input['native_read_verified'] );
        $native_write = ! empty( $input['native_write_verified'] );
        $adapter_read = ! empty( $input['adapter_read_verified'] );
        $adapter_write = ! empty( $input['adapter_write_verified'] );
        $rollback = ! empty( $input['rollback_verified'] );
        $steps = array(
            array( 'priority' => 1, 'route' => 'provider_native_diagnostics',
                'status' => $native_read ? 'reported_available_reverify_host' : 'discover_and_verify' ),
            array( 'priority' => 2, 'route' => 'wordpress_registered_abilities',
                'status' => 'discover_schema_and_runtime_authority' ),
            array( 'priority' => 3, 'route' => 'mad4b_existing_adapter',
                'status' => $adapter_read ? 'reported_available_reverify_host' : 'discover_and_verify' ),
            array( 'priority' => 4, 'route' => 'governed_adapter_extension',
                'status' => 'only_if_existing_native_routes_insufficient' ),
        );
        $gap = ! $native_read && ! $adapter_read ? 'SETTINGS_READ_NOT_VERIFIED'
            : ( ! $native_write && ! $adapter_write ? 'BOUNDED_SETTINGS_WRITE_NOT_VERIFIED'
            : ( ! $rollback ? 'ROLLBACK_CONTRACT_NOT_VERIFIED' : 'NONE_REPORTED' ) );
        // Even a complete caller-reported capability inventory is not an
        // authoritative runtime snapshot and cannot create a write plan.
        return array(
            'contract' => self::CONTRACT,
            'state' => 'NONE_REPORTED' === $gap ? 'READ_ONLY_REVIEW_REQUIRED' : 'CAPABILITY_GAP',
            'diagnosis' => $diagnosis,
            'cause_confirmed' => false,
            'evidence_authority' => 'caller_report_only_not_independent_browser_attestation',
            'candidate_providers' => $candidates,
            'confounding_optimizers_present' => $multiple,
            'native_first_routes' => $steps,
            'capability_gap' => $gap,
            'recommended_next_action' => $multiple
                ? 'isolate_each_optimizer_and_capture_new_browser_evidence'
                : ( 'NO_REPRODUCED_FAILURE' === $diagnosis
                    ? 'capture_reproducible_browser_failure'
                    : 'verify_provider_runtime_settings_and_script_dependency_order' ),
            'standalone_plugin_creation_allowed' => false,
            'mutation_performed' => false,
            'automatic_write_allowed' => false,
            'owner_approval_created' => false,
            'requires_exact_plan_cas_audit_browser_acceptance_rollback' => true,
        );
    }
}
