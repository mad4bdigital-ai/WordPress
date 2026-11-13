<?php
/**
 * Core Web Vitals field-data observer for the existing MAD4B Performance surface.
 * Uses Google's PageSpeed Insights v5 CrUX aggregates (not Lighthouse scores)
 * for the rolling 28-day Core Web Vitals assessment.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MAD4B_SCP_Core_Web_Vitals {
    const CONTRACT = 'mad4b.core-web-vitals-assessment.v1';
    const OPTION = 'mad4b_scp_core_web_vitals_v1';
    const SETTINGS_OPTION = 'mad4b_scp_cwv_settings_v1';
    const JOB_OPTION = 'mad4b_scp_cwv_last_job_v1';
    const LOCK_TTL = 180;
    const MANUAL_WAIT = 90;
    const LOCK = 'mad4b_scp_core_web_vitals_lock_v1';
    const DAILY_HOOK = 'mad4b_core_web_vitals_daily';
    const ONCE_HOOK = 'mad4b_core_web_vitals_refresh_once';
    const DEVICE_HOOK = 'mad4b_core_web_vitals_device_worker';
    const DEVICE_TIMEOUT = 70;
    const RETRY_DELAY = 180;
    const MAX_HISTORY = 35;
    const API = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';

    public static function boot() {
        add_action( 'admin_init', array( __CLASS__, 'schedule_if_needed' ) );
        add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_ability' ), 39 );
        // Queue lightweight dispatchers; each device gets a separate worker request.
        add_action( self::DAILY_HOOK, array( __CLASS__, 'dispatch_daily' ) );
        add_action( self::ONCE_HOOK, array( __CLASS__, 'dispatch_queued' ) );
        add_action( self::DEVICE_HOOK, array( __CLASS__, 'run_device' ), 10, 2 );
        add_action( 'mad4b/performance/release_verified', array( __CLASS__, 'queue_verified_release' ), 50, 1 );
        add_action( 'admin_post_mad4b_cwv_refresh', array( __CLASS__, 'handle_refresh' ) );
        add_action( 'admin_post_mad4b_cwv_save_settings', array( __CLASS__, 'handle_save_settings' ) );
        add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ), 35 );
    }


    /** Administrative REST surface. All responses are private and contain no credential material. */
    public static function register_rest_routes() {
        $ns = 'mad4b/v1';
        $base = '/admin/core-web-vitals';
        $options = array( 'permission_callback' => array( __CLASS__, 'rest_admin_permission' ) );
        register_rest_route( $ns, $base, array_merge( $options, array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array( __CLASS__, 'rest_read_status' ),
        ) ) );
        register_rest_route( $ns, $base . '/lifecycle', array_merge( $options, array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array( __CLASS__, 'rest_read_lifecycle' ),
        ) ) );
        register_rest_route( $ns, $base . '/settings', array_merge( $options, array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array( __CLASS__, 'rest_save_settings' ),
            'args' => array(
                'page_url' => array( 'type' => 'string', 'required' => true ),
                'api_key' => array( 'type' => 'string', 'required' => false ),
                'clear_api_key' => array( 'type' => 'boolean', 'required' => false ),
            ),
        ) ) );
        register_rest_route( $ns, $base . '/refresh', array_merge( $options, array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array( __CLASS__, 'rest_queue_refresh' ),
        ) ) );
    }

    public static function rest_admin_permission() {
        return current_user_can( 'manage_options' ) ? true :
            new WP_Error( 'mad4b_cwv_admin_required', 'Site administrator access required.', array( 'status' => 403 ) );
    }

    private static function private_rest_response( $body, $code = 200 ) {
        $response = new WP_REST_Response( $body, $code );
        $response->header( 'Cache-Control', 'private, no-store, max-age=0' );
        return $response;
    }

    public static function rest_read_status() {
        return self::private_rest_response( self::status() );
    }

    public static function rest_read_lifecycle() {
        return self::private_rest_response( self::lifecycle_status() );
    }

    public static function rest_save_settings( $request ) {
        $data = $request->get_json_params();
        if ( ! is_array( $data ) ||
            array_diff( array_keys( $data ), array( 'page_url', 'api_key', 'clear_api_key' ) ) ||
            ! array_key_exists( 'page_url', $data ) ||
            ( isset( $data['clear_api_key'] ) && ! is_bool( $data['clear_api_key'] ) ) ) {
            return new WP_Error( 'mad4b_cwv_rest_settings_invalid', 'Only page_url, api_key, and clear_api_key are accepted as JSON.', array( 'status' => 422 ) );
        }
        $result = self::save_settings( $data['page_url'], $data['api_key'] ?? '', $data['clear_api_key'] ?? false );
        if ( is_wp_error( $result ) ) {
            return new WP_Error( sanitize_key( $result->get_error_code() ), $result->get_error_message(), array( 'status' => 422 ) );
        }
        return self::private_rest_response( array(
            'contract' => self::CONTRACT,
            'state' => 'saved',
            'settings' => $result,
            'lifecycle' => self::lifecycle_status(),
            'credentials_returned' => false,
        ) );
    }

    public static function rest_queue_refresh( $request ) {
        $data = $request->get_json_params();
        if ( is_array( $data ) && ! empty( $data ) ) {
            return new WP_Error( 'mad4b_cwv_refresh_unexpected_parameters', 'Refresh accepts an empty JSON object only.', array( 'status' => 422 ) );
        }
        $result = self::queue_refresh( 'rest_admin' );
        if ( is_wp_error( $result ) ) return $result;
        return self::private_rest_response( array(
            'contract' => self::CONTRACT,
            'operation' => 'queue_refresh',
            'result' => $result,
            'lifecycle' => self::lifecycle_status(),
        ), 'queued' === $result['state'] ? 202 : 200 );
    }

    /** Read-only truth about the last worker and the present scheduler/lock. */
    public static function lifecycle_status() {
        $job = get_option( self::JOB_OPTION, array() );
        if ( ! is_array( $job ) ) $job = array();
        $lock = get_option( self::LOCK, array() );
        $lock = is_array( $lock ) ? $lock : array();
        $locked = ! empty( $lock['expires'] ) && (int) $lock['expires'] > time();
        $record = get_option( self::OPTION, array() );
        $record = is_array( $record ) ? $record : array();
        $once = wp_next_scheduled( self::ONCE_HOOK );
        $daily = wp_next_scheduled( self::DAILY_HOOK );
        $state = $job['state'] ?? 'idle';
        $active_device = isset( $job['pending_devices'][0] ) ? (string) $job['pending_devices'][0] : '';
        $device_next = '' !== $active_device && ! empty( $job['job_id'] )
            ? wp_next_scheduled( self::DEVICE_HOOK, array( (string) $job['job_id'], $active_device ) ) : false;
        $started = ! empty( $job['started_at'] ) ? strtotime( (string) $job['started_at'] ) : false;
        if ( 'running' === $state && ! $locked && ! $device_next && ( ! $started || time() - $started > self::LOCK_TTL ) ) {
            $state = 'stale_running';
        }
        if ( 'queued' === $state && ! $once && ! $locked ) $state = 'orphaned_queue';
        return array(
            'contract' => 'mad4b.cwv-lifecycle.v1',
            'read_only' => true,
            'state' => $state,
            'job_id' => $job['job_id'] ?? null,
            'entry_point' => $job['entry_point'] ?? null,
            'queued_at' => $job['queued_at'] ?? null,
            'started_at' => $job['started_at'] ?? null,
            'completed_at' => $job['completed_at'] ?? null,
            'result' => $job['result'] ?? null,
            'worker_running' => $locked,
            'lock_expires_at_epoch' => $locked ? (int) $lock['expires'] : null,
            'daily_next_run_epoch' => $daily ?: null,
            'one_time_next_run_epoch' => $once ?: null,
            'device_next_run_epoch' => $device_next ?: null,
            'active_device' => $active_device ?: null,
            'pending_devices' => is_array( $job['pending_devices'] ?? null ) ? $job['pending_devices'] : array(),
            'device_attempt_counts' => is_array( $job['attempt_counts'] ?? null ) ? $job['attempt_counts'] : array(),
            'wp_cron_disabled' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
            'worker_hook_registered' => false !== has_action( self::ONCE_HOOK ),
            'cooldown_until_epoch' => (int) ( $record['cooldown_until_epoch'] ?? 0 ),
            'last_attempted_at' => $record['attempted_at'] ?? null,
            'last_attempt_has_errors' => ! empty( $record['last_attempt_has_errors'] ),
        );
    }

    /** One bounded post-release refresh, deduplicated by the existing scheduler. */
    public static function queue_verified_release( $receipt = array() ) {
        if (!is_array($receipt) || empty($receipt['contract'])
            || $receipt['contract'] !== 'mad4b.performance-release-verified.v1'
            || empty($receipt['source_staging_passed'])) return new WP_Error('mad4b_cwv_release_not_verified', 'A verified performance release receipt is required.');
        return self::queue_refresh('performance_release');
    }

    /** Never performs a Google request while serving an admin or REST request. */
    public static function queue_refresh( $entry_point = 'admin_post' ) {
        if ( ! in_array( $entry_point, array( 'admin_post', 'rest_admin', 'performance_release' ), true ) ) {
            return new WP_Error( 'mad4b_cwv_entry_invalid', 'Unsupported refresh entry point.', array( 'status' => 422 ) );
        }
        $life = self::lifecycle_status();
        if ( $life['worker_running'] || ( 'running' === $life['state'] && ! empty( $life['device_next_run_epoch'] ) ) ) {
            return array( 'state' => 'already_running', 'job_id' => $life['job_id'] );
        }
        if ( $life['one_time_next_run_epoch'] ) {
            return array( 'state' => 'already_queued', 'job_id' => $life['job_id'], 'scheduled_at_epoch' => $life['one_time_next_run_epoch'] );
        }
        if ( $life['cooldown_until_epoch'] > time() && 'performance_release' !== $entry_point ) {
            return new WP_Error( 'mad4b_cwv_cooldown', 'Google API cooldown is active; retry after the returned timestamp.', array(
                'status' => 429, 'retry_after_epoch' => $life['cooldown_until_epoch'],
            ) );
        }
        // Release verification may queue once during provider cooldown; the
        // worker is deferred rather than hammering Google or blocking PHP-FPM.
        $scheduled_at = 'performance_release' === $entry_point
            ? max( time() + 10, (int)$life['cooldown_until_epoch'] + 60 ) : time() + 10;
        $scheduled = wp_schedule_single_event( $scheduled_at, self::ONCE_HOOK, array(), true );
        if ( is_wp_error( $scheduled ) || false === $scheduled ) {
            return new WP_Error( 'mad4b_cwv_queue_failed', 'Unable to queue Core Web Vitals refresh.', array( 'status' => 503 ) );
        }
        $job = array(
            'job_id' => wp_generate_uuid4(),
            'state' => 'queued',
            'entry_point' => $entry_point,
            'queued_at' => gmdate( 'c' ),
        );
        update_option( self::JOB_OPTION, $job, false );
        $saved = get_option( self::JOB_OPTION, array() );
        if ( ! is_array( $saved ) || ( $saved['job_id'] ?? '' ) !== $job['job_id'] ) {
            return new WP_Error( 'mad4b_cwv_queue_not_persisted', 'Scheduled refresh, but its job receipt could not be saved.', array( 'status' => 503 ) );
        }
        return array( 'state' => 'queued', 'job_id' => $job['job_id'], 'scheduled_at_epoch' => $scheduled_at );
    }

    public static function schedule_if_needed() {
        if ( ! current_user_can( 'manage_options' ) || ! function_exists( 'wp_schedule_event' ) ) return;
        if ( ! wp_next_scheduled( self::DAILY_HOOK ) ) {
            wp_schedule_event( time() + 120, 'daily', self::DAILY_HOOK );
        }
    }

    public static function register_ability() {
        if ( ! function_exists( 'wp_register_ability' ) ) return;
        if ( function_exists( 'wp_has_ability' ) && wp_has_ability( 'mad4b/core-web-vitals-status' ) ) return;
        wp_register_ability( 'mad4b/core-web-vitals-status', array(
            'label' => 'Read Core Web Vitals Assessment',
            'description' => 'Read saved 28-day Google CrUX field-data assessments by device, without making network requests.',
            'category' => 'mad4b-read',
            'execute_callback' => array( __CLASS__, 'status' ),
            'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' ),
            'input_schema' => array( 'type' => 'object', 'additionalProperties' => false ),
            'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
            'meta' => array(
                'public' => false, 'show_in_rest' => false,
                'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'read' ),
                'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
            ),
        ) );
    }

    private static function settings() {
        $raw = get_option( self::SETTINGS_OPTION, array() );
        return is_array( $raw ) ? $raw : array();
    }

    private static function encryption_key() {
        if ( ! function_exists( 'sodium_crypto_secretbox' ) || ! function_exists( 'wp_salt' ) ) return false;
        return hash( 'sha256', wp_salt( 'auth' ) . '|' . wp_salt( 'secure_auth' ) . '|mad4b:cwv:api-key:v1', true );
    }

    private static function encrypt_api_key( $value ) {
        $key = self::encryption_key();
        if ( false === $key ) return new WP_Error( 'cwv_crypto_unavailable', 'Secret encryption is unavailable on this server.' );
        try {
            $nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
            return base64_encode( $nonce . sodium_crypto_secretbox( $value, $nonce, $key ) );
        } catch ( Throwable $e ) {
            return new WP_Error( 'cwv_crypto_failed', 'Unable to encrypt the API key.' );
        }
    }

    private static function decrypt_api_key( $ciphertext ) {
        if ( ! is_string( $ciphertext ) || '' === $ciphertext ) return '';
        $key = self::encryption_key();
        if ( false === $key ) return '';
        $raw = base64_decode( $ciphertext, true );
        if ( false === $raw || strlen( $raw ) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES ) return '';
        $nonce = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
        $text = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), $nonce, $key );
        return false === $text ? '' : $text;
    }

    private static function server_api_key() {
        foreach ( array( 'MAD4B_CWV_API_KEY', 'MAD4B_PAGESPEED_API_KEY' ) as $constant ) {
            if ( defined( $constant ) && is_string( constant( $constant ) ) && '' !== trim( constant( $constant ) ) ) {
                return trim( constant( $constant ) );
            }
        }
        foreach ( array( 'MAD4B_CWV_API_KEY', 'MAD4B_PAGESPEED_API_KEY' ) as $environment_key ) {
            $value = getenv( $environment_key );
            if ( is_string( $value ) && '' !== trim( $value ) ) return trim( $value );
        }
        return '';
    }

    private static function api_key() {
        $server_key = self::server_api_key();
        if ( '' !== $server_key ) return $server_key;
        $settings = self::settings();
        return self::decrypt_api_key( $settings['encrypted_api_key'] ?? '' );
    }

    public static function validate_monitored_url( $input ) {
        if ( ! is_string( $input ) || strlen( $input ) > 2048 ) {
            return new WP_Error( 'cwv_url_invalid', 'Enter a valid website URL (maximum 2048 characters).' );
        }
        $home = home_url( '/' );
        $candidate = trim( $input );
        if ( '' === $candidate ) $candidate = $home;
        if ( 0 === strpos( $candidate, '/' ) && 0 !== strpos( $candidate, '//' ) ) {
            $candidate = home_url( $candidate );
        }
        $base = wp_parse_url( $home );
        $parts = wp_parse_url( $candidate );
        if ( ! is_array( $parts ) || ! is_array( $base ) ||
            ! isset( $parts['scheme'], $parts['host'], $base['host'] ) ||
            'https' !== strtolower( (string) $parts['scheme'] ) ||
            strtolower( (string) $parts['host'] ) !== strtolower( (string) $base['host'] ) ||
            (int) ( $parts['port'] ?? 443 ) !== (int) ( $base['port'] ?? 443 ) ||
            isset( $parts['user'] ) || isset( $parts['pass'] ) ||
            isset( $parts['query'] ) || isset( $parts['fragment'] ) ) {
            return new WP_Error( 'cwv_url_not_same_site', 'Use an HTTPS URL on this WordPress site, without query parameters or fragments.' );
        }
        $path = $parts['path'] ?? '/';
        $base_path = $base['path'] ?? '/';
        if ( ! is_string( $path ) || ! is_string( $base_path ) ||
            0 !== strpos( $path, '/' ) ||
            0 !== strpos( $path, $base_path ) ||
            false !== strpos( strtolower( rawurldecode( $path ) ), '/wp-admin' ) ||
            false !== strpos( strtolower( rawurldecode( $path ) ), '/wp-json' ) ) {
            return new WP_Error( 'cwv_url_path_invalid', 'Use a public page URL within the site.' );
        }
        return esc_url_raw( $candidate );
    }

    private static function site_url() {
        $settings = self::settings();
        return self::validate_monitored_url( $settings['page_url'] ?? home_url( '/' ) );
    }

    /** Public for testability; callers must already be authenticated. Never returns API key. */
    public static function save_settings( $url, $new_key, $clear_key = false ) {
        $url = self::validate_monitored_url( $url );
        if ( is_wp_error( $url ) ) return $url;
        if ( ! is_string( $new_key ) || strlen( $new_key ) > 256 ) {
            return new WP_Error( 'cwv_api_key_invalid', 'Google API key must be a short text value.' );
        }
        $new_key = trim( $new_key );
        if ( '' !== $new_key && ! preg_match( '/\A[A-Za-z0-9_-]{10,256}\z/D', $new_key ) ) {
            return new WP_Error( 'cwv_api_key_invalid', 'Google API key format is invalid.' );
        }
        $settings = self::settings();
        $previous_settings = $settings;
        $settings['page_url'] = $url;
        if ( $clear_key ) unset( $settings['encrypted_api_key'] );
        if ( '' !== $new_key ) {
            $encrypted = self::encrypt_api_key( $new_key );
            if ( is_wp_error( $encrypted ) ) return $encrypted;
            $settings['encrypted_api_key'] = $encrypted;
        }
        update_option( self::SETTINGS_OPTION, $settings, false );
        if ( self::settings() !== $settings ) {
            return new WP_Error( 'cwv_settings_not_persisted', 'Settings could not be verified after saving.' );
        }
        $record = get_option( self::OPTION, array() );
        if ( is_array( $record ) ) {
            $previous_url = isset( $previous_settings['page_url'] ) ? (string) $previous_settings['page_url'] : home_url( '/' );
            if ( $previous_url !== $url ) {
                // A new monitored page must never inherit an old page's field-data evidence.
                $record['devices'] = array();
                $record['history'] = array();
                $record['attempts'] = array();
            }
            $record['cooldown_until_epoch'] = 0;
            $record['configuration_updated_at'] = gmdate( 'c' );
            update_option( self::OPTION, $record, false );
        }
        return array(
            'saved' => true,
            'page_url' => $url,
            'api_key_configured' => '' !== self::api_key(),
            'api_key_source' => '' !== self::server_api_key() ? 'server' :
                ( '' !== self::decrypt_api_key( $settings['encrypted_api_key'] ?? '' ) ? 'encrypted_settings' : 'none' ),
        );
    }

    public static function handle_save_settings() {
        if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Administrator POST required.', '', array( 'response' => 403 ) );
        }
        check_admin_referer( 'mad4b_cwv_save_settings', 'mad4b_cwv_settings_nonce' );
        $raw_url = $_POST['mad4b_cwv_page_url'] ?? '';
        $raw_key = $_POST['mad4b_cwv_api_key'] ?? '';
        $url = is_string( $raw_url ) ? wp_unslash( $raw_url ) : null;
        $key = is_string( $raw_key ) ? wp_unslash( $raw_key ) : null;
        $clear = isset( $_POST['mad4b_cwv_clear_key'] ) && '1' === (string) $_POST['mad4b_cwv_clear_key'];
        $result = self::save_settings( $url, $key, $clear );
        $code = is_wp_error( $result ) ? sanitize_key( $result->get_error_code() ) : 'saved';
        wp_safe_redirect( add_query_arg( 'mad4b_cwv_settings', $code, admin_url( 'admin.php?page=mad4b-control-plane-performance' ) ) );
        exit;
    }

    public static function render_settings_form() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        $settings = self::settings();
        $page_url = $settings['page_url'] ?? home_url( '/' );
        $status = self::status();
        $notice = $_GET['mad4b_cwv_settings'] ?? '';
        if ( is_string( $notice ) && '' !== $notice ) {
            $notice = sanitize_key( wp_unslash( $notice ) );
            $success = 'saved' === $notice;
            echo '<div class="notice ' . ( $success ? 'notice-success' : 'notice-error' ) . ' inline"><p>' .
                esc_html( $success ? 'Core Web Vitals settings saved and verified.' : 'Settings not changed. Validation error: ' . $notice ) .
                '</p></div>';
        }
        echo '<h3>Core Web Vitals settings</h3>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" autocomplete="off">';
        echo '<input type="hidden" name="action" value="mad4b_cwv_save_settings">';
        wp_nonce_field( 'mad4b_cwv_save_settings', 'mad4b_cwv_settings_nonce' );
        echo '<table class="form-table" role="presentation" style="max-width:1050px"><tbody>';
        echo '<tr><th scope="row"><label for="mad4b_cwv_page_url">Page URL to measure</label></th><td>';
        echo '<input class="regular-text" style="width:100%;max-width:620px" type="url" id="mad4b_cwv_page_url" name="mad4b_cwv_page_url" value="' .
            esc_attr( $page_url ) . '" required>';
        echo '<p class="description">The home page by default. You can enter any public HTTPS page on the same domain. Origin and selected page results are kept separate.</p>';
        echo '</td></tr>';
        echo '<tr><th scope="row"><label for="mad4b_cwv_api_key">Google PageSpeed Insights API key</label></th><td>';
        echo '<input class="regular-text" style="width:100%;max-width:620px" type="password" id="mad4b_cwv_api_key" name="mad4b_cwv_api_key" value="" placeholder="' .
            esc_attr( $status['api_key_configured'] ? 'Key configured — leave blank to keep it' : 'Paste Google API key' ) .
            '" autocomplete="new-password" spellcheck="false">';
        echo '<p class="description">Leave blank to keep the existing key. Newly saved keys are encrypted at rest, not displayed again. Environment variables take precedence.</p>';
        echo '<p>Key status: <strong>' .
            esc_html( $status['api_key_configured'] ? 'Configured (' . $status['api_key_source'] . ')' : 'Not configured' ) .
            '</strong></p>';
        echo '<label><input type="checkbox" name="mad4b_cwv_clear_key" value="1"> Remove stored dashboard API key</label>';
        echo '</td></tr></tbody></table>';
        submit_button( 'Save Core Web Vitals settings', 'primary', 'submit', false );
        echo '</form>';
        echo '<p class="description">Measurement targets: Mobile and Desktop. Updating these settings does not automatically start an external API request; use the refresh button below.</p>';
    }

    private static function metric( $metrics, $key, $good, $poor, $unit ) {
        $datum = isset( $metrics[ $key ] ) && is_array( $metrics[ $key ] ) ? $metrics[ $key ] : array();
        $raw = isset( $datum['percentile'] ) ? $datum['percentile'] : null;
        if ( ! is_numeric( $raw ) || (float) $raw < 0 ) {
            return array( 'p75' => null, 'unit' => $unit, 'rating' => 'unavailable' );
        }
        $p75 = (float) $raw;
        // Reject malformed CLS values rather than guessing a scale.
        if ( 'score' === $unit && $p75 > 10 ) {
            return array( 'p75' => null, 'unit' => $unit, 'rating' => 'unavailable' );
        }
        $rating = $p75 <= $good ? 'good' : ( $p75 <= $poor ? 'needs_improvement' : 'poor' );
        return array( 'p75' => $p75, 'unit' => $unit, 'rating' => $rating );
    }

    public static function assess_field_data( $experience ) {
        $metrics = isset( $experience['metrics'] ) && is_array( $experience['metrics'] )
            ? $experience['metrics'] : array();
        $items = array(
            'lcp' => self::metric( $metrics, 'LARGEST_CONTENTFUL_PAINT_MS', 2500, 4000, 'ms' ),
            'inp' => self::metric( $metrics, 'INTERACTION_TO_NEXT_PAINT', 200, 500, 'ms' ),
            'cls' => self::metric( $metrics, 'CUMULATIVE_LAYOUT_SHIFT_SCORE', 0.1, 0.25, 'score' ),
        );
        $available = 0;
        $good = 0;
        foreach ( $items as $item ) {
            if ( null !== $item['p75'] ) {
                ++$available;
                if ( 'good' === $item['rating'] ) ++$good;
            }
        }
        // Missing/insufficient CrUX samples are NOT a failed assessment.
        $verdict = 3 !== $available ? 'insufficient_data' : ( 3 === $good ? 'passed' : 'failed' );
        return array(
            'verdict' => $verdict,
            'source' => 'google_crux_field_data',
            'window' => 'rolling_28_days',
            'percentile' => 75,
            'available_core_metrics' => $available,
            'metrics' => $items,
        );
    }

    private static function fetch_strategy( $strategy, $site_url, $timeout = 35 ) {
        $args = array( 'url' => $site_url, 'strategy' => $strategy, 'category' => 'performance' );
        $api_key = self::api_key();
        if ( '' !== $api_key ) $args['key'] = $api_key;
        $url = add_query_arg( $args, self::API );
        // The endpoint is constant; do not follow redirects to arbitrary hosts.
        $request_started_at = microtime( true );
        $response = wp_remote_get( $url, array(
            // Two serial Lighthouse requests must fit the live PHP-FPM 100-second ceiling.
            'timeout' => max( 5, min( self::DEVICE_TIMEOUT, (int) $timeout ) ), 'redirection' => 0, 'limit_response_size' => 8388608,
            'headers' => array( 'Accept' => 'application/json' ),
            'user-agent' => 'MAD4B-Core-Web-Vitals/1.0',
        ) );
        $duration_ms = (int) round( ( microtime( true ) - $request_started_at ) * 1000 );
        if ( is_wp_error( $response ) ) {
            $message = strtolower( (string) $response->get_error_message() );
            $class = ( false !== strpos( $message, 'timed out' ) || false !== strpos( $message, 'timeout' ) )
                ? 'timeout' : ( false !== strpos( $message, 'ssl' ) ? 'tls_error'
                : ( false !== strpos( $message, 'resolve' ) ? 'dns_error' : 'transport_other' ) );
            return array(
                'ok' => false,
                'state' => 'transport_error',
                'error_class' => $class,
                'error_code' => sanitize_key( (string) $response->get_error_code() ),
                'duration_ms' => $duration_ms,
            );
        }
        $http = (int) wp_remote_retrieve_response_code( $response );
        if ( 200 !== $http ) {
            return array( 'ok' => false, 'state' => 429 === $http ? 'rate_limited' :
                ( 403 === $http ? 'provider_access_denied' : 'provider_http_error' ), 'http_status' => $http, 'duration_ms' => $duration_ms );
        }
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $body ) ) {
            return array( 'ok' => false, 'state' => 'invalid_provider_response', 'duration_ms' => $duration_ms );
        }
        $origin_data = isset( $body['originLoadingExperience'] ) && is_array( $body['originLoadingExperience'] )
            ? $body['originLoadingExperience'] : array();
        $page_data = isset( $body['loadingExperience'] ) && is_array( $body['loadingExperience'] )
            ? $body['loadingExperience'] : array();
        $lighthouse = isset( $body['lighthouseResult'] ) && is_array( $body['lighthouseResult'] )
            ? $body['lighthouseResult'] : array();
        $score = $lighthouse['categories']['performance']['score'] ?? null;
        $score = is_numeric( $score ) && $score >= 0 && $score <= 1 ? (int) round( 100 * $score ) : null;
        $origin = self::assess_field_data( $origin_data );
        $page = self::assess_field_data( $page_data );
        if ( ! empty( $page_data['origin_fallback'] ) ) {
            $page['verdict'] = 'origin_fallback';
            $page['fallback_to_origin'] = true;
        }
        return array(
            'ok' => true,
            'state' => 'collected',
            'duration_ms' => $duration_ms,
            'snapshot' => array(
                'collected_at' => gmdate( 'c' ),
                'strategy' => $strategy,
                'url' => $site_url,
                'origin' => $origin,
                'homepage' => $page,
                'lighthouse_performance_score' => $score,
                'lighthouse_is_not_field_data' => true,
                'source' => 'google_pagespeed_insights_v5',
            ),
        );
    }


    /**
     * Daily and manual Cron events only schedule workers. No remote Google call
     * occurs in dispatch, admin-post or REST. Device jobs are chained rather
     * than parallel, so a slow Lighthouse run never consumes two PHP-FPM slots.
     */
    public static function dispatch_daily() {
        $life = self::lifecycle_status();
        if ( in_array( $life['state'], array( 'queued', 'running' ), true ) ||
            $life['worker_running'] || $life['device_next_run_epoch'] ) return;
        $record = get_option( self::OPTION, array() );
        if ( is_array( $record ) && (int) ( $record['cooldown_until_epoch'] ?? 0 ) > time() ) return;
        update_option( self::JOB_OPTION, array(
            'job_id' => wp_generate_uuid4(),
            'state' => 'queued',
            'entry_point' => 'daily',
            'queued_at' => gmdate( 'c' ),
        ), false );
        self::dispatch_queued();
    }

    public static function dispatch_queued() {
        $job = get_option( self::JOB_OPTION, array() );
        if ( ! is_array( $job ) || 'queued' !== ( $job['state'] ?? '' ) || empty( $job['job_id'] ) ) return;
        $url = self::site_url();
        if ( is_wp_error( $url ) ) {
            $job['state'] = 'failed';
            $job['completed_at'] = gmdate( 'c' );
            $job['result'] = array( 'state' => 'invalid_site_url' );
            update_option( self::JOB_OPTION, $job, false );
            return;
        }
        $job['state'] = 'running';
        $job['started_at'] = gmdate( 'c' );
        $job['page_url'] = $url;
        $job['pending_devices'] = array( 'mobile', 'desktop' );
        $job['attempt_counts'] = array( 'mobile' => 0, 'desktop' => 0 );
        $job['result'] = array( 'device_states' => array(), 'attempts' => array() );
        update_option( self::JOB_OPTION, $job, false );
        $record = get_option( self::OPTION, array() );
        if ( ! is_array( $record ) ) $record = array();
        $record['attempts'] = array();
        $record['attempted_at'] = gmdate( 'c' );
        update_option( self::OPTION, $record, false );
        $scheduled = wp_schedule_single_event(
            time() + 5, self::DEVICE_HOOK, array( (string) $job['job_id'], 'mobile' ), true
        );
        if ( is_wp_error( $scheduled ) || false === $scheduled ) {
            $job['state'] = 'failed';
            $job['completed_at'] = gmdate( 'c' );
            $job['result'] = array( 'state' => 'device_schedule_failed' );
            update_option( self::JOB_OPTION, $job, false );
        }
    }

    /** Single-device, bounded provider worker. Old successful snapshots are never deleted on failure. */
    public static function run_device( $job_id, $strategy ) {
        $job_id = (string) $job_id;
        $strategy = (string) $strategy;
        if ( ! in_array( $strategy, array( 'mobile', 'desktop' ), true ) ) return;
        $job = get_option( self::JOB_OPTION, array() );
        if ( ! is_array( $job ) || ( $job['job_id'] ?? '' ) !== $job_id ||
            'running' !== ( $job['state'] ?? '' ) ||
            ( $job['pending_devices'][0] ?? '' ) !== $strategy ) return;
        $token = self::acquire_lock();
        if ( ! $token ) {
            // WordPress unschedules an event before firing it: explicitly requeue
            // on contention rather than silently dropping this device.
            wp_schedule_single_event( time() + 45, self::DEVICE_HOOK, array( $job_id, $strategy ) );
            return;
        }
        try {
            $job = get_option( self::JOB_OPTION, array() );
            if ( ! is_array( $job ) || ( $job['job_id'] ?? '' ) !== $job_id ||
                'running' !== ( $job['state'] ?? '' ) ) return;
            $job['active_device'] = $strategy;
            $job['attempt_counts'][ $strategy ] = 1 + (int) ( $job['attempt_counts'][ $strategy ] ?? 0 );
            $job['device_started_at'] = gmdate( 'c' );
            update_option( self::JOB_OPTION, $job, false );
            $url = self::site_url();
            if ( is_wp_error( $url ) || ( $job['page_url'] ?? '' ) !== $url ) {
                $result = array( 'ok' => false, 'state' => 'configuration_changed' );
            } else {
                $result = self::fetch_strategy( $strategy, $url, self::DEVICE_TIMEOUT );
            }
            // Reload after the long network request; a newer page setting must not
            // inherit field-data from a previous target.
            $current_url = self::site_url();
            if ( is_wp_error( $current_url ) || ( $job['page_url'] ?? '' ) !== $current_url ) {
                $result = array( 'ok' => false, 'state' => 'configuration_changed' );
            }
            $record = get_option( self::OPTION, array() );
            if ( ! is_array( $record ) ) $record = array();
            $record['contract'] = self::CONTRACT;
            $record['attempted_at'] = gmdate( 'c' );
            $record['attempts'] = is_array( $record['attempts'] ?? null ) ? $record['attempts'] : array();
            $record['devices'] = is_array( $record['devices'] ?? null ) ? $record['devices'] : array();
            $record['history'] = is_array( $record['history'] ?? null ) ? $record['history'] : array();
            $attempt = array(
                'state' => (string) ( $result['state'] ?? 'unknown' ),
                'duration_ms' => isset( $result['duration_ms'] ) ? (int) $result['duration_ms'] : null,
                'error_code' => isset( $result['error_code'] ) ? sanitize_key( (string) $result['error_code'] ) : null,
                'error_class' => isset( $result['error_class'] ) ? sanitize_key( (string) $result['error_class'] ) : null,
                'http_status' => isset( $result['http_status'] ) ? (int) $result['http_status'] : null,
                'at' => gmdate( 'c' ),
            );
            $record['attempts'][ $strategy ] = $attempt;
            if ( ! empty( $result['ok'] ) ) {
                $record['devices'][ $strategy ] = $result['snapshot'];
                $record['history'][] = array(
                    'date' => gmdate( 'Y-m-d' ), 'strategy' => $strategy,
                    'url' => $url,
                    'origin_verdict' => $result['snapshot']['origin']['verdict'],
                    'origin_lcp' => $result['snapshot']['origin']['metrics']['lcp']['p75'],
                    'origin_inp' => $result['snapshot']['origin']['metrics']['inp']['p75'],
                    'origin_cls' => $result['snapshot']['origin']['metrics']['cls']['p75'],
                );
                $record['history'] = array_slice( $record['history'], -self::MAX_HISTORY * 2 );
            }
            $record['last_attempt_has_errors'] = false;
            foreach ( $record['attempts'] as $latest ) {
                if ( 'collected' !== ( $latest['state'] ?? '' ) ) $record['last_attempt_has_errors'] = true;
            }
            if ( 'rate_limited' === $attempt['state'] ) {
                $record['cooldown_until_epoch'] = max( (int) ( $record['cooldown_until_epoch'] ?? 0 ), time() + 900 );
            } elseif ( 'provider_access_denied' === $attempt['state'] ) {
                $record['cooldown_until_epoch'] = max( (int) ( $record['cooldown_until_epoch'] ?? 0 ), time() + 1800 );
            }
            update_option( self::OPTION, $record, false );
            $job = get_option( self::JOB_OPTION, array() );
            if ( ! is_array( $job ) || ( $job['job_id'] ?? '' ) !== $job_id ||
                'running' !== ( $job['state'] ?? '' ) ) return;
            $job['result']['device_states'][ $strategy ] = $attempt['state'];
            $job['result']['attempts'][ $strategy ] = $attempt;
            $job['device_completed_at'] = gmdate( 'c' );
            array_shift( $job['pending_devices'] );
            $retryable = 'transport_error' === $attempt['state']
                && (int) ( $job['attempt_counts'][ $strategy ] ?? 0 ) < 2;
            if ( $retryable ) $job['pending_devices'][] = $strategy;
            if ( ! empty( $job['pending_devices'] ) ) {
                $next = (string) $job['pending_devices'][0];
                // Delay a second attempt to avoid a tight PSI retry loop.
                $delay = (int) ( $job['attempt_counts'][ $next ] ?? 0 ) > 0 ? self::RETRY_DELAY : 5;
                $scheduled = wp_schedule_single_event(
                    time() + $delay, self::DEVICE_HOOK, array( $job_id, $next ), true
                );
                if ( is_wp_error( $scheduled ) || false === $scheduled ) {
                    $job['state'] = 'failed';
                    $job['completed_at'] = gmdate( 'c' );
                    $job['result']['state'] = 'next_device_schedule_failed';
                }
            } else {
                $collected = count( array_filter(
                    $job['result']['device_states'],
                    static function( $state ) { return 'collected' === $state; }
                ) );
                $job['state'] = 2 === $collected ? 'completed' : ( $collected ? 'partial' : 'failed' );
                $job['completed_at'] = gmdate( 'c' );
                $job['result']['collected_devices'] = $collected;
                if ( 'failed' === $job['state'] && empty( $record['cooldown_until_epoch'] ) ) {
                    $record['cooldown_until_epoch'] = time() + 120;
                    update_option( self::OPTION, $record, false );
                }
            }
            update_option( self::JOB_OPTION, $job, false );
        } catch ( Throwable $e ) {
            // Never expose provider error strings, which can contain URL query credentials.
            $job = get_option( self::JOB_OPTION, array() );
            if ( is_array( $job ) && ( $job['job_id'] ?? '' ) === $job_id ) {
                $job['state'] = 'failed';
                $job['completed_at'] = gmdate( 'c' );
                $job['result']['state'] = 'device_worker_exception';
                update_option( self::JOB_OPTION, $job, false );
            }
        } finally {
            self::release_lock( $token );
        }
    }

    private static function acquire_lock() {
        global $wpdb;
        $now = time();
        $prior = get_option( self::LOCK, array() );
        if ( is_array( $prior ) && ! empty( $prior['expires'] ) && (int) $prior['expires'] < $now ) {
            $deleted = $wpdb->delete(
                $wpdb->options,
                array( 'option_name' => self::LOCK, 'option_value' => maybe_serialize( $prior ) ),
                array( '%s', '%s' )
            );
            if ( 1 === (int) $deleted ) wp_cache_delete( self::LOCK, 'options' );
        }
        $token = wp_generate_uuid4();
        return add_option( self::LOCK, array( 'token' => $token, 'expires' => $now + self::LOCK_TTL ), '', false )
            ? $token : false;
    }

    private static function release_lock( $token ) {
        global $wpdb;
        $current = get_option( self::LOCK, array() );
        if ( is_array( $current ) && isset( $current['token'] ) && hash_equals( (string) $current['token'], $token ) ) {
            $deleted = $wpdb->delete(
                $wpdb->options,
                array( 'option_name' => self::LOCK, 'option_value' => maybe_serialize( $current ) ),
                array( '%s', '%s' )
            );
            if ( 1 === (int) $deleted ) wp_cache_delete( self::LOCK, 'options' );
        }
    }

    public static function refresh() {
        $token = self::acquire_lock();
        if ( ! $token ) return array( 'state' => 'already_running' );
        $job = get_option( self::JOB_OPTION, array() );
        if ( ! is_array( $job ) || 'queued' !== ( $job['state'] ?? '' ) ) {
            $job = array(
                'job_id' => wp_generate_uuid4(),
                'entry_point' => 'scheduled_cron',
                'queued_at' => gmdate( 'c' ),
            );
        }
        $job['state'] = 'running';
        $job['started_at'] = gmdate( 'c' );
        update_option( self::JOB_OPTION, $job, false );
        try {
            $record = get_option( self::OPTION, array() );
            if ( ! is_array( $record ) ) $record = array();
            $record['attempted_at'] = gmdate( 'c' );
            $record['contract'] = self::CONTRACT;
            $record['devices'] = is_array( $record['devices'] ?? null ) ? $record['devices'] : array();
            $record['attempts'] = array();
            $record['history'] = is_array( $record['history'] ?? null ) ? $record['history'] : array();
            $site_url = self::site_url();
            if ( is_wp_error( $site_url ) ) {
                $record['attempts']['site'] = array( 'state' => 'invalid_site_url', 'at' => gmdate( 'c' ) );
            } else {
                $provider_unavailable = false;
                foreach ( array( 'mobile', 'desktop' ) as $strategy ) {
                    $result = $provider_unavailable
                        ? array('ok'=>false,'state'=>'deferred_provider_unavailable','duration_ms'=>0)
                        : self::fetch_strategy( $strategy, $site_url );
                    if (in_array($result['state'], array('transport_error','rate_limited','provider_access_denied'), true)) {
                        $provider_unavailable = true;
                    }
                    $record['attempts'][ $strategy ] = array(
                        'state' => $result['state'],
                        'duration_ms' => isset( $result['duration_ms'] ) ? (int) $result['duration_ms'] : null,
                        'error_code' => isset( $result['error_code'] ) ? sanitize_key( (string) $result['error_code'] ) : null,
                        'error_class' => isset( $result['error_class'] ) ? sanitize_key( (string) $result['error_class'] ) : null,
                        'http_status' => isset( $result['http_status'] ) ? (int) $result['http_status'] : null,
                        'at' => gmdate( 'c' ),
                    );
                    if ( ! empty( $result['ok'] ) ) {
                        $record['devices'][ $strategy ] = $result['snapshot'];
                        $record['history'][] = array(
                            'date' => gmdate( 'Y-m-d' ), 'strategy' => $strategy,
                            'url' => $site_url,
                            'origin_verdict' => $result['snapshot']['origin']['verdict'],
                            'origin_lcp' => $result['snapshot']['origin']['metrics']['lcp']['p75'],
                            'origin_inp' => $result['snapshot']['origin']['metrics']['inp']['p75'],
                            'origin_cls' => $result['snapshot']['origin']['metrics']['cls']['p75'],
                        );
                    }
                    // Persist per-device evidence even if the next provider request fails.
                    update_option( self::OPTION, $record, false );
                }
            }
            $record['history'] = array_slice( $record['history'], -self::MAX_HISTORY * 2 );
            $record['last_attempt_has_errors'] = false;
            $collected = 0;
            $cooldown = 0;
            foreach ( $record['attempts'] as $attempt ) {
                if ( 'collected' === $attempt['state'] ) { ++$collected; continue; }
                $record['last_attempt_has_errors'] = true;
                $cooldown = max( $cooldown, 'rate_limited' === $attempt['state'] ? 900 :
                    ( 'provider_access_denied' === $attempt['state'] ? 1800 :
                    ( 'transport_error' === $attempt['state'] || 'deferred_provider_unavailable' === $attempt['state'] ? 600 : 120 ) ) );
            }
            $record['cooldown_until_epoch'] = $cooldown ? time() + $cooldown : 0;
            update_option( self::OPTION, $record, false );
            $job['state'] = 2 === $collected ? 'completed' : ( $collected ? 'partial' : 'failed' );
            $job['completed_at'] = gmdate( 'c' );
            $job['result'] = array(
                'collected_devices' => $collected,
                'attempts' => $record['attempts'],
                'cooldown_until_epoch' => $record['cooldown_until_epoch'],
            );
            update_option( self::JOB_OPTION, $job, false );
            return self::status();
        } catch ( Throwable $e ) {
            // Do not include sensitive provider errors or request URLs in any status.
            $job['state'] = 'failed';
            $job['completed_at'] = gmdate( 'c' );
            $job['result'] = array( 'state' => 'worker_exception' );
            update_option( self::JOB_OPTION, $job, false );
            return array( 'state' => 'worker_exception' );
        } finally {
            self::release_lock( $token );
        }
    }

    public static function status() {
        $record = get_option( self::OPTION, array() );
        if ( ! is_array( $record ) ) $record = array();
        $devices = isset( $record['devices'] ) && is_array( $record['devices'] ) ? $record['devices'] : array();
        foreach ( $devices as $key => &$device ) {
            $stamp = isset( $device['collected_at'] ) ? strtotime( $device['collected_at'] ) : false;
            $device['stale'] = ! $stamp || time() - $stamp > 259200;
        }
        unset( $device );
        return array(
            'contract' => self::CONTRACT,
            'read_only' => true, 'mutation_performed' => false,
            'source' => 'google_crux_via_pagespeed_insights',
'api_key_configured' => '' !== self::api_key(),
            'api_key_source' => '' !== self::server_api_key() ? 'server' :
                ( '' !== self::decrypt_api_key( self::settings()['encrypted_api_key'] ?? '' ) ? 'encrypted_settings' : 'none' ),
            'monitored_page_url' => self::site_url() instanceof WP_Error ? home_url( '/' ) : self::site_url(),
            'site' => home_url( '/' ),
            'scheduled' => (bool) wp_next_scheduled( self::DAILY_HOOK ),
            'next_run_epoch' => wp_next_scheduled( self::DAILY_HOOK ) ?: null,
            'attempted_at' => $record['attempted_at'] ?? null,
            'last_attempt_has_errors' => $record['last_attempt_has_errors'] ?? false,
            'attempts' => $record['attempts'] ?? array(),
            'devices' => $devices,
            'history' => $record['history'] ?? array(),
            'lifecycle' => self::lifecycle_status(),
        );
    }

    public static function handle_refresh() {
        if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Administrator POST required.', '', array( 'response' => 403 ) );
        }
        check_admin_referer( 'mad4b_cwv_refresh', 'mad4b_cwv_nonce' );
        $result = self::queue_refresh( 'admin_post' );
        $state = is_wp_error( $result ) ? sanitize_key( $result->get_error_code() ) :
            sanitize_key( (string) ( $result['state'] ?? 'unknown' ) );
        wp_safe_redirect( add_query_arg( 'mad4b_cwv_refresh_state', $state,
            admin_url( 'admin.php?page=mad4b-control-plane-performance' ) ) );
        exit;
    }

    public static function render_admin_section() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        $status = self::status();
        echo '<h2>Core Web Vitals Assessment (Google real-user data)</h2>';
        echo '<p>Rolling 28-day Chrome UX Report (75th percentile). A Lighthouse score is diagnostic only and never determines Passed/Failed.</p>';
        self::render_settings_form();
        echo '<table class="widefat striped" style="max-width:1100px"><thead><tr><th>Device</th><th>Origin assessment</th><th>LCP (ms)</th><th>INP (ms)</th><th>CLS</th><th>Updated UTC</th><th>Lighthouse</th></tr></thead><tbody>';
        foreach ( array( 'mobile', 'desktop' ) as $strategy ) {
            $snapshot = $status['devices'][ $strategy ] ?? array();
            $origin = $snapshot['origin'] ?? array();
            $metrics = $origin['metrics'] ?? array();
            $verdict = $origin['verdict'] ?? 'not_measured';
            if ( ! empty( $snapshot['stale'] ) ) $verdict .= ' (stale)';
            $values = array(
                ucfirst( $strategy ), $verdict,
                $metrics['lcp']['p75'] ?? null, $metrics['inp']['p75'] ?? null,
                $metrics['cls']['p75'] ?? null, $snapshot['collected_at'] ?? '—',
                isset( $snapshot['lighthouse_performance_score'] ) ? (string) $snapshot['lighthouse_performance_score'] . '/100' : '—',
            );
            echo '<tr>';
            foreach ( $values as $value ) echo '<td>' . esc_html( null === $value ? '—' : (string) $value ) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '<p><strong>Data source:</strong> Google CrUX origin field data. LCP ≤ 2500ms; INP ≤ 200ms; CLS ≤ 0.10. All three must be good for Passed.</p>';
        if ( ! empty( $status['attempts'] ) ) {
            echo '<p><strong>Last refresh attempts:</strong> ';
            foreach ( $status['attempts'] as $strategy => $attempt ) {
                echo esc_html( $strategy . ': ' . ( $attempt['state'] ?? 'unknown' ) . ( isset( $attempt['http_status'] ) ? ' (HTTP ' . $attempt['http_status'] . ')' : '' ) ) . ' ';
            }
            echo '</p>';
        }
        if ( ! $status['api_key_configured'] ) echo '<p>A Google API key is not configured. Add one above for authenticated API quota. HTTP 429 is a rate-limit response, not a Failed assessment.</p>';
        $life = $status['lifecycle'] ?? self::lifecycle_status();
        echo '<p><strong>Worker lifecycle:</strong> ' . esc_html( $life['state'] ) .
            ' · <strong>Last job:</strong> ' . esc_html( $life['job_id'] ?? 'none' ) . '</p>';
        if ( $life['cooldown_until_epoch'] > time() ) echo '<p><strong>Google API retry after (UTC):</strong> ' .
            esc_html( gmdate( 'Y-m-d H:i:s', $life['cooldown_until_epoch'] ) ) . '</p>';
        $refresh_notice = $_GET['mad4b_cwv_refresh_state'] ?? '';
        if ( is_string( $refresh_notice ) && '' !== $refresh_notice ) echo '<div class="notice notice-info inline"><p>Refresh request: ' .
            esc_html( sanitize_key( wp_unslash( $refresh_notice ) ) ) . '</p></div>';
        echo '<p>Next scheduled run (UTC): ' . esc_html( $status['next_run_epoch'] ? gmdate( 'Y-m-d H:i:s', $status['next_run_epoch'] ) : 'not scheduled' ) . '</p>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        echo '<input type="hidden" name="action" value="mad4b_cwv_refresh">';
        wp_nonce_field( 'mad4b_cwv_refresh', 'mad4b_cwv_nonce' );
        submit_button( 'Queue Core Web Vitals refresh', 'secondary', 'submit', false );
        echo '</form><hr>';
    }
}
MAD4B_SCP_Core_Web_Vitals::boot();
