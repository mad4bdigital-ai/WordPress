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
    const FRESH_SECONDS = 1800;
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

    /** Exact local build/site/owner identity for native functional evidence. */
    public static function current_identity() {
        $prefs = class_exists( 'MAD4B_SCP_Browser_Acceptance_Admin_UI', false )
            ? MAD4B_SCP_Browser_Acceptance_Admin_UI::public_selection() : array();
        $source = class_exists( 'MAD4B_SCP_Live_Acceptance_Observer', false )
            && method_exists( 'MAD4B_SCP_Live_Acceptance_Observer', 'build_provenance_identity_status' )
            ? MAD4B_SCP_Live_Acceptance_Observer::build_provenance_identity_status() : array();
        $head = isset( $source['source_commit_sha'] ) ? strtolower( (string) $source['source_commit_sha'] ) : '';
        $origin = function_exists( 'home_url' ) ? (string) home_url( '/' ) : '';
        $revision = (string) ( $prefs['configuration_revision'] ?? '' );
        return array(
            'source_commit_sha' => preg_match( '/^[a-f0-9]{40}$/D', $head ) ? $head : '',
            'site_origin_sha256' => 0 === strpos( $origin, 'https://' ) ? hash( 'sha256', $origin ) : '',
            'preference_revision' => preg_match( '/^[a-f0-9]{32}$/D', $revision ) ? $revision : '',
            'build_manifest_current' => ! empty( $source['identity_ready'] )
                && ! empty( $source['manifest_valid'] ),
        );
    }

    /** Scope-driven, current-build Staging acceptance; NEVER a Write or release grant. */
    public static function functional_status( $input = array() ) {
        $input = is_array( $input ) ? $input : array();
        $requested = isset( $input['scope'] ) && is_string( $input['scope'] )
            ? sanitize_key( $input['scope'] ) : 'frontend';
        // A caller cannot provide its own pass flags or trust assertions.
        if ( ! in_array( $requested, array( 'frontend', 'filters', 'server', 'wpml', 'full' ), true ) )
            $requested = 'unknown';
        $base = array(
            'contract' => 'mad4b.staging-functional-acceptance.v1',
            'state' => 'pending', 'staging_acceptance_ready' => false,
            'frontend_functional_ready' => false, 'filter_functional_ready' => false,
            'server_http_ready' => false, 'independent_browser_required' => false,
            'independent_browser_verified' => false, 'release_ready' => false,
            'authorizing' => false, 'production_activation_authorized' => false,
            'evidence_level' => 'local_owner_session_not_independent',
            'required_scopes' => array( 'frontend_public' ),
            'scope_statuses' => array(), 'blocking_reasons' => array(),
        );
        if ( ! self::eligible() ) {
            $base['blocking_reasons'][] = 'saved_staging_native_hybrid_owner_optin_required';
            return $base;
        }
        $identity = self::current_identity();
        if ( empty( $identity['build_manifest_current'] )
            || '' === $identity['source_commit_sha'] || '' === $identity['site_origin_sha256']
            || '' === $identity['preference_revision'] ) {
            $base['blocking_reasons'][] = 'exact_native_build_site_or_optin_unverified';
            return $base;
        }
        $now = time();
        $browser = get_option( self::RESULT_OPTION, array() );
        $match = static function ( $row ) use ( $identity, $now ) {
            return is_array( $row )
                && $identity['source_commit_sha'] === (string) ( $row['source_commit_sha'] ?? '' )
                && $identity['site_origin_sha256'] === (string) ( $row['site_origin_sha256'] ?? '' )
                && $identity['preference_revision'] === (string) ( $row['preference_revision'] ?? '' )
                && isset( $row['observed_epoch'] )
                && is_numeric( $row['observed_epoch'] )
                && (int) $row['observed_epoch'] <= $now
                && (int) $row['observed_epoch'] >= $now - self::FRESH_SECONDS;
        };
        $browser_fresh = $match( $browser );
        $frontend = $browser_fresh && ! empty( $browser['frontend_functional_ready'] );
        // Presence of an installed filter is not intent to test it. Only an
        // explicitly requested interaction becomes a mandatory scope.
        $filter_required = 'filters' === $requested
            || ( 'full' === $requested && $browser_fresh && ! empty( $browser['filter_attempted'] ) )
            || ( 'frontend' === $requested && $browser_fresh && ! empty( $browser['filter_attempted'] ) );
        $filter = $browser_fresh && ! empty( $browser['filter_functional_ready'] );
        $cron = class_exists( 'MAD4B_SCP_Native_Cron_Acceptance', false )
            ? MAD4B_SCP_Native_Cron_Acceptance::status() : array();
        $job = is_array( $cron['job'] ?? null ) ? $cron['job'] : array();
        $cron_fresh = isset( $job['checked_epoch'] ) && is_numeric( $job['checked_epoch'] )
            && (int) $job['checked_epoch'] <= $now
            && (int) $job['checked_epoch'] >= $now - self::FRESH_SECONDS;
        $server = $cron_fresh && 'smoke_observed' === (string) ( $job['state'] ?? '' )
            && 200 === (int) ( $job['http_status'] ?? 0 );
        foreach ( array( 'source_commit_sha', 'site_origin_sha256', 'preference_revision' ) as $field ) {
            if ( $identity[ $field ] !== (string) ( $job[ $field ] ?? '' ) ) $server = false;
        }
        // Verify live WPML link relationships only for the explicit WPML task.
        // REST response success and hreflang marker count are NOT translation parity.
        $wpml = 'wpml' === $requested ? self::native_wpml_parity_readback()
            : array( 'verified' => false, 'checked_pairs' => 0, 'evidence' => 'not_requested' );
        $base['wpml_linkage_evidence'] = $wpml;
        $base['frontend_functional_ready'] = $frontend;
        $base['filter_functional_ready'] = $filter;
        $base['server_http_ready'] = $server;
        $base['scope_statuses'] = array(
            'server_http' => $server ? 'PASS' : 'PENDING',
            'frontend_public' => $frontend ? 'PASS' : 'PENDING',
            'filter_interaction' => ! $filter_required ? 'NOT_APPLICABLE' : ( $filter ? 'PASS' : 'PENDING' ),
            'wpml_translation_parity' => ! empty( $wpml['verified'] ) ? 'PASS' : 'NOT_TESTED',
            'independent_browser' => 'NOT_REQUIRED_FOR_FUNCTIONAL_ACCEPTANCE',
        );
        $base['requested_scope'] = $requested;
        if ( 'server' === $requested ) $base['required_scopes'] = array( 'server_http' );
        if ( 'full' === $requested ) $base['required_scopes'] = array( 'server_http', 'frontend_public' );
        if ( $filter_required ) $base['required_scopes'][] = 'filter_interaction';
        if ( 'wpml' === $requested ) {
            // Hreflang presence is not live translation/dataset parity.
            $base['required_scopes'][] = 'wpml_translation_parity';
        }
        if ( 'unknown' === $requested ) $base['blocking_reasons'][] = 'scope_not_registered';
        foreach ( $base['required_scopes'] as $scope )
            if ( 'PASS' !== $base['scope_statuses'][ $scope ] ) $base['blocking_reasons'][] = $scope . '_not_verified';
        $base['staging_acceptance_ready'] = empty( $base['blocking_reasons'] );
        $base['state'] = $base['staging_acceptance_ready'] ? 'ready' : 'pending';
        return $base;
    }

    /**
     * Bounded, same-runtime read-only verification of native WPML translation
     * relationships. A successful WPML REST endpoint or hreflang tag cannot
     * substitute for checking reciprocal language IDs, TRID and permalinks.
     * This is sampled link parity, NOT all-content/SEO/browser certification.
     */
    private static function native_wpml_parity_readback() {
        $pending = array( 'verified' => false, 'checked_pairs' => 0,
            'evidence' => 'wpml_native_links_not_verified',
            'sitewide_translation_content_parity' => false, 'authorizing' => false );
        if ( ! ( defined( 'ICL_SITEPRESS_VERSION' ) || class_exists( 'SitePress', false ) )
            || ! function_exists( 'get_posts' ) || ! function_exists( 'get_post_type' )
            || ! function_exists( 'get_post_status' ) || ! function_exists( 'get_permalink' )
            || ! function_exists( 'has_filter' ) || ! function_exists( 'apply_filters' ) ) return $pending;
        foreach ( array( 'wpml_element_trid','wpml_element_language_details',
            'wpml_get_element_translations' ) as $hook ) {
            if ( false === has_filter( $hook ) ) return $pending;
        }
        // Discover actual public post types, including installed CPTs, from WP.
        // WPML-translatable types are appended only when its registered filter says yes.
        $types = array( 'post', 'page' );
        if ( function_exists( 'get_post_types' ) && false !== has_filter( 'wpml_is_translated_post_type' ) ) {
            $registered = get_post_types( array( 'public' => true ), 'names' );
            if ( is_array( $registered ) ) {
                foreach ( array_slice( $registered, 0, 24 ) as $type ) {
                    if ( is_string( $type ) && preg_match( '/^[a-z][a-z0-9_]{0,31}$/D', $type )
                        && true === apply_filters( 'wpml_is_translated_post_type', false, $type ) )
                        $types[] = $type;
                }
            }
        }
        $types = array_slice( array_values( array_unique( $types ) ), 0, 12 );
        // No caller-supplied post IDs, URL, SQL or mutation; bounded public posts.
        $posts = get_posts( array(
            'post_type' => $types, 'post_status' => 'publish',
            'numberposts' => 40, 'no_found_rows' => true, 'fields' => 'ids',
            'orderby' => 'ID', 'order' => 'DESC', 'suppress_filters' => true,
        ) );
        if ( ! is_array( $posts ) ) return $pending;
        foreach ( array_slice( $posts, 0, 40 ) as $id ) {
            $id = (int) $id;
            if ( $id < 1 ) continue;
            $type = (string) get_post_type( $id );
            if ( ! in_array( $type, $types, true ) ) continue;
            $element = 'post_' . $type;
            $trid = apply_filters( 'wpml_element_trid', null, $id, $element );
            if ( ! is_numeric( $trid ) || (int) $trid < 1 ) continue;
            $links = apply_filters( 'wpml_get_element_translations', null, (int) $trid, $element );
            if ( ! is_array( $links ) || count( $links ) < 2 || count( $links ) > 32 ) continue;
            $languages = array(); $urls = array(); $verified_members = 0;
            foreach ( $links as $lang => $link ) {
                if ( ! is_string( $lang ) || ! preg_match( '/^[a-z]{2,3}(?:-[a-z]{2,4})?$/iD', $lang )
                    || ! is_object( $link ) || ! isset( $link->element_id ) ) break;
                $translated_id = (int) $link->element_id;
                if ( $translated_id < 1 || (string) get_post_type( $translated_id ) !== $type
                    || 'publish' !== get_post_status( $translated_id ) ) break;
                $details = apply_filters( 'wpml_element_language_details', null,
                    array( 'element_id' => $translated_id, 'element_type' => $type ) );
                if ( ! is_object( $details ) || ! isset( $details->trid, $details->language_code )
                    || (int) $details->trid !== (int) $trid
                    || (string) $details->language_code !== $lang ) break;
                $permalink = get_permalink( $translated_id );
                if ( ! is_string( $permalink ) || 0 !== strpos( $permalink, 'https://' )
                    || isset( $urls[ $permalink ] ) || isset( $languages[ $lang ] ) ) break;
                $urls[ $permalink ] = true; $languages[ $lang ] = true;
                $verified_members++;
            }
            if ( $verified_members >= 2 && $verified_members === count( $links )
                && in_array( $id, array_map( static function ( $link ) {
                    return is_object( $link ) && isset( $link->element_id ) ? (int) $link->element_id : -1;
                }, $links ), true ) ) {
                return array( 'verified' => true, 'checked_pairs' => 1,
                    'evidence' => 'live_wpml_native_reciprocal_links_and_https_permalinks',
                    'sitewide_translation_content_parity' => false, 'authorizing' => false );
            }
        }
        return $pending;
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
        $frontend_ok = 'OBSERVED' === $out['verdict'] && $out['route_count'] > 0
            && $out['listing_unique']
            && ( 0 === $out['listing_count'] || '' !== $out['listing_digest'] );
        $filter_ok = $out['filter_attempted'] && $out['filter_ajax_observed']
            && $out['filter_dataset_changed'] && '' !== $out['filter_dataset_digest']
            && '' !== $out['listing_digest']
            && ! hash_equals( $out['listing_digest'], $out['filter_dataset_digest'] );
        $out['frontend_functional_ready'] = $frontend_ok;
        $out['filter_functional_ready'] = $frontend_ok && $filter_ok;
        $out['staging_acceptance_ready'] = false; // Cron + exact identity checked on server readback.
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
        $identity = self::current_identity();
        if ( empty( $identity['build_manifest_current'] )
            || '' === $identity['source_commit_sha'] || '' === $identity['site_origin_sha256']
            || '' === $identity['preference_revision'] )
            wp_send_json_error( array( 'code' => 'mad4b_native_hybrid_identity_unverified' ), 409 );
        $out = array_merge( $out, $identity );
        $out['observed_epoch'] = time();
        $out['contract'] = 'mad4b.native-hybrid-observation.v1';
        $out['observed_at'] = gmdate( 'c' );
        $out['source'] = 'owner_wp_admin_browser_untrusted';
        $out['staging_only'] = true;
        if ( ! update_option( self::RESULT_OPTION, $out, false )
            && get_option( self::RESULT_OPTION, array() ) !== $out )
            wp_send_json_error( array( 'code' => 'mad4b_native_hybrid_store_not_confirmed' ), 503 );
        $functional = self::functional_status();
        wp_send_json_success( array( 'state' => $out['verdict'],
            'frontend_functional_ready' => $functional['frontend_functional_ready'],
            'staging_acceptance_ready' => $functional['staging_acceptance_ready'],
            'blocking_reasons' => $functional['blocking_reasons'],
            'filter_parity' => $out['filter_parity'], 'release_ready' => false ) );
    }

    public static function readback() {
        if ( ! self::eligible() ) return new WP_Error( 'mad4b_native_hybrid_read_denied', 'Admin Staging opt-in required.' );
        $out = get_option( self::RESULT_OPTION, array() );
        return is_array( $out ) ? array_intersect_key( $out, array_flip( array(
            'contract','observed_at','source','verdict','filter_parity','filter_attempted',
            'filter_ajax_observed','filter_dataset_changed','route_count','dom_ready','http_ok',
            'canonical_match','same_origin','js_errors','hreflang_count','listing_count',
            'filter_controls','duration_ms','blocked_reason',
            'frontend_functional_ready','filter_functional_ready',
            'release_ready','authorizing'
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
            $scoped = self::functional_status();
            echo '<p><strong>Staging functional acceptance:</strong> <code>'
                . esc_html( $scoped['staging_acceptance_ready'] ? 'READY' : 'PENDING' )
                . '</code> — pending: ' . esc_html( implode( ', ', $scoped['blocking_reasons'] ) )
                . '. This does not authorize Write or Production.</p>';
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
