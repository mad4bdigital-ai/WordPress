<?php
/**
 * Hermetic MCP OAuth challenge regression: the Bearer challenge lists
 * REQUIRED scopes, not all optional scopes exposed in RFC 9728 metadata.
 */
define( 'ABSPATH', '/test/' );
function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $s ) ); }
function untrailingslashit( $s ) { return rtrim( $s, '/' ); }
function home_url( $path = '' ) { return 'https://example.test' . $path; }
function rest_url( $path = '' ) { return home_url( '/wp-json/' . ltrim( $path, '/' ) ); }
function esc_url_raw( $s ) { return $s; }
function wp_parse_url( $s, $component = -1 ) { return parse_url( $s, $component ); }
function wp_get_environment_type() { return 'staging'; }
class MAD4B_SCP_Site_Profile {
    public static function configured() { return false; }
    public static function current_environment() { return 'staging'; }
}
class MAD4B_SCP_Site_Profile_Enrollment {
    const ABILITY = 'mad4b/site-profile-feature-reenroll';
    public static function chatgpt_step_up_tools() { return array( self::ABILITY ); }
}
class MAD4B_SCP_MCP_Client_Compatibility {
    public static function authoritative_well_known_url( $server_id = 'mad4b-chatgpt' ) {
        return home_url( '/.well-known/oauth-protected-resource/wp-json/mcp/' . $server_id );
    }
}
class WP_REST_Response {
    private $headers = array();
    public function header( $key, $value ) { $this->headers[ $key ] = $value; }
    public function get_headers() { return $this->headers; }
}
class FakeRequest {
    public function get_route() { return '/mcp/mad4b-chatgpt'; }
}
require __DIR__ . '/../includes/class-mad4b-scp-oauth-resource-bridge.php';
require __DIR__ . '/../includes/class-mad4b-scp-oauth-challenge-alignment.php';
function must( $condition, $message ) {
    if ( ! $condition ) { fwrite( STDERR, 'FAIL: ' . $message . "\n" ); exit( 1 ); }
}
$resource = MAD4B_SCP_OAuth_Resource_Bridge::resource_identifier();
$supported = MAD4B_SCP_OAuth_Resource_Bridge::scopes_for_resource( $resource );
must( in_array( 'mad4b:read', $supported, true ), 'Read remains supported' );
must( in_array( 'mad4b:authority:step-up', $supported, true ), 'Optional step-up remains supported' );
must( in_array( 'mad4b:approval:auto:all', $supported, true ), 'Optional AI approval remains supported' );
$challenge = MAD4B_SCP_OAuth_Resource_Bridge::challenge_header( $resource );
must( false !== strpos( $challenge, 'scope="mad4b:read"' ), 'Read challenge must not require optional grants' );
must( false === strpos( $challenge, 'approval:auto:all' ), 'Read challenge must not require AI approval' );
$response = new WP_REST_Response();
$response->header( 'WWW-Authenticate', 'Bearer resource_metadata="https://example.test/obsolete"' );
$response = MAD4B_SCP_OAuth_Challenge_Alignment::align_response( $response, null, new FakeRequest() );
$aligned = $response->get_headers()['WWW-Authenticate'];
must( false !== strpos( $aligned, 'scope="mad4b:read"' ), 'Alignment must preserve minimum read scope' );
must( false !== strpos( $aligned, '/.well-known/oauth-protected-resource/wp-json/mcp/mad4b-chatgpt' ), 'Metadata URL remains authoritative' );
must( false === strpos( $aligned, 'approval:auto:all' ), 'Alignment must not reintroduce optional grants' );
echo "PASS: MCP OAuth challenge requires only read while optional scopes remain available\n";
