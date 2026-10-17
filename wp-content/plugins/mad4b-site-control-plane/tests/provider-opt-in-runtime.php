<?php
/** Safe provider opt-in is a read-only hint, never a credential intake. */
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );
final class WP_Error {
    public $code;
    public function __construct( $code, $message = '' ) { $this->code = $code; }
}
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function add_action() {}
function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $s ) ); }
function sanitize_text_field( $s ) { return strip_tags( (string) $s ); }
function esc_html__( $s, $domain = '' ) { return $s; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function current_user_can( $s ) { return !empty( $GLOBALS['optin_authorized'] ) && 'manage_options' === $s; }
function apply_filters( $name, $items ) {
    if ( 'mad4b_scp_provider_opt_in_requirements' !== $name ) return $items;
    return array(
        array( 'provider_id' => 'custom_safe', 'label' => 'Custom approved provider',
            'state' => 'MISSING', 'handoff_page' => 'mad4b-provider-connections',
            'detail' => 'Use the reviewed owner handoff.',
            'required_environment_names' => array( 'CUSTOM_API_KEY', 'bad-value', 'API_SECRET') ),
        array( 'provider_id' => 'custom_bad', 'label' => '<script>alert(1)</script>',
            'state' => 'MISSING', 'handoff_page' => 'example-evil',
            'detail' => 'https://evil.invalid/bad' ),
        array( 'provider_id' => 'custom_safe', 'label' => 'duplicate',
            'state' => 'MISSING', 'handoff_page' => 'mad4b-provider-connections',
            'detail' => 'Duplicate must be ignored.' ),
    );
}
function check_optin( $ok, $label ) {
    if ( ! $ok ) { fwrite( STDERR, 'FAIL: ' . $label . PHP_EOL ); exit( 1 ); }
}
final class MAD4B_SCP_Admin_Route_Registry {
    public static function routes() {
        return array_fill_keys( array( 'mad4b-control-plane-connection', 'mad4b-control-plane-context', 'mad4b-browser-acceptance',
            'mad4b-provider-connections', 'mad4b-search-intelligence' ), array( 'required_capability' => 'manage_options' ) );
    }
}
final class MAD4B_SCP_Admin_Workspace {
    public static function link( $page ) {
        return isset( MAD4B_SCP_Admin_Route_Registry::routes()[ $page ] )
            ? 'https://staging.example.test/wp-admin/admin.php?page=' . $page : '';
    }
}
function add_query_arg( $name, $value, $url ) {
    return $url . '&' . rawurlencode( $name ) . '=' . rawurlencode( $value );
}
final class MAD4B_SCP_Policy { public static function can_read() { return true; } }
final class MAD4B_SCP_Provider_Connection_Center {
    public static $connected = false;
    public static function discover( $input = array() ) {
        return array( 'providers' => array( array( 'provider' => 'google_drive',
            'label' => 'Google Workspace', 'connected' => self::$connected,
            'reconnect_required' => false, 'revocation_pending' => false ),
            array( 'provider' => 'custom_oauth', 'label' => 'Other provider',
                'connected' => false, 'reconnect_required' => true,
                'revocation_pending' => false ) ) );
    }
}
final class MAD4B_SCP_Browser_Acceptance_Admin_UI {
    public static function public_selection() {
        return array( 'executor' => 'browserbase', 'credential_verified' => false,
            'external_runner_connected' => false );
    }
    public static function managed_executors() {
        return array( 'browserbase' => array( 'Browserbase', array( 'BROWSERBASE_API_KEY' ) ) );
    }
}
final class MAD4B_SCP_Native_Capability_Browser_Provider {
    public static function capabilities() {
        return array( 'browser_attestation_public_key_valid' => false );
    }
}
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-provider-opt-in.php';
$GLOBALS['optin_authorized'] = false;
check_optin( is_wp_error( MAD4B_SCP_Provider_Opt_In::status() ), 'unauthorized read denied' );
$GLOBALS['optin_authorized'] = true;
check_optin( is_wp_error( MAD4B_SCP_Provider_Opt_In::status( array( 'credential' => 'secrets' ) ) ), 'unexpected input denied' );
$o = MAD4B_SCP_Provider_Opt_In::status();
check_optin( is_array( $o ) && 4 === $o['count'] && !empty( $o['auto_triggered'] ),
    'Google + browser + one safe extension discovered' );
check_optin( $o['mutation_performed'] === false && $o['secret_values_included'] === false
    && $o['authority_created'] === false && $o['oauth_consent_performed'] === false,
    'opt in creates no authority or side effect' );
$map = array_column( $o['items'], null, 'provider_id' );
check_optin( isset( $map['custom_oauth'] )
    && 'RECONNECT' === $map['custom_oauth']['state']
    && 'mad4b-control-plane-connection' === $map['custom_oauth']['handoff_page'],
    'registered third-party providers use the existing canonical owner handoff' );
check_optin( isset( $map['browser_runner'] )
    && $map['browser_runner']['required_environment_names'] === array( 'BROWSERBASE_API_KEY' ),
    'Browser credential field names derived from selected external executor' );
check_optin( $map['custom_safe']['required_environment_names'] === array( 'CUSTOM_API_KEY', 'API_SECRET' ),
    'extension names bounded and invalid identifiers rejected' );
check_optin( !isset( $map['custom_bad'] ) && $map['custom_safe']['label'] === 'Custom approved provider',
    'unregistered handoff and duplicate extension rejected' );
ob_start(); MAD4B_SCP_Provider_Opt_In::render( 'mad4b-browser-acceptance' );
$html = ob_get_clean();
check_optin( strpos( $html, 'Provider setup needed' ) !== false
    && strpos( $html, 'page=mad4b-browser-acceptance' ) !== false
    && strpos( $html, 'page=mad4b-control-plane-context' ) !== false
    && strpos( $html, 'example-evil' ) === false
    && strpos( $html, '<script>' ) === false, 'read-only bounded owner handoffs rendered' );
$GLOBALS['optin_authorized'] = false;
ob_start(); MAD4B_SCP_Provider_Opt_In::render( 'mad4b-browser-acceptance' );
$denied = ob_get_clean();
check_optin( $denied === '', 'non-admin sees no provider hints' );
echo "PROVIDER_OPT_IN_RUNTIME_PASS\n";
