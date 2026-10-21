<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/class-mad4b-scp-http-probe-timing.php';

/** WordPress-native, read-first diagnostics over existing Site Health and Query Monitor. */
final class MAD4B_SCP_Native_Runtime_Diagnostics {
    const ABILITY = 'mad4b/native-runtime-diagnostics';
    const CONTRACT = 'mad4b.native-runtime-diagnostics.v1';
    const POST_ACTION = 'mad4b_native_recovery_probe';
    const NONCE_ACTION = 'mad4b_native_recovery_probe';
    private static $booted = false;

    public static function boot() {
        if ( self::$booted ) return;
        self::$booted = true;
        add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_ability' ), 39 );
        add_action( 'admin_post_' . self::POST_ACTION, array( __CLASS__, 'admin_probe' ) );
    }

    public static function register_ability() {
        if ( ! function_exists( 'wp_register_ability' ) ||
            ( function_exists( 'wp_has_ability' ) && wp_has_ability( self::ABILITY ) ) ) return;
        wp_register_ability( self::ABILITY, array(
            'label' => 'Inspect native WordPress recovery evidence',
            'description' => 'Read bounded sanitized PHP incident metadata, WordPress Site Health availability and Query Monitor bridge state; never approve or repair anything.',
            'category' => 'mad4b-read',
            'execute_callback' => array( __CLASS__, 'status' ),
            'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' ),
            'input_schema' => array( 'type' => 'object', 'properties' => array(), 'additionalProperties' => false ),
            'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
            'meta' => array( 'public' => false, 'show_in_rest' => false,
                'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'read' ),
                'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ) ),
        ) );
    }

    /** Use the Adapter interface, preserving its response semantics and authentication. */
    public static function adapter_error_handler() {
        $fallback = '\\WP\\MCP\\Infrastructure\\ErrorHandling\\ErrorLogMcpErrorHandler';
        if ( !class_exists('MAD4B_Native_Incident_MU',false) || !method_exists('MAD4B_Native_Incident_MU','active') || !MAD4B_Native_Incident_MU::active()
            || !interface_exists('\\WP\\MCP\\Infrastructure\\ErrorHandling\\Contracts\\McpErrorHandlerInterface') ) return $fallback;
        require_once __DIR__ . '/class-mad4b-scp-native-mcp-error-handler.php';
        return 'MAD4B_SCP_Native_MCP_Error_Handler';
    }

    private static function authorized() {
        if ( function_exists( 'current_user_can' ) && current_user_can( 'manage_options' ) ) return true;
        $allowed = class_exists( 'MAD4B_SCP_Policy', false ) ? MAD4B_SCP_Policy::can_read() : false;
        return ! is_wp_error( $allowed ) && true === $allowed;
    }

    public static function status( $input = array() ) {
        unset( $input );
        if ( ! self::authorized() ) return new WP_Error( 'mad4b_native_recovery_read_denied', 'Governed read access required.' );
        $profile = class_exists( 'MAD4B_SCP_Site_Profile', false ) ? MAD4B_SCP_Site_Profile::status() : array();
        $staging = is_array( $profile ) && 'staging' === (string) ( $profile['configured_environment'] ?? '' )
            && ! empty( $profile['origin_match'] ) && ! empty( $profile['environment_match'] );
        $incidents = class_exists( 'MAD4B_Native_Incident_MU', false ) ? MAD4B_Native_Incident_MU::status() : array(
            'state' => 'RECORDER_NOT_LOADED', 'recorder_loaded' => false,
            'events' => array(), 'count' => 0,
        );
        $qm_bridge = class_exists( 'MAD4B_SCP_Query_Monitor_Evidence_Bridge', false );
        return array(
            'contract' => self::CONTRACT, 'state' => $staging ? 'STAGING_DIAGNOSTIC_READY' : 'NOT_EXACT_STAGING',
            'site_profile_staging_enrolled' => $staging,
            'wordpress_site_health' => array(
                'core_screen' => 'site-health.php',
                'core_screen_available' => defined( 'ABSPATH' ) && is_readable( ABSPATH . 'wp-admin/site-health.php' ),
                'core_tests_executed' => false,
                'tests_run_in_wordpress_ui' => true,
                'status' => 'OPEN_NATIVE_SITE_HEALTH_FOR_LIVE_TESTS',
            ),
            'query_monitor' => array(
                'plugin_loaded_in_this_request' => defined( 'QM_VERSION' ),
                'existing_collector_bridge_available' => $qm_bridge,
                'collector_snapshot_taken_in_this_read' => false,
                'status' => ! $qm_bridge ? 'BRIDGE_NOT_LOADED' : ( defined( 'QM_VERSION' ) ? 'NATIVE_QM_AVAILABLE' : 'QUERY_MONITOR_NOT_LOADED_IN_THIS_REQUEST' ),
            ),
            'incidents' => $incidents,
            'paired_probe' => array(
                'available' => $staging && class_exists( 'MAD4B_SCP_Frontend_Origin_Diagnostic', false ),
                'mode' => 'explicit_admin_post_or_governed_mcp_read',
                'automatic_on_page_load' => false,
            ),
            'next_step' => $staging ? 'Review last sanitized incidents, then explicitly run paired homepage + REST probe and WordPress Site Health.' : 'Verify exact enrolled Staging Site Profile before any diagnostics.',
            'read_only' => true, 'mutation_performed' => false,
            'authority_granted' => false, 'production_mutation_allowed' => false,
            'browser_acceptance_certified' => false, 'root_cause_proven' => false,
        );
    }

    /**
     * Metadata-only, allowlisted projection of a same-origin paired probe.
     * Preserve the actionable transport class across the per-user transient;
     * never store WP_Error text, response bodies, URLs, headers or secrets.
     * Also accepts an already projected record so a refresh is idempotent.
     */
    public static function project_probe( $result ) {
        if ( ! is_array( $result ) ) return array( 'state' => 'PROBE_UNAVAILABLE', 'root_cause_proven' => false );
        $allowed = array( 'PAIRED_HTTP_INCONCLUSIVE', 'FRONTEND_LOOPBACK_5XX_REST_REACHABLE',
            'PAIRED_HTTP_REACHABLE_NOT_BROWSER_VERIFIED', 'BOTH_LOOPBACK_PATHS_5XX' );
        $state = isset( $result['state'] ) && in_array( (string) $result['state'], $allowed, true ) ? $result['state'] : 'PROBE_UNAVAILABLE';
        $results = isset( $result['results'] ) && is_array( $result['results'] ) ? $result['results'] : $result;
        $classes = array( 'dns_resolution_failure', 'tls_verification_failure', 'network_timeout',
            'tcp_connection_failure', 'local_http_policy_block', 'unknown_transport_failure' );
        $output = array( 'state' => $state, 'root_cause_proven' => false, 'browser_acceptance_certified' => false );
        foreach ( array( 'homepage', 'rest_root' ) as $name ) {
            $row = isset( $results[ $name ] ) && is_array( $results[ $name ] ) ? $results[ $name ] : array();
            $code = isset( $row['http_status'] ) && is_numeric( $row['http_status'] ) ? (int) $row['http_status'] : 0;
            $code = $code >= 100 && $code <= 599 ? $code : 0;
            $class = isset( $row['transport_error_class'] ) && is_string( $row['transport_error_class'] )
                && in_array( $row['transport_error_class'], $classes, true ) ? $row['transport_error_class'] : '';
            // Previously persisted records store only a boolean. Never invent
            // a specific cause when reading one from an older release.
            if ( '' === $class && ! empty( $row['transport_error'] ) ) $class = 'unknown_transport_failure';
            $elapsed = isset( $row['elapsed_ms'] ) && is_numeric( $row['elapsed_ms'] )
                ? (float) $row['elapsed_ms'] : 0.0;
            $elapsed = is_finite( $elapsed ) && $elapsed >= 0 ? min( 60000, round( $elapsed, 2 ) ) : 0.0;
            $output[ $name ] = array(
                'http_status' => $code,
                'transport_error' => '' !== $class,
                'transport_error_class' => $class,
                'elapsed_ms' => $elapsed,
                'network_timing' => MAD4B_SCP_HTTP_Probe_Timing::project( $row['network_timing'] ?? array() ),
            );
        }
        return $output;
    }

    /** Explicit, nonce-verified site-local probe; no automatic repairs or grants. */
    public static function admin_probe() {
        if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) )
            wp_die( 'Administrator permission required.', '', array( 'response' => 403 ) );
        if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' )
            wp_die( 'POST required.', '', array( 'response' => 405 ) );
        check_admin_referer( self::NONCE_ACTION );
        $status = self::status();
        if ( is_wp_error( $status ) || empty( $status['site_profile_staging_enrolled'] ) || empty( $status['paired_probe']['available'] ) )
            wp_die( 'Exact enrolled Staging profile required.', '', array( 'response' => 403 ) );
        $result = MAD4B_SCP_Frontend_Origin_Diagnostic::inspect( array( 'mode' => 'probe', 'target' => 'paired' ) );
        $result = is_wp_error( $result ) ? array() : $result;
        $summary = self::project_probe( $result );
        $summary['observed_at'] = time();
        // Diagnostic-only, per-user, short-lived result; no request bodies, URLs,
        // debug traces, grant state, or owner-intent modifications persisted.
        set_transient( 'mad4b_native_probe_' . get_current_user_id(), $summary, 10 * MINUTE_IN_SECONDS );
        wp_safe_redirect( admin_url( 'admin.php?page=mad4b-runtime-components&tab=diagnostics' ) );
        exit;
    }

    public static function last_local_probe() {
        if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) return array();
        $row = get_transient( 'mad4b_native_probe_' . get_current_user_id() );
        if ( ! is_array( $row ) ) return array();
        $safe = self::project_probe( $row + array( 'results' => array(
            'homepage' => $row['homepage'] ?? array(), 'rest_root' => $row['rest_root'] ?? array(),
        ) ) );
        $safe['observed_at'] = max( 0, (int) ( $row['observed_at'] ?? 0 ) );
        return $safe;
    }
}
