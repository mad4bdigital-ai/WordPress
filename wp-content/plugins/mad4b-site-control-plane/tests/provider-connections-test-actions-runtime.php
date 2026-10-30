<?php
/** Hermetic UI regression: real POST tests, never setup-link masquerading as a test. */
define( 'ABSPATH', __DIR__ . '/' );
function admin_url( $path = '' ) { return 'https://wordpress.test/wp-admin/' . $path; }
function esc_url( $value ) { return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $value ) { return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' ); }
function wp_nonce_field( $action ) { echo '<input type="hidden" name="_wpnonce" value="test-nonce" data-action="' . esc_attr( $action ) . '">'; }
function wp_validate_redirect( $url, $fallback = '' ) { return strpos( $url, 'https://wordpress.test/' ) === 0 ? $url : $fallback; }
function is_wp_error( $value ) { return false; }
class MAD4B_SCP_Firecrawl_Provider {}
class MAD4B_SCP_Search_Runtime { public static function can_configure() { return true; } }
class MAD4B_SCP_Search_Provider_Connections {
    public static function status( $provider ) {
        return array( 'configured' => $provider === 'serp_test', 'revision' => 7 );
    }
}
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-provider-setup-coverage.php';
$method = new ReflectionMethod( 'MAD4B_SCP_Provider_Setup_Coverage', 'render_test_control' );
$method->setAccessible( true );
$render = function ( $row ) use ( $method ) {
    ob_start();
    $method->invoke( null, $row );
    return ob_get_clean();
};
$verify = function ( $ok, $message ) { if ( ! $ok ) throw new RuntimeException( $message ); };

$firecrawl = $render( array( 'family' => 'research', 'provider_id' => 'firecrawl', 'credential_state' => 'encrypted_saved' ) );
$verify( strpos( $firecrawl, 'method="post"' ) !== false, 'Firecrawl test must POST' );
$verify( strpos( $firecrawl, 'name="action" value="mad4b_firecrawl_test"' ) !== false, 'Firecrawl POST action missing' );
$verify( strpos( $firecrawl, 'data-action="mad4b_firecrawl_test"' ) !== false, 'Firecrawl nonce missing' );
$verify( strpos( $firecrawl, 'Test Firecrawl connection' ) !== false, 'Firecrawl test button missing' );

$search = $render( array( 'family' => 'search', 'provider_id' => 'serp_test', 'credential_state' => 'encrypted_saved' ) );
foreach ( array(
    'method="post"',
    'name="action" value="mad4b_search_provider_connection"',
    'name="provider_id" value="serp_test"',
    'name="operation" value="test"',
    'name="expected_revision" value="7"',
    'name="return_to_provider_connections" value="1"',
    'data-action="mad4b_search_provider_connection:serp_test"',
) as $required ) $verify( strpos( $search, $required ) !== false, 'SERP test missing ' . $required );

foreach ( array(
    array( 'family' => 'oauth', 'provider_id' => 'google_drive', 'setup_url' => 'https://wordpress.test/wp-admin/oauth' ),
    array( 'family' => 'custom', 'provider_id' => 'my_provider', 'setup_url' => 'https://wordpress.test/wp-admin/custom' ),
    array( 'family' => 'research', 'provider_id' => 'other', 'setup_url' => 'https://wordpress.test/wp-admin/other' ),
) as $row ) {
    $output = $render( $row );
    $verify( strpos( $output, '<form' ) === false, 'Unreviewed provider exposed a fake network test' );
    $verify( strpos( $output, 'Live test unavailable' ) !== false, 'Unavailable test needs explicit explanation' );
}
$missing = $render( array( 'family' => 'research', 'provider_id' => 'firecrawl', 'credential_state' => 'not_configured' ) );
$verify( strpos( $missing, '<form' ) === false, 'Firecrawl cannot be tested without saved credentials' );
$verify( strpos( $missing, 'Save Firecrawl credentials' ) !== false, 'Missing credential guidance missing' );
$ui_source = file_get_contents( dirname( __DIR__ ) . '/includes/class-mad4b-scp-provider-connections-admin-ui.php' );
$verify( strpos( $ui_source, "is_readable( __DIR__ . '/class-mad4b-scp-firecrawl-discovery-preflight.php' )" ) !== false,
    'Optional Firecrawl discovery preflight must never break an installed site when absent' );
echo "PROVIDER_CONNECTION_TEST_ACTIONS: PASS (explicit POST, nonce, revision and unavailable paths)\n";
