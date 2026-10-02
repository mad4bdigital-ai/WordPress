<?php
// Behavioral contracts with external WordPress services replaced, production
// discovery/classification/dispatch code unchanged. Real WP parity runs in CI too.
define( 'ABSPATH', __DIR__ );
class WP_Error {
 private $code; function __construct( $code, $message = '', $data = array() ) { $this->code = $code; }
 function get_error_code() { return $this->code; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function add_action() {} function add_filter() {}
function sanitize_key( $value ) { return strtolower( $value ); }
function absint( $value ) { return abs( (int) $value ); }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function wp_salt( $scheme ) { return $GLOBALS['signing_salt'] ?? 'test-only-receipt-salt'; }
function wp_strip_all_tags( $value ) { return strip_tags( $value ); }
function wp_check_invalid_utf8( $value ) { return iconv( 'UTF-8', 'UTF-8//IGNORE', $value ); }
function get_current_blog_id() { return $GLOBALS['blog']; }
function wp_get_abilities() { return $GLOBALS['abilities']; }
function wp_get_ability( $name ) { return $GLOBALS['abilities'][$name] ?? null; }
function wp_has_ability( $name ) { return isset( $GLOBALS['abilities'][$name] ); }
function apply_filters( $name, $value ) { return $value; }
function get_current_user_id() { return $GLOBALS['receipt_user'] ?? 1; }
function wp_get_current_user() { return (object) array( 'allcaps' => $GLOBALS['receipt_caps'] ?? array() ); }
function current_user_can() { return false; }
function get_option( $name, $default = false ) { return $default; }
function rest_url( $path ) { return 'https://ci.test/wp-json/' . $path; }
function untrailingslashit( $value ) { return rtrim( $value, '/' ); }
class MAD4B_SCP_Policy { static function can_read() { return $GLOBALS['read_allowed']; } static function can_admin() { return true; } static function can_mutate() { return true; } }
class MAD4B_SCP_Servers {
 static function ability_is_mounted( $server, $name ) { return isset( $GLOBALS['mounted'][$server][$name] ); }
 static function provider_for_ability( $server, $name ) { return self::ability_is_mounted( $server, $name ) ? 'fixture' : null; }
 static function core_tools() { return array(); }
 static function is_external_write_candidate( $name ) { return isset( $GLOBALS['mounted']['mad4b-write'][$name] ); }
 static function write_tools() { return array_keys( $GLOBALS['mounted']['mad4b-write'] ?? array() ); }
 static function chatgpt_base_tools() { return array(); }
 static function chatgpt_reviewed_direct_step_up_tools() { return array(); }
}
class MAD4B_SCP_Authorization { static function execution_boundary_verified( $ability ) { return $ability->boundary; } }
class MAD4B_SCP_Identity_Context { static function bind_approval_ticket_for_request( $id ) { $GLOBALS['approval_bind_calls']++; return true; } }
class MAD4B_SCP_Connector_Resilience {
 const CONTRACT = 'fixture';
 static function execute_mutation( $lane, $name, $callback ) { $v = $callback(); return is_wp_error( $v ) ? $v : array( 'result' => $v ); }
 static function execute_read( $name, $callback ) { $v = $callback(); return is_wp_error( $v ) ? $v : array( 'result' => $v ); }
}
class MAD4B_SCP_Transport_Context { static function with_write_dispatch_target( $name, $digest, $callback ) { return $callback(); } }
class MAD4B_SCP_OAuth_Resource_Bridge { static function verified_bearer_active() { return $GLOBALS['bearer']; } }
class GatewayFixture {
 public $aliases = array(); public $boundary = true; public $permission = true; public $lane; public $readonly; public $calls = 0; public $schema_reads = 0;
 private $name;
 function __construct( $name, $lane, $readonly ) { $this->name = $name; $this->lane = $lane; $this->readonly = $readonly; }
 function get_name() { return $this->name; }
 function get_meta() { return array( 'annotations' => array( 'readonly' => $this->readonly ), 'mcp' => array( 'surface' => $this->lane, 'search_aliases' => $this->aliases ) ); }
 function get_input_schema() { ++$this->schema_reads; return array( 'type' => 'object' ); }
 function get_output_schema() { ++$this->schema_reads; return array( 'type' => 'object' ); }
 function get_category() { return 'fixture'; }
 function get_label() { return 'Metadata fixture'; }
 function get_description() { return 'Schema must remain lazy'; }
 function execute( $input = null ) { if ( ! $this->permission ) return new WP_Error( 'permission_denied' ); ++$this->calls; return array( 'ok' => true ); }
}
$GLOBALS['blog'] = 1; $GLOBALS['read_allowed'] = true; $GLOBALS['bearer'] = false; $GLOBALS['mounted'] = array(); $GLOBALS['abilities'] = array(); $GLOBALS['approval_bind_calls'] = 0;
require __DIR__ . '/../includes/class-mad4b-scp-ability-contract-inspector.php';
require __DIR__ . '/../includes/class-mad4b-scp-capability-descriptor-registry.php';
require __DIR__ . '/../includes/class-mad4b-scp-preparation-receipt.php';
require __DIR__ . '/../includes/class-mad4b-scp-chatgpt-tool-projection.php';
require __DIR__ . '/../includes/class-mad4b-scp-ability-catalog-transport.php';
require __DIR__ . '/../includes/class-mad4b-scp-unified-capability-gateway.php';
require __DIR__ . '/../includes/class-mad4b-scp-abilities.php';
function check_gateway( $condition, $message ) { if ( ! $condition ) throw new RuntimeException( $message ); }
function prepared_dispatch_identity( $ability_name ) {
 $row = MAD4B_SCP_Capability_Descriptor_Registry::describe( $ability_name );
 if ( is_wp_error( $row ) ) return $row;
 $receipt = MAD4B_SCP_Preparation_Receipt::issue( $row );
 if ( ! is_string( $receipt ) || '' === $receipt ) return new WP_Error( 'receipt_issue_failed' );
 return array(
  'expected_input_schema_sha256' => $row['input_schema_sha256'],
  'expected_execution_lane' => $row['execution_lane'],
  'expected_classification_sha256' => $row['classification_sha256'],
  'expected_authority_scope_sha256' => MAD4B_SCP_Ability_Catalog_Transport::current_authority_scope(),
  'preparation_receipt' => $receipt,
 );
}
$dispatcher = new MAD4B_SCP_Abilities();
foreach ( array( 'write', 'content', 'admin' ) as $lane ) {
 $name = 'fixture/' . $lane;
 $a = new GatewayFixture( $name, $lane, false ); $GLOBALS['abilities'][$name] = $a;
 $GLOBALS['mounted']['mad4b-' . $lane][$name] = true;
 $GLOBALS['mounted']['mad4b-write'][$name] = true;
 $row = MAD4B_SCP_ChatGPT_Tool_Projection::describe_ability( $name );
 $execution = MAD4B_SCP_Unified_Capability_Gateway::describe_execution( $row );
 check_gateway( $execution['dispatch_tool'] === 'mad4b/write-execute' && $execution['expected_execution_lane'] === $lane, 'Mutation lane lost stable dispatch/original identity: ' . $lane );
 $identity = prepared_dispatch_identity( $name );
 check_gateway( ! is_wp_error( $identity ), 'Unable to issue prepared identity: ' . $lane );
 $input = array_merge( array( 'ability_name' => $name, 'input' => array() ), $identity );
 $approval_id = '00000000-0000-0000-0000-000000000001';
 $invalid_permission = $input; $invalid_permission['preparation_receipt'] .= '0'; $invalid_permission['_mad4b_approval_ticket_id'] = $approval_id;
 $bind_before = $GLOBALS['approval_bind_calls'];
 check_gateway( is_wp_error( $dispatcher->can_write_dispatch( $invalid_permission ) ) && $bind_before === $GLOBALS['approval_bind_calls'], 'Invalid preparation bound approval metadata during write permission admission' );
 $valid_permission = $input; $valid_permission['_mad4b_approval_ticket_id'] = $approval_id;
 check_gateway( true === $dispatcher->can_write_dispatch( $valid_permission ) && $bind_before + 1 === $GLOBALS['approval_bind_calls'], 'Valid signed preparation did not admit governance metadata after revalidation' );
 $oversized_permission = $input;
 $oversized_permission['_mad4b_context_receipt'] = array( 'payload' => str_repeat( 'x', MAD4B_SCP_Abilities::MAX_WRITE_DISPATCH_CONTEXT_RECEIPT_BYTES + 1 ) );
 $oversized_bind_before = $GLOBALS['approval_bind_calls'];
 $oversized_result = $dispatcher->can_write_dispatch( $oversized_permission );
 check_gateway( is_wp_error( $oversized_result ) && 'mad4b_write_dispatch_context_receipt_oversized' === $oversized_result->get_error_code() && $oversized_bind_before === $GLOBALS['approval_bind_calls'], 'Oversized Context Receipt reached governance binding' );
 $input = $valid_permission;
 check_gateway( ! is_wp_error( $dispatcher->write_execute( $input ) ) && 1 === $a->calls, 'Valid original lane failed execution: ' . $lane );
 foreach ( array( 'expected_execution_lane', 'expected_classification_sha256', 'expected_authority_scope_sha256', 'preparation_receipt' ) as $required_pin ) {
  $missing = $input; unset( $missing[$required_pin] );
  check_gateway( is_wp_error( $dispatcher->write_execute( $missing ) ) && 1 === $a->calls, 'Missing prepared identity reached execution: ' . $required_pin );
 }
 $legacy = $input; unset( $legacy['expected_execution_lane'], $legacy['expected_classification_sha256'], $legacy['expected_authority_scope_sha256'], $legacy['preparation_receipt'] );
 check_gateway( is_wp_error( $dispatcher->write_execute( $legacy ) ) && 1 === $a->calls, 'Schema-only legacy execution was accepted' );
 $foreign = $input; $foreign['expected_authority_scope_sha256'] = str_repeat( 'f', 64 );
 check_gateway( is_wp_error( $dispatcher->write_execute( $foreign ) ) && 1 === $a->calls, 'Foreign transport authority reached execution' );
 $a->permission = false;
 check_gateway( is_wp_error( $dispatcher->write_execute( $input ) ) && 1 === $a->calls, 'Dispatcher bypassed original permission: ' . $lane );
 $a->permission = true; $a->lane = 'write' === $lane ? 'content' : 'write';
 $GLOBALS['mounted']['mad4b-' . $a->lane][$name] = true;
 check_gateway( is_wp_error( $dispatcher->write_execute( $input ) ) && 1 === $a->calls, 'Same-schema original lane drift reached execution' );
 $a->lane = $lane; $a->boundary = false;
 check_gateway( is_wp_error( $dispatcher->write_execute( $input ) ) && 1 === $a->calls, 'Lost execution boundary reached execution' );
 $a->boundary = true; unset( $GLOBALS['mounted']['mad4b-write'][$name] );
 check_gateway( 'blocked' === MAD4B_SCP_Unified_Capability_Gateway::describe_execution( $row )['state'], 'Unmounted write lane advertised executable dispatch' );
}
foreach ( array( 'internal', 'breakglass', 'developer-breakglass', 'unknown' ) as $lane ) {
 $row = array( 'ability_name' => 'fixture/sensitive', 'execution_lane' => $lane, 'execution_eligible' => true, 'readonly' => false );
 check_gateway( 'blocked' === MAD4B_SCP_Unified_Capability_Gateway::describe_execution( $row )['state'], 'Unsupported lane gained generic dispatcher: ' . $lane );
}
foreach ( array( 'content', 'admin' ) as $lane ) {
 $row = array( 'ability_name' => 'fixture/read-admin', 'execution_lane' => $lane, 'execution_eligible' => true, 'readonly' => true );
 check_gateway( 'blocked' === MAD4B_SCP_Unified_Capability_Gateway::describe_execution( $row )['state'], 'Readonly annotation on mutation surface gained write dispatch' );
}
$request = new class { public $header = ''; function get_header( $name ) { return $this->header; } };
check_gateway( true === MAD4B_SCP_Unified_Capability_Gateway::can_read_rest( $request ), 'Authenticated WP read context denied' );
$request->header = 'Bearer invalid';
check_gateway( is_wp_error( MAD4B_SCP_Unified_Capability_Gateway::can_read_rest( $request ) ), 'Invalid bearer fell back to WordPress user authority' );
$GLOBALS['bearer'] = true;
check_gateway( true === MAD4B_SCP_Unified_Capability_Gateway::can_read_rest( $request ), 'Verified bearer denied' );
$GLOBALS['read_allowed'] = false;
check_gateway( is_wp_error( MAD4B_SCP_Unified_Capability_Gateway::can_read_rest( $request ) ), 'Bearer substituted for required read capability' );
$GLOBALS['read_allowed'] = true;
$manifest = MAD4B_SCP_Unified_Capability_Gateway::public_manifest();
check_gateway( false === $manifest['rest_requires_oauth_bearer'] && false === $manifest['server_tools_list_changed'] && 'fixed_dispatch' === $manifest['primary_execution_mode'], 'Auth/refresh manifest contradicts runtime' );
// High-cardinality metadata search: no schema reads and stable top-K ordering.
$GLOBALS['abilities'] = array();
for ( $i = 0; $i < 5000; ++$i ) { $name = sprintf( 'fixture/%05d', $i ); $GLOBALS['abilities'][$name] = new GatewayFixture( $name, 'read', true ); }
$started = microtime( true );
$search = MAD4B_SCP_Unified_Capability_Gateway::dispatch( array( 'action' => 'search', 'task' => 'metadata fixture', 'limit' => 7 ) );
check_gateway( 7 === count( $search['items'] ) && 5000 === $search['universe_count'] && 'fixture/00000' === $search['items'][0]['ability_name'], 'High-cardinality relevance ordering/bounds regressed' );
foreach ( $GLOBALS['abilities'] as $a ) check_gateway( 0 === $a->schema_reads, 'Metadata search loaded a schema' );
check_gateway( microtime( true ) - $started < 5, '5000 Ability metadata search exceeded regression ceiling' );
$GLOBALS['blog'] = 2;
$switched_apply = MAD4B_SCP_ChatGPT_Tool_Projection::can_apply();
check_gateway( is_wp_error( $switched_apply ) && 'mad4b_projection_blog_switch_denied' === $switched_apply->get_error_code(), 'Switched blog reached site projection authority checks' );
check_gateway( is_wp_error( MAD4B_SCP_Unified_Capability_Gateway::dispatch( array( 'action' => 'search', 'task' => 'fixture' ) ) ), 'Blog switch reused first-site registry' );
check_gateway( is_wp_error( MAD4B_SCP_Ability_Catalog_Transport::handle( array() ) ), 'Blog switch reused first-site catalog' );
check_gateway( is_wp_error( $dispatcher->read_execute( array( 'ability_name' => 'fixture/00000' ) ) ), 'Fixed dispatcher reused first-site registry' );
$GLOBALS['blog'] = 1;
check_gateway( ! is_wp_error( MAD4B_SCP_Unified_Capability_Gateway::dispatch( array( 'action' => 'search', 'task' => 'fixture' ) ) ), 'Restored blog did not recover discovery' );
check_gateway( is_wp_error( $dispatcher->read_execute( array( 'ability_name' => 'fixture/00000' ) ) ), 'Legacy unpinned read request was accepted' );
echo "PASS gateway regressions: original mutation lanes, permissions, drift, unsupported lanes, auth modes, 5000 lazy schemas, blog isolation and legacy pin denial\n";

foreach ( array( array( 'task' => array() ), array( 'ability_names' => array( array() ) ), array( 'ability_names' => array_fill( 0, 17, 'fixture/a' ) ), array( 'client_capabilities' => array( 'max_response_bytes' => array() ) ), array( 'limit' => 'not-an-integer' ) ) as $invalid ) {
 check_gateway( is_wp_error( MAD4B_SCP_Unified_Capability_Gateway::dispatch( $invalid ) ), 'Malformed REST gateway input did not fail closed' );
}
echo "PASS malformed gateway input: bounded strings, batches, scalar capabilities and integer limits\n";

$a = new GatewayFixture( 'vendor/product-edit', 'read', true ); $a->aliases = array( 'تحديث المنتج', 'modify product', array( 'ignored' ) );
$GLOBALS['abilities'] = array( 'vendor/product-edit' => $a );
$aliases = MAD4B_SCP_Unified_Capability_Gateway::dispatch( array( 'action' => 'search', 'task' => 'تحديث المنتج' ) );
check_gateway( 1 === $aliases['count'] && 0 === $a->schema_reads && 2 === count( $aliases['items'][0]['search_aliases'] ), 'Bounded Arabic alias discovery loaded schemas or failed matching' );
echo "PASS bounded bilingual aliases remain metadata-only and non-authorizing\n";
