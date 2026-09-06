<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Scheduled health checks for third-party commercial plugin registrations.
 *
 * The monitor never invents, replaces, or bypasses a vendor key. It only uses a
 * key already stored by the vendor plugin itself and calls that vendor's own
 * registration/revalidation API. Unsupported, expired, revoked or missing keys
 * are reported for manual action.
 */
class WPL_License_Health_Monitor {

    const CRON_HOOK          = 'wpl_license_health_check';
    const CRON_SCHEDULE      = 'wpl_every_six_hours';
    const REPORT_OPTION      = 'wpl_license_health_report';
    const RETRY_OPTION       = 'wpl_license_health_retry_state';
    const LOCK_TRANSIENT     = 'wpl_license_health_lock';
    const FAILURE_RETRY_SECS = DAY_IN_SECONDS;

    public function __construct() {
        add_filter( 'cron_schedules', [ $this, 'add_cron_schedule' ] );
        add_action( self::CRON_HOOK, [ __CLASS__, 'run' ] );
        add_action( 'init', [ __CLASS__, 'ensure_scheduled' ] );
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
                restore_current_blog();
            }
            return;
        }
        self::schedule_current_site();
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

    private static function unschedule_current_site() {
        wp_clear_scheduled_hook( self::CRON_HOOK );
        delete_transient( self::LOCK_TRANSIENT );
    }

    public function ajax_run_now() {
        check_ajax_referer( 'wpl_client_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
        }
        $report = self::run( true );
        wp_send_json_success( $report );
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

        $started = time();
        $results = [];

        try {
            $adapters = [
                'wpml'       => [ __CLASS__, 'check_wpml' ],
                'crocoblock' => [ __CLASS__, 'check_crocoblock' ],
            ];

            /**
             * Add vendor-specific health adapters without modifying this class.
             * Callback signature: function(bool $force): array
             */
            $adapters = apply_filters( 'wpl_client_license_health_adapters', $adapters );

            foreach ( $adapters as $id => $callback ) {
                $id = sanitize_key( $id );
                if ( ! is_callable( $callback ) ) continue;

                if ( ! $force && ! self::retry_allowed( $id ) ) {
                    $previous      = self::get_previous_adapter_result( $id );
                    $results[$id]  = $previous ?: self::result( 'deferred', 'Retry backoff is active.' );
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
            'version'      => 1,
            'checked_at'   => time(),
            'duration_ms'  => max( 0, (int) round( ( microtime( true ) - (float) $started ) * 1000 ) ),
            'results'      => $results,
        ];

        // duration_ms above uses integer seconds for $started on purpose only as a
        // coarse fallback; replace with safe elapsed measurement below.
        $report['duration_ms'] = 0;

        update_option( self::REPORT_OPTION, $report, false );
        do_action( 'wpl_client_license_health_checked', $report );
        return $report;
    }

    private static function check_wpml( $force = false ) {
        unset( $force );

        if ( ! function_exists( 'WP_Installer' ) ) {
            return self::result( 'unavailable', 'WPML/OTGS Installer is not loaded.' );
        }

        $installer = WP_Installer();
        if ( ! is_object( $installer ) || ! method_exists( $installer, 'get_site_key' ) ) {
            return self::result( 'unavailable', 'WPML Installer API is unavailable.' );
        }

        $repo = 'wpml';
        $key  = (string) $installer->get_site_key( $repo );
        if ( $key === '' ) {
            return self::result( 'needs_manual_action', 'WPML has no stored site key.' );
        }

        if ( method_exists( $installer, 'repository_has_valid_subscription' )
            && $installer->repository_has_valid_subscription( $repo ) ) {
            return self::result( 'healthy', 'WPML registration is valid.' );
        }

        // OTGS exposes this as its normal daily revalidation path. It uses the
        // existing site key and refreshes the subscription for the current URL.
        if ( method_exists( $installer, 'refresh_subscriptions_data' ) ) {
            $installer->refresh_subscriptions_data();
        }

        if ( method_exists( $installer, 'repository_has_valid_subscription' )
            && $installer->repository_has_valid_subscription( $repo ) ) {
            return self::result( 'repaired', 'WPML registration was revalidated.' );
        }

        // Some OTGS versions can register the same already-stored key again.
        // Keep this guarded by capability discovery and never use a different key.
        if ( method_exists( $installer, 'save_site_key' ) ) {
            $args = [
                'repository_id' => $repo,
                'site_key'      => $key,
                'nonce'         => wp_create_nonce( 'save_site_key_' . $repo ),
            ];
            $response = $installer->save_site_key( $args );

            if ( method_exists( $installer, 'repository_has_valid_subscription' )
                && $installer->repository_has_valid_subscription( $repo ) ) {
                return self::result( 'repaired', 'WPML site key was re-registered for this site.' );
            }

            if ( is_wp_error( $response ) ) {
                return self::result( 'needs_manual_action', $response->get_error_message() );
            }
        }

        return self::result(
            'needs_manual_action',
            'WPML kept the existing key but could not validate it for the current site.'
        );
    }

    private static function check_crocoblock( $force = false ) {
        unset( $force );

        $class = '\\Crocoblock_Wizard\\Modules\\License\\API';
        if ( ! class_exists( $class ) ) {
            return self::result( 'unavailable', 'Crocoblock Wizard license API is not loaded.' );
        }

        $api = new $class();
        if ( ! method_exists( $api, 'get_license' ) || ! method_exists( $api, 'is_active' ) ) {
            return self::result( 'unavailable', 'Crocoblock license API is incomplete.' );
        }

        $key = (string) $api->get_license();
        if ( $key === '' ) {
            return self::result( 'needs_manual_action', 'Crocoblock has no stored license key.' );
        }

        if ( $api->is_active() ) {
            return self::result( 'healthy', 'Crocoblock registration is valid.' );
        }

        // First retry the official activation endpoint using the existing key.
        if ( method_exists( $api, 'activate_license' ) ) {
            $activated = $api->activate_license( $key );
            if ( $activated && $api->is_active() ) {
                return self::result( 'repaired', 'Crocoblock license was reactivated.' );
            }
        }

        // Domain/SSL migrations often leave an EDD-style activation bound to the
        // old URL. Crocoblock itself recommends deactivate + reactivate. Use the
        // vendor endpoint directly while preserving the existing local key.
        if ( method_exists( $api, 'license_request' ) && method_exists( $api, 'activate_license' ) ) {
            $api->license_request( 'deactivate_license', $key );
            $activated = $api->activate_license( $key );

            // Never destroy the legitimate key merely because a repair failed.
            if ( method_exists( $api, 'get_license' ) && (string) $api->get_license() === '' ) {
                update_option( 'jet_theme_core_license', $key, false );
            }

            if ( $activated && $api->is_active() ) {
                return self::result( 'repaired', 'Crocoblock license was rebound to the current domain.' );
            }
        }

        $message = 'Crocoblock kept the existing key but could not reactivate it.';
        if ( method_exists( $api, 'get_error' ) ) {
            $error = wp_strip_all_tags( (string) $api->get_error() );
            if ( $error !== '' ) $message = $error;
        }
        return self::result( 'needs_manual_action', $message );
    }

    private static function retry_allowed( $id ) {
        $state = get_option( self::RETRY_OPTION, [] );
        if ( ! is_array( $state ) ) return true;
        return empty( $state[$id]['next_retry_at'] ) || time() >= (int) $state[$id]['next_retry_at'];
    }

    private static function update_retry_state( $id, $result ) {
        $state = get_option( self::RETRY_OPTION, [] );
        if ( ! is_array( $state ) ) $state = [];

        $failure = in_array( $result['status'], [ 'error', 'needs_manual_action' ], true );
        $state[$id] = [
            'last_status'   => $result['status'],
            'last_attempt'  => time(),
            'next_retry_at' => $failure ? time() + self::FAILURE_RETRY_SECS : 0,
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

    private static function result( $status, $message ) {
        return [
            'status'  => sanitize_key( $status ),
            'message' => wp_strip_all_tags( (string) $message ),
        ];
    }

    private static function sanitize_result( $result ) {
        return [
            'status'     => sanitize_key( (string) ( $result['status'] ?? 'error' ) ),
            'message'    => wp_strip_all_tags( (string) ( $result['message'] ?? '' ) ),
            'checked_at' => (int) ( $result['checked_at'] ?? time() ),
        ];
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
