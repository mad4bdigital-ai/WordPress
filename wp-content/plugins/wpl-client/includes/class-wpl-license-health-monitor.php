<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Scheduled health checks for third-party commercial plugin registrations.
 *
 * This component never invents, replaces, or bypasses a vendor key. It only
 * reuses a key already stored by the vendor plugin and calls that vendor's own
 * validation/registration endpoint. Missing, expired, revoked, disabled or
 * otherwise unusable keys are surfaced for manual action.
 */
class WPL_License_Health_Monitor {

    const CRON_HOOK          = 'wpl_license_health_check';
    const SOON_HOOK          = 'wpl_license_health_check_soon';
    const CRON_SCHEDULE      = 'wpl_every_six_hours';
    const REPORT_OPTION      = 'wpl_license_health_report';
    const RETRY_OPTION       = 'wpl_license_health_retry_state';
    const LOCK_TRANSIENT     = 'wpl_license_health_lock';
    const FAILURE_RETRY_SECS = DAY_IN_SECONDS;
    const NETWORK_RETRY_SECS = 30 * MINUTE_IN_SECONDS;

    public function __construct() {
        add_filter( 'cron_schedules', [ $this, 'add_cron_schedule' ] );
        add_action( self::CRON_HOOK, [ __CLASS__, 'run' ] );
        add_action( self::SOON_HOOK, [ __CLASS__, 'run' ] );
        add_action( 'init', [ __CLASS__, 'ensure_scheduled' ] );
        add_action( 'activated_plugin', [ __CLASS__, 'on_plugin_changed' ], 20, 2 );
        add_action( 'upgrader_process_complete', [ __CLASS__, 'on_upgrader_complete' ], 20, 2 );
        add_action( 'admin_notices', [ $this, 'render_admin_notice' ] );
        add_action( 'wp_ajax_wpl_run_license_health_check', [ $this, 'ajax_run_now' ] );
    }

    public function add_cron_schedule( $schedules ) {
        if ( ! isset( $schedules[ self::CRON_SCHEDULE ] ) ) {
            $schedules[ self::CRON_SCHEDULE ] = [
                'interval' => 6 * HOUR_IN_SECONDS,
                'display'  => 'Every six hours (WPL license health)',
            ];
        }
        return $schedules;
    }

    public static function activate( $network_wide = false ) {
        if ( is_multisite() && $network_wide ) {
            foreach ( get_sites( [ 'fields' => 'ids', 'number' => 0 ] ) as $site_id ) {
                switch_to_blog( (int) $site_id );
                self::schedule_current_site();
                self::schedule_soon();
                restore_current_blog();
            }
            return;
        }
        self::schedule_current_site();
        self::schedule_soon();
    }

    public static function deactivate( $network_wide = false ) {
        if ( is_multisite() && $network_wide ) {
            foreach ( get_sites( [ 'fields' => 'ids', 'number' => 0 ] ) as $site_id ) {
                switch_to_blog( (int) $site_id );
                self::unschedule_current_site();
                restore_current_blog();
            }
            return;
        }
        self::unschedule_current_site();
    }

    public static function ensure_scheduled() {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            self::schedule_current_site();
        }
    }

    private static function schedule_current_site() {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            // First inspection shortly after activation, then every six hours.
            wp_schedule_event( time() + MINUTE_IN_SECONDS, self::CRON_SCHEDULE, self::CRON_HOOK );
        }
    }

    public static function schedule_soon() {
        if ( ! wp_next_scheduled( self::SOON_HOOK ) ) {
            wp_schedule_single_event( time() + 30, self::SOON_HOOK );
        }
    }

    public static function on_plugin_changed( $plugin = '', $network_wide = false ) {
        unset( $plugin, $network_wide );
        self::schedule_soon();
    }

    public static function on_upgrader_complete( $upgrader, $hook_extra ) {
        unset( $upgrader );
        if ( ! is_array( $hook_extra ) ) return;
        $type   = $hook_extra['type'] ?? '';
        $action = $hook_extra['action'] ?? '';
        if ( $type === 'plugin' && in_array( $action, [ 'install', 'update' ], true ) ) {
            self::schedule_soon();
        }
    }

    private static function unschedule_current_site() {
        wp_clear_scheduled_hook( self::CRON_HOOK );
        wp_clear_scheduled_hook( self::SOON_HOOK );
        delete_transient( self::LOCK_TRANSIENT );
    }

    public function ajax_run_now() {
        check_ajax_referer( 'wpl_client_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
        }
        wp_send_json_success( self::run( true ) );
    }

    /**
     * @param bool $force Ignore per-adapter failure backoff for manual checks.
     * @return array
     */
    public static function run( $force = false ) {
        if ( get_transient( self::LOCK_TRANSIENT ) ) {
            return self::get_report();
        }
        set_transient( self::LOCK_TRANSIENT, 1, 10 * MINUTE_IN_SECONDS );

        $started = microtime( true );
        $results = [];

        try {
            $adapters = [
                'wpml'       => [ __CLASS__, 'check_wpml' ],
                'crocoblock' => [ __CLASS__, 'check_crocoblock' ],
            ];

            /**
             * Register additional vendor-specific adapters.
             * Callback signature: function(bool $force): array
             */
            $adapters = apply_filters( 'wpl_client_license_health_adapters', $adapters );

            foreach ( $adapters as $id => $callback ) {
                $id = sanitize_key( $id );
                if ( ! is_callable( $callback ) ) continue;

                if ( ! $force && ! self::retry_allowed( $id ) ) {
                    $previous = self::get_previous_adapter_result( $id );
                    $results[$id] = $previous ?: self::result( 'deferred', 'Retry backoff is active.' );
                    continue;
                }

                try {
                    $result = call_user_func( $callback, $force );
                    if ( ! is_array( $result ) || empty( $result['status'] ) ) {
                        $result = self::result( 'error', 'Adapter returned an invalid result.' );
                    }
                } catch ( Throwable $e ) {
                    $result = self::result( 'error', $e->getMessage() );
                }

                $result['checked_at'] = time();
                $results[$id] = self::sanitize_result( $result );
                self::update_retry_state( $id, $results[$id] );
            }
        } finally {
            delete_transient( self::LOCK_TRANSIENT );
        }

        $report = [
            'version'     => 2,
            'checked_at'  => time(),
            'duration_ms' => max( 0, (int) round( ( microtime( true ) - $started ) * 1000 ) ),
            'scheduler'   => [
                'next_recurring_at'      => (int) ( wp_next_scheduled( self::CRON_HOOK ) ?: 0 ),
                'next_event_driven_at'   => (int) ( wp_next_scheduled( self::SOON_HOOK ) ?: 0 ),
                'wp_cron_spawn_disabled' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
            ],
            'results'     => $results,
        ];

        update_option( self::REPORT_OPTION, $report, false );
        do_action( 'wpl_client_license_health_checked', $report );
        return $report;
    }

    private static function check_wpml( $force = false ) {
        unset( $force );

        if ( ! function_exists( 'WP_Installer' ) ) {
            $installed = is_dir( WP_PLUGIN_DIR . '/sitepress-multilingual-cms' )
                || is_dir( WP_PLUGIN_DIR . '/otgs-installer-plugin' );
            return $installed
                ? self::result( 'error', 'WPML/OTGS is installed but its Installer API is not loaded in this execution context.', self::NETWORK_RETRY_SECS )
                : self::result( 'not_installed', 'WPML/OTGS is not installed.' );
        }

        $installer = WP_Installer();
        if ( ! is_object( $installer ) || ! method_exists( $installer, 'get_site_key' ) ) {
            return self::result( 'unavailable', 'WPML Installer API is unavailable.' );
        }

        $repository_id = 'wpml';
        $key = (string) $installer->get_site_key( $repository_id );
        if ( $key === '' ) {
            return self::result( 'needs_manual_action', 'WPML has no stored site key.' );
        }

        if ( self::wpml_is_valid( $installer, $repository_id ) ) {
            return self::result( 'healthy', 'WPML registration is valid.' );
        }

        // OTGS uses this method for its own periodic site-key revalidation.
        if ( method_exists( $installer, 'refresh_subscriptions_data' ) ) {
            $installer->refresh_subscriptions_data();
        }
        if ( self::wpml_is_valid( $installer, $repository_id ) ) {
            return self::result( 'repaired', 'WPML registration was revalidated.' );
        }

        // Re-register exactly the same stored key against the current site URL.
        // `return => true` is essential: without it OTGS echoes JSON and exits.
        if ( method_exists( $installer, 'save_site_key' ) ) {
            $response = $installer->save_site_key( [
                'repository_id' => $repository_id,
                'site_key'      => $key,
                'nonce'         => wp_create_nonce( 'save_site_key_' . $repository_id ),
                'return'        => true,
            ] );

            if ( self::wpml_is_valid( $installer, $repository_id ) ) {
                return self::result( 'repaired', 'WPML site key was re-registered for this site.' );
            }

            if ( is_array( $response ) && ! empty( $response['error'] ) ) {
                return self::result( 'needs_manual_action', wp_strip_all_tags( $response['error'] ) );
            }
        }

        return self::result(
            'needs_manual_action',
            'WPML kept the existing key but could not validate it for the current site.'
        );
    }

    private static function wpml_is_valid( $installer, $repository_id ) {
        return method_exists( $installer, 'repository_has_valid_subscription' )
            && (bool) $installer->repository_has_valid_subscription( $repository_id );
    }

    private static function check_crocoblock( $force = false ) {
        unset( $force );

        $class = '\\Crocoblock_Wizard\\Modules\\License\\API';
        if ( ! class_exists( $class ) ) {
            $installed = is_dir( WP_PLUGIN_DIR . '/crocoblock-wizard' );
            return $installed
                ? self::result( 'error', 'Crocoblock Wizard is installed but its license API is not loaded in this execution context.', self::NETWORK_RETRY_SECS )
                : self::result( 'not_installed', 'Crocoblock Wizard is not installed.' );
        }

        $api = new $class();
        if ( ! method_exists( $api, 'get_license' ) || ! method_exists( $api, 'license_request' ) ) {
            return self::result( 'unavailable', 'Crocoblock license API is incomplete.' );
        }

        $key = (string) $api->get_license();
        if ( $key === '' ) {
            return self::result( 'needs_manual_action', 'Crocoblock has no stored license key.' );
        }

        $check = self::crocoblock_request( $api, 'check_license', $key );
        if ( self::crocoblock_response_is_valid( $check ) ) {
            return self::result( 'healthy', 'Crocoblock registration is valid.' );
        }

        // Do not call Crocoblock::activate_license() from WP-Cron: that method
        // intentionally requires a logged-in manage_options user. The lower-level
        // vendor API is public and is what activate_license() calls internally.
        $activate = self::crocoblock_request( $api, 'activate_license', $key );
        if ( self::crocoblock_response_is_valid( $activate ) ) {
            self::save_crocoblock_key( $api, $key );
            return self::result( 'repaired', 'Crocoblock license was reactivated.' );
        }

        $error_code = self::crocoblock_status_code( $activate );
        if ( $error_code === '' ) $error_code = self::crocoblock_status_code( $check );

        if ( in_array( $error_code, [ 'connection_error', 'invalid_response' ], true ) ) {
            return self::result(
                'error',
                'Crocoblock license service is temporarily unreachable: ' . $error_code,
                self::NETWORK_RETRY_SECS
            );
        }

        // `invalid` is intentionally not treated as a domain-binding state: it can
        // also mean a wrong/revoked key. Automatic mutation stays fail-closed.
        $domain_binding_errors = [ 'site_inactive', 'inactive' ];

        // Crocoblock/EDD can retain the same key against an old URL after domain,
        // SSL or canonical-URL changes. Only those binding states get a controlled
        // deactivate + reactivate cycle. Expired/revoked/no-slot states are not touched.
        if ( in_array( $error_code, $domain_binding_errors, true ) ) {
            self::crocoblock_request( $api, 'deactivate_license', $key );
            $activate = self::crocoblock_request( $api, 'activate_license', $key );
            if ( self::crocoblock_response_is_valid( $activate ) ) {
                self::save_crocoblock_key( $api, $key );
                return self::result( 'repaired', 'Crocoblock license was rebound to the current site URL.' );
            }
            $reactivate_code = self::crocoblock_status_code( $activate );
            if ( $reactivate_code !== '' ) $error_code = $reactivate_code;
        }

        return self::result(
            'needs_manual_action',
            $error_code !== ''
                ? 'Crocoblock license needs manual action: ' . $error_code
                : 'Crocoblock kept the existing key but could not reactivate it.'
        );
    }

    private static function crocoblock_request( $api, $action, $key ) {
        $response = $api->license_request( $action, $key );
        if ( is_wp_error( $response ) ) {
            return [ 'success' => false, 'error' => 'connection_error' ];
        }
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        return is_array( $body ) ? $body : [ 'success' => false, 'error' => 'invalid_response' ];
    }

    private static function crocoblock_status_code( $response ) {
        if ( ! is_array( $response ) ) return '';
        if ( ! empty( $response['error'] ) ) return sanitize_key( (string) $response['error'] );
        $license = sanitize_key( (string) ( $response['license'] ?? '' ) );
        return ( $license && $license !== 'valid' ) ? $license : '';
    }

    private static function crocoblock_response_is_valid( $response ) {
        return ! empty( $response['success'] ) && ( $response['license'] ?? '' ) === 'valid';
    }

    private static function save_crocoblock_key( $api, $key ) {
        $option = isset( $api->license_option ) && is_string( $api->license_option )
            ? $api->license_option
            : 'jet_theme_core_license';
        update_option( $option, $key, false );
    }

    private static function retry_allowed( $id ) {
        $state = get_option( self::RETRY_OPTION, [] );
        if ( ! is_array( $state ) ) return true;
        return empty( $state[$id]['next_retry_at'] ) || time() >= (int) $state[$id]['next_retry_at'];
    }

    private static function update_retry_state( $id, $result ) {
        $state = get_option( self::RETRY_OPTION, [] );
        if ( ! is_array( $state ) ) $state = [];

        $status = $result['status'] ?? 'error';
        $delay  = 0;
        if ( $status === 'error' ) {
            $delay = ! empty( $result['retry_after'] )
                ? max( 5 * MINUTE_IN_SECONDS, (int) $result['retry_after'] )
                : self::NETWORK_RETRY_SECS;
        } elseif ( $status === 'needs_manual_action' ) {
            $delay = ! empty( $result['retry_after'] )
                ? max( 5 * MINUTE_IN_SECONDS, (int) $result['retry_after'] )
                : self::FAILURE_RETRY_SECS;
        }

        $state[$id] = [
            'last_status'   => $status,
            'last_attempt'  => time(),
            'next_retry_at' => $delay ? time() + $delay : 0,
        ];
        update_option( self::RETRY_OPTION, $state, false );
    }

    private static function get_previous_adapter_result( $id ) {
        $report = self::get_report();
        return isset( $report['results'][$id] ) && is_array( $report['results'][$id] )
            ? $report['results'][$id]
            : null;
    }

    public static function get_report() {
        $report = get_option( self::REPORT_OPTION, [] );
        return is_array( $report ) ? $report : [];
    }

    private static function result( $status, $message, $retry_after = 0 ) {
        $result = [
            'status'  => sanitize_key( $status ),
            'message' => wp_strip_all_tags( (string) $message ),
        ];
        if ( $retry_after ) $result['retry_after'] = max( 0, (int) $retry_after );
        return $result;
    }

    private static function sanitize_result( $result ) {
        $clean = [
            'status'     => sanitize_key( (string) ( $result['status'] ?? 'error' ) ),
            'message'    => wp_strip_all_tags( (string) ( $result['message'] ?? '' ) ),
            'checked_at' => (int) ( $result['checked_at'] ?? time() ),
        ];
        if ( ! empty( $result['retry_after'] ) ) {
            $clean['retry_after'] = max( 0, (int) $result['retry_after'] );
        }
        return $clean;
    }

    public function render_admin_notice() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        $report = self::get_report();
        if ( empty( $report['results'] ) || ! is_array( $report['results'] ) ) return;

        $problems = [];
        foreach ( $report['results'] as $id => $result ) {
            if ( ! in_array( $result['status'] ?? '', [ 'error', 'needs_manual_action' ], true ) ) continue;
            $problems[] = sprintf(
                '<strong>%s</strong>: %s',
                esc_html( ucfirst( $id ) ),
                esc_html( $result['message'] ?? 'License needs attention.' )
            );
        }
        if ( empty( $problems ) ) return;

        echo '<div class="notice notice-warning"><p><strong>WPL License Health:</strong> '
            . implode( ' &nbsp; | &nbsp; ', $problems )
            . '</p></div>';
    }
}
