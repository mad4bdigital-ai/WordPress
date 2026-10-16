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

    public function get_optimization_settings() {
        if ( ! $this->is_available() ) return $this->unavailable_error();
        // These documented provider hooks are an observed integration surface,
        // NOT proof of a settings read/write API or of the values in the DB.
        return array(
            'state' => 'CAPABILITY_GAP',
            'provider' => 'litespeed',
            'provider_version' => $this->runtime_version(),
            'documented_native_hooks' => array( 'litespeed_can_optm',
                'litespeed_optimize_js_excludes', 'litespeed_optm_js_defer_exc',
                'litespeed_optm_gm_js_exc', 'litespeed_optm_uri_exc' ),
            'settings_to_inspect' => array( 'JS Combine', 'Load JS Deferred',
                'JS Excludes', 'JS Deferred/Delayed Excludes',
                'Guest Mode', 'Guest Optimization' ),
            'setting_values_read' => false,
            'missing_operation' => 'provider_verified_optimization_settings_read',
            'reason' => 'no_verified_public_settings_getter_for_this_runtime',
            'native_first' => true,
            'mutation_performed' => false,
        );
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
