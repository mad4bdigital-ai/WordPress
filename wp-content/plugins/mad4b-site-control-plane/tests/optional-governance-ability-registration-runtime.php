<?php
/** Hermetic WordPress Abilities registration and category-contract canary. */
define( 'ABSPATH', __DIR__ );
class WP_Error {
    private $code; private $message;
    public function __construct( $code, $message ) { $this->code=$code; $this->message=$message; }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
$GLOBALS['actions'] = array();
$GLOBALS['registered_abilities'] = array();
$GLOBALS['registered_categories'] = array( 'mad4b-read'=>true );
function add_action( $event, $callback, $priority = 10 ) {
    $GLOBALS['actions'][$event][] = $callback;
}
function wp_has_ability( $name ) { return isset( $GLOBALS['registered_abilities'][$name] ); }
function wp_register_ability( $name, array $args ) {
    if ( empty( $GLOBALS['registered_categories'][$args['category'] ?? ''] ) ||
        ! is_callable( $args['execute_callback'] ?? null ) ||
        ! is_callable( $args['permission_callback'] ?? null ) ) return null;
    $GLOBALS['registered_abilities'][$name] = $args;
    return (object) $args;
}
function expect( $ok, $reason ) { if ( ! $ok ) throw new RuntimeException( $reason ); }
class MAD4B_SCP_Policy { public static function can_read() { return true; } }
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-optional-governance-remote.php';
$c = 'MAD4B_SCP_Optional_Governance_Remote';
$c::boot();
expect( count( $GLOBALS['actions']['wp_abilities_api_init'] ?? array() ) === 1,
    'Remote abilities did not hook into real WordPress Abilities lifecycle' );
foreach ( $GLOBALS['actions']['wp_abilities_api_init'] as $fn ) call_user_func( $fn );
expect( wp_has_ability($c::PLAN) && wp_has_ability($c::VERIFY),
    'Read Plan and Verify must register under pre-registered mad4b-read category' );
expect( ! wp_has_ability($c::APPLY),
    'Without a registered write category WordPress must fail closed; no phantom Apply' );
$GLOBALS['registered_categories']['mad4b-write'] = true;
foreach ( $GLOBALS['actions']['wp_abilities_api_init'] as $fn ) call_user_func( $fn );
expect( wp_has_ability($c::APPLY), 'Existing mad4b-write category must admit Apply registration' );
$apply=$GLOBALS['registered_abilities'][$c::APPLY];
expect( $apply['category']==='mad4b-write'
    && ($apply['meta']['mcp']['surface']??null)==='write'
    && ($apply['meta']['annotations']['readonly']??true)===false,
    'Apply category, MCP surface or write boundary metadata drifted' );
$required=$apply['input_schema']['required']??array();
expect( in_array('expected_plan_sha256',$required,true)
    && in_array('confirmation',$required,true)
    && empty( array_diff(array('policy_id','mode'),$required) ),
    'Apply must require exact plan SHA and typed confirmation' );
$direct = call_user_func($apply['execute_callback'],array(
    'policy_id'=>'developer_lane', 'mode'=>'off',
    'expected_plan_sha256'=>str_repeat('a',64), 'confirmation'=>$c::CONFIRM
));
expect( is_wp_error($direct) &&
    $direct->get_error_code()==='mad4b_optional_remote_claimed_execution_required',
    'Direct native callback without central execution claim must always deny' );
echo "OPTIONAL_ABILITY_REGISTRATION: PASS (category lifecycle, read/write mounting, direct-call denial)\n";
