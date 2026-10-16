<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Version-neutral LiteSpeed discovery.
 * Vendor documents optimization hooks, not a durable public settings writer.
 * Do NOT write private LSCWP option keys or infer browser execution order
 * from the PHP WordPress script registry.
 */
final class MAD4B_SCP_LiteSpeed_Adapter extends MAD4B_SCP_Adapter_Base {
    public function id() { return 'litespeed'; }
    public function label() { return 'LiteSpeed Cache'; }
    public function is_available() {
        return defined( 'LSCWP_V' ) || class_exists( 'LiteSpeed_Core' );
    }
    protected function detect_plugin_version() {
        return defined( 'LSCWP_V' ) ? LSCWP_V : '';
    }
    public function ability_names() {
        return array(
            'read' => array( 'litespeed/status', 'litespeed/get-optimization-settings',
                'litespeed/diagnose-script-order', 'litespeed/settings-change-plan',
                'litespeed/verify-optimization' ),
            'content' => array(),
            'admin' => array( 'litespeed/purge-url', 'litespeed/purge-all' ),
        );
    }
    public function register_abilities() {
        $read = array( 'MAD4B_SCP_Policy', 'can_read' );
        $admin = array( 'MAD4B_SCP_Policy', 'can_admin' );
        $this->add_ability( 'litespeed/status', 'Get LiteSpeed Cache Status', 'status', $read );
        $this->add_ability( 'litespeed/get-optimization-settings',
            'Inspect LiteSpeed optimization setting discovery', 'get_optimization_settings', $read );
        $this->add_ability( 'litespeed/diagnose-script-order',
            'Inspect bounded WordPress script dependency graph', 'diagnose_script_order', $read,
            $this->schema( array( 'handles' => array( 'type' => 'array', 'maxItems' => 16,
                'items' => array( 'type' => 'string', 'maxLength' => 100 ) ) ) ) );
        $this->add_ability( 'litespeed/settings-change-plan',
            'Plan native-first JS remediation (no writes)', 'settings_change_plan', $read,
            $this->schema( array( 'error_code' => array( 'type' => 'string',
                'enum' => array( 'wp_not_defined', 'script_order', 'other' ) ) ),
                array( 'error_code' ) ) );
        $this->add_ability( 'litespeed/verify-optimization',
            'Report browser acceptance evidence requirements', 'verify_optimization', $read );
        $this->add_ability( 'litespeed/purge-url', 'Purge LiteSpeed URL', 'purge_url',
            $admin, $this->schema( array( 'url' => array( 'type' => 'string',
                'format' => 'uri' ) ), array( 'url' ) ), 'admin', false, false, true );
        $this->add_ability( 'litespeed/purge-all', 'Purge All LiteSpeed Cache',
            'purge_all', $admin, null, 'admin', false, true, true );
    }

    /**
     * Bounded, non-sensitive keys verified against LiteSpeed Base constants.
     * Consult the public 'litespeed_conf' filter, never private WP options.
     */
    public static function optimization_slug_map() {
        return array(
            'js_combine' => 'optm-js_comb',
            'js_combine_external_inline' => 'optm-js_comb_ext_inl',
            'js_minify' => 'optm-js_min',
            'js_defer' => 'optm-js_defer',
            'js_excludes' => 'optm-js_exc',
            'js_defer_excludes' => 'optm-js_defer_exc',
            'js_delay_includes' => 'optm-js_delay_inc',
            'guest_mode_js_excludes' => 'optm-gm_js_exc',
            'guest_optimization' => 'guest_optm',
            'optimize_guest_only' => 'optm-guest_only',
        );
    }

    public function get_optimization_settings() {
        if ( ! $this->is_available() ) return $this->unavailable_error();
        $base = array(
            'provider' => 'litespeed',
            'provider_version' => $this->runtime_version(),
            'api_reference' => 'https://docs.litespeedtech.com/lscache/lscwp/api/',
            'documented_native_read_hook' => 'litespeed_conf',
            'documented_native_write_hooks' => array( 'litespeed_save_conf', 'litespeed_update_confs' ),
            'write_contract_certified' => false,
            'mutation_performed' => false,
        );
        // A plugin constant is not enough: the actual vendor getter must be
        // bound in the live WordPress request, or the observation is invalid.
        if ( ! function_exists( 'has_filter' ) || false === has_filter( 'litespeed_conf' )
            || ! function_exists( 'apply_filters' ) )
            return array_merge( $base, array(
                'state' => 'CAPABILITY_GAP',
                'setting_values_read' => false,
                'reason' => 'official_litespeed_conf_filter_not_observed',
            ) );
        $settings = array();
        foreach ( self::optimization_slug_map() as $name => $slug ) {
            $value = apply_filters( 'litespeed_conf', $slug );
            // Reject unexpected schemas; never truncate silently, particularly
            // since the readback digest is a future CAS planning input.
            if ( is_array( $value ) ) {
                if ( count( $value ) > 64 ) return new WP_Error(
                    'mad4b_litespeed_setting_value_oversized', 'A bounded settings snapshot could not be read.' );
                foreach ( $value as $item )
                    if ( ! is_string( $item ) || strlen( $item ) > 512 )
                        return new WP_Error( 'mad4b_litespeed_setting_invalid',
                            'LiteSpeed setting contains an unbounded non-scalar item.' );
            } elseif ( ! is_bool( $value ) && ! is_int( $value ) && ! is_string( $value ) )
                return new WP_Error( 'mad4b_litespeed_setting_invalid',
                    'LiteSpeed setting type is not supported by bounded diagnostics.' );
            $settings[ $name ] = array( 'slug' => $slug, 'value' => $value );
        }
        $encoded = function_exists( 'wp_json_encode' )
            ? wp_json_encode( $settings ) : json_encode( $settings );
        if ( false === $encoded ) return new WP_Error(
            'mad4b_litespeed_snapshot_encode_failed', 'LiteSpeed setting snapshot could not be hashed.' );
        return array_merge( $base, array(
            'state' => 'NATIVE_SETTINGS_READ_OBSERVED',
            'setting_values_read' => true,
            'settings' => $settings,
            'snapshot_sha256' => hash( 'sha256', $encoded ),
            'snapshot_scope' => 'current_request_vendor_filter_not_browser_effective_runtime',
            'independent_browser_verification_required' => true,
        ) );
    }

    public function diagnose_script_order( $input = array() ) {
        if ( ! $this->is_available() ) return $this->unavailable_error();
        if ( ! is_array( $input ) || array_diff( array_keys( $input ), array( 'handles' ) ) )
            return new WP_Error( 'mad4b_litespeed_invalid_script_request', 'Only bounded registered handles may be inspected.' );
        $handles = isset( $input['handles'] ) ? $input['handles'] : array( 'wp-hooks', 'wp-i18n', 'jquery', 'elementor-frontend' );
        if ( ! is_array( $handles ) || count( $handles ) > 16 )
            return new WP_Error( 'mad4b_litespeed_invalid_handles', 'At most 16 handles are supported.' );
        $unique = array();
        foreach ( $handles as $handle ) {
            if ( ! is_string( $handle ) || ! preg_match( '/^[a-zA-Z0-9_-]{1,100}$/D', $handle ) )
                return new WP_Error( 'mad4b_litespeed_invalid_handle', 'Invalid script handle.' );
            $unique[ $handle ] = true;
        }
        $scripts = function_exists( 'wp_scripts' ) ? wp_scripts() : null;
        $registered = ( is_object( $scripts ) && isset( $scripts->registered )
            && is_array( $scripts->registered ) ) ? $scripts->registered : array();
        $result = array();
        foreach ( array_keys( $unique ) as $handle ) {
            $row = isset( $registered[ $handle ] ) ? $registered[ $handle ] : null;
            $dependencies = is_object( $row ) && isset( $row->deps ) && is_array( $row->deps )
                ? $row->deps : array();
            $result[] = array(
                'handle' => $handle,
                'registered_in_current_php_request' => is_object( $row ),
                'declared_dependencies' => array_values( array_slice( $dependencies, 0, 32 ) ),
                'unregistered_dependencies_in_current_request' => array_values( array_filter(
                    $dependencies, static function ( $dep ) use ( $registered ) {
                        return ! isset( $registered[ $dep ] );
                    }
                ) ),
            );
        }
        return array(
            'state' => empty( $registered ) ? 'SCRIPT_REGISTRY_NOT_SAMPLED' : 'PHP_DEPENDENCY_SNAPSHOT',
            'sample_context' => 'current_mcp_request_not_browser_render',
            'scripts' => $result,
            'browser_order_verified' => false,
            'optimization_impact_verified' => false,
            'next_action' => 'compare_independent_browser_baseline_bypass_and_restored_trace',
            'mutation_performed' => false,
        );
    }

    public function settings_change_plan( $input = array() ) {
        if ( ! $this->is_available() ) return $this->unavailable_error();
        if ( ! is_array( $input ) || array_keys( $input ) !== array( 'error_code' )
            || ! in_array( $input['error_code'],
                array( 'wp_not_defined', 'script_order', 'other' ), true ) )
            return new WP_Error( 'mad4b_litespeed_invalid_plan_input', 'An exact bounded error_code is required.' );
        return array(
            'state' => 'CAPABILITY_GAP',
            'native_first_order' => array(
                'verify_browser_error_and_provider_isolation',
                'inspect_native_JS_Combine_Defer_Delay_and_Guest_Optimization',
                'inspect_documented_vendor_JS_exclusion_hooks',
                'verify_registered_WordPress_Abilities_and_existing_adapter',
                'certify_exact_version_safe_settings_writer_and_reversible_contract',
            ),
            'missing_operation' => 'version_verified_bounded_js_settings_mutation_with_rollback',
            'proposed_scope' => 'single_minimum_JS_exclusion_or_setting_after_causal_proof',
            'cas_required' => true,
            'owner_approval_required' => true,
            'browser_acceptance_required' => true,
            'restore_contract_implemented' => false,
            'automatic_write_allowed' => false,
            'mutation_performed' => false,
            'new_plugin_required' => false,
        );
    }

    public function verify_optimization() {
        if ( ! $this->is_available() ) return $this->unavailable_error();
        return array(
            'state' => 'INDEPENDENT_BROWSER_ACCEPTANCE_REQUIRED',
            'checks' => array( 'baseline_and_optimized_console_error',
                'frontend_filters_and_AJAX_parity', 'script_dependency_order',
                'core_web_vitals_regression', 'rollback_on_failed_acceptance' ),
            'browser_test_performed' => false,
            'mutation_performed' => false,
        );
    }

    public function purge_url( $input ) {
        if ( ! $this->is_available() ) return $this->unavailable_error();
        $url = esc_url_raw( $input['url'] );
        $home_host = wp_parse_url( home_url(), PHP_URL_HOST );
        $target_host = wp_parse_url( $url, PHP_URL_HOST );
        if ( ! $target_host || strtolower( $target_host ) !== strtolower( $home_host ) )
            return new WP_Error( 'mad4b_litespeed_external_url', 'Only this WordPress site can be purged.' );
        do_action( 'litespeed_purge_url', $url );
        MAD4B_SCP_Audit::record( 'litespeed/purge-url', array( 'url' => $url ) );
        return array( 'purged' => true, 'url' => $url );
    }
    public function purge_all() {
        if ( ! $this->is_available() ) return $this->unavailable_error();
        do_action( 'litespeed_purge_all' );
        MAD4B_SCP_Audit::record( 'litespeed/purge-all', array() );
        return array( 'purged' => true, 'scope' => 'all' );
    }
}
