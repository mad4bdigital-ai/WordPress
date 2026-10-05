<?php
// A registered route cannot conceal an observed failed catalog.
define( 'ABSPATH', __DIR__ . '/' );
class WP_Error { private $code; function __construct( $code, $message = '' ) { $this->code = $code; } function get_error_code() { return $this->code; } }
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function sanitize_key( $v ) { return strtolower( $v ); }
function sanitize_text_field( $v ) { return $v; }
function esc_url_raw( $v ) { return $v; }
function home_url() { return 'https://fixture.test'; }
function site_url() { return home_url(); }
function rest_url( $path = '' ) { return home_url() . '/wp-json/' . $path; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function get_current_user_id() { return 0; }
function is_admin() { return false; }
class MAD4B_SCP_Servers {
 static $row = array( 'registered' => true, 'error' => '', 'catalog_evidence' => array( 'ready' => true ) );
 static function expected_server_ids() { return array( 'mad4b-chatgpt' ); }
 static function registration_status() { return array( 'mad4b-chatgpt' => self::$row ); }
 static function write_tools() { return array(); }
}
class FixtureServer {
 function get_server_id() { return 'mad4b-chatgpt'; }
 function get_transport_permission_callback() { return array( 'MAD4B_SCP_Servers', 'can_chatgpt_transport' ); }
}
class FixtureAdapter { static function instance() { return new self(); } function get_servers() { return array( new FixtureServer() ); } }
class_alias( 'FixtureAdapter', 'WP\\MCP\\Core\\McpAdapter' );
class MAD4B_SCP_Provider_Diagnostic_Policy { static function current_rest_server() { return new FixtureRest(); } }
class FixtureRest { function get_routes() { return array( '/mcp/mad4b-chatgpt' => array() ); } }
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-connection-status.php';
function check( $ok, $why ) { if ( ! $ok ) throw new RuntimeException( $why ); }
$ok = MAD4B_SCP_Connection_Status::status( true );
check( ! in_array( 'mad4b_transport_registration_incomplete', $ok['local_blockers'], true ), 'Ready registration reported incomplete' );
MAD4B_SCP_Servers::$row['error'] = 'mcp_required_tool_preflight_failed';
MAD4B_SCP_Servers::$row['catalog_evidence']['ready'] = false;
$failed = MAD4B_SCP_Connection_Status::status( true );
check( ! $failed['local_transport_ready'] && ! $failed['connection_certified'], 'Failed catalog certified' );
check( in_array( 'mcp_required_tool_preflight_failed', $failed['local_blockers'], true ), 'Exact preflight failure lost' );
check( $failed['servers'][0]['registered'] && $failed['servers'][0]['route_registered'] && $failed['servers'][0]['permission_callback_match'], 'Failure must retain independent route evidence' );
MAD4B_SCP_Servers::$row['error'] = '';
MAD4B_SCP_Servers::$row['catalog_evidence']['blocker'] = 'mcp_catalog_projection_mismatch';
$failed = MAD4B_SCP_Connection_Status::status( true );
check( in_array( 'mad4b_transport_registration_incomplete', $failed['local_blockers'], true ), 'Observed failed catalog without aggregate error admitted' );
check( in_array( 'mcp_catalog_projection_mismatch', $failed['local_blockers'], true ), 'Exact materialized catalog blocker lost' );
unset( MAD4B_SCP_Servers::$row['catalog_evidence'] );
$unknown = MAD4B_SCP_Connection_Status::status( true );
check( null === $unknown['servers'][0]['catalog_ready'], 'Unobserved catalog fabricated as failed' );
MAD4B_SCP_Servers::$row['descriptor_evidence'] = array( 'ready' => false, 'blockers' => array(
	'mad4b/staging-write-candidate-binding-audit:mad4b_chatgpt_projection_ability_unavailable',
	'PRIVATE/PATH:PRIVATE exception details',
) );
$diagnostic = MAD4B_SCP_Connection_Status::endpoint_diagnostic( 'mad4b-chatgpt' );
check( false === $diagnostic['capability_descriptor_ready'] && 2 === $diagnostic['capability_descriptor_failure_count'], 'Descriptor failure was not projected' );
check( 'mad4b/staging-write-candidate-binding-audit' === $diagnostic['capability_descriptor_failures'][0]['failing_ability'], 'Missing ability identity lost' );
check( false === strpos( json_encode( $diagnostic ), 'PRIVATE' ), 'Private descriptor error leaked' );
unset( MAD4B_SCP_Servers::$row['descriptor_evidence'] );
check( null === MAD4B_SCP_Connection_Status::endpoint_diagnostic( 'mad4b-chatgpt' )['capability_descriptor_ready'], 'Unobserved descriptor evidence fabricated' );
echo "Connection catalog readiness runtime: PASS\n";

class MAD4B_SCP_Policy { static $allow = true; static function can_mutate() { return self::$allow; } }
class MAD4B_SCP_Staging_Write_Authority {
 static $current = false;
 static function effective() { return self::$current; }
 static function current_execution_readiness() { throw new RuntimeException( 'A connection view must not scan live grants' ); }
}
$surface = new ReflectionMethod( 'MAD4B_SCP_Connection_Status', 'write_surface_summary' ); $surface->setAccessible( true );
$blocked = $surface->invoke( null, array(), true );
check( true === $blocked['mutation_policy_allows_current_request'] && false === $blocked['mutation_effective_for_current_request'], 'General policy gate concealed a stale candidate' );
MAD4B_SCP_Staging_Write_Authority::$current = true;
$checkpoint = $surface->invoke( null, array(), true );
check( true === $checkpoint['candidate_bound_write_checkpoint_ready'] && null === $checkpoint['mutation_effective_for_current_request'] && $checkpoint['live_write_grant_validation_deferred'], 'Checkpoint invented exact execution authority' );
MAD4B_SCP_Policy::$allow = false;
check( false === $surface->invoke( null, array(), true )['mutation_effective_for_current_request'], 'Denied policy was displayed as unmeasured' );
echo "Connection write checkpoint versus execution truth: PASS\n";
