<?php
// Behavioral contracts with external WordPress services replaced, production
// discovery/classification/dispatch code unchanged. Real WP parity runs in CI too.
define( 'ABSPATH', __DIR__ );
class WP_Error {
 private $code; private $message; private $data;
 function __construct( $code, $message = '', $data = array() ) { $this->code = $code; $this->message = $message; $this->data = $data; }
 function get_error_code() { return $this->code; }
 function get_error_message() { return $this->message; }
 function get_error_data() { return $this->data; }
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
class MAD4B_SCP_Authorization {
 static function execution_boundary_verified( $ability ) { return $ability->boundary; }
 static function begin_execution_callback_observation( $name ) { $GLOBALS['observation_started'][$name] = false; }
 static function execution_callback_started( $name ) { return ! empty( $GLOBALS['observation_started'][$name] ); }
 static function clear_execution_callback_observation( $name ) { ++$GLOBALS['observation_clear_calls']; unset( $GLOBALS['observation_started'][$name] ); }
}
class MAD4B_SCP_Identity_Context {
 static function with_approval_ticket_for_request( $id, $callback ) {
  ++$GLOBALS['approval_scope_calls'];
  $previous = $GLOBALS['approval_scope_active'];
  if ( '' !== $previous && $previous !== $id ) return new WP_Error( 'approval_rebind' );
  $GLOBALS['approval_scope_active'] = $id;
  try { return $callback(); } finally { $GLOBALS['approval_scope_active'] = $previous; }
 }
}
class MAD4B_SCP_Connector_Resilience {
 const CONTRACT = 'fixture';
 static function execute_mutation( $lane, $name, $callback ) { $v = $callback(); return is_wp_error( $v ) ? $v : array( 'result' => $v ); }
 static function execute_read( $name, $callback ) { $v = $callback(); return is_wp_error( $v ) ? $v : array( 'result' => $v ); }
}
class MAD4B_SCP_Replay_Policy {
 const CONTRACT = 'mad4b.replay-policy.v1';
 static function policy_sha256() { return hash( 'sha256', 'gateway-regression-replay-policy-fixture-v1' ); }
 static function begin( $preparation_receipt, $ability_name, $provider, $target_input, $idempotency_key = '' ) {
  if ( ! class_exists( 'MAD4B_SCP_Preparation_Receipt' ) || ! method_exists( 'MAD4B_SCP_Preparation_Receipt', 'claims' ) ) return new WP_Error( 'fixture_replay_claims_unavailable' );
  $claims = MAD4B_SCP_Preparation_Receipt::claims( $preparation_receipt, $ability_name );
  if ( is_wp_error( $claims ) ) return $claims;
  $policy_sha = self::policy_sha256();
  if ( empty( $claims['replay_policy_sha256'] ) || ! hash_equals( $policy_sha, (string) $claims['replay_policy_sha256'] ) ) return new WP_Error( 'fixture_replay_policy_binding_mismatch' );
  ++$GLOBALS['replay_begin_calls'];
  return array(
   'contract' => self::CONTRACT,
   'replayed' => false,
   'mode' => 'single_use',
   'risk_tier' => 'fixture',
   'policy_sha256' => $policy_sha,
   'authorizing' => false,
  );
 }
 static function complete( array $admission, $dispatch_result ) {
  if ( empty( $admission['policy_sha256'] ) || ! hash_equals( self::policy_sha256(), (string) $admission['policy_sha256'] ) ) return new WP_Error( 'fixture_replay_completion_binding_mismatch' );
  ++$GLOBALS['replay_complete_calls'];
  return array( 'contract' => self::CONTRACT, 'completed' => true, 'authorizing' => false );
 }
}
class MAD4B_SCP_Abuse_Budget {
 const CONTRACT = 'mad4b.abuse-budget.fixture.v1';
 static function admit( $surface, $input = array() ) {
  ++$GLOBALS['abuse_admit_calls'];
  if ( 'prepare' !== $surface ) return new WP_Error( 'fixture_abuse_surface_unknown' );
  return array( 'contract' => self::CONTRACT, 'surface' => $surface, 'authorizing' => false );
 }
}
class MAD4B_SCP_Transport_Context { static function with_write_dispatch_target( $name, $digest, $callback ) { return $callback(); } }
class MAD4B_SCP_Staging_Write_Planning_Guard {
 const ABILITY = 'mad4b/approval-plan';
 static function canonicalize_remote_plan_input( $input ) { return $input; }
 static function validate_remote_plan_input( $input ) { return true; }
}
class MAD4B_SCP_OAuth_Resource_Bridge { static function verified_bearer_active() { return $GLOBALS['bearer']; } }
class GatewayFixture {
 public $aliases = array(); public $boundary = true; public $permission = true; public $lane; public $readonly; public $calls = 0; public $schema_reads = 0; public $throw_exception = false; public $error_code = '';
 private $name;
 function __construct( $name, $lane, $readonly ) { $this->name = $name; $this->lane = $lane; $this->readonly = $readonly; }
 function get_name() { return $this->name; }
 function get_meta() { return array( 'annotations' => array( 'readonly' => $this->readonly ), 'mcp' => array( 'surface' => $this->lane, 'search_aliases' => $this->aliases ) ); }
 function get_input_schema() { ++$this->schema_reads; return array( 'type' => 'object' ); }
 function get_output_schema() { ++$this->schema_reads; return array( 'type' => 'object' ); }
 function get_category() { return 'fixture'; }
 function get_label() { return 'Metadata fixture'; }
 function get_description() { return 'Schema must remain lazy'; }
 function execute( $input = null ) {
  if ( ! $this->permission ) return new WP_Error( 'permission_denied' );
  if ( is_array( $input ) && ! empty( $input['_mad4b_approval_ticket_id'] ) && $GLOBALS['approval_scope_active'] !== $input['_mad4b_approval_ticket_id'] ) return new WP_Error( 'approval_scope_missing' );
  $GLOBALS['observation_started'][$this->name] = true;
  if ( $this->throw_exception ) throw new RuntimeException( 'fixture planner exception' );
  if ( '' !== $this->error_code ) return new WP_Error( $this->error_code );
  ++$this->calls; return array( 'ok' => true );
 }
}
$GLOBALS['blog'] = 1; $GLOBALS['read_allowed'] = true; $GLOBALS['bearer'] = false; $GLOBALS['mounted'] = array(); $GLOBALS['abilities'] = array(); $GLOBALS['approval_scope_calls'] = 0; $GLOBALS['approval_scope_active'] = ''; $GLOBALS['observation_started'] = array(); $GLOBALS['observation_clear_calls'] = 0; $GLOBALS['replay_begin_calls'] = 0; $GLOBALS['replay_complete_calls'] = 0; $GLOBALS['abuse_admit_calls'] = 0;
require __DIR__ . '/../includes/class-mad4b-scp-identifiers.php';
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
 check_gateway( 0 < $GLOBALS['abuse_admit_calls'], 'Preparation fixture bypassed abuse-budget admission: ' . $lane );
 $input = array_merge( array( 'ability_name' => $name, 'input' => array() ), $identity );
 $approval_id = '00000000-0000-4000-8000-000000000001';
 $invalid_permission = $input; $invalid_permission['preparation_receipt'] .= '0'; $invalid_permission['_mad4b_approval_ticket_id'] = $approval_id;
 $scope_before = $GLOBALS['approval_scope_calls'];
 check_gateway( is_wp_error( $dispatcher->can_write_dispatch( $invalid_permission ) ) && $scope_before === $GLOBALS['approval_scope_calls'] && '' === $GLOBALS['approval_scope_active'], 'Invalid preparation entered approval execution scope during permission admission' );
 $valid_permission = $input; $valid_permission['_mad4b_approval_ticket_id'] = $approval_id;
 check_gateway( true === $dispatcher->can_write_dispatch( $valid_permission ) && $scope_before === $GLOBALS['approval_scope_calls'] && '' === $GLOBALS['approval_scope_active'], 'Canonical approval fixture failed permission admission or leaked approval identity before target execution' );
 $oversized_permission = $input;
 $oversized_permission['_mad4b_context_receipt'] = array( 'payload' => str_repeat( 'x', MAD4B_SCP_Abilities::MAX_WRITE_DISPATCH_CONTEXT_RECEIPT_BYTES + 1 ) );
 $oversized_scope_before = $GLOBALS['approval_scope_calls'];
 $oversized_result = $dispatcher->can_write_dispatch( $oversized_permission );
 check_gateway( is_wp_error( $oversized_result ) && 'mad4b_write_dispatch_context_receipt_oversized' === $oversized_result->get_error_code() && $oversized_scope_before === $GLOBALS['approval_scope_calls'], 'Oversized Context Receipt entered approval execution scope' );
 $input = $valid_permission;
 check_gateway( ! is_wp_error( $dispatcher->write_execute( $input ) ) && 1 === $a->calls && $scope_before + 1 === $GLOBALS['approval_scope_calls'] && '' === $GLOBALS['approval_scope_active'], 'Valid original lane failed scoped approval execution or leaked approval identity: ' . $lane );
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

// Permission state from an abandoned prepared target must never be reusable by
// another write target in the same PHP request.
$first = new GatewayFixture( 'fixture/stale-a', 'content', false );
$second = new GatewayFixture( 'fixture/stale-b', 'content', false );
$GLOBALS['abilities']['fixture/stale-a'] = $first;
$GLOBALS['abilities']['fixture/stale-b'] = $second;
foreach ( array( 'fixture/stale-a', 'fixture/stale-b' ) as $stale_name ) {
 $GLOBALS['mounted']['mad4b-content'][$stale_name] = true;
 $GLOBALS['mounted']['mad4b-write'][$stale_name] = true;
}
$first_identity = prepared_dispatch_identity( 'fixture/stale-a' );
$second_identity = prepared_dispatch_identity( 'fixture/stale-b' );
$stale_approval = '00000000-0000-4000-8000-000000000099';
$first_input = array_merge( array( 'ability_name' => 'fixture/stale-a', 'input' => array( 'alpha' => 1, 'beta' => 2 ), '_mad4b_approval_ticket_id' => $stale_approval ), $first_identity );
$second_input = array_merge( array( 'ability_name' => 'fixture/stale-b', 'input' => array() ), $second_identity );
check_gateway( true === $dispatcher->can_write_dispatch( $first_input ), 'First prepared target failed governance capture' );
$reordered_payload = $first_input;
unset( $reordered_payload['_mad4b_approval_ticket_id'] );
$reordered_payload['input'] = array( 'beta' => 2, 'alpha' => 1 );
check_gateway( true === $dispatcher->can_write_dispatch( $reordered_payload ), 'Semantically identical reordered mutation input produced false governance drift' );
$changed_payload = $first_input;
unset( $changed_payload['_mad4b_approval_ticket_id'] );
$changed_payload['input'] = array( 'alpha' => 1, 'beta' => 3 );
$cross_payload = $dispatcher->can_write_dispatch( $changed_payload );
check_gateway( is_wp_error( $cross_payload ) && 'mad4b_write_dispatch_governance_target_conflict' === $cross_payload->get_error_code(), 'Abandoned governance envelope crossed into different mutation input for the same prepared Ability' );
$cross_target = $dispatcher->can_write_dispatch( $second_input );
check_gateway( is_wp_error( $cross_target ) && 'mad4b_write_dispatch_governance_target_conflict' === $cross_target->get_error_code(), 'Abandoned governance envelope crossed into another prepared target' );
$execution_rebind = $first_input;
$execution_rebind['_mad4b_approval_ticket_id'] = '00000000-0000-4000-8000-000000000098';
$execution_rebind_result = $dispatcher->write_execute( $execution_rebind );
check_gateway(
 is_wp_error( $execution_rebind_result )
 && 'mad4b_write_dispatch_governance_envelope_rebind_conflict' === $execution_rebind_result->get_error_code()
 && 0 === $first->calls
 && '' === $GLOBALS['approval_scope_active'],
 'Execution phase rebound governance evidence after permission admission'
);
// The conflicting attempt consumes the request-local capture fail-closed. Re-run
// permission admission with the reviewed envelope before the legitimate execution.
check_gateway( true === $dispatcher->can_write_dispatch( $first_input ), 'Reviewed governance envelope could not be recaptured after rejected execution rebind' );
$first_execution = $dispatcher->write_execute( $first_input );
check_gateway( ! is_wp_error( $first_execution ) && 1 === $first->calls && '' === $GLOBALS['approval_scope_active'], 'Original governance target could not safely consume its captured evidence' );

// Approval-plan callback observation must be cleared on target errors and
// exceptions so a later dispatcher in the same PHP request cannot inherit stale state.
$planner = new GatewayFixture( 'mad4b/approval-plan', 'admin', false );
$GLOBALS['abilities']['mad4b/approval-plan'] = $planner;
$GLOBALS['mounted']['mad4b-admin']['mad4b/approval-plan'] = true;
$GLOBALS['mounted']['mad4b-write']['mad4b/approval-plan'] = true;
$planner_identity = prepared_dispatch_identity( 'mad4b/approval-plan' );
check_gateway( ! is_wp_error( $planner_identity ), 'Approval-plan signed preparation failed' );
$planner_input = array_merge( array( 'ability_name' => 'mad4b/approval-plan', 'input' => array() ), $planner_identity );

$planner->error_code = 'fixture_planner_error';
check_gateway( true === $dispatcher->can_write_dispatch( $planner_input ), 'Approval-plan error fixture failed permission admission' );
$clear_before = $GLOBALS['observation_clear_calls'];
$planner_error = $dispatcher->write_execute( $planner_input );
check_gateway(
 is_wp_error( $planner_error )
 && 'mad4b_approval_plan_dispatch_target_error' === $planner_error->get_error_code()
 && $clear_before + 1 === $GLOBALS['observation_clear_calls']
 && ! isset( $GLOBALS['observation_started']['mad4b/approval-plan'] ),
 'Approval-plan target error leaked execution callback observation state'
);

$planner->error_code = '';
$planner->throw_exception = true;
check_gateway( true === $dispatcher->can_write_dispatch( $planner_input ), 'Approval-plan exception fixture failed permission admission' );
$clear_before = $GLOBALS['observation_clear_calls'];
$planner_exception = $dispatcher->write_execute( $planner_input );
check_gateway(
 is_wp_error( $planner_exception )
 && 'mad4b_approval_plan_dispatch_exception' === $planner_exception->get_error_code()
 && $clear_before + 1 === $GLOBALS['observation_clear_calls']
 && ! isset( $GLOBALS['observation_started']['mad4b/approval-plan'] ),
 'Approval-plan exception leaked execution callback observation state'
);

$planner->throw_exception = false;
check_gateway( true === $dispatcher->can_write_dispatch( $planner_input ), 'Approval-plan success fixture failed permission admission after prior failures' );
$clear_before = $GLOBALS['observation_clear_calls'];
$planner_success = $dispatcher->write_execute( $planner_input );
check_gateway(
 ! is_wp_error( $planner_success )
 && 1 === $planner->calls
 && $clear_before + 1 === $GLOBALS['observation_clear_calls']
 && ! isset( $GLOBALS['observation_started']['mad4b/approval-plan'] ),
 'Approval-plan did not recover cleanly after prior failed executions'
);

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
