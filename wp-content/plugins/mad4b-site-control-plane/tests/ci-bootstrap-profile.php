<?php
/** Opt-in CI timings; observe the existing bootstrap without changing its result. */
if ( PHP_SAPI !== 'cli' || ! defined( 'WP_CLI' ) || ! WP_CLI
    || getenv( 'GITHUB_ACTIONS' ) !== 'true'
    || getenv( 'MAD4B_CI_BOOTSTRAP_PROFILE' ) !== '1' ) return;

$mad4b_ci_started = microtime( true );
$mad4b_ci_http = array();
$mad4b_ci_emit = static function ( array $row ) {
    fwrite( STDERR, 'MAD4B_BOOTSTRAP_PROFILE ' . json_encode( $row, JSON_UNESCAPED_SLASHES ) . PHP_EOL );
};
foreach ( array( 'muplugins_loaded', 'plugins_loaded', 'after_setup_theme', 'init', 'wp_loaded', 'wp_abilities_api_init', 'mcp_adapter_init' ) as $mad4b_ci_hook ) {
    $mad4b_ci_phase = null;
    WP_CLI::add_wp_hook( $mad4b_ci_hook, static function () use ( &$mad4b_ci_phase ) {
        $mad4b_ci_phase = microtime( true );
    }, PHP_INT_MIN, 0 );
    WP_CLI::add_wp_hook( $mad4b_ci_hook, static function () use ( &$mad4b_ci_phase, $mad4b_ci_hook, $mad4b_ci_started, $mad4b_ci_emit ) {
        $mad4b_ci_emit( array( 'phase' => $mad4b_ci_hook,
            'phase_ms' => round( ( microtime( true ) - $mad4b_ci_phase ) * 1000, 2 ),
            'total_ms' => round( ( microtime( true ) - $mad4b_ci_started ) * 1000, 2 ) ) );
    }, PHP_INT_MAX, 0 );
    // Each hook gets its own reference rather than sharing the loop variable.
    unset( $mad4b_ci_phase );
}
WP_CLI::add_wp_hook( 'pre_http_request', static function ( $preempt, $args, $url ) use ( &$mad4b_ci_http ) {
    if ( count( $mad4b_ci_http ) < 32 ) $mad4b_ci_http[ hash( 'sha256', $url ) ] = microtime( true );
    return $preempt;
}, PHP_INT_MIN, 3 );
WP_CLI::add_wp_hook( 'http_api_debug', static function ( $response, $context, $class, $args, $url ) use ( &$mad4b_ci_http, $mad4b_ci_emit ) {
    $key = hash( 'sha256', $url );
    if ( ! isset( $mad4b_ci_http[ $key ] ) ) return;
    $mad4b_ci_emit( array( 'phase' => 'http', 'host' => parse_url( $url, PHP_URL_HOST ),
        'elapsed_ms' => round( ( microtime( true ) - $mad4b_ci_http[ $key ] ) * 1000, 2 ),
        'timeout_s' => isset( $args['timeout'] ) ? $args['timeout'] : null ) );
    unset( $mad4b_ci_http[ $key ] );
}, PHP_INT_MAX, 5 );
register_shutdown_function( static function () use ( $mad4b_ci_started, $mad4b_ci_emit ) {
    global $wpdb;
    $mad4b_ci_emit( array( 'phase' => 'process_exit',
        'total_ms' => round( ( microtime( true ) - $mad4b_ci_started ) * 1000, 2 ),
        'db_queries' => isset( $wpdb->num_queries ) ? (int) $wpdb->num_queries : null ) );
} );
