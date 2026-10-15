<?php
/**
 * Feature007 read-only dashboard AI approval mode regression, PHP 7.4+.
 * No site bootstrap, OAuth tokens, admin writes or mutation tickets.
 */
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', dirname( __DIR__ ) . '/' );
class WP_Error {}
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function get_current_user_id() { return 7; }
function current_user_can( $cap ) { return 'manage_options' === $cap; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_html__( $s, $domain = '' ) { return $s; }
class MAD4B_SCP_Context_Authority {
    public static function review_policy() { return array( 'mode' => 'human_only' ); }
}
class MAD4B_SCP_Site_Profile {
    public static $environment = 'staging';
    public static function current_environment() { return self::$environment; }
    public static function status() { return array( 'origin_match' => true, 'environment_match' => true ); }
}
class MAD4B_SCP_Local_OAuth_Server {
    const CHATGPT_CIMD_CLIENT_ID = 'https://chatgpt.com/oauth/client.json';
    public static function issuer() { return 'https://demo.test/oauth/mcp'; }
}
class MAD4B_SCP_OAuth_Resource_Bridge {
    const AI_AUTO_ALL_SCOPE = 'mad4b:approval:auto:all';
    public static $remote_bearer = false;
    public static function resource_identifier() { return 'https://demo.test/wp-json/mcp/mad4b-chatgpt'; }
    public static function scopes_for_resource( $url ) { return MAD4B_SCP_Site_Profile::$environment === 'staging'
        ? array( 'mad4b:read', self::AI_AUTO_ALL_SCOPE ) : array( 'mad4b:read' ); }
    public static function verified_bearer_active() { return self::$remote_bearer; }
    public static function verified_bearer_has_scope( $s ) { return self::$remote_bearer && self::AI_AUTO_ALL_SCOPE === $s; }
    public static function verified_bearer_client_is( $id ) { return self::$remote_bearer && MAD4B_SCP_Local_OAuth_Server::CHATGPT_CIMD_CLIENT_ID === $id; }
    public static function issuer_fingerprint_for_issuer( $issuer ) { return hash( 'sha256', 'oauth-issuer' . "\0" . rtrim( $issuer, '/' ) ); }
}
require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-ai-approval.php';
function admin_ai_assert( $ok, $reason ) {
    if ( ! $ok ) { fwrite( STDERR, 'FAIL: ' . $reason . PHP_EOL ); exit( 1 ); }
}
$state = MAD4B_SCP_AI_Approval::dashboard_projection();
admin_ai_assert( $state['oauth_scope_supported'] === true
    && $state['legacy_standing_policy_configured'] === false
    && $state['oauth_current_request_verified'] === false
    && 'not_observed_in_admin_request' === $state['remote_chatgpt_token_grant_status']
    && $state['mutation_performed'] === false,
    'Scope availability must not impersonate active OAuth bearer consent or persistence' );
ob_start();
MAD4B_SCP_AI_Approval::render_admin_status();
$html = ob_get_clean();
admin_ai_assert( false !== strpos( $html, 'current token unverified here' )
    && false !== strpos( $html, 'mad4b:approval:auto:all' )
    && false !== strpos( $html, 'Original brand ownership' ),
    'Global wp-admin status must explicitly distinguish support, consent and review ownership' );
MAD4B_SCP_Site_Profile::$environment = 'production';
$production = MAD4B_SCP_AI_Approval::dashboard_projection();
admin_ai_assert( $production['oauth_scope_supported'] === false
    && $production['optional_owner_oauth_scope'] === '',
    'Staging-only owner OAuth approval option must not appear enabled in Production' );
$base = dirname( __DIR__ );
$files = array(
    'includes/class-mad4b-scp-admin-workspace.php' => array( 'MAD4B_SCP_AI_Approval::render_admin_status()' ),
    'includes/class-mad4b-scp-chatgpt-connection-admin-ui.php' => array( "'optional_ai_auto_all_scope'", "'optional_ai_auto_all_scope_requires_reconsent'", 'Existing ChatGPT AI scope grant' ),
    'includes/class-mad4b-scp-context-admin-ui.php' => array( 'Brand review: Human only', 'not general operation-ticket AI decisions' ),
    'includes/class-mad4b-scp-approval-decision-admin.php' => array( 'Separately, an explicitly delegated AI Agent', 'Approver evidence', 'approved_by' ),
    'includes/class-mad4b-scp-staging-write-authority.php' => array( "'owner_oauth_ai_auto_approval_effective_for_current_request'", 'ai_approval_scoped_request' ),
);
foreach ( $files as $filename => $tokens ) {
    $contents = file_get_contents( $base . '/' . $filename );
    admin_ai_assert( is_string( $contents ), 'Required dashboard path missing: ' . $filename );
    foreach ( $tokens as $token ) admin_ai_assert( false !== strpos( $contents, $token ), 'Missing global dashboard mode integration: ' . $filename . ':' . $token );
}
echo "PASS: dashboards distinguish Staging OAuth scope support from live consent and human Brand review\n";
