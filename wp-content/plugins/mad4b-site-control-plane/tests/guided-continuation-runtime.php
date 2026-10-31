<?php
/** QA31: fail-closed guided continuation. Hermetic: no WP, DB, network or secrets. */
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );
class WP_Error {
    private $code;
    public function __construct( $code, $message = '' ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
$GLOBALS['qa31_read_allowed'] = true;
$GLOBALS['qa31_abilities'] = array();
$GLOBALS['qa31_registered'] = array();
function add_action( $hook, $callback, $priority = 10 ) { $GLOBALS['qa31_hook'] = $hook; }
function wp_has_ability( $name ) { return isset( $GLOBALS['qa31_registered'][$name] ); }
function wp_register_ability( $name, $spec ) { $GLOBALS['qa31_registered'][$name] = $spec; }
function wp_get_ability( $name ) { return $GLOBALS['qa31_abilities'][$name] ?? null; }
class MAD4B_SCP_Policy {
    public static function can_read() { return $GLOBALS['qa31_read_allowed']; }
}
class MAD4B_QA31_Read_Ability {
    public $calls = 0;
    public $input = null;
    public $result;
    public $category;
    public $meta;
    public $schema;
    public function __construct( $result, $category = 'mad4b-read', $readonly = true, $required = array() ) {
        $this->result = $result;
        $this->category = $category;
        $this->meta = array( 'annotations' => array( 'readonly' => $readonly ) );
        $this->schema = array( 'type' => 'object', 'required' => $required );
    }
    public function get_input_schema() { return $this->schema; }
    public function get_category() { return $this->category; }
    public function get_meta() { return $this->meta; }
    public function execute( $input ) { $this->calls++; $this->input = $input; return $this->result; }
}
function qa31_check( $ok, $reason ) {
    if ( ! $ok ) { fwrite( STDERR, 'FAIL: ' . $reason . "\n" ); exit( 1 ); }
}
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-guided-continuation.php';
$router = 'MAD4B_SCP_Guided_Continuation';
$router::boot();
$router::register_abilities();
$name = 'mad4b/guided-continuation-run';
qa31_check( ( $GLOBALS['qa31_hook'] ?? '' ) === 'wp_abilities_api_init'
    && isset( $GLOBALS['qa31_registered'][$name] ), 'read-only Ability registration present' );
$spec = $GLOBALS['qa31_registered'][$name];
qa31_check( ( $spec['permission_callback'][1] ?? '' ) === 'can_read'
    && ( $spec['meta']['public'] ?? true ) === false
    && ( $spec['meta']['annotations']['readonly'] ?? false ) === true
    && ( $spec['meta']['mcp']['surface'] ?? '' ) === 'read'
    && ( $spec['meta']['annotations']['destructive'] ?? true ) === false,
    'registered Ability remains private read-only and governed' );
$first = 'mad4b/runtime-convergence-status';
$second = 'mad4b/skills-runtime-certification';
$GLOBALS['qa31_abilities'] = array(
    $first => new MAD4B_QA31_Read_Ability( array( 'state' => 'pending', 'ready' => false, 'next_ability' => $second ) ),
    $second => new MAD4B_QA31_Read_Ability( array( 'state' => 'ready', 'ready' => true ) ),
);
$result = $router::run( array( 'next_ability' => $first, 'max_steps' => 3,
    'original_operation_sha256' => str_repeat( 'a', 64 ) ) );
qa31_check( $result['state'] === 'READY_FOR_ORIGINAL_WORKFLOW_RECHECK'
    && $result['step_count'] === 2 && $result['resume_original_request'] === true
    && $result['mutation_performed'] === false && $result['approval_created'] === false
    && $result['original_mutation_replayed'] === false
    && $result['fresh_receipt_and_exact_write_approval_required'] === true,
    'chained permitted reads never create ticket, mutation or replay' );
qa31_check( $GLOBALS['qa31_abilities'][$first]->calls === 1
    && $GLOBALS['qa31_abilities'][$second]->calls === 1
    && $GLOBALS['qa31_abilities'][$first]->input === array(),
    'underlying Ability executes only with empty input' );
$before = $GLOBALS['qa31_abilities'][$first]->calls;
$result = $router::run( array( 'next_ability' => 'mad4b/control-plane-selected-head-apply' ) );
qa31_check( $result['state'] === 'AWAITING_GOVERNED_ACTION'
    && $result['step_count'] === 0 && $result['mutation_performed'] === false
    && $GLOBALS['qa31_abilities'][$first]->calls === $before,
    'unsafe write handoff never executes' );
$result = $router::run( array( 'next_ability' => $first, 'max_steps' => 1 ) );
qa31_check( $result['state'] === 'READ_STEP_BUDGET_EXHAUSTED'
    && $result['step_count'] === 1 && $result['next_ability'] === $second,
    'strict step budget' );
$GLOBALS['qa31_abilities'][$first] = new MAD4B_QA31_Read_Ability( array( 'ready' => true ), 'mad4b-admin' );
$result = $router::run( array( 'next_ability' => $first ) );
qa31_check( $result['state'] === 'READ_CONTRACT_NOT_CERTIFIED'
    && $GLOBALS['qa31_abilities'][$first]->calls === 0, 'admin-labelled read denied' );
$GLOBALS['qa31_abilities'][$first] = new MAD4B_QA31_Read_Ability( array( 'ready' => true ), 'mad4b-read', false );
$result = $router::run( array( 'next_ability' => $first ) );
qa31_check( $result['state'] === 'READ_CONTRACT_NOT_CERTIFIED'
    && $GLOBALS['qa31_abilities'][$first]->calls === 0, 'non-readonly annotation denied' );
$GLOBALS['qa31_abilities'][$first] = new MAD4B_QA31_Read_Ability( array( 'ready' => true ), 'mad4b-read', true, array( 'token' ) );
$result = $router::run( array( 'next_ability' => $first ) );
qa31_check( $result['state'] === 'READ_CONTRACT_NOT_CERTIFIED'
    && $GLOBALS['qa31_abilities'][$first]->calls === 0, 'required-input Ability denied' );
$GLOBALS['qa31_abilities'][$first] = new MAD4B_QA31_Read_Ability( array( 'ready' => false, 'next_ability' => $second ) );
$GLOBALS['qa31_abilities'][$second] = new MAD4B_QA31_Read_Ability( array( 'ready' => false, 'next_ability' => $first ) );
$result = $router::run( array( 'next_ability' => $first, 'max_steps' => 3 ) );
qa31_check( $result['state'] === 'CYCLE_DETECTED' && $result['step_count'] === 2,
    'cycle detected with no repeated execution' );
$GLOBALS['qa31_abilities'][$first] = new MAD4B_QA31_Read_Ability( new WP_Error( 'fixture_denied' ) );
$result = $router::run( array( 'next_ability' => $first ) );
qa31_check( $result['state'] === 'READ_BLOCKED'
    && $result['steps'][0]['error_code'] === 'fixture_denied'
    && ! isset( $result['steps'][0]['token'] ), 'blocked read returns redacted code only' );
$GLOBALS['qa31_abilities'][$first] = new MAD4B_QA31_Read_Ability( array( 'ready' => true ) );
$GLOBALS['qa31_read_allowed'] = false;
$result = $router::run( array( 'next_ability' => $first ) );
qa31_check( is_wp_error( $result )
    && $result->get_error_code() === 'mad4b_guided_continuation_read_denied'
    && $GLOBALS['qa31_abilities'][$first]->calls === 0, 'denied policy prevents inspector execution' );
$GLOBALS['qa31_read_allowed'] = true;
$result = $router::run( array( 'next_ability' => $first, 'max_steps' => 4 ) );
qa31_check( is_wp_error( $result ) && $result->get_error_code() === 'mad4b_guided_continuation_identity_invalid',
    'oversized step budget rejected' );
$result = $router::run( array( 'next_ability' => $first, 'original_operation_sha256' => 'invalid' ) );
qa31_check( is_wp_error( $result ) && $result->get_error_code() === 'mad4b_guided_continuation_identity_invalid',
    'invalid original digest rejected' );
$result = $router::run( array( 'next_ability' => $first, 'approval_ticket' => 'forged' ) );
qa31_check( is_wp_error( $result ) && $result->get_error_code() === 'mad4b_guided_continuation_input_invalid',
    'extra approval field rejected' );
echo "PASS: Guided continuation bounded read-only chain, denial, cycle/step limits, no mutation\n";
