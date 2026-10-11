<?php
/** Exact OAuth owner AI auto-delegation; isolated PHP 7.4+ fixture. No grants written. */
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', dirname( __DIR__ ) . '/' );
class WP_Error { public function __construct( $code = 'err', $message = '' ) {} }
function is_wp_error( $v ) { return $v instanceof WP_Error; }
$GLOBALS['ai_scope_current_user'] = 44;
$GLOBALS['ai_scope_admin'] = true;
function get_current_user_id() { return $GLOBALS['ai_scope_current_user']; }
function current_user_can( $cap ) { return 'manage_options' === $cap && $GLOBALS['ai_scope_admin']; }
class MAD4B_SCP_Site_Profile {
    public static $environment = 'staging';
    public static $origin = true;
    public static function current_environment() { return self::$environment; }
    public static function status() { return array( 'origin_match' => self::$origin, 'environment_match' => true ); }
    public static function agent_slug() { return 'demo-staging-agent'; }
}
class MAD4B_SCP_Local_OAuth_Server {
    const CHATGPT_CIMD_CLIENT_ID = 'https://chatgpt.com/oauth/client.json';
    public static function issuer() { return 'https://example.test/oauth/mcp'; }
}
class MAD4B_SCP_OAuth_Resource_Bridge {
    const AI_AUTO_ALL_SCOPE = 'mad4b:approval:auto:all';
    public static $bearer = true;
    public static $client = true;
    public static $scopes = array( 'mad4b:read', self::AI_AUTO_ALL_SCOPE );
    public static function issuer_fingerprint_for_issuer( $issuer ) { return hash( 'sha256', 'oauth-issuer' . "\\0" . rtrim( $issuer, '/' ) ); }
    public static function verified_bearer_active() { return self::$bearer; }
    public static function verified_bearer_has_scope( $v ) { return in_array( $v, self::$scopes, true ); }
    public static function verified_bearer_client_is( $v ) {
        return self::$client && MAD4B_SCP_Local_OAuth_Server::CHATGPT_CIMD_CLIENT_ID === $v;
    }
}
require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-ai-approval.php';
function oauth_ai_assert( $ok, $reason ) {
    if ( ! $ok ) { fwrite( STDERR, 'FAIL: ' . $reason . PHP_EOL ); exit( 1 ); }
}
$identity = array( 'authenticated' => true, 'auth_method' => 'oauth2_bearer', 'wp_user_id' => 44,
    'issuer_fingerprint' => MAD4B_SCP_OAuth_Resource_Bridge::issuer_fingerprint_for_issuer( MAD4B_SCP_Local_OAuth_Server::issuer() ),
    'token_scopes' => array( 'mad4b:read', 'mad4b:approval:auto:all' ) );
$test = static function( $value ) {
    return MAD4B_SCP_AI_Approval::oauth_auto_all_delegation_active( $value );
};
oauth_ai_assert( $test( $identity ), 'Verified, exact-client, site-admin user with explicit new OAuth scope is delegated' );
MAD4B_SCP_OAuth_Resource_Bridge::$scopes = array( 'mad4b:read', 'mad4b:authority:step-up' );
oauth_ai_assert( ! $test( $identity ), 'Old read + step-up scopes never imply auto approval' );
MAD4B_SCP_OAuth_Resource_Bridge::$scopes = array( 'mad4b:read', 'mad4b:approval:auto:all' );
MAD4B_SCP_OAuth_Resource_Bridge::$bearer = false;
oauth_ai_assert( ! $test( $identity ), 'Unverified request-supplied scopes never authorize AI' );
MAD4B_SCP_OAuth_Resource_Bridge::$bearer = true;
MAD4B_SCP_OAuth_Resource_Bridge::$client = false;
oauth_ai_assert( ! $test( $identity ), 'Another OAuth client cannot reuse delegated authority' );
MAD4B_SCP_OAuth_Resource_Bridge::$client = true;
MAD4B_SCP_Site_Profile::$environment = 'production';
oauth_ai_assert( ! $test( $identity ), 'Production cannot inherit Staging AI approval' );
MAD4B_SCP_Site_Profile::$environment = 'staging';
MAD4B_SCP_Site_Profile::$origin = false;
oauth_ai_assert( ! $test( $identity ), 'Site origin mismatch is not authorized' );
MAD4B_SCP_Site_Profile::$origin = true;
$GLOBALS['ai_scope_admin'] = false;
oauth_ai_assert( ! $test( $identity ), 'User lacking current administrator capability is not delegated' );
$GLOBALS['ai_scope_admin'] = true;
$GLOBALS['ai_scope_current_user'] = 45;
oauth_ai_assert( ! $test( $identity ), 'OAuth user must match mapped WordPress user' );
$GLOBALS['ai_scope_current_user'] = 44;
$foreign = $identity; $foreign['issuer_fingerprint'] = str_repeat( 'f', 64 );
oauth_ai_assert( ! $test( $foreign ), 'Other trusted OAuth issuer cannot mint the same delegated approval scope' );
$unscoped = $identity; $unscoped['token_scopes'] = array( 'mad4b:read' );
oauth_ai_assert( ! $test( $unscoped ), 'Verified bridge and identity must both contain explicit scope' );

$root = dirname( __DIR__ );
$issuer = file_get_contents( $root . '/includes/class-mad4b-scp-local-oauth-server.php' );
$bridge = file_get_contents( $root . '/includes/class-mad4b-scp-oauth-resource-bridge.php' );
$approval = file_get_contents( $root . '/includes/class-mad4b-scp-ai-approval.php' );
oauth_ai_assert( false !== strpos( $issuer, "mad4b:approval:auto:all" )
    && false !== strpos( $issuer, 'AI automatic approval delegation:' )
    && false !== strpos( $issuer, 'CHATGPT_CIMD_CLIENT_ID' )
    && false !== strpos( $bridge, "const AI_AUTO_ALL_SCOPE = 'mad4b:approval:auto:all';" ),
    'Server metadata and human consent must advertise the same exact AI scope' );
oauth_ai_assert( false !== strpos( $approval, "'authorization_mode' => \$oauth_auto_all ? 'owner_oauth_scope_auto_all'" )
    && false !== strpos( $approval, "'context/remote-owner-review-apply'" )
    && false !== strpos( $approval, "'breakglass' ===" ),
    'Scope may delegate ticket decisions but not fake original human ownership or raw SQL Breakglass' );
echo "PASS: new owner OAuth scope delegates Staging AI approvals; old tokens and forged identities are denied\\n";
