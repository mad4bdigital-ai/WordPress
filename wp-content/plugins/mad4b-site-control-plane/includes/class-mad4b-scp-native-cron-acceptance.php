<?php
/**
 * Optional owner-triggered WP-Cron HTTP / WordPress smoke.
 *
 * This is NOT browser automation, JS parity or a signed Browser Acceptance
 * result. It never calls mutating plugin abilities or grants authority.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class MAD4B_SCP_Native_Cron_Acceptance {
    const OPTION = 'mad4b_scp_native_cron_smoke_v1';
    const LOCK = 'mad4b_scp_native_cron_smoke_lock_v1';
    const HOOK = 'mad4b_scp_native_cron_smoke_execute';
    const STALE_SECONDS = 1800;
    public static function boot() {
        add_action( 'admin_post_mad4b_native_cron_smoke_queue', array( __CLASS__, 'enqueue_from_admin' ) );
        add_action( 'admin_post_mad4b_native_cron_smoke_reconcile', array( __CLASS__, 'reconcile_from_admin' ) );
        add_action( self::HOOK, array( __CLASS__, 'run' ), 10, 1 );
    }
    public static function staging() {
        return class_exists( 'MAD4B_SCP_Environment' )
            && 'staging' === MAD4B_SCP_Environment::effective();
    }
    public static function available() {
        return self::staging() && function_exists( 'wp_schedule_single_event' )
            && function_exists( 'wp_next_scheduled' )
            && ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON );
    }
    private static function stale_job( $job ) {
        if ( ! is_array( $job ) || ! in_array( (string) ( $job['state'] ?? '' ),
            array( 'queued', 'running' ), true ) ) return false;
        $queued = strtotime( (string) ( $job['queued_at'] ?? '' ) );
        return false !== $queued && $queued > 0 && time() - $queued >= self::STALE_SECONDS;
    }
    public static function status() {
        $stored = get_option( self::OPTION, array() );
        $job = is_array( $stored ) ? $stored : array();
        $id = (string) ( $job['job_id'] ?? '' );
        $lock = get_option( self::LOCK, '' );
        $lock_matches = is_string( $lock ) && '' !== $id && hash_equals( $lock, $id );
        $stale = self::stale_job( $job ) && $lock_matches;
        $scheduled = $stale && function_exists( 'wp_next_scheduled' )
            ? wp_next_scheduled( self::HOOK, array( $id ) ) : false;
        return array( 'contract' => 'mad4b.native-wpcron-acceptance-status.v1',
            'mode' => 'wp_cron', 'wp_cron_available' => self::available(),
            'browser_automation' => false, 'signed_browser_acceptance' => false,
            'release_ready' => false, 'authorizing' => false, 'read_only' => true,
            'stale_lock_detected' => $stale,
            'manual_reconciliation_eligible' => $stale && false === $scheduled,
            'manual_reconciliation_required' => $stale,
            'blind_retry_allowed' => false, 'recovery_performed' => false,
            'job' => array_intersect_key( $job, array_flip(
                array( 'job_id', 'state', 'queued_at', 'checked_at', 'http_status', 'elapsed_ms',
                    'error_code', 'source', 'checked_epoch', 'source_commit_sha',
                    'site_origin_sha256', 'preference_revision' ) ) );
    }

    /** Explicit local administrator reconciliation; never requeues a stale job. */
    public static function reconcile_stale( $expected_job_id ) {
        if ( ! self::staging() || ! current_user_can( 'manage_options' ) )
            return new WP_Error( 'mad4b_native_cron_recovery_denied', 'Staging administrator required.' );
        $id = (string) $expected_job_id;
        if ( ! preg_match( '/^[a-f0-9-]{36}$/D', $id ) )
            return new WP_Error( 'mad4b_native_cron_recovery_identity_invalid', 'Exact job ID required.' );
        $job = get_option( self::OPTION, array() );
        $locked = get_option( self::LOCK, '' );
        if ( ! self::stale_job( $job )
            || ! is_string( $locked ) || ! hash_equals( $locked, $id )
            || ! hash_equals( (string) ( $job['job_id'] ?? '' ), $id ) )
            return new WP_Error( 'mad4b_native_cron_recovery_not_stale', 'Stale exact locked job required.' );
        if ( ! function_exists( 'wp_next_scheduled' )
            || false !== wp_next_scheduled( self::HOOK, array( $id ) ) )
            return new WP_Error( 'mad4b_native_cron_recovery_event_pending', 'A scheduled event still exists; inspect WP-Cron before recovery.' );
        $job['state'] = 'abandoned_after_owner_review';
        $job['checked_at'] = gmdate( 'c' );
        $job['checked_epoch'] = time();
        $job['error_code'] = 'stale_or_interrupted_readonly_smoke';
        if ( ! update_option( self::OPTION, $job, false )
            || ! hash_equals( $id, (string) get_option( self::LOCK, '' ) )
            || ! delete_option( self::LOCK ) )
            return new WP_Error( 'mad4b_native_cron_recovery_writeback_unverified',
                'Job was marked abandoned but lock readback failed; do not retry.' );
        return array( 'state' => 'abandoned_after_owner_review',
            'original_job_id' => $id, 'new_job_enqueued' => false,
            'lock_released' => '' === (string) get_option( self::LOCK, '' ),
            'authorizing' => false, 'browser_signed' => false );
    }

    public static function reconcile_from_admin() {
        if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) )
            wp_die( 'POST required.', '', array( 'response' => 405 ) );
        if ( ! current_user_can( 'manage_options' ) )
            wp_die( 'Administrator required.', '', array( 'response' => 403 ) );
        check_admin_referer( 'mad4b_native_cron_smoke_reconcile', 'mad4b_native_cron_nonce' );
        $id = isset( $_POST['expected_job_id'] ) && is_string( $_POST['expected_job_id'] )
            ? sanitize_text_field( wp_unslash( $_POST['expected_job_id'] ) ) : '';
        $result = self::reconcile_stale( $id );
        if ( is_wp_error( $result ) )
            wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => 409 ) );
        wp_safe_redirect( add_query_arg( array(
            'page' => MAD4B_SCP_Browser_Acceptance_Admin_UI::PAGE_SLUG,
        ), admin_url( 'admin.php' ) ) );
        exit;
    }
    public static function enqueue_from_admin() {
        if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) )
            wp_die( 'POST required.', '', array( 'response' => 405 ) );
        if ( ! current_user_can( 'manage_options' ) )
            wp_die( 'Administrator required.', '', array( 'response' => 403 ) );
        check_admin_referer( 'mad4b_native_cron_smoke_queue', 'mad4b_native_cron_nonce' );
        $result = self::enqueue();
        if ( is_wp_error( $result ) ) wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => 409 ) );
        wp_safe_redirect( add_query_arg( array( 'page' => MAD4B_SCP_Browser_Acceptance_Admin_UI::PAGE_SLUG ),
            admin_url( 'admin.php' ) ) );
        exit;
    }
    public static function enqueue() {
        if ( ! current_user_can( 'manage_options' ) || ! self::available() )
            return new WP_Error( 'mad4b_native_cron_unavailable', 'Only an administrator on supported Staging may queue WP-Cron smoke.' );
        if ( ! class_exists( 'MAD4B_SCP_Browser_Acceptance_Admin_UI' )
            || ! in_array( (string) ( MAD4B_SCP_Browser_Acceptance_Admin_UI::selection()['executor'] ?? '' ),
                array( 'wp_cron', 'native_hybrid' ), true ) )
            return new WP_Error( 'mad4b_native_cron_optin_required', 'Save the WP-Cron runner preference with owner opt-in first.' );
        $preference = MAD4B_SCP_Browser_Acceptance_Admin_UI::public_selection();
        if ( empty( $preference['preference_valid'] )
            || 'operator_saved_or_legacy' !== (string) ( $preference['preference_source'] ?? '' )
            || ! preg_match( '/^[a-f0-9]{32}$/D', (string) ( $preference['configuration_revision'] ?? '' ) ) )
            return new WP_Error( 'mad4b_native_cron_saved_optin_required', 'An exact saved owner opt-in revision is required before scheduling any native smoke.' );
        if ( ! function_exists( 'add_option' ) || ! function_exists( 'wp_generate_uuid4' ) )
            return new WP_Error( 'mad4b_native_cron_job_unavailable', 'Native job primitives unavailable.' );
        $id = wp_generate_uuid4();
        if ( ! is_string( $id ) || ! preg_match( '/^[a-f0-9-]{36}$/D', $id ) )
            return new WP_Error( 'mad4b_native_cron_identity_unavailable', 'Secure job identifier unavailable.' );
        $identity = class_exists( 'MAD4B_SCP_Native_Hybrid_Acceptance', false )
            ? MAD4B_SCP_Native_Hybrid_Acceptance::current_identity() : array();
        if ( empty( $identity['build_manifest_current'] )
            || empty( $identity['source_commit_sha'] )
            || empty( $identity['site_origin_sha256'] )
            || empty( $identity['preference_revision'] ) )
            return new WP_Error( 'mad4b_native_cron_identity_unavailable',
                'Exact Staging origin, build manifest and saved owner revision are required.' );
        // Fail closed on old or ambiguous in-flight state; never blind retry.
        if ( ! add_option( self::LOCK, $id, '', false ) )
            return new WP_Error( 'mad4b_native_cron_job_pending', 'Another smoke is pending; review the prior result before queueing another.' );
        $job = array_merge( $identity,
            array( 'job_id' => $id, 'state' => 'queued', 'queued_at' => gmdate( 'c' ),
            'source' => 'owner_explicit_wp_cron', 'browser_signed' => false ) );
        if ( ! update_option( self::OPTION, $job, false ) && get_option( self::OPTION, array() ) !== $job ) {
            delete_option( self::LOCK );
            return new WP_Error( 'mad4b_native_cron_store_failed', 'Smoke job persistence could not be verified.' );
        }
        $scheduled = wp_schedule_single_event( time() + 10, self::HOOK, array( $id ), true );
        if ( is_wp_error( $scheduled ) || true !== $scheduled ) {
            $job['state'] = 'schedule_failed';
            $job['error_code'] = 'wp_cron_schedule_failed';
            update_option( self::OPTION, $job, false );
            delete_option( self::LOCK );
            return new WP_Error( 'mad4b_native_cron_schedule_failed', 'WP-Cron could not schedule a native smoke.' );
        }
        return self::status();
    }
    public static function run( $id ) {
        $job = get_option( self::OPTION, array() );
        if ( ! is_array( $job ) || 'queued' !== (string) ( $job['state'] ?? '' )
            || ! is_string( $id ) || ! hash_equals( (string) ( $job['job_id'] ?? '' ), $id )
            || ! hash_equals( (string) get_option( self::LOCK, '' ), $id )
            || ! self::staging() ) return;
        if ( ! class_exists( 'MAD4B_SCP_Browser_Acceptance_Admin_UI' )
            || ! in_array( (string) ( MAD4B_SCP_Browser_Acceptance_Admin_UI::selection()['executor'] ?? '' ),
                array( 'wp_cron', 'native_hybrid' ), true ) ) {
            $job['state'] = 'cancelled_preference_changed';
            $job['checked_at'] = gmdate( 'c' );
            update_option( self::OPTION, $job, false );
            delete_option( self::LOCK );
            return;
        }
        $saved = MAD4B_SCP_Browser_Acceptance_Admin_UI::public_selection();
        if ( empty( $saved['preference_valid'] )
            || ! preg_match( '/^[a-f0-9]{32}$/D', (string) ( $saved['configuration_revision'] ?? '' ) ) ) {
            $job['state'] = 'cancelled_missing_owner_optin';
            update_option( self::OPTION, $job, false );
            delete_option( self::LOCK );
            return;
        }
        $identity = class_exists( 'MAD4B_SCP_Native_Hybrid_Acceptance', false )
            ? MAD4B_SCP_Native_Hybrid_Acceptance::current_identity() : array();
        $same = ! empty( $identity['build_manifest_current'] );
        foreach ( array( 'source_commit_sha', 'site_origin_sha256', 'preference_revision' ) as $field )
            if ( empty( $identity[ $field ] ) || ! hash_equals( (string) ( $job[ $field ] ?? '' ), (string) $identity[ $field ] ) )
                $same = false;
        if ( ! $same ) {
            $job['state'] = 'cancelled_build_site_or_optin_changed';
            $job['checked_at'] = gmdate( 'c' );
            update_option( self::OPTION, $job, false );
            delete_option( self::LOCK );
            return;
        }
        $job['state'] = 'running';
        update_option( self::OPTION, $job, false );
        // Explicitly allow no redirects, user cookies, non-GET methods,
        // external hosts or arbitrary operator-input URLs.
        $home = home_url( '/' );
        $site = site_url( '/' );
        $home_host = wp_parse_url( $home, PHP_URL_HOST );
        $site_host = wp_parse_url( $site, PHP_URL_HOST );
        if ( ! is_string( $home_host ) || ! hash_equals( strtolower( $home_host ), strtolower( (string) $site_host ) )
            || 'https' !== wp_parse_url( $home, PHP_URL_SCHEME ) ) {
            $job['state'] = 'blocked';
            $job['error_code'] = 'site_origin_not_exact_https';
        } elseif ( ! function_exists( 'wp_safe_remote_get' ) ) {
            $job['state'] = 'blocked';
            $job['error_code'] = 'wordpress_http_api_unavailable';
        } else {
            $start = microtime( true );
            $response = wp_safe_remote_get( $home, array(
                'timeout' => 10, 'redirection' => 0,
                'sslverify' => true, 'reject_unsafe_urls' => true,
                'limit_response_size' => 4096, 'blocking' => true ) );
            $job['elapsed_ms'] = round( ( microtime( true ) - $start ) * 1000, 2 );
            if ( is_wp_error( $response ) ) {
                $job['state'] = 'failed';
                $job['error_code'] = 'wordpress_http_request_failed';
            } else {
                $code = (int) wp_remote_retrieve_response_code( $response );
                $job['http_status'] = $code;
                $job['state'] = 200 === $code ? 'smoke_observed' : 'http_unexpected';
                // No response body, cookies, SQL, credentials or acceptance claim persisted.
            }
        }
        $job['checked_at'] = gmdate( 'c' );
        $job['checked_epoch'] = time();
        $job['browser_signed'] = false;
        update_option( self::OPTION, $job, false );
        delete_option( self::LOCK );
    }
    public static function render() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        $s = self::status();
        echo '<h2>4. WordPress-native WP-Cron smoke (no external runner)</h2>';
        echo '<p>Runs one owner-requested, read-only same-site HTTP check. It does not execute JavaScript or certify Browser Acceptance.</p>';
        echo '<p>Availability: <strong>' . esc_html( $s['wp_cron_available'] ? 'ready' : 'blocked' )
            . '</strong>; latest state: <code>' . esc_html( (string) ( $s['job']['state'] ?? 'not_queued' ) ) . '</code></p>';
        if ( ! empty( $s['manual_reconciliation_required'] ) ) {
            echo '<p>Stale WP-Cron job detected. No automatic replay or release certification is allowed.</p>';
            if ( ! empty( $s['manual_reconciliation_eligible'] ) ) {
                echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
                echo '<input type="hidden" name="action" value="mad4b_native_cron_smoke_reconcile">';
                echo '<input type="hidden" name="expected_job_id" value="' . esc_attr( (string) ( $s['job']['job_id'] ?? '' ) ) . '">';
                wp_nonce_field( 'mad4b_native_cron_smoke_reconcile', 'mad4b_native_cron_nonce' );
                submit_button( 'Reconcile stale read-only smoke (no replay)' );
                echo '</form>';
            }
        }
        if ( ! $s['wp_cron_available']
            || ! in_array( (string) ( MAD4B_SCP_Browser_Acceptance_Admin_UI::selection()['executor'] ?? '' ),
                array( 'wp_cron', 'native_hybrid' ), true ) ) return;
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        echo '<input type="hidden" name="action" value="mad4b_native_cron_smoke_queue">';
        wp_nonce_field( 'mad4b_native_cron_smoke_queue', 'mad4b_native_cron_nonce' );
        submit_button( 'Queue one WP-Cron HTTP smoke (read-only)' );
        echo '</form>';
    }
}
MAD4B_SCP_Native_Cron_Acceptance::boot();
