<?php
/** Hermetic dynamic registration and policy tests. Never alters WordPress. */
define( 'ABSPATH', __DIR__ . '/' );
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function wp_json_encode( $v, $flags = 0 ) { return json_encode( $v, $flags ); }
function sanitize_key( $v ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $v ) ); }
function sanitize_text_field( $v ) { return trim( (string) $v ); }
function absint( $v ) { return abs( (int) $v ); }
function current_user_can( $cap ) { return 'manage_options' === $cap; }
function get_current_user_id() { return 7; }
class WP_Error {
    private $code;
    public function __construct( $code, $message = '', $data = null ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
class WP_Ability {
    protected $name, $meta, $input_schema, $execute_callback, $permission_callback;
    public $calls = 0;
    public static $test_result = null;
    public function __construct( $name, array $definition ) {
        $this->name = $name;
        $this->meta = $definition['meta'] ?? array();
        $this->input_schema = $definition['input_schema'] ?? array();
        $this->execute_callback = $definition['execute_callback'] ?? null;
        $this->permission_callback = $definition['permission_callback'] ?? null;
    }
    public function get_name() { return $this->name; }
    public function get_meta() { return $this->meta; }
    public function get_input_schema() { return $this->input_schema; }
    public function execute( $payload = null ) {
        ++$this->calls;
        // Stub never executes real WordPress callbacks; optionally simulate a
        // redacted target error to verify the governed transport contract.
        if ( null !== self::$test_result ) return self::$test_result;
        return array( 'state' => 'stub_only', 'mutation_performed' => false );
    }
}
$GLOBALS['registered'] = array();
$GLOBALS['discovery_filter_hides_all'] = false;
class WP_Abilities_Registry {
    public static function get_instance() { return new self(); }
    public function get_all_registered() { return $GLOBALS['registered']; }
}
function wp_has_ability( $name ) { return isset( $GLOBALS['registered'][ $name ] ); }
function wp_get_ability( $name ) { return $GLOBALS['registered'][ $name ] ?? null; }
function wp_get_abilities() {
    return $GLOBALS['discovery_filter_hides_all'] ? array() : $GLOBALS['registered'];
}
function wp_register_ability( $name, array $args ) {
    $GLOBALS['registered'][ $name ] = new WP_Ability( $name, $args );
    return $GLOBALS['registered'][ $name ];
}
function mad4b_check( $v, $reason ) {
    if ( ! $v ) { fwrite( STDERR, "DYNAMIC_CENSUS_FAIL: " . $reason . "\n" ); exit( 1 ); }
}
class MAD4B_SCP_Site_Profile {
    public static $env = 'staging';
    public static function configured() { return true; }
    public static function current_environment() { return self::$env; }
    public static function origin_enrolled() { return true; }
    public static function site_urls_match_enrollment() { return true; }
    public static function user_is_enrolled( $user ) { return 7 === $user; }
}
class MAD4B_SCP_Policy { public static function can_breakglass() { return false; } }
class MAD4B_SCP_Local_OAuth_Server { const CHATGPT_CIMD_CLIENT_ID = 'https://chatgpt.com/oauth/client.json'; }
class MAD4B_SCP_OAuth_Resource_Bridge {
    const AUTHORITY_STEP_UP_SCOPE = 'mad4b:authority:step-up';
    public static $scope = true;
    public static $client = true;
    public static function verified_bearer_active() { return true; }
    public static function verified_bearer_has_scope( $scope ) {
        return self::$scope && self::AUTHORITY_STEP_UP_SCOPE === $scope;
    }
    public static function verified_bearer_client_is( $id ) {
        return self::$client && MAD4B_SCP_Local_OAuth_Server::CHATGPT_CIMD_CLIENT_ID === $id;
    }
}
class MAD4B_SCP_Remote_Operation_Parity {
    public static function catalog() { return array(); }
    public static function enrollment_abilities() { return array(); }
    public static function reviewed_owner_staging_convergence( $id, $row ) { return false; }
}
class MAD4B_SCP_Servers {
    public static function ability_is_mounted( $surface, $ability ) {
        return 'mad4b-enrollment' === $surface && wp_has_ability( $ability );
    }
}
class MAD4B_Test_Untrusted {
    public static function exec( $input ) { return array(); }
    public static function permission( $input ) { return true; }
}
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-staging-write-owner-intent.php';
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-enrollment-dispatch.php';
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-error-contract-registry.php';

MAD4B_SCP_Staging_Write_Owner_Intent::register_ability();
$owner = MAD4B_SCP_Staging_Write_Owner_Intent::ATTEST_ABILITY;
// Unrelated plugin registrations must not globally disable MAD4B discovery.
for ( $n = 0; $n < MAD4B_SCP_Governed_Ability_Census::MAX_ABILITIES + 20; ++$n ) {
    $name = 'unrelated/plugin-' . $n;
    $GLOBALS['registered'][ $name ] = new WP_Ability( $name, array() );
}
mad4b_check( isset( MAD4B_SCP_Governed_Ability_Census::eligible()[ $owner ] ),
    'Unrelated WordPress abilities unexpectedly blocked owner discovery' );
$GLOBALS['discovery_filter_hides_all'] = true;
mad4b_check( isset( MAD4B_SCP_Governed_Ability_Census::eligible()[ $owner ] ),
    'WP 7.1 discovery-result filters improperly controlled owner broker admission' );
$GLOBALS['discovery_filter_hides_all'] = false;
foreach ( array_keys( $GLOBALS['registered'] ) as $name ) {
    if ( 0 === strpos( $name, 'unrelated/' ) ) unset( $GLOBALS['registered'][ $name ] );
}
$pre = MAD4B_SCP_Governed_Ability_Census::eligible();
mad4b_check( isset( $pre[ $owner ] ), 'registered core owner ability not detected' );
mad4b_check( $pre[ $owner ]['owner_intent_only'], 'owner intent not classified correctly' );
$discovery = MAD4B_SCP_Enrollment_Dispatch::discover( array( 'query' => 'owner attest' ) );
mad4b_check( 1 === $discovery['count'], 'dynamic owner enrollment not discoverable' );
$entry = current( $discovery['operations'] );
mad4b_check( $owner === $entry['remote_ability'] && ! empty( $entry['human_decision_required'] ),
    'discovery stripped the owner gate' );
mad4b_check( $entry['operation_id'] === $pre[ $owner ]['operation_id'],
    'census and broker operation IDs diverged' );
$info = MAD4B_SCP_Enrollment_Dispatch::info( array( 'operation_id' => $entry['operation_id'] ) );
mad4b_check( ! is_wp_error( $info ) && $info['remote_ability'] === $owner,
    'info did not resolve exact registered ability' );
// The first-party Owner Intent lane must report a bounded unknown outcome
// rather than handing WP_Error to the MCP transport after a possible write.
class MAD4B_SCP_Execution_Fence {
    public static function with_governed_child( $name, $payload, $callback, $reason = '' ) {
        return call_user_func( $callback );
    }
}
WP_Ability::$test_result = new WP_Error( 'mad4b_owner_review_mismatch', 'SENSITIVE_ATTEST_MESSAGE',
    array( 'secret' => 'PRIVATE_ATTEST_TOKEN' ) );
$owner_error_response = MAD4B_SCP_Enrollment_Dispatch::execute( array(
    'operation_id' => $entry['operation_id'],
    'expected_registration_digest' => $info['registration_digest'],
    'expected_dispatch_policy_digest' => $info['dispatch_policy_digest'],
    'expected_input_schema_sha256' => $info['input_schema_sha256'],
    'input' => array( 'review_digest' => str_repeat( 'a', 64 ),
        'confirmation' => 'AUTHORIZE EXACT STAGING WRITE CONVERGENCE' ),
) );
mad4b_check( is_array( $owner_error_response )
    && 'target_error_reconciliation_required' === $owner_error_response['state']
    && $owner_error_response['target_error_code'] === 'mad4b_owner_review_mismatch'
    && $owner_error_response['human_decision_required']
    && $owner_error_response['reconciliation_required']
    && $owner_error_response['authority_not_proven']
    && false === $owner_error_response['blind_retry_allowed']
    && null === $owner_error_response['mutation_performed']
    && ! isset( $owner_error_response['result'] )
    && ! array_key_exists( 'operation_succeeded', $owner_error_response )
    && false === strpos( wp_json_encode( $owner_error_response ), 'SENSITIVE_ATTEST_MESSAGE' )
    && false === strpos( wp_json_encode( $owner_error_response ), 'PRIVATE_ATTEST_TOKEN' ),
    'Dynamic first-party owner WP_Error was not a bounded unknown outcome' );
WP_Ability::$test_result = null;
// Real WordPress wraps each original MAD4B callable through audit and
// execution-admission closures, rather than retaining the raw class method.
$unwrapped = $GLOBALS['registered'][ $owner ];
$original_meta = $unwrapped->get_meta();
$wrapped = MAD4B_SCP_Error_Contract_Registry::wrap_ability_args( array(
    'execute_callback' => array( 'MAD4B_SCP_Staging_Write_Owner_Intent', 'attest' ),
    'permission_callback' => array( 'MAD4B_SCP_Staging_Write_Owner_Intent', 'can_remote_attest' ),
    'meta' => $original_meta,
), $owner );
$twice = MAD4B_SCP_Error_Contract_Registry::wrap_ability_args( $wrapped, $owner );
$GLOBALS['registered'][ $owner ] = new WP_Ability( $owner, array(
    'meta' => $twice['meta'], 'input_schema' => $unwrapped->get_input_schema(),
    'execute_callback' => $twice['execute_callback'],
    'permission_callback' => $twice['permission_callback'],
) );
mad4b_check( isset( MAD4B_SCP_Governed_Ability_Census::structural_step_up()[ $owner ] ),
    'Genuine nested first-party registration wrappers disappeared from tools/list' );
mad4b_check( isset( MAD4B_SCP_Governed_Ability_Census::eligible()[ $owner ] ),
    'Nested audit wrappers incorrectly blocked reviewed owner Enrollment' );
$wrapped_info = MAD4B_SCP_Enrollment_Dispatch::info( array( 'operation_id' => $entry['operation_id'] ) );
mad4b_check( ! is_wp_error( $wrapped_info )
    && $wrapped_info['registration_digest'] !== $info['registration_digest'],
    'Wrapped callback chain was not bound into the registration digest' );
$GLOBALS['registered'][ $owner ] = $unwrapped;
// A malicious wrapper from a third-party or test directory cannot pass
// merely because it captures a real first-party method.
$external = static function ( $input = null ) use ( $unwrapped ) {
    return array( 'not_authorized' => true );
};
$GLOBALS['registered'][ $owner ] = new WP_Ability( $owner, array(
    'meta' => $original_meta, 'input_schema' => $unwrapped->get_input_schema(),
    'execute_callback' => $external,
    'permission_callback' => array( 'MAD4B_SCP_Staging_Write_Owner_Intent', 'can_remote_attest' ),
) );
mad4b_check( '' === MAD4B_SCP_Governed_Ability_Census::operation_id_for( $owner ),
    'Untrusted third-party callback wrapper gained Enrollment admission' );
$GLOBALS['registered'][ $owner ] = $unwrapped;
// Swapping two *trusted* plugin callbacks must invalidate the registration
// identity even when the ability name, metadata and schema remain unchanged.
$original = $GLOBALS['registered'][ $owner ];
$GLOBALS['registered'][ $owner ] = new WP_Ability( $owner, array(
    'meta' => $original->get_meta(), 'input_schema' => $original->get_input_schema(),
    'execute_callback' => array( 'MAD4B_SCP_Staging_Write_Owner_Intent', 'diagnose' ),
    'permission_callback' => array( 'MAD4B_SCP_Staging_Write_Owner_Intent', 'can_remote_attest' ),
) );
$changed = MAD4B_SCP_Enrollment_Dispatch::info( array( 'operation_id' => $entry['operation_id'] ) );
mad4b_check( ! is_wp_error( $changed ) && ! hash_equals(
    (string) $info['registration_digest'], (string) $changed['registration_digest'] ),
    'First-party callback replacement did not invalidate the enrollment registration digest' );
$GLOBALS['registered'][ $owner ] = $original;
$input = array( 'operation_id' => $entry['operation_id'] );
mad4b_check( true === MAD4B_SCP_Enrollment_Dispatch::can_execute( $input ),
    'verified owner session was not admitted into target permission path' );
MAD4B_SCP_OAuth_Resource_Bridge::$scope = false;
mad4b_check( 'mad4b_enrollment_dispatch_step_up_scope_required' ===
    MAD4B_SCP_Enrollment_Dispatch::can_execute( $input )->get_error_code(),
    'missing Step-up scope was allowed' );
MAD4B_SCP_OAuth_Resource_Bridge::$scope = true;
MAD4B_SCP_OAuth_Resource_Bridge::$client = false;
mad4b_check( 'mad4b_enrollment_dispatch_chatgpt_client_required' ===
    MAD4B_SCP_Enrollment_Dispatch::can_execute( $input )->get_error_code(),
    'foreign OAuth client was allowed' );
MAD4B_SCP_OAuth_Resource_Bridge::$client = true;
// A separately governed direct-only runtime control must not become a generic
// Staging enrollment operation, even when callbacks are first-party.
$direct_name = 'mad4b/direct-runtime-control';
$direct_meta = $GLOBALS['registered'][ $owner ]->get_meta();
$direct_meta['mcp']['direct_only_excluded_from_staging_broker'] = true;
$direct_meta['mcp']['production_allowed'] = true;
$direct_meta['mcp']['generic_raw_sql_breakglass_configuration'] = true;
$GLOBALS['registered'][ $direct_name ] = new WP_Ability( $direct_name, array(
    'meta' => $direct_meta,
    'input_schema' => $GLOBALS['registered'][ $owner ]->get_input_schema(),
    'execute_callback' => array( 'MAD4B_SCP_Staging_Write_Owner_Intent', 'attest' ),
    'permission_callback' => array( 'MAD4B_SCP_Staging_Write_Owner_Intent', 'can_remote_attest' ),
) );
mad4b_check( isset( MAD4B_SCP_Governed_Ability_Census::direct_only()[ $direct_name ] ),
    'independent direct-only governance lane was lost' );
mad4b_check( ! isset( MAD4B_SCP_Governed_Ability_Census::eligible()[ $direct_name ] ),
    'direct-only runtime control leaked into Staging broker' );
$all = MAD4B_SCP_Enrollment_Dispatch::discover( array( 'query' => 'direct runtime' ) );
mad4b_check( 0 === $all['count'], 'direct-only operation appeared in generic enrollment discovery' );
MAD4B_SCP_Site_Profile::$env = 'production';
// MCP registration is an early structural read, not a permission decision.
// The owner candidate must remain discoverable while execution stays denied.
mad4b_check( isset( MAD4B_SCP_Governed_Ability_Census::structural_step_up()[ $owner ] ),
    'Early tools/list lost trusted owner structural registration' );
mad4b_check( array() === MAD4B_SCP_Governed_Ability_Census::eligible(),
    'Staging broker became available on Production' );
mad4b_check( isset( MAD4B_SCP_Governed_Ability_Census::direct_only()[ $direct_name ] ),
    'independent direct-only projection wrongly bound to Staging' );
MAD4B_SCP_Site_Profile::$env = 'staging';
unset( $GLOBALS['registered'][ $direct_name ] );
$schema = $GLOBALS['registered'][ $owner ]->get_input_schema();
$meta = $GLOBALS['registered'][ $owner ]->get_meta();
// Generic direct Step-up exposure is not permission to use Enrollment broker.
$bad = $meta;
unset( $bad['mcp']['governed_enrollment_broker'] );
$GLOBALS['registered'][ $owner ] = new WP_Ability( $owner, array(
    'meta' => $bad, 'input_schema' => $schema,
    'execute_callback' => array( 'MAD4B_SCP_Staging_Write_Owner_Intent', 'attest' ),
    'permission_callback' => array( 'MAD4B_SCP_Staging_Write_Owner_Intent', 'can_remote_attest' ),
) );
mad4b_check( '' === MAD4B_SCP_Governed_Ability_Census::operation_id_for( $owner ),
    'Unreviewed direct Step-up leaked into the generic Enrollment broker' );
mad4b_check( isset( MAD4B_SCP_Governed_Ability_Census::structural_step_up()[ $owner ] ),
    'Safe structural discovery was incorrectly coupled to broker eligibility' );
$bad = $meta;
$bad['mcp']['production_mutation_allowed'] = true;
$GLOBALS['registered'][ $owner ] = new WP_Ability( $owner, array(
    'meta' => $bad, 'input_schema' => $schema,
    'execute_callback' => array( 'MAD4B_SCP_Staging_Write_Owner_Intent', 'attest' ),
    'permission_callback' => array( 'MAD4B_SCP_Staging_Write_Owner_Intent', 'can_remote_attest' ),
) );
mad4b_check( '' === MAD4B_SCP_Governed_Ability_Census::operation_id_for( $owner ),
    'Production-allowed candidate was trusted' );
$bad = $meta;
$bad['mcp']['production_mutation_allowed'] = false;
$bad['mcp']['chatgpt_direct_step_up'] = false;
$GLOBALS['registered'][ $owner ] = new WP_Ability( $owner, array(
    'meta' => $bad, 'input_schema' => $schema,
    'execute_callback' => array( 'MAD4B_SCP_Staging_Write_Owner_Intent', 'attest' ),
    'permission_callback' => array( 'MAD4B_SCP_Staging_Write_Owner_Intent', 'can_remote_attest' ),
) );
mad4b_check( '' === MAD4B_SCP_Governed_Ability_Census::operation_id_for( $owner ),
    'Candidate without direct-step-up declaration was trusted' );
$GLOBALS['registered'][ $owner ] = new WP_Ability( $owner, array(
    'meta' => $meta, 'input_schema' => $schema,
    'execute_callback' => array( 'MAD4B_Test_Untrusted', 'exec' ),
    'permission_callback' => array( 'MAD4B_Test_Untrusted', 'permission' ),
) );
mad4b_check( '' === MAD4B_SCP_Governed_Ability_Census::operation_id_for( $owner ),
    'Foreign callbacks spoofed core metadata' );
MAD4B_SCP_Site_Profile::$env = 'production';
mad4b_check( array() === MAD4B_SCP_Governed_Ability_Census::eligible(),
    'Production did not fail closed' );
mad4b_check( 0 === $GLOBALS['registered'][ $owner ]->calls, 'Discovery executed mutation' );
echo "DYNAMIC_FIRST_PARTY_ENROLLMENT_RUNTIME_PASS\n";
