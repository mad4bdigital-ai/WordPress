<?php
define( 'ABSPATH', __DIR__ . '/' );
class WP_Error {
	private $code;
	public function __construct( $code, $message = '', $data = array() ) { $this->code = $code; }
	public function get_error_code() { return $this->code; }
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function wp_json_encode( $v, $flags = 0 ) { return json_encode( $v, $flags ); }
function get_current_user_id() { return $GLOBALS['g6_admin'] ? 17 : 0; }
function current_user_can( $cap ) { return $GLOBALS['g6_admin'] && 'manage_options' === $cap; }
function wp_get_environment_type() { return 'staging'; }
function add_action( $hook, $callback, $priority = 10 ) {}
function wp_has_ability( $name ) { return isset( $GLOBALS['g6_abilities'][ $name ] ); }
function wp_register_ability( $name, $def ) { $GLOBALS['g6_abilities'][ $name ] = $def; }
class MAD4B_SCP_Site_Profile { public static function site_uuid() { return '12345678-1234-1234-1234-123456789abc'; } }
class MAD4B_SCP_Runtime_Generation_Fence {
	public static function capture() { return array( 'generation_sha256' => str_repeat( '1', 64 ), 'material' => array( 'runtime' => str_repeat( '2', 64 ) ) ); }
}
class MAD4B_SCP_Restore_Epoch { public static function material() { return array( 'epoch' => 1 ); } }
$GLOBALS['g6_admin'] = true;
$GLOBALS['g6_abilities'] = array();
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-g6-acceptance.php';
function g6_admit_assert( $ok, $label ) { if ( ! $ok ) { fwrite( STDERR, 'FAIL: ' . $label . PHP_EOL ); exit( 1 ); } }
function g6_admit_error( $result, $code ) { g6_admit_assert( is_wp_error( $result ) && $code === $result->get_error_code(), 'expected fail-closed ' . $code ); }
MAD4B_SCP_G6_Acceptance::register_abilities();
g6_admit_assert( 7 === count( $GLOBALS['g6_abilities'] ), 'seven private G6 abilities' );
foreach ( $GLOBALS['g6_abilities'] as $name => $def ) {
	g6_admit_assert( ! $def['meta']['public'] && ! $def['meta']['show_in_rest'] && ! $def['meta']['mcp']['public'] && 'admin' === $def['meta']['mcp']['surface'], 'G6 must stay private: ' . $name );
}
$private = 'PRIVATE-PROMPT-EXFILTRATE-KEYS-6172';
$proposal = array( 'intent' => 'content', 'prompt' => $private, 'context_sha256' => str_repeat( 'a', 64 ), 'requested_region' => 'eu', 'max_cost_micro' => 1000, 'data_class' => 'restricted' );
$result = MAD4B_SCP_G6_AI_Workspace::propose( $proposal );
g6_admit_assert( ! is_wp_error( $result ) && 'EXTERNAL_ACTION_REQUIRED' === $result['execution_state'], 'AI intent is only a reviewed proposal' );
g6_admit_assert( false === strpos( json_encode( $result ), $private ), 'private prompt must never echo' );
g6_admit_assert( ! $result['authorizing'] && ! $result['paid_generation_performed'] && ! $result['tool_execution_performed'] && ! $result['payload_persisted'], 'no model call or tool mutation is admitted' );
$injected = $proposal; $injected['approval_override'] = true;
g6_admit_error( MAD4B_SCP_G6_AI_Workspace::propose( $injected ), 'mad4b_g6_untrusted_control_key' );
$missing = $proposal; unset( $missing['context_sha256'] );
g6_admit_error( MAD4B_SCP_G6_AI_Workspace::propose( $missing ), 'mad4b_g6_workspace_context' );
$knowledge = array(
	'source_ref' => 'source-1', 'source_sha256' => str_repeat( 'a', 64 ), 'artifact_sha256' => str_repeat( 'b', 64 ),
	'site_uuid' => MAD4B_SCP_Site_Profile::site_uuid(), 'kind' => 'pdf', 'rights' => 'owner_provided',
	'expires_at' => time() + 3600, 'privacy_class' => 'restricted', 'storage_region' => 'eu'
);
$review = MAD4B_SCP_G6_Knowledge_Admission::preview( $knowledge );
g6_admit_assert( ! is_wp_error( $review ) && 'APPROVAL_REQUIRED' === $review['admission_state'], 'source admission stays review-only' );
g6_admit_assert( ! $review['content_retrieved'] && ! $review['embeddings_generated'] && ! $review['ingestion_performed'] && ! $review['authorizing'], 'no ingestion or authority' );
$badTenant = $knowledge; $badTenant['site_uuid'] = 'other-site';
g6_admit_error( MAD4B_SCP_G6_Knowledge_Admission::preview( $badTenant ), 'mad4b_g6_source_tenant' );
$expired = $knowledge; $expired['expires_at'] = time() - 1;
g6_admit_error( MAD4B_SCP_G6_Knowledge_Admission::preview( $expired ), 'mad4b_g6_source_rights_expired' );
$deleted = $knowledge; $deleted['deleted'] = true;
g6_admit_error( MAD4B_SCP_G6_Knowledge_Admission::preview( $deleted ), 'mad4b_g6_source_revoked' );
$malicious = $knowledge; $malicious['raw_pdf_bytes'] = 'poisoned';
g6_admit_error( MAD4B_SCP_G6_Knowledge_Admission::preview( $malicious ), 'mad4b_g6_source_schema' );
$GLOBALS['g6_admin'] = false;
g6_admit_error( MAD4B_SCP_G6_AI_Workspace::propose( $proposal ), 'mad4b_g6_owner_required' );
g6_admit_error( MAD4B_SCP_G6_Knowledge_Admission::preview( $knowledge ), 'mad4b_g6_owner_required' );
echo "mad4b.feature007-g6-admission-denials.v1: PASS\n";
