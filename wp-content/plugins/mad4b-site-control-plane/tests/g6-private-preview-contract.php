<?php
define( 'ABSPATH', __DIR__ . '/' );
class WP_Error {
	private $code;
	public function __construct( $code, $message = '', $data = array() ) { $this->code = $code; }
	public function get_error_code() { return $this->code; }
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function wp_json_encode( $v, $flags = 0 ) { return json_encode( $v, $flags ); }
function get_current_user_id() { return $GLOBALS['g6_uid']; }
function current_user_can( $capability ) { return 'manage_options' === $capability && $GLOBALS['g6_admin']; }
function add_action( $event, $cb, $priority = 10 ) {}
function wp_has_ability( $name ) { return isset( $GLOBALS['g6_abilities'][ $name ] ); }
function wp_register_ability( $name, $schema ) { $GLOBALS['g6_abilities'][ $name ] = $schema; }
$GLOBALS['g6_uid'] = 12;
$GLOBALS['g6_admin'] = false;
$GLOBALS['g6_abilities'] = array();

require dirname( __DIR__ ) . '/includes/class-mad4b-scp-g6-acceptance.php';
function g6_expect( $ok, $label ) { if ( ! $ok ) { fwrite( STDERR, 'FAIL: ' . $label . PHP_EOL ); exit( 1 ); } }

MAD4B_SCP_G6_Acceptance::register_abilities();
g6_expect( 3 === count( $GLOBALS['g6_abilities'] ), 'three private read-only G6 abilities expected' );
foreach ( $GLOBALS['g6_abilities'] as $name => $def ) {
	g6_expect( ! $def['meta']['public'] && ! $def['meta']['show_in_rest'] && ! $def['meta']['mcp']['public'] && 'admin' === $def['meta']['mcp']['surface'], 'G6 ability must remain private: ' . $name );
	g6_expect( ! call_user_func( $def['permission_callback'] ), 'non-admin cannot access G6' );
	$GLOBALS['g6_admin'] = true;
	g6_expect( call_user_func( $def['permission_callback'] ), 'admin must inspect G6' );
	$GLOBALS['g6_admin'] = false;
}
g6_expect( ! MAD4B_SCP_G6_Operation_Compiler::compilable_job_state( 'FAILED' ), 'failed job requires lifecycle recovery' );
g6_expect( ! MAD4B_SCP_G6_Operation_Compiler::compilable_job_state( 'BLOCKED' ), 'blocked job requires lifecycle recovery' );
g6_expect( ! MAD4B_SCP_G6_Operation_Compiler::compilable_job_state( 'COMPLETED' ), 'terminal job must fail closed' );
g6_expect( MAD4B_SCP_G6_Operation_Compiler::compilable_job_state( 'WAITING_REVIEW' ), 'review state supports read-only planning' );

$private = 'PRIVATE-CUSTOMER-BRIEF-424242';
$path = '/customer-brief-' . $private;
$step = array(
	'node_id' => 'customer-secret-node',
	'primitive' => 'content',
	'strategy_id' => 'native-content-experience-v1',
	'provider_id' => 'native_content_experience',
	'capability_id' => 'content_experience.update',
	'arguments_sha256' => MAD4B_SCP_G6_Contracts::digest( array( $private ) ),
	'schema_sha256' => str_repeat( 'a', 64 ),
	'provider_binding_sha256' => str_repeat( 'b', 64 ),
	'native_plan_sha256' => str_repeat( 'c', 64 ),
	'step_sha256' => str_repeat( 'd', 64 ),
	'depends_on' => array(),
	'object_pins' => array( array( 'resource_id' => 'private:' . $private, 'state_sha256' => str_repeat( 'e', 64 ) ) ),
	'permissions' => array( array( 'capability' => 'edit_post', 'object_id' => 55 ) ),
	'effects' => array( array( 'kind' => 'local_content', 'reversibility' => 'reversible', 'private_note' => $private ) ),
	'diffs' => array( array( 'path' => $path, 'before_sha256' => str_repeat( '1', 64 ), 'after_sha256' => str_repeat( '2', 64 ), 'values_redacted' => true ) ),
	'typed_input' => array( 'post_content' => $private ),
);
$plan = array(
	'contract' => MAD4B_SCP_G6_Operation_Compiler::CONTRACT,
	'owner_user_id' => 12, 'binding' => array( 'site_uuid' => 'private-test-site' ),
	'job_id' => 'job-' . $private, 'job_revision' => 5, 'job_state' => 'WAITING_REVIEW',
	'compile_input' => array( 'reason' => $private ), 'input_sha256' => str_repeat( '3', 64 ),
	'schema_sha256' => str_repeat( '4', 64 ), 'nodes' => array( 'customer-secret-node' => $step ),
	'workflow_handoff' => array( 'secret' => $private ),
);
$plan['plan_sha256'] = MAD4B_SCP_G6_Contracts::digest( $plan );
$denied = MAD4B_SCP_G6_Operation_Compiler::review_projection( $plan );
g6_expect( is_wp_error( $denied ) && 'mad4b_g6_owner_required' === $denied->get_error_code(), 'non-admin cannot inspect compiled plans' );
$GLOBALS['g6_admin'] = true;
$view = MAD4B_SCP_G6_Operation_Compiler::review_projection( $plan );
g6_expect( ! is_wp_error( $view ), 'owned plan must produce redacted review' );
$text = json_encode( $view );
g6_expect( false === strpos( $text, $private ), 'review must not expose private draft text, node IDs, paths or object references' );
g6_expect( false === strpos( $text, 'typed_input' ) && false === strpos( $text, 'compile_input' ), 'private execution material must not be in preview' );
g6_expect( ! $view['authorizing'] && ! $view['mutation_performed'] && ! $view['plan_usable_as_execution_authority'], 'review does not authorize execution' );
g6_expect( true === $view['steps'][0]['diffs'][0]['path_redacted'], 'private diff paths must be redacted' );
$GLOBALS['g6_uid'] = 13;
$foreign = MAD4B_SCP_G6_Operation_Compiler::review_projection( $plan );
g6_expect( is_wp_error( $foreign ) && 'mad4b_g6_plan_owner' === $foreign->get_error_code(), 'different admin cannot read a plan' );
echo "mad4b.feature007-g6-private-review.v1: PASS\n";
