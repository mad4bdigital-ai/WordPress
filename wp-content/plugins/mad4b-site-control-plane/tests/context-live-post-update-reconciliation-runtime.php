<?php
/**
 * Context UI must reconcile saved provider state on every read without
 * clearing OAuth revocation evidence, changing grants or claiming readiness.
 */
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );

function esc_html__( $text, $domain = '' ) { return (string) $text; }
function __( $text, $domain = '' ) { return (string) $text; }
function sanitize_key( $text ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $text ) ); }
function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function admin_url( $path = '' ) { return 'https://staging.example.test/wp-admin/' . $path; }
function add_query_arg( $params, $url ) { return $url . '?' . http_build_query( $params ); }
function mad4b_test_assert( $condition, $message ) {
    if ( ! $condition ) { fwrite( STDERR, "FAIL: " . $message . "\n" ); exit( 1 ); }
}

final class MAD4B_SCP_Google_Drive_Context {
    public static $status = array();
    public static $reads = 0;
    public static function connection_status() { self::$reads++; return self::$status; }
}
final class MAD4B_SCP_Context_Authority {
    public static $status = array();
    public static $reads = 0;
    public static function status() { self::$reads++; return self::$status; }
}

require dirname( __DIR__ ) . '/includes/class-mad4b-scp-context-admin-ui.php';
$method = new ReflectionMethod( 'MAD4B_SCP_Context_Admin_UI', 'render_live_context_reconciliation' );
$method->setAccessible( true );
$render = static function () use ( $method ) {
    ob_start();
    $method->invoke( null );
    return ob_get_clean();
};

MAD4B_SCP_Google_Drive_Context::$status = array(
    'connected' => true, 'revocation_pending' => true, 'read_available' => false,
    'write_available' => false, 'revocation_error' => 'google_revocation_http_400',
    'access_token' => 'MUST_NOT_RENDER',
);
MAD4B_SCP_Context_Authority::$status = array(
    'quarantined_source_record_count' => 1, 'quarantined_asset_record_count' => 4,
);
$pending = $render();
mad4b_test_assert( false !== strpos( $pending, 'google_revocation_pending' )
    && false !== strpos( $pending, 'google_revocation_http_400' )
    && false !== strpos( $pending, '1 legacy source(s) and 4 asset(s)' ),
    'Pending revocation and quarantined ownership must be rendered as distinct live blockers.' );
mad4b_test_assert( false === strpos( $pending, 'MUST_NOT_RENDER' )
    && false === strpos( $pending, 'Revocation confirmed' ),
    'Neither credentials nor unsupported revocation completion may appear.' );
mad4b_test_assert( MAD4B_SCP_Google_Drive_Context::$reads === 1
    && MAD4B_SCP_Context_Authority::$reads === 1,
    'A render must perform one fresh read of each source without retry or mutation.' );

MAD4B_SCP_Google_Drive_Context::$status = array(
    'connected' => false, 'revocation_pending' => false,
    'read_available' => false, 'write_available' => false,
);
MAD4B_SCP_Context_Authority::$status = array(
    'quarantined_source_record_count' => 0, 'quarantined_asset_record_count' => 0,
);
mad4b_test_assert( '' === $render(),
    'Resolved provider and ownership states must not leave a stale warning.' );
MAD4B_SCP_Google_Drive_Context::$status = array(
    'connected' => false, 'revocation_pending' => false, 'token_unreadable' => true,
);
$unreadable = $render();
mad4b_test_assert( false !== strpos( $unreadable, 'google_token_unreadable' )
    && false === strpos( $unreadable, 'google_revocation_pending' ),
    'Unreadable credential is distinct from unconfirmed Google revocation.' );
echo "PASS: live Context recovery readback, zero stale notice, no token exposure\n";
