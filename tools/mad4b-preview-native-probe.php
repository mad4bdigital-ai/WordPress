<?php
/**
 * MAD4B on-host read-only diagnostic.
 * Invoke only via: wp --path=/staging/root eval-file tools/mad4b-preview-native-probe.php
 * Never include from WordPress bootstrap, and never use this as a browser receipt.
 */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! defined( 'ABSPATH' ) ) {
    exit( 3 );
}
$expected_origin = rtrim( (string) getenv( 'MAD4B_PREVIEW_EXPECTED_ORIGIN' ), '/' );
$expected_sha = strtolower( (string) getenv( 'MAD4B_PREVIEW_EXPECTED_SHA' ) );
$site_origin = rtrim( (string) home_url( '/' ), '/' );
if ( 'staging' !== wp_get_environment_type()
    || '' === $expected_origin || ! hash_equals( $expected_origin, $site_origin )
    || ! preg_match( '/^[a-f0-9]{40}$/D', $expected_sha ) ) {
    WP_CLI::error( 'MAD4B_NATIVE_SITE_OR_ENVIRONMENT_MISMATCH', false );
    exit( 3 );
}
if ( ! class_exists( 'MAD4B_SCP_Live_Acceptance_Observer' )
    || ! method_exists( 'MAD4B_SCP_Live_Acceptance_Observer', 'build_provenance_status' ) ) {
    WP_CLI::error( 'MAD4B_NATIVE_PROVENANCE_UNAVAILABLE', false );
    exit( 3 );
}
$build = MAD4B_SCP_Live_Acceptance_Observer::build_provenance_status();
$source = is_array( $build ) && isset( $build['source_commit_sha'] )
    ? strtolower( (string) $build['source_commit_sha'] ) : '';
if ( ! hash_equals( $expected_sha, $source )
    || empty( $build['manifest_valid'] ) || empty( $build['runtime_manifest_match'] ) ) {
    WP_CLI::error( 'MAD4B_NATIVE_PACKAGE_IDENTITY_NOT_CURRENT', false );
    exit( 3 );
}
global $wpdb;
// Measures this read-only procedure, not PHP boot/request time or HTTP latency.
$started = microtime( true );
$base_queries = isset( $wpdb->num_queries ) ? (int) $wpdb->num_queries : 0;
$theme_slug = sanitize_key( (string) get_stylesheet() );
$theme = wp_get_theme();
$mods = get_theme_mods();
$theme_mod_count = is_array( $mods ) ? count( $mods ) : 0;
$rest = array( 'state' => 'not_requested', 'http_status' => null, 'route' => '' );
if ( '1' === getenv( 'MAD4B_PREVIEW_NATIVE_REST' ) ) {
    // Fixed WordPress Core GET only: never follow external links or arbitrary routes.
    if ( function_exists( 'rest_do_request' ) && class_exists( 'WP_REST_Request' ) ) {
        $request = new WP_REST_Request( 'GET', '/wp/v2/types' );
        $response = rest_do_request( $request );
        $rest = array(
            'state' => 'observed_internal_dispatch',
            'http_status' => is_object( $response ) && method_exists( $response, 'get_status' )
                ? (int) $response->get_status() : null,
            'route' => '/wp/v2/types',
        );
    } else {
        $rest = array( 'state' => 'rest_api_unavailable', 'http_status' => null, 'route' => '/wp/v2/types' );
    }
}
$results = array(
    'contract' => 'mad4b.wp-native-preview-evidence.v1',
    'source_commit_sha' => $source,
    'build_fingerprint' => isset( $build['build_fingerprint'] ) ? $build['build_fingerprint'] : '',
    'site_environment' => 'staging',
    'theme_slug' => $theme_slug,
    'theme_exists' => $theme->exists(),
    'theme_mod_count' => $theme_mod_count,
    'rest' => $rest,
    'server_elapsed_ms' => round( ( microtime( true ) - $started ) * 1000, 3 ),
    'db_queries' => max( 0, (int) $wpdb->num_queries - $base_queries ),
    'peak_memory_bytes' => memory_get_peak_usage( true ),
    'includes_bootstrap' => false,
    'comparable_to_frontend_http' => false,
    'external_browser_evidence' => false,
    'acceptance_authorized' => false,
    'production_mutation' => false,
);
echo wp_json_encode( $results, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
