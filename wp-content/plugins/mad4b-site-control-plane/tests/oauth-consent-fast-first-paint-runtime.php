<?php
/**
 * Regression for OAuth consent first-byte safety (PHP 7.4+).
 * No WordPress site boot, no OAuth token or write authority generated.
 */
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', dirname( __DIR__ ) . '/' );
require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-local-oauth-server.php';

function oauth_first_paint_assert( $condition, $reason ) {
    if ( ! $condition ) {
        fwrite( STDERR, 'FAIL: ' . $reason . PHP_EOL );
        exit( 1 );
    }
}
$c = 'MAD4B_SCP_Local_OAuth_Server';
$method = new ReflectionMethod( $c, 'consent_grant_pending_projection' );
$method->setAccessible( true );
$pending = $method->invoke( null );
oauth_first_paint_assert(
    is_array( $pending )
    && isset( $pending['authority_evaluated'], $pending['read_only'], $pending['mutation_performed'] )
    && false === $pending['authority_evaluated']
    && true === $pending['read_only']
    && false === $pending['mutation_performed']
    && false === $pending['ready']
    && empty( $pending['grants'] )
    && empty( $pending['observed_at'] )
    && 'live_authority_readback_pending' === $pending['blocking_conditions'][0]['code'],
    'Initial consent has no synthetic grant or readiness evidence'
);
$source = file_get_contents( dirname( __DIR__ ) . '/includes/class-mad4b-scp-local-oauth-server.php' );
oauth_first_paint_assert( is_string( $source ), 'OAuth source must be present' );
$render_start = strpos( $source, 'private static function render_consent(' );
$token_start = strpos( $source, 'private static function handle_token(' );
$ajax_start = strpos( $source, 'public static function ajax_grant_projection(' );
oauth_first_paint_assert(
    false !== $render_start && false !== $token_start
    && false !== $ajax_start && $ajax_start < $render_start
    && $render_start < $token_start,
    'OAuth handler source boundaries remain explicit'
);
$render = substr( $source, $render_start, $token_start - $render_start );
$ajax = substr( $source, $ajax_start, $render_start - $ajax_start );
oauth_first_paint_assert(
    false !== strpos( $render, 'consent_grant_pending_projection()' )
    && false === strpos( $render, 'self::consent_grant_projection()' )
    && false !== strpos( $render, 'Awaiting verification' ),
    'Initial OAuth GET must not materialize Write grant catalog synchronously'
);
oauth_first_paint_assert(
    false !== strpos( $ajax, "check_ajax_referer( 'mad4b_oauth_grant_projection'" )
    && false !== strpos( $ajax, 'self::user_authorized( get_current_user_id() )' )
    && false !== strpos( $ajax, "'projection' => self::consent_grant_projection()" ),
    'Only authenticated, nonce-checked asynchronous endpoint may compute live projection'
);
oauth_first_paint_assert(
    false !== strpos( $render, 'const BASE=60000,MAX=180000' )
    && false !== strpos( $render, 'schedule(1200)})();</script>' )
    && false !== strpos( $render, 'wp_nonce_field(' ),
    'Live readback is delayed and governed consent nonce remains required'
);
echo "PASS: OAuth first paint bypasses heavy projection; live readback stays authenticated and fail-closed\n";
