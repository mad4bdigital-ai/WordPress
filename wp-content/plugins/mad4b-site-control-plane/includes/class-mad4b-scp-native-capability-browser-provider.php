<?php
/**
 * Site-neutral native semantic oracle for bounded, publicly published WordPress
 * pages. All case expectations are derived on the server, never from the MCP
 * caller. A signed browser-agent attestation is required for PASS.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MAD4B_SCP_Native_Capability_Browser_Provider {
    const ID = 'mad4b-native-public';
    const CONTRACT = 'mad4b.capability-browser-provider.v1';
    const PLAN = 'mad4b.browser-acceptance-plan.v1';
    const RESULT = 'mad4b.browser-acceptance-result.v1';
    const EVIDENCE = 'mad4b.capability-browser-evidence.v1';
    const PROFILE = 'public-canonical';
    const MAX_CASES = 4;
    const MAX_SCAN = 32;
    const VALID_SECONDS = 600;

    public static function register( $providers ) {
        if ( ! is_array( $providers ) ) $providers = array();
        $providers[ self::ID ] = array(
            'provider_id' => self::ID, 'contract' => self::CONTRACT,
            'read_only' => true, 'authorizing' => false,
            'execution_mode' => 'external_browser_agent',
            'transport_owned_by_provider' => false,
            'descriptor_callback' => array( __CLASS__, 'descriptor' ),
            'capabilities_callback' => array( __CLASS__, 'capabilities' ),
            'plan_callback' => array( __CLASS__, 'plan' ),
            'result_callback' => array( __CLASS__, 'result' ),
        );
        return $providers;
    }

    public static function descriptor() {
        return array(
            'contract' => self::CONTRACT, 'provider_id' => self::ID,
            // A generic observation is supplemental to a site-specific
            // semantic contract, never a replacement for that contract.
            'selection_role' => 'supplemental',
            'recognition' => array( 'source_post_types' => array( 'page' ) ),
            'read_only' => true, 'authorizing' => false,
            'execution_mode' => 'external_browser_agent',
            'transport_owned_by_provider' => false,
            'browser_engine_owned_by_provider' => false,
            'arbitrary_url_input' => false, 'arbitrary_javascript_input' => false,
            'business_state_mutation' => false, 'profile_mutation' => false,
            'seo_mutation' => false, 'production_activation' => false,
            'max_cases' => self::MAX_CASES,
        );
    }

    public static function capabilities() {
        return array(
            'contract' => 'mad4b.browser-acceptance-capabilities.v1',
            'provider_contract' => self::CONTRACT, 'provider_id' => self::ID,
            'read_only' => true, 'authorizing' => false,
            'default_profile_id' => self::PROFILE,
            'execution_mode' => 'external_browser_agent',
            'capabilities' => array( 'browser.canonical_path' ),
            'semantic_oracle' => 'wp_get_canonical_url_published_page',
            'browser_attestation' => 'trusted_rsa_sha256',
            // A valid public key alone never proves a browser session or
            // matching private-key ownership by the remote worker.
            'browser_attestation_public_key_valid' => self::trusted_key() !== '',
            'browser_attestation_key_id' => self::trusted_key_id(),
            'browser_attestation_ready' => false,
            'external_agent_identity_verified' => false,
            'replay_prevention_verified' => false,
            'independent_reducer' => true,
            'release_ready' => false,
        );
    }

    public static function plan( $request = array() ) {
        if ( ! is_array( $request ) || array_diff( array_keys( $request ), array( 'profile_id', 'suite' ) ) ||
            ( $request['profile_id'] ?? '' ) !== self::PROFILE ||
            ! in_array( $request['suite'] ?? '', array( 'browser_runtime', 'browser' ), true ) ) {
            return self::blocked( 'plan', array( 'profile_or_input_invalid' ) );
        }
        try { $nonce = bin2hex( random_bytes( 16 ) ); }
        catch ( Throwable $error ) { return self::blocked( 'plan', array( 'secure_nonce_unavailable' ) ); }
        return self::prepare( time(), $nonce );
    }

    private static function blocked( $kind, $reasons ) {
        $base = array(
            'contract' => 'plan' === $kind ? self::PLAN : self::RESULT,
            'provider_contract' => self::CONTRACT, 'provider_id' => self::ID,
            'profile_id' => self::PROFILE, 'suite' => 'browser_runtime',
            'read_only' => true, 'authorizing' => false,
            'release_ready' => false, 'globally_unique_consumption_proven' => false,
            'blocking_reasons' => array_values( array_unique( (array) $reasons ) ),
        );
        if ( 'plan' === $kind ) return $base + array(
            'state' => 'blocked', 'cases' => array(), 'case_count' => 0,
        );
        return $base + array(
            'verdict' => 'BLOCKED', 'classification' => 'TEST_INFRASTRUCTURE_FAILURE',
            'verification' => array( 'browser_runtime_parity_verified' => false, 'verified_through' => 'none' ),
            'infrastructure_failures' => array_values( array_unique( (array) $reasons ) ),
            'receipt_authorizing' => false,
        );
    }

    private static function trusted_key() {
        if ( ! defined( 'MAD4B_BROWSER_ATTESTATION_PUBLIC_KEY_PEM' ) ||
            ! is_string( MAD4B_BROWSER_ATTESTATION_PUBLIC_KEY_PEM ) ||
            ! function_exists( 'openssl_pkey_get_public' ) ||
            ! function_exists( 'openssl_pkey_get_details' ) ) return '';
        $pem = trim( MAD4B_BROWSER_ATTESTATION_PUBLIC_KEY_PEM );
        if ( strlen( $pem ) > 8192 ||
            strpos( $pem, '-----BEGIN PUBLIC KEY-----' ) !== 0 ) return '';
        $parsed = openssl_pkey_get_public( $pem );
        $details = $parsed ? openssl_pkey_get_details( $parsed ) : false;
        if ( ! is_array( $details ) || ( $details['type'] ?? null ) !== OPENSSL_KEYTYPE_RSA ||
            (int) ( $details['bits'] ?? 0 ) < 2048 ||
            ! is_string( $details['key'] ?? null ) ) return '';
        return $details['key'];
    }

    private static function trusted_key_id() {
        $pem = self::trusted_key();
        if ( '' === $pem ) return '';
        $base64 = preg_replace( '/-----BEGIN PUBLIC KEY-----|-----END PUBLIC KEY-----|\\s+/', '', $pem );
        $der = is_string( $base64 ) ? base64_decode( $base64, true ) : false;
        return false === $der || strlen( $der ) < 200
            ? '' : 'rsa-spki-sha256:' . hash( 'sha256', $der );
    }

    private static function binding() {
        if ( ! function_exists( 'home_url' ) || ! function_exists( 'wp_salt' ) ||
            ! class_exists( 'MAD4B_SCP_Live_Acceptance_Observer', false ) ||
            ! class_exists( 'MAD4B_SCP_Site_Capability_Discovery', false ) ||
            ! class_exists( 'MAD4B_SCP_Browser_Acceptance_Provider_Registry', false ) ||
            ! class_exists( 'MAD4B_SCP_Browser_Acceptance_Admin_UI', false ) ) return null;
        $origin = rtrim( (string) home_url( '/' ), '/' ) . '/';
        $parsed = parse_url( $origin );
        if ( ! is_array( $parsed ) || ( $parsed['scheme'] ?? '' ) !== 'https' ||
            empty( $parsed['host'] ) || isset( $parsed['user'] ) || isset( $parsed['query'] ) ||
            ! preg_match( '#^/(?:[a-zA-Z0-9][a-zA-Z0-9._-]{0,63}/)*$#D',
                (string) ( $parsed['path'] ?? '/' ) ) ) return null;
        $secret = (string) wp_salt( 'auth' );
        if ( strlen( $secret ) < 16 ) return null;
        $provenance = MAD4B_SCP_Live_Acceptance_Observer::build_provenance_status();
        if ( ! is_array( $provenance ) || empty( $provenance['runtime_manifest_match'] ) ||
            ! empty( $provenance['stale'] ) ||
            ! preg_match( '/^[a-f0-9]{40}$/D', (string) ( $provenance['source_commit_sha'] ?? '' ) ) ||
            ! preg_match( '/^[a-f0-9]{64}$/D', (string) ( $provenance['build_fingerprint'] ?? '' ) ) ) return null;
        // Use the exact live registry also projected by Browser Acceptance
        // Core. Empty provider inventories produce a different SHA and allow
        // signed plans to silently drift from selected provider recognition.
        $registered = ( new MAD4B_SCP_Browser_Acceptance_Provider_Registry() )->all();
        if ( ! is_array( $registered ) || ! isset( $registered[ self::ID ] ) ) return null;
        $discovery = MAD4B_SCP_Site_Capability_Discovery::observe( rtrim( $origin, '/' ), $registered );
        if ( empty( $discovery['discovery_complete'] ) ||
            ! preg_match( '/^[a-f0-9]{64}$/D', (string) ( $discovery['snapshot_sha256'] ?? '' ) ) ) return null;
        $operator = MAD4B_SCP_Browser_Acceptance_Admin_UI::public_selection();
        if ( empty( $operator['preference_valid'] ) ||
            ! preg_match( '/^[a-f0-9]{32}$/D', (string) ( $operator['configuration_revision'] ?? '' ) ) ) return null;
        return array(
            'origin' => $origin, 'secret' => $secret,
            'build_identity' => array(
                'git_sha' => $provenance['source_commit_sha'],
                'build_fingerprint' => $provenance['build_fingerprint'],
            ),
            'source_snapshot_sha256' => $discovery['snapshot_sha256'],
            'configuration_revision' => $operator['configuration_revision'],
        );
    }

    private static function page_cases( $origin ) {
        if ( ! function_exists( 'get_posts' ) || ! function_exists( 'get_permalink' ) ||
            ! function_exists( 'wp_get_canonical_url' ) ) return array();
        $posts = get_posts( array(
            'post_type' => 'page', 'post_status' => 'publish',
            'posts_per_page' => self::MAX_SCAN, 'orderby' => 'ID', 'order' => 'ASC',
            'suppress_filters' => false, 'no_found_rows' => true,
        ) );
        if ( ! is_array( $posts ) ) return array();
        $cases = array();
        $seen_ids = array();
        $seen_paths = array();
        foreach ( $posts as $post ) {
            if ( count( $cases ) >= self::MAX_CASES ) break;
            if ( ! is_object( $post ) || ! isset( $post->ID ) || (int) $post->ID <= 0 ||
                ( $post->post_type ?? '' ) !== 'page' ||
                ( $post->post_status ?? '' ) !== 'publish' ||
                ! empty( $post->post_password ) ||
                isset( $seen_ids[ (int) $post->ID ] ) ) continue;
            $seen_ids[ (int) $post->ID ] = true;
            $permalink = get_permalink( (int) $post->ID );
            $canonical = wp_get_canonical_url( (int) $post->ID );
            if ( ! is_string( $permalink ) || ! is_string( $canonical ) ||
                rtrim( $permalink, '/' ) !== rtrim( $canonical, '/' ) ) continue;
            $parsed = parse_url( $canonical );
            if ( ! is_array( $parsed ) || strtolower( (string) ( $parsed['scheme'] ?? '' ) ) !== 'https' ||
                strtolower( (string) ( $parsed['host'] ?? '' ) ) !== strtolower( (string) parse_url( $origin, PHP_URL_HOST ) ) ||
                isset( $parsed['query'] ) || isset( $parsed['fragment'] ) ||
                (int) ( $parsed['port'] ?? 443 ) !== (int) ( parse_url( $origin, PHP_URL_PORT ) ?: 443 ) ) continue;
            $path = (string) ( $parsed['path'] ?? '/' );
            $scope = (string) ( parse_url( $origin, PHP_URL_PATH ) ?: '/' );
            if ( '/' !== $scope && 0 !== strpos( $path, $scope ) ) continue;
            if ( ! preg_match( '#^/(?!/)[a-zA-Z0-9~._/%-]*$#D', $path ) ||
                preg_match( '~(?:\\.\\.|%2e|%2f|%5c|%00|\\\\|#)~i', $path ) ||
                preg_match( '~(?:^|/)(?:wp-admin|wp-json|wp-login\\.php|xmlrpc\\.php|wp-cron\\.php)(?:/|$)~i', $path ) ) continue;
            if ( isset( $seen_paths[ $path ] ) ) continue;
            $seen_paths[ $path ] = true;
            $cases[] = array(
                'case_id' => 'page-' . (int) $post->ID,
                'capability_id' => 'seo.canonical',
                'probe_type' => 'public.canonical_path',
                'page_path' => $path,
                'expected' => array( 'path' => $path ),
            );
        }
        return $cases;
    }

    private static function prepare( $issued_at, $nonce ) {
        $context = self::binding();
        if ( ! $context ) return self::blocked( 'plan', array( 'build_or_discovery_or_preference_unverified' ) );
        if ( ! self::trusted_key() ) return self::blocked( 'plan', array( 'trusted_browser_attestation_key_missing' ) );
        if ( ! is_int( $issued_at ) || $issued_at > time() + 30 ||
            $issued_at + self::VALID_SECONDS <= time() ) return self::blocked( 'plan', array( 'challenge_expired' ) );
        $cases = self::page_cases( $context['origin'] );
        if ( ! $cases ) return self::blocked( 'plan', array( 'published_public_page_oracle_unavailable' ) );
        if ( ! is_string( $nonce ) || ! preg_match( '/^[a-f0-9]{32}$/D', $nonce ) ) {
            return self::blocked( 'plan', array( 'nonce_invalid' ) );
        }
        $expires = $issued_at + self::VALID_SECONDS;
        $challenge = array(
            'nonce' => $nonce, 'issued_at' => $issued_at, 'expires_at' => $expires,
            'signature' => hash_hmac( 'sha256',
                'challenge|' . $nonce . '|' . $issued_at . '|' . $expires,
                $context['secret'] ),
        );
        $core = array(
            'provider_id' => self::ID, 'profile_id' => self::PROFILE,
            'suite' => 'browser_runtime', 'origin' => $context['origin'],
            'read_only' => true, 'authorizing' => false,
            'build_identity' => $context['build_identity'],
            'source_snapshot_sha256' => $context['source_snapshot_sha256'],
            'configuration_revision' => $context['configuration_revision'],
            'cases' => $cases, 'case_count' => count( $cases ),
            'challenge' => $challenge,
        );
        $digest = hash( 'sha256', self::canonical_json( $core ) );
        return array_merge( array(
            'contract' => self::PLAN, 'provider_contract' => self::CONTRACT,
            'state' => 'ready',
        ), $core, array(
            'plan_digest' => $digest,
            'plan_signature' => hash_hmac( 'sha256', 'plan|' . $digest, $context['secret'] ),
            'blocking_reasons' => array(),
        ) );
    }

    public static function result( $request = array() ) {
        if ( ! is_array( $request ) ||
            array_diff( array_keys( $request ), array( 'profile_id', 'suite', 'plan_digest', 'plan_signature', 'evidence' ) ) ||
            ( $request['profile_id'] ?? '' ) !== self::PROFILE ||
            ( $request['suite'] ?? '' ) !== 'browser_runtime' ) {
            return self::blocked( 'result', array( 'request_invalid' ) );
        }
        $evidence = $request['evidence'] ?? null;
        if ( ! is_array( $evidence ) || ! isset( $evidence['observer']['plan_issued_at'] ) ||
            ! is_int( $evidence['observer']['plan_issued_at'] ) ) {
            return self::blocked( 'result', array( 'signed_plan_epoch_missing' ) );
        }
        $nonce = $evidence['cases'][0]['challenge_nonce'] ?? null;
        $plan = self::prepare( $evidence['observer']['plan_issued_at'], $nonce );
        if ( ( $plan['state'] ?? '' ) !== 'ready' ) return self::blocked( 'result', $plan['blocking_reasons'] ?? array( 'plan_unavailable' ) );
        foreach ( array( 'plan_digest', 'plan_signature' ) as $field ) {
            if ( ! is_string( $request[ $field ] ?? null ) || ! is_string( $evidence[ $field ] ?? null ) ||
                ! hash_equals( $plan[ $field ], $request[ $field ] ) ||
                ! hash_equals( $plan[ $field ], $evidence[ $field ] ) ) {
                return self::blocked( 'result', array( 'plan_signature_or_digest_mismatch' ) );
            }
        }
        if ( array_diff( array_keys( $evidence ), array(
                'contract', 'plan_digest', 'plan_signature', 'origin',
                'build_identity', 'observer', 'cases', 'attestation'
            ) ) ||
            ! is_array( $evidence['observer'] ?? null ) ||
            array_diff( array_keys( $evidence['observer'] ), array(
                'contract', 'javascript_runtime', 'runner_javascript_runtime',
                'page_javascript_enabled', 'browser_engine',
                'execution_mode', 'plan_issued_at'
            ) ) ) {
            return self::blocked( 'result', array( 'browser_evidence_unknown_fields' ) );
        }
        if ( ( $evidence['contract'] ?? '' ) !== self::EVIDENCE ||
            ( $evidence['origin'] ?? '' ) !== $plan['origin'] ||
            ( $evidence['build_identity'] ?? null ) !== $plan['build_identity'] ||
            ( $evidence['observer']['contract'] ?? '' ) !== 'mad4b.capability-browser-observer.v1' ||
            ( $evidence['observer']['javascript_runtime'] ?? null ) !== true ||
            ( $evidence['observer']['runner_javascript_runtime'] ?? null ) !== true ||
            ( $evidence['observer']['page_javascript_enabled'] ?? null ) !== false ||
            ( $evidence['observer']['execution_mode'] ?? '' ) !== 'managed_browser_agent' ||
            ! is_string( $evidence['observer']['browser_engine'] ?? null ) ||
            ! preg_match( '/^[\\x20-\\x7e]{1,160}$/D', $evidence['observer']['browser_engine'] ) ) {
            return self::blocked( 'result', array( 'browser_evidence_envelope_invalid' ) );
        }
        if ( ! isset( $evidence['cases'] ) || ! is_array( $evidence['cases'] ) ||
            count( $evidence['cases'] ) !== count( $plan['cases'] ) ) {
            return self::blocked( 'result', array( 'browser_evidence_case_count_invalid' ) );
        }
        if ( ! self::verify_attestation( $evidence ) ) {
            return self::blocked( 'result', array( 'browser_attestation_untrusted' ) );
        }
        $match = true;
        $shape_invalid = false;
        foreach ( $plan['cases'] as $index => $expected ) {
            $observed = $evidence['cases'][ $index ] ?? null;
            if ( ! is_array( $observed ) ||
                array_diff( array_keys( $observed ), array(
                    'case_id', 'capability_id', 'probe_type', 'challenge_nonce',
                    'http_status', 'observed', 'matches_expected', 'certification_issued', 'authorizing'
                ) ) ||
                ! is_int( $observed['http_status'] ?? null ) ||
                ! is_bool( $observed['matches_expected'] ?? null ) ||
                ! is_array( $observed['observed'] ?? null ) ||
                array_keys( $observed['observed'] ) !== array( 'path' ) ||
                ! is_string( $observed['observed']['path'] ?? null ) ) {
                $shape_invalid = true;
                continue;
            }
            if ( ( $observed['case_id'] ?? '' ) !== $expected['case_id'] ||
                ( $observed['capability_id'] ?? '' ) !== $expected['capability_id'] ||
                ( $observed['probe_type'] ?? '' ) !== $expected['probe_type'] ||
                ( $observed['challenge_nonce'] ?? '' ) !== $plan['challenge']['nonce'] ||
                ( $observed['http_status'] ?? 0 ) < 200 ||
                ( $observed['http_status'] ?? 0 ) > 299 ||
                ( $observed['authorizing'] ?? null ) !== false ||
                ( $observed['certification_issued'] ?? null ) !== false ||
                ( $observed['matches_expected'] ?? null ) !== true ||
                ( $observed['observed']['path'] ?? null ) !== $expected['expected']['path'] ) {
                $match = false;
            }
        }
        if ( $shape_invalid ) return self::blocked( 'result', array( 'browser_case_schema_invalid' ) );
        $verdict = $match ? 'PASS' : 'FAIL';
        $evidence_digest = hash( 'sha256', self::canonical_json( $evidence ) );
        $secret = (string) wp_salt( 'auth' );
        $receipt_signature = hash_hmac( 'sha256',
            'receipt|' . $plan['plan_digest'] . '|' . $evidence_digest . '|' . $verdict,
            $secret );
        return array(
            'contract' => self::RESULT, 'provider_contract' => self::CONTRACT,
            'provider_id' => self::ID, 'profile_id' => self::PROFILE,
            'suite' => 'browser_runtime', 'plan_digest' => $plan['plan_digest'],
            'read_only' => true, 'authorizing' => false, 'receipt_authorizing' => false,
            // Observation parity is deliberately not an anti-replay or release
            // certificate. Consumption is a distinct governed write authority.
            'release_ready' => false,
            'globally_unique_consumption_proven' => false,
            'consumption_authority' => 'separate_governed_authority_required',
            'evidence_digest' => $evidence_digest, 'receipt_signature' => $receipt_signature,
            'verdict' => $verdict, 'classification' => $match ? 'NO_CONFIRMED_DEFECT' : 'PRODUCT_DEFECT',
            'verification' => array(
                'semantic_parity_verified' => $match,
                'browser_runtime_parity_verified' => $match,
                'verified_through' => $match ? 'live_browser_runtime' : 'live_browser_runtime_failed',
            ),
            'case_count' => count( $plan['cases'] ),
            'blocking_reasons' => array(), 'infrastructure_failures' => array(),
            'defect_reasons' => $match ? array() : array( 'canonical_path_mismatch' ),
        );
    }

    private static function verify_attestation( $evidence ) {
        if ( ! function_exists( 'openssl_verify' ) || ! is_array( $evidence['attestation'] ?? null ) ||
            array_keys( $evidence['attestation'] ) !== array( 'algorithm', 'key_id', 'signature' ) ||
            ( $evidence['attestation']['algorithm'] ?? '' ) !== 'rsa-sha256' ||
            ! is_string( $evidence['attestation']['key_id'] ?? null ) ||
            '' === self::trusted_key_id() ||
            ! hash_equals( self::trusted_key_id(), $evidence['attestation']['key_id'] ) ) return false;
        $signature = base64_decode( (string) ( $evidence['attestation']['signature'] ?? '' ), true );
        if ( false === $signature || strlen( $signature ) > 1024 || strlen( $signature ) < 32 ) return false;
        $copy = $evidence;
        unset( $copy['attestation'] );
        $key = self::trusted_key();
        return '' !== $key && 1 === openssl_verify( self::canonical_json( $copy ),
            $signature, $key, OPENSSL_ALGO_SHA256 );
    }

    private static function canonical_json( $input ) {
        $encoded = json_encode( self::canonicalize( $input ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        return is_string( $encoded ) ? $encoded : '';
    }

    private static function canonicalize( $value ) {
        if ( ! is_array( $value ) ) return $value;
        // PHP range(0, -1) is not empty; [] must stay a JSON list.
        $list = 0 === count( $value ) || array_keys( $value ) === range( 0, count( $value ) - 1 );
        if ( $list ) return array_map( array( __CLASS__, 'canonicalize' ), $value );
        ksort( $value, SORT_STRING );
        foreach ( $value as $k => $v ) $value[ $k ] = self::canonicalize( $v );
        return $value;
    }
}
