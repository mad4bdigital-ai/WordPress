<?php
/* Adaptive route selection is evidence preparation, never mutation execution. */
define( 'ABSPATH', __DIR__ );
class WP_Error {
    private $code;
    public function __construct( $code, $message = '' ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function sanitize_key( $x ) { return strtolower( preg_replace( '/[^a-z0-9_-]/i', '', (string) $x ) ); }
function add_action( $hook, $callback, $priority = 10 ) {}
$GLOBALS['abilities'] = array();
function wp_register_ability( $name, $definition ) { $GLOBALS['abilities'][ $name ] = $definition; }
function wp_has_ability( $name ) { return isset( $GLOBALS['abilities'][ $name ] ); }
function require_route( $yes, $why ) {
    if ( ! $yes ) { fwrite( STDERR, 'FAIL: ' . $why . PHP_EOL ); exit( 1 ); }
}
class MAD4B_SCP_Policy {
    public static $can = true;
    public static function can_read() { return self::$can; }
}
class MAD4B_SCP_Servers {
    public static $base = array( 'mad4b/read-execute', 'mad4b/write-execute',
        'mad4b/enrollment-execute', 'mad4b/developer-execute' );
    public static function chatgpt_base_tools() { return self::$base; }
}
class MAD4B_SCP_Operation_Registry {
    public static function discover( $input ) {
        if ( $input['operation'] !== 'create_trip' )
            return new WP_Error( 'mad4b_operation_not_registered' );
        return array( 'planner' => 'example/trip-plan',
            'executor' => 'example/trip-apply' );
    }
}
class MAD4B_SCP_Unified_Capability_Gateway {
    public static function search( $input ) {
        if ( 'nonexistent' === $input['task'] ) return array( 'items' => array() );
        return array( 'items' => array(
            array( 'ability_name' => 'example/trip-apply',
                'relevance_score' => 20 ),
            array( 'ability_name' => 'example/trip-plan',
                'relevance_score' => 18 ),
        ) );
    }
    public static function prepare( $input, $transport = 'internal' ) {
        $items = array();
        foreach ( $input['ability_names'] as $name ) {
            $read = substr( $name, -5 ) === '-plan';
            $lane = $read ? 'read' : 'write';
            $items[] = array(
                'ability_name' => $name, 'execution_eligible' => true,
                'preparation_receipt_ready' => true,
                'preparation_receipt' => 'SIGNED_FIXTURE_RECEIPT_' . $name,
                'descriptor_sha256' => str_repeat( 'd', 64 ),
                'input_schema_sha256' => str_repeat( 'a', 64 ),
                'classification_sha256' => str_repeat( 'b', 64 ),
                'authority_scope_sha256' => str_repeat( 'c', 64 ),
                'classification' => $read ? 'read' : 'write',
                'execution' => array( 'state' => 'governed_dispatch',
                    'expected_execution_lane' => $lane,
                    'dispatch_tool' => $read ? 'mad4b/read-execute' : 'mad4b/write-execute',
                    'expected_input_schema_sha256' => str_repeat( 'a', 64 ),
                    'expected_classification_sha256' => str_repeat( 'b', 64 ) ),
            );
        }
        return array( 'abilities' => $items );
    }
}
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-adaptive-operation-router.php';
MAD4B_SCP_Adaptive_Operation_Router::register_abilities();
require_route( count( $GLOBALS['abilities'] ) === 2, 'two read-only router abilities' );
foreach ( $GLOBALS['abilities'] as $name => $ability ) {
    require_route( $ability['meta']['annotations']['readonly'] === true
        && !$ability['meta']['mcp']['public']
        && !$ability['input_schema']['additionalProperties'],
        'the planner has no mutation or public ungoverned API' );
}
$router = 'MAD4B_SCP_Adaptive_Operation_Router';
$read = $router::plan( array( 'ability_name' => 'example/trip-plan', 'intent' => 'read' ) );
require_route( $read['state'] === 'ROUTE_SELECTED_REQUIRES_EXECUTION_REVALIDATION'
    && $read['selected']['route'] === 'read-execute'
    && $read['selected']['dispatch_tool'] === 'mad4b/read-execute'
    && $read['selected']['preparation_receipt'] === 'SIGNED_FIXTURE_RECEIPT_example/trip-plan'
    && $read['authority_effect'] === 'none', 'read route uses canonical dispatcher and receipt' );
$write = $router::plan( array( 'ability_name' => 'example/trip-apply', 'intent' => 'write' ) );
require_route( $write['state'] === 'ROUTE_SELECTED_REQUIRES_EXECUTION_REVALIDATION'
    && $write['selected']['route'] === 'write-execute'
    && $write['selected']['client_tool_visibility'] === 'UNVERIFIED_UNTIL_HOST_TOOLS_LIST'
    && $write['requires_exact_owner_approval_for_mutations']
    && !$write['production_auto_write_enabled']
    && !$write['never_auto_executes'] === false,
    'write route metadata never issues permission or executes' );
$auto = $router::plan( array( 'task' => 'create trip', 'intent' => 'auto' ) );
require_route( $auto['selected']['ability_name'] === 'example/trip-apply',
    'dynamic semantic selection ranks actual registered metadata' );
$semantic = $router::plan( array( 'operation' => 'create_trip', 'intent' => 'read' ) );
require_route( $semantic['selected']['ability_name'] === 'example/trip-plan',
    'exact operation resolver uses registered planner rather than guessing names' );
$exec = $router::plan( array( 'operation' => 'create_trip', 'phase' => 'execute', 'intent' => 'write' ) );
require_route( $exec['selected']['ability_name'] === 'example/trip-apply',
    'exact static operation executor is described but NOT invoked' );
$unknown = $router::plan( array( 'operation' => 'delete_secret' ) );
require_route( is_wp_error( $unknown ), 'no invented semantic operation aliases' );
$no_result = $router::plan( array( 'task' => 'nonexistent' ) );
require_route( $no_result['state'] === 'NO_REGISTERED_MATCH',
    'unknown tasks return no fabricated adapter' );
$in_doubt = $router::plan( array(
    'task' => 'create trip', 'intent' => 'auto',
    'failed_transport' => 'write-execute', 'failure_code' => 'tool_internal_error' ) );
require_route( $in_doubt['state'] === 'PREVIOUS_WRITE_REQUIRES_INDEPENDENT_READBACK'
    && $in_doubt['selected'] === null,
    'a fallback read may not silently convert a failed write into a replay' );
$write_failed = $router::plan( array(
    'ability_name' => 'example/trip-apply', 'intent' => 'write',
    'failed_transport' => 'direct', 'failure_code' => 'tool_not_found' ) );
require_route( $write_failed['selected'] === null,
    'untrusted host-side tool failure report grants no automatic write reroute' );
$read_failed = $router::plan( array(
    'ability_name' => 'example/trip-plan', 'intent' => 'read',
    'failed_transport' => 'read-execute', 'failure_code' => 'connection_reset' ) );
require_route( $read_failed['selected'] === null,
    'failed read dispatcher requires discovery, not pretending another route is active' );
MAD4B_SCP_Servers::$base = array( 'mad4b/read-execute' );
$missing_write = $router::plan( array( 'ability_name' => 'example/trip-apply' ) );
require_route( $missing_write['state'] === 'NO_VERIFIED_ROUTE_AVAILABLE',
    'missing governed write transport is never upgraded to direct privileged mutation' );
MAD4B_SCP_Servers::$base = array( 'mad4b/read-execute', 'mad4b/write-execute' );
require_route( is_wp_error( $router::plan( array(
    'task' => 'a', 'ability_name' => 'example/trip-apply' ) ) ),
    'ambiguous task selection denied' );
require_route( is_wp_error( $router::plan( array(
    'ability_name' => 'mad4b/x', 'unexpected_transport' => 'breakglass' ) ) ),
    'unknown routing fields cannot inject privileged lanes' );
require_route( is_wp_error( $router::plan( array(
    'ability_name' => 'example/trip-apply', 'max_candidates' => 1000 ) ) ),
    'bounded candidate planning' );
MAD4B_SCP_Policy::$can = false;
require_route( is_wp_error( $router::plan( array( 'ability_name' => 'example/trip-plan' ) ) ),
    'canonical policy reader remains mandatory' );
echo "mad4b.site-control-plane.adaptive-operation-router.runtime.v1: PASS\n";
