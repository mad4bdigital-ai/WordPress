<?php
/**
 * Regression for OAuth consent first-byte safety (PHP 7.4+).
 * No WordPress site boot, no OAuth token or write authority generated.
 */
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', dirname( __DIR__ ) . '/' );
class WP_Error {
    private $code;
    public function __construct( $code, $message = '' ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_parse_url( $value, $component = -1 ) { return parse_url( $value, $component ); }
function get_transient( $key ) { return false; }
$GLOBALS['oauth_cimd_network_calls'] = 0;
function wp_safe_remote_get( $url, $args = array() ) {
    $GLOBALS['oauth_cimd_network_calls']++;
    return new WP_Error( 'fixture_upstream_unavailable' );
}
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
// The choice must always be rendered before optional authority details.
$form_position = strpos( $render, 'mad4b-oauth-consent-decision' );
$pending_position = strpos( $render, 'consent_grant_pending_projection()' );
$diagnostics_position = strpos( $render, 'mad4b-live-authority' );
oauth_first_paint_assert(
    false !== $form_position && false !== $pending_position && false !== $diagnostics_position
    && $form_position < $pending_position && $pending_position < $diagnostics_position,
    'OAuth approve and deny actions must precede optional diagnostic lists'
);
oauth_first_paint_assert(
    1 === substr_count( stripslashes( $render ), 'name="decision" value="approve"' )
    && 1 === substr_count( stripslashes( $render ), 'name="decision" value="deny"' ),
    'OAuth primary form must have exactly one choice for each action'
);
oauth_first_paint_assert(
    false !== strpos( $render, 'new AbortController()' )
    && false !== strpos( $render, 'signal:controller.signal' )
    && false !== strpos( $render, 'clearTimeout(abortTimer)' ),
    'Optional live status requires a bounded, abortable HTTP request'
);
// A failed CIMD request must not be repeated when the OAuth error path
// validates the same trusted client a second time during one HTTP request.
$client = new ReflectionMethod( $c, 'client' );
$client->setAccessible( true );
$one = $client->invoke( null, $c::CHATGPT_CIMD_CLIENT_ID );
$two = $client->invoke( null, $c::CHATGPT_CIMD_CLIENT_ID );
oauth_first_paint_assert(
    is_wp_error( $one ) && is_wp_error( $two )
    && 'invalid_client' === $one->get_error_code()
    && 'invalid_client' === $two->get_error_code()
    && 1 === $GLOBALS['oauth_cimd_network_calls'],
    'Unreachable CIMD must be bounded to one network attempt per request'
);
echo "PASS: OAuth first paint bypasses heavy projection; live readback stays authenticated and fail-closed\n";
