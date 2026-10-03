<?php
// Exercise the real notice with malformed provider evidence and passive-page gates.
define( 'ABSPATH', __DIR__ . '/' );
function add_action( ...$args ) {}
function current_user_can( $cap ) { return $GLOBALS['allowed']; }
function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( $s ) ); }
function sanitize_text_field( $s ) { return strip_tags( $s ); }
function wp_unslash( $s ) { return $s; }
function esc_html( $s ) { return htmlspecialchars( $s, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $s ) { return esc_html( $s ); }
function esc_html__( $s, $domain ) { return esc_html( $s ); }
class MAD4B_SCP_Provider_Diagnostic_Policy { static $deep = false; static function explicit_rest_materialization_allowed() { return self::$deep; } }
class MAD4B_SCP_MCP_Registration_Bridge { static $calls = 0; static function status() { self::$calls++; return array( 'registration_errors' => array( 'mad4b-chatgpt' => 'mcp_required_tool_preflight_failed' ) ); } }
class MAD4B_SCP_Servers { static function registration_status() { return array( 'mad4b-chatgpt' => array( 'registered' => false, 'requested_tool_count' => 34, 'tool_count' => 0, 'preflight' => array( 'ready' => false, 'blocker' => 'mcp_required_tool_preflight_failed', 'tools' => array_fill( 0, 33, 'fixture' ), 'failures' => $GLOBALS['failures'], 'runtime_class_provenance' => isset( $GLOBALS['provenance'] ) ? $GLOBALS['provenance'] : array() ) ) ); } }
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-mcp-registration-diagnostics-admin.php';
function check( $ok, $why ) { if ( ! $ok ) throw new RuntimeException( $why ); }
function notice() { ob_start(); MAD4B_SCP_MCP_Registration_Diagnostics_Admin::render(); return ob_get_clean(); }
$GLOBALS['allowed'] = true;
$_GET = array( 'page' => 'mad4b-control-plane-connection', 'tab' => 'endpoints' );
check( '' === notice() && 0 === MAD4B_SCP_MCP_Registration_Bridge::$calls, 'passive notice performs no deep work' );
MAD4B_SCP_Provider_Diagnostic_Policy::$deep = true;
$GLOBALS['allowed'] = false;
check( '' === notice(), 'non-admin cannot see evidence' );
$GLOBALS['allowed'] = true;
$GLOBALS['failures'] = array_fill( 0, 15, array( 'failing_ability' => 'mad4b/example', 'stage' => 'official_schema_validation', 'error_class' => 'WP_Error', 'error_code' => 'mcp_tool_validation_failed', 'validator_reason' => 'input_schema_properties_not_object', 'source_schema_fingerprint' => str_repeat( 'a', 64 ), 'schema_fingerprint' => str_repeat( 'b', 64 ), 'tool_bytes' => 98000, 'raw_payload' => 'DO_NOT_COPY' ) );
$GLOBALS['provenance'] = array(
	'enforced' => true,
	'ready' => false,
	'state' => 'mixed_runtime',
	'blocker' => 'mcp_adapter_class_provenance_mismatch',
	'failure_count' => 1,
	'failures' => array( array(
		'alias' => 'tool_validator',
		'class' => 'WP\\MCP\\Domain\\Tools\\McpToolValidator',
		'expected_source' => 'mcp-adapter/includes/Domain/Tools/McpToolValidator.php',
		'observed_source' => 'foreign-plugin/vendor/McpToolValidator.php',
		'expected_sha256' => str_repeat( 'c', 64 ),
		'actual_sha256' => str_repeat( 'd', 64 ),
		'reason' => 'runtime_class_source_mismatch',
		'absolute_path' => '/PRIVATE/ABSOLUTE/PATH',
	) ),
);
$html = notice();
check( false !== strpos( $html, 'class=WP_Error' ) && false !== strpos( $html, 'tool_bytes=98000' ), 'class and size identify failure' );
check( false !== strpos( $html, 'validator_reason=input_schema_properties_not_object' ), 'bounded validator reason missing' );
check( false !== strpos( $html, 'MCP class provenance state' ) && false !== strpos( $html, 'mixed_runtime' ), 'class provenance state missing' );
check( false !== strpos( $html, 'alias=tool_validator' ) && false !== strpos( $html, 'observed=foreign-plugin/vendor/McpToolValidator.php' ), 'class-level provenance failure missing' );
check( false === strpos( $html, '/PRIVATE/ABSOLUTE/PATH' ), 'absolute class path leaked' );
check( false !== strpos( $html, 'expected_sha=' . str_repeat( 'c', 16 ) ) && false === strpos( $html, str_repeat( 'c', 17 ) ), 'class provenance SHA prefix not bounded' );
check( false !== strpos( $html, 'source_schema=' . str_repeat( 'a', 16 ) ) && false === strpos( $html, str_repeat( 'a', 17 ) ), 'fingerprint prefix bounded' );
check( 12 === preg_match_all( '/ChatGPT preflight failure [0-9]+<\/th>/', $html ), 'failure rows bounded' );
check( false === strpos( $html, 'DO_NOT_COPY' ), 'unknown diagnostic fields excluded' );
check( false !== strpos( $html, 'ChatGPT accepted preflight tool count' ) && false !== strpos( $html, '>33</code>' ) && false !== strpos( $html, 'ChatGPT materialized tool count' ), 'accepted and materialized counts distinguished' );
$GLOBALS['failures'] = array( array( 'failing_ability' => array(), 'stage' => new stdClass(), 'error_class' => str_repeat( 'x', 10000 ), 'error_code' => '<script>alert(1)</script>', 'schema_fingerprint' => array(), 'tool_bytes' => new stdClass() ) );
$html = notice();
check( false === strpos( $html, '<script>' ) && false === strpos( $html, str_repeat( 'x', 161 ) ), 'malformed and oversized evidence remains renderable and bounded' );
echo "MCP registration diagnostics admin runtime: PASS\n";
