<?php
/**
 * Hermetic consumer check: first-party reviewed Enrollment Step-ups are
 * structurally projectable only with a registered callback census AND
 * the final runtime execution fence. No ability is invoked here.
 */
define( 'ABSPATH', __DIR__ . '/' );
class WP_Error {
    private $code;
    public function __construct( $code, $message = '' ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_-]/i', '', (string) $value ) ); }
function wp_has_ability( $name ) { return isset( $GLOBALS['tested_ability'] ) && $GLOBALS['tested_ability']->get_name() === $name; }
function wp_get_ability( $name ) { return wp_has_ability( $name ) ? $GLOBALS['tested_ability'] : null; }

class WP_Ability {
    private $name;
    private $meta;
    public function __construct( $name, array $meta ) { $this->name = $name; $this->meta = $meta; }
    public function get_name() { return $this->name; }
    public function get_meta() { return $this->meta; }
    public function set_meta( array $meta ) { $this->meta = $meta; }
    public function get_category() { return 'mad4b-enrollment'; }
    public function get_input_schema() { return array( 'type' => 'object', 'properties' => array() ); }
    public function get_output_schema() { return array( 'type' => 'object' ); }
    public function execute( $input = null ) { throw new RuntimeException( 'No execution is allowed in descriptor tests.' ); }
}
class MAD4B_SCP_Governed_Ability_Census {
    public static $reviewed = true;
    public static function structural_step_up() {
        return self::$reviewed ? array(
            'mad4b/full-staging-authority-apply' => array(
                'source' => 'verified_first_party_php_callbacks',
                'callback_pair_digest' => str_repeat( 'a', 64 ),
            ),
        ) : array();
    }
    public static function operator_grant_original_boundary_verified( $ability ) { return false; }
    public static function staging_owner_intent_original_boundary_verified( $ability ) { return false; }
    public static function chatgpt_projection_original_boundary_verified( $ability ) { return false; }
}
class MAD4B_SCP_Execution_Fence {
    public static $verified = true;
    public static function final_execution_wrapper_verified( $name ) {
        return self::$verified && 'mad4b/full-staging-authority-apply' === $name;
    }
}
class MAD4B_SCP_Authorization {
    public static function execution_boundary_verified( $ability ) { return false; }
}
class MAD4B_SCP_Servers {
    public static function provider_for_ability( $surface, $name ) {
        return in_array( $surface, array( 'mad4b-chatgpt', 'mad4b-enrollment' ), true ) ? 'core' : null;
    }
    public static function core_tools( $surface ) { return array(); }
}

require __DIR__ . '/../includes/class-mad4b-scp-ability-contract-inspector.php';
$name = 'mad4b/full-staging-authority-apply';
$meta = array(
    'annotations' => array( 'readonly' => false ),
    'mcp' => array(
        'surface' => 'enrollment',
        'chatgpt_direct_step_up' => true,
        'exact_chatgpt_client_required' => true,
        'generic_remote_admin' => false,
    ),
);
$GLOBALS['tested_ability'] = new WP_Ability( $name, $meta );
$assert = static function ( $condition, $message ) {
    if ( ! $condition ) throw new RuntimeException( $message );
};
$inspect = static function () use ( $name ) {
    $result = MAD4B_SCP_Ability_Contract_Inspector::inspect( $name );
    if ( is_wp_error( $result ) ) throw new RuntimeException( 'Descriptor inspection error: ' . $result->get_error_code() );
    return $result;
};
$ok = $inspect();
$assert( 'enrollment' === $ok['execution_lane'] && 'core' === $ok['execution_provider']
    && true === $ok['execution_eligible'] && true === $ok['execution_boundary_verified'],
    'Reviewed first-party final-fenced step-up must be structurally bound.' );

MAD4B_SCP_Execution_Fence::$verified = false;
$denied = $inspect();
$assert( 'none' === $denied['execution_lane'] && 'governed_execution_boundary_required' === $denied['execution_blocker'],
    'Missing final execution wrapper must fail closed.' );
MAD4B_SCP_Execution_Fence::$verified = true;

MAD4B_SCP_Governed_Ability_Census::$reviewed = false;
$denied = $inspect();
$assert( 'none' === $denied['execution_lane'] && false === $denied['execution_boundary_verified'],
    'Missing first-party callback-pair review must fail closed.' );
MAD4B_SCP_Governed_Ability_Census::$reviewed = true;

$changed = $meta; $changed['mcp']['exact_chatgpt_client_required'] = false;
$GLOBALS['tested_ability']->set_meta( $changed );
$assert( 'none' === $inspect()['execution_lane'], 'Missing exact ChatGPT client requirement must fail closed.' );
$changed = $meta; $changed['mcp']['generic_remote_admin'] = true;
$GLOBALS['tested_ability']->set_meta( $changed );
$assert( 'none' === $inspect()['execution_lane'], 'Generic admin execution is not an Enrollment step-up proof.' );
$GLOBALS['tested_ability']->set_meta( $meta );

$assert( false === isset( $ok['authorizing'] ), 'Inspector must not mint authority fields.' );
echo "REVIEWED_STEP_UP_DESCRIPTOR_RUNTIME: PASS (structural-only, 5 assertions, no invocation)\n";
