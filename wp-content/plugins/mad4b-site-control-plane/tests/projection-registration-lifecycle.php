<?php
// Structural registration must not depend on request identity arriving later.
define( 'ABSPATH', __DIR__ );
function add_action() {} function add_filter() {}
function sanitize_key( $value ) { return $value; }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function get_option( $name, $default = false ) { return $GLOBALS['options'][$name] ?? $default; }
function wp_has_ability( $name ) { return isset( $GLOBALS['abilities'][$name] ); }
function wp_get_ability( $name ) { return $GLOBALS['abilities'][$name] ?? null; }
function wp_get_abilities() { throw new RuntimeException( 'Registration notice scanned the Ability universe' ); }
class MAD4B_SCP_MCP_Catalog_Diagnostics { static function preflight() { throw new RuntimeException( 'Registration notice rebuilt the MCP catalog' ); } }
class MAD4B_SCP_Catalog_Object_Store { static function status() { return array( 'indexed_bytes' => 0, 'indexed_objects' => 0 ); } }
class WP_Error {
 private $code; private $message; private $data;
 function __construct( $code, $message, $data = array() ) { $this->code = $code; $this->message = $message; $this->data = $data; }
 function get_error_code() { return $this->code; }
 function get_error_message() { return $this->message; }
 function get_error_data() { return $this->data; }
}
class MAD4B_SCP_Site_Profile {
 static function configured() { return true; } static function current_environment() { return 'staging'; }
 static function origin_enrolled() { return true; } static function site_urls_match_enrollment() { return true; }
 static function site_uuid() { return 'fixture'; } static function current_origin() { return 'https://ci.test'; } static function revision() { return 1; }
}
class MAD4B_SCP_Policy {
 static function can_breakglass() { $GLOBALS['identity_checks']++; return false; }
}
class MAD4B_SCP_Authorization { static function execution_boundary_verified( $ability ) { return false; } }
class MAD4B_SCP_Servers {
 static function chatgpt_base_tools() { return array(); } static function chatgpt_reviewed_direct_step_up_tools() { return array(); }
 static function core_tools( $server ) { return array(); } static function provider_for_ability( $server, $ability ) { return 'fixture'; }
}
class Ability {
 protected $execute_callback; protected $permission_callback;
 function __construct() { $this->execute_callback = static function() {}; $this->permission_callback = static function() {}; }
 function get_meta() { return array( 'annotations' => array( 'readonly' => true ), 'mcp' => array( 'surface' => 'breakglass' ) ); }
 function get_input_schema() { return array( 'type' => 'object', 'properties' => array() ); }
 function get_output_schema() { return array( 'type' => 'object' ); }
 function get_category() { return 'mad4b-read'; } function get_label() { return 'Sensitive read'; } function get_description() { return 'fixture'; }
 function execute() { throw new RuntimeException( 'Forbidden callback reached' ); }
}
require __DIR__ . '/../includes/class-mad4b-scp-ability-contract-inspector.php';
require __DIR__ . '/../includes/class-mad4b-scp-chatgpt-tool-projection.php';
require __DIR__ . '/../includes/class-mad4b-scp-ability-catalog-transport.php';
$name = 'fixture/breakglass'; $GLOBALS['abilities'][$name] = new Ability(); $GLOBALS['identity_checks'] = 0;
$row = MAD4B_SCP_ChatGPT_Tool_Projection::describe_ability( $name );
$GLOBALS['options'][MAD4B_SCP_ChatGPT_Tool_Projection::OPTION] = array( 'contract' => MAD4B_SCP_ChatGPT_Tool_Projection::CONTRACT, 'revision' => 1, 'binding' => MAD4B_SCP_ChatGPT_Tool_Projection::current_binding(), 'abilities' => array( $name => $row ) );
if ( array( $name ) !== MAD4B_SCP_ChatGPT_Tool_Projection::projected_ability_names() || 0 !== $GLOBALS['identity_checks'] ) throw new RuntimeException( 'Registration evaluated pre-auth identity' );
$snapshot = MAD4B_SCP_ChatGPT_Tool_Projection::registration_diagnostic_snapshot();
if ( 1 !== $snapshot['stored_count'] || 1 !== $snapshot['effective_count'] || $snapshot['abilities'][0]['stale'] || $snapshot['catalog_preflight_performed'] || $snapshot['universe_scan_performed'] || 0 !== $GLOBALS['identity_checks'] ) throw new RuntimeException( 'Registration bookkeeping changed projection or evaluated authority' );
$GLOBALS['options'][MAD4B_SCP_ChatGPT_Tool_Projection::OPTION]['abilities'][$name]['classification_sha256'] = str_repeat( '0', 64 );
$snapshot = MAD4B_SCP_ChatGPT_Tool_Projection::registration_diagnostic_snapshot();
if ( 0 !== $snapshot['effective_count'] || ! $snapshot['abilities'][0]['stale'] ) throw new RuntimeException( 'Registration bookkeeping missed classification drift' );
$GLOBALS['options'][MAD4B_SCP_ChatGPT_Tool_Projection::OPTION]['abilities'][$name] = $row;
echo "PASS registration bookkeeping: no DTO build, universe scan or permission callback\n";
$tool = new class { function get_adapter_meta() { return array( 'ability' => 'fixture/breakglass' ); } };
$server = new class { function get_server_id() { return 'mad4b-chatgpt'; } };
$denied = MAD4B_SCP_ChatGPT_Tool_Projection::guard_tool_call( array(), 'fixture-breakglass', $tool, $server );
if ( ! is_wp_error( $denied ) || 'mad4b_projection_breakglass_disabled' !== $denied->get_error_code() || 1 !== $GLOBALS['identity_checks'] ) throw new RuntimeException( 'Execution did not check post-auth authority' );
$wire_error = MAD4B_SCP_Ability_Catalog_Transport::mcp_result( new WP_Error( 'mad4b_catalog_snapshot_expired', 'Expired', array( 'status' => 410, 'private' => 'do not expose' ) ) );
$envelope = json_decode( $wire_error->get_error_message(), true );
if ( 410 !== $envelope['status'] || isset( $envelope['private'] ) ) throw new RuntimeException( 'Bounded MCP error contract lost status or exposed data' );
echo "PASS projection lifecycle: identity-free registration, guarded execution and bounded Adapter error envelope\n";

$protected_tool = new class( $GLOBALS['abilities'][$name] ) {
 protected $ability;
 function __construct( $ability ) { $this->ability = $ability; }
};
$callbacks = MAD4B_SCP_ChatGPT_Tool_Projection::callback_identity( $protected_tool );
if ( ! is_array( $callbacks ) || 2 !== count( $callbacks ) || ! is_callable( $callbacks[0] ) || ! is_callable( $callbacks[1] ) ) throw new RuntimeException( 'Protected callback identity unavailable on supported PHP' );
echo "PASS protected reflection: Adapter Ability and callbacks accessible on PHP 7.4+\n";
