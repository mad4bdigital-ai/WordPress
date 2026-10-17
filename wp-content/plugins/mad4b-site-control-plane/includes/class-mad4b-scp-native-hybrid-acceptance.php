<?php
/**
 * Native Hybrid Acceptance — admin-browser, local evidence only.
 *
 * Browser observations are untrusted client assertions. Neither this module
 * nor a WordPress nonce can issue an independent browser attestation, unlock
 * a write capability, promote a deployment, or claim an AJAX dataset parity.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MAD4B_SCP_Native_Hybrid_Acceptance {
    const RESULT_OPTION = 'mad4b_scp_native_hybrid_observation_v1';
    const NONCE = 'mad4b_native_hybrid_browser_v1';
    const MAX_SECONDS = 30;
    private static $booted = false;

    public static function boot() {
        if ( self::$booted ) return;
        self::$booted = true;
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
        add_action( 'wp_ajax_mad4b_native_hybrid_observe', array( __CLASS__, 'receive' ) );
    }

    public static function eligible() {
        if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) return false;
        if ( ! class_exists( 'MAD4B_SCP_Native_Cron_Acceptance', false )
            || ! MAD4B_SCP_Native_Cron_Acceptance::staging() ) return false;
        if ( ! class_exists( 'MAD4B_SCP_Browser_Acceptance_Admin_UI', false ) ) return false;
        // The no-credential hybrid preference is shown as the default, but
        // executing a browser test still requires a saved owner opt-in revision.
        $setting = MAD4B_SCP_Browser_Acceptance_Admin_UI::public_selection();
        return ! empty( $setting['preference_valid'] )
            && 'native_hybrid' === (string) ( $setting['executor'] ?? '' )
            && 'operator_saved_or_legacy' === (string) ( $setting['preference_source'] ?? '' )
            && 1 === preg_match( '/^[a-f0-9]{32}$/D', (string) ( $setting['configuration_revision'] ?? '' ) );
    }

    public static function enqueue() {
        if ( ! isset( $_GET['page'] ) || ! is_string( $_GET['page'] )
            || MAD4B_SCP_Browser_Acceptance_Admin_UI::PAGE_SLUG !== $_GET['page']
            || ! self::eligible() ) return;
        $src = plugins_url( 'assets/native-hybrid-acceptance.js', MAD4B_SCP_FILE );
        wp_enqueue_script( 'mad4b-native-hybrid-acceptance', $src, array(), MAD4B_SCP_VERSION, true );
        $payload = array(
            'endpoint' => admin_url( 'admin-ajax.php' ),
            'nonce' => wp_create_nonce( self::NONCE ),
            'origin' => home_url( '/' ),
            'maximum_seconds' => self::MAX_SECONDS,
            'staging_only' => true,
            'independent_browser_certification' => false,
        );
        wp_add_inline_script( 'mad4b-native-hybrid-acceptance',
            'window.MAD4BNativeHybrid=' . wp_json_encode( $payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . ';',
            'before' );
    }

    /**
     * Normalize only bounded observations. Never accept arbitrary URL, HTML,
     * raw post IDs, AJAX payload, PHP stacks, keys, cookies or SQL.
     */
    public static function normalize( $body ) {
        if ( ! is_array( $body ) || array_diff( array_keys( $body ), array(
            'route_count','js_errors','dom_ready','http_ok','same_origin','canonical_match',
            'hreflang_count','listing_count','listing_unique','listing_digest',
            'filter_controls','filter_attempted','filter_ajax_observed','filter_dataset_changed',
            'filter_dataset_digest','duration_ms','blocked_reason'
        ) ) ) return new WP_Error( 'mad4b_native_hybrid_unexpected_fields', 'Only bounded browser assertions are accepted.' );
        $booleans = array( 'dom_ready','http_ok','same_origin','canonical_match',
            'listing_unique','filter_attempted','filter_ajax_observed','filter_dataset_changed' );
        $ints = array( 'route_count' => 2, 'js_errors' => 20, 'hreflang_count' => 64,
            'listing_count' => 10000, 'filter_controls' => 10000, 'duration_ms' => 30000 );
        $out = array();
        foreach ( $booleans as $key ) {
            if ( ! isset( $body[ $key ] ) || ! in_array( $body[ $key ], array( true,false,0,1,'0','1' ), true ) )
                return new WP_Error( 'mad4b_native_hybrid_field_invalid', 'Browser boolean assertion invalid.' );
            $out[ $key ] = in_array( $body[ $key ], array( true,1,'1' ), true );
        }
        foreach ( $ints as $key => $max ) {
            if ( ! isset( $body[ $key ] ) || ! is_scalar( $body[ $key ] )
                || ! preg_match( '/^(0|[1-9][0-9]{0,5})$/D', (string) $body[ $key ] ) )
                return new WP_Error( 'mad4b_native_hybrid_field_invalid', 'Browser numeric assertion invalid.' );
            $num = (int) $body[ $key ];
            if ( $num > $max ) return new WP_Error( 'mad4b_native_hybrid_field_invalid', 'Browser bound exceeded.' );
            $out[ $key ] = $num;
        }
        foreach ( array( 'listing_digest','filter_dataset_digest' ) as $key ) {
            $v = (string) ( $body[ $key ] ?? '' );
            if ( '' !== $v && 1 !== preg_match( '/^[a-f0-9]{64}$/D', $v ) )
                return new WP_Error( 'mad4b_native_hybrid_digest_invalid', 'Only SHA-256 dataset hashes permitted.' );
            $out[ $key ] = $v;
        }
        $reason = (string) ( $body['blocked_reason'] ?? '' );
        if ( 1 !== preg_match( '/^(|none|iframe_blocked|navigation_timeout|js_error|unsafe_route|filter_unavailable|filter_no_ajax|other)$/D', $reason ) )
            return new WP_Error( 'mad4b_native_hybrid_reason_invalid', 'Unrecognized browser failure code.' );
        $out['blocked_reason'] = $reason;
        // Do not infer AJAX success merely from a changed DOM or URL.
        $out['verdict'] = $out['dom_ready'] && $out['same_origin'] && $out['http_ok']
            && 0 === $out['js_errors'] && $out['canonical_match'] && 'none' === $reason
            ? 'OBSERVED' : 'INCOMPLETE';
        $out['filter_parity'] = 'not_independently_verified';
        $out['independent_browser_attested'] = false;
        $out['release_ready'] = false;
        $out['authorizing'] = false;
        return $out;
    }

    public static function receive() {
        if ( ! self::eligible() )
            wp_send_json_error( array( 'code' => 'mad4b_native_hybrid_not_eligible' ), 403 );
        if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' )
            || ! check_ajax_referer( self::NONCE, 'nonce', false ) )
            wp_send_json_error( array( 'code' => 'mad4b_native_hybrid_nonce_invalid' ), 403 );
        $raw = isset( $_POST['observation'] ) && is_string( $_POST['observation'] )
            ? wp_unslash( $_POST['observation'] ) : '';
        if ( strlen( $raw ) > 1800 )
            wp_send_json_error( array( 'code' => 'mad4b_native_hybrid_payload_too_large' ), 400 );
        $decoded = json_decode( $raw, true, 10 );
        $out = self::normalize( $decoded );
        if ( is_wp_error( $out ) )
            wp_send_json_error( array( 'code' => $out->get_error_code() ), 400 );
        $out['contract'] = 'mad4b.native-hybrid-observation.v1';
        $out['observed_at'] = gmdate( 'c' );
        $out['source'] = 'owner_wp_admin_browser_untrusted';
        $out['staging_only'] = true;
        if ( ! update_option( self::RESULT_OPTION, $out, false )
            && get_option( self::RESULT_OPTION, array() ) !== $out )
            wp_send_json_error( array( 'code' => 'mad4b_native_hybrid_store_not_confirmed' ), 503 );
        wp_send_json_success( array( 'state' => $out['verdict'],
            'filter_parity' => $out['filter_parity'], 'release_ready' => false ) );
    }

    public static function readback() {
        if ( ! self::eligible() ) return new WP_Error( 'mad4b_native_hybrid_read_denied', 'Admin Staging opt-in required.' );
        $out = get_option( self::RESULT_OPTION, array() );
        return is_array( $out ) ? array_intersect_key( $out, array_flip( array(
            'contract','observed_at','source','verdict','filter_parity','filter_attempted',
            'filter_ajax_observed','filter_dataset_changed','route_count','dom_ready','http_ok',
            'canonical_match','same_origin','js_errors','hreflang_count','listing_count',
            'filter_controls','duration_ms','blocked_reason','release_ready','authorizing'
        ) ) ) : array();
    }

    public static function render() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        $eligible = self::eligible();
        echo '<section class="mad4b-scp-panel" id="mad4b-native-hybrid-panel"><h2>Native Hybrid — browser + WordPress</h2>';
        echo '<p>Run a read-only DOM/JavaScript and safe GET filter inspection in your current admin browser. WP-Cron remains the server-side smoke. No external provider credentials are needed.</p>';
        echo '<p>Mode: <strong>' . esc_html( $eligible ? 'owner opted-in on Staging' : 'save Native Hybrid preference to opt in' ) . '</strong>.</p>';
        if ( $eligible ) {
            echo '<button type="button" class="button button-primary" id="mad4b-native-hybrid-run">Run local browser checks</button> ';
            echo '<label><input type="checkbox" id="mad4b-native-hybrid-filter"> Include one safe, read-only JetSmartFilters checkbox inspection (if available)</label>';
            echo '<p id="mad4b-native-hybrid-status" role="status" aria-live="polite">Idle — no browser evidence yet.</p>';
            echo '<div id="mad4b-native-hybrid-host" aria-hidden="true"></div>';
            $last = self::readback();
            if ( is_array( $last ) && ! empty( $last['verdict'] ) )
                echo '<p>Last local observation: <code>' . esc_html( $last['verdict'] )
                    . '</code> | AJAX dataset parity: <code>' . esc_html( $last['filter_parity'] ?? 'unverified' )
                    . '</code> | time: <code>' . esc_html( $last['observed_at'] ?? '' ) . '</code></p>';
        }
        echo '<p><strong>Trust boundary:</strong> admin-browser reports are not independently signed, and cannot certify Browser Acceptance release, WPML parity or govern any WordPress mutation.</p></section>';
    }
}
MAD4B_SCP_Native_Hybrid_Acceptance::boot();
