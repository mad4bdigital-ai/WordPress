<?php
if ( PHP_VERSION_ID < 70400 ) {
    fwrite( STDERR, "FAIL: PHP 7.4+ required\n" );
    exit( 1 );
}

define( 'ABSPATH', __DIR__ . '/' );

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) { return true; }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( (string) $value ); }
function absint( $value ) { return abs( (int) $value ); }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function current_user_can( $capability ) { return 'manage_options' === (string) $capability; }
function get_current_user_id() { return 7; }

class WP_Error {
    private $code;
    private $message;
    private $data;
    public function __construct( $code = '', $message = '', $data = null ) {
        $this->code = (string) $code;
        $this->message = (string) $message;
        $this->data = $data;
    }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
    public function get_error_data() { return $this->data; }
}

final class MAD4B_Test_Ability {
    private $meta;
    private $schema;
    private $result;
    public $calls = 0;

    public function __construct( array $meta, array $schema, $result ) {
        $this->meta = $meta;
        $this->schema = $schema;
        $this->result = $result;
    }
    public function get_meta() { return $this->meta; }
    public function get_input_schema() { return $this->schema; }
    public function execute( $input = null ) {
        ++$this->calls;
        return is_callable( $this->result ) ? call_user_func( $this->result, $input ) : $this->result;
    }
}

$GLOBALS['mad4b_test_abilities'] = array();
function wp_has_ability( $name ) { return isset( $GLOBALS['mad4b_test_abilities'][ $name ] ); }
function wp_get_ability( $name ) { return isset( $GLOBALS['mad4b_test_abilities'][ $name ] ) ? $GLOBALS['mad4b_test_abilities'][ $name ] : null; }

final class MAD4B_SCP_Site_Profile {
    public static function configured() { return true; }
    public static function current_environment() { return 'staging'; }
    public static function origin_enrolled() { return true; }
    public static function site_urls_match_enrollment() { return true; }
    public static function user_is_enrolled( $user_id ) { return 7 === (int) $user_id; }
}

final class MAD4B_SCP_Policy {
    public static function can_read() { return true; }
    public static function can_breakglass() { return false; }
}

final class MAD4B_SCP_Local_OAuth_Server {
    const CHATGPT_CIMD_CLIENT_ID = 'https://chatgpt.com/oauth/client.json';
}

final class MAD4B_SCP_OAuth_Resource_Bridge {
    const AUTHORITY_STEP_UP_SCOPE = 'mad4b:authority:step-up';
    public static $bearer = true;
    public static $scope = true;
    public static $client = true;

    public static function verified_bearer_active() { return (bool) self::$bearer; }
    public static function verified_bearer_has_scope( $scope ) {
        return self::$scope && self::AUTHORITY_STEP_UP_SCOPE === (string) $scope;
    }
    public static function verified_bearer_client_is( $client_id ) {
        return self::$client && MAD4B_SCP_Local_OAuth_Server::CHATGPT_CIMD_CLIENT_ID === (string) $client_id;
    }
}

final class MAD4B_SCP_Servers {
    public static $mounted = array();
    public static function ability_is_mounted( $server_id, $ability_name ) {
        return 'mad4b-enrollment' === (string) $server_id && in_array( (string) $ability_name, self::$mounted, true );
    }
}

final class MAD4B_SCP_Remote_Operation_Parity {
    const SKILLS = 'mad4b/reconcile-managed-skills';
    const CLAIM = 'mad4b/remote-operation-work-claim';
    const HUMAN = 'mad4b/human-decision-test';
    const SYSTEM = 'mad4b/system-test';

    public static function enrollment_abilities() {
        return array( self::SKILLS, self::CLAIM, self::HUMAN, self::SYSTEM );
    }

    public static function catalog() {
        return array(
            'managed_skills_reconciliation' => self::row( self::SKILLS, 'operator', false, 'deny', '1' ),
            'external_executor_work_claim' => self::row( self::CLAIM, 'external_executor', false, 'deny', '2' ),
            'human_decision_test' => self::row( self::HUMAN, 'operator', true, 'deny', '3' ),
            'system_test' => self::row( self::SYSTEM, 'system', false, 'deny', '4' ),
        );
    }

    private static function row( $ability, $role, $human, $production, $digit ) {
        return array(
            'feature_id' => 'test',
            'capability_tags' => array( 'test' ),
            'provider' => 'core',
            'status_ability' => 'mad4b/status',
            'local_surface' => '',
            'remote_ability' => $ability,
            'authority_surface' => 'mad4b-enrollment',
            'executor' => 'wordpress_native',
            'remote_mode' => 'checkpointed_convergence',
            'remote_caller_role' => $role,
            'production_policy' => $production,
            'human_decision_required' => $human,
            'operation_id' => '',
            'catalog_contract' => 'mad4b.remote-operation-parity.v1',
            'catalog_version' => 3,
            'registration_digest' => str_repeat( $digit, 64 ),
            'remote_registered' => true,
            'manual_only' => false,
            'executor_available' => true,
            'executor_state' => 'wordpress_runtime_available',
            'execution_eligible' => true,
            'remote_parity_ready' => true,
        );
    }
}

function mad4b_test_meta( array $mcp = array(), array $annotations = array() ) {
    return array(
        'mcp' => array_merge(
            array(
                'surface' => 'enrollment',
                'generic_remote_admin' => false,
                'production_mutation_allowed' => false,
            ),
            $mcp
        ),
        'annotations' => array_merge(
            array(
                'readonly' => false,
                'destructive' => false,
                'idempotent' => true,
            ),
            $annotations
        ),
    );
}

function mad4b_assert( $condition, $message ) {
    if ( ! $condition ) {
        fwrite( STDERR, 'FAIL: ' . $message . "\n" );
        exit( 1 );
    }
}

function mad4b_error_code( $value ) {
    return is_wp_error( $value ) ? $value->get_error_code() : '';
}

$schema = array(
    'type' => 'object',
    'properties' => array(
        'expected_source_commit_sha' => array( 'type' => 'string' ),
        'confirmation' => array( 'type' => 'string' ),
    ),
    'required' => array( 'expected_source_commit_sha', 'confirmation' ),
    'additionalProperties' => false,
);

$skills = new MAD4B_Test_Ability(
    mad4b_test_meta(),
    $schema,
    array( 'state' => 'ready', 'mutation_performed' => false, 'production_mutation' => false )
);
$GLOBALS['mad4b_test_abilities'][ MAD4B_SCP_Remote_Operation_Parity::SKILLS ] = $skills;
$GLOBALS['mad4b_test_abilities'][ MAD4B_SCP_Remote_Operation_Parity::CLAIM ] = new MAD4B_Test_Ability( mad4b_test_meta(), $schema, array() );
$GLOBALS['mad4b_test_abilities'][ MAD4B_SCP_Remote_Operation_Parity::HUMAN ] = new MAD4B_Test_Ability( mad4b_test_meta(), $schema, array() );
$GLOBALS['mad4b_test_abilities'][ MAD4B_SCP_Remote_Operation_Parity::SYSTEM ] = new MAD4B_Test_Ability( mad4b_test_meta(), $schema, array() );
MAD4B_SCP_Servers::$mounted = MAD4B_SCP_Remote_Operation_Parity::enrollment_abilities();

require dirname( __DIR__ ) . '/includes/class-mad4b-scp-enrollment-dispatch.php';

$discover = MAD4B_SCP_Enrollment_Dispatch::discover( array() );
mad4b_assert( 1 === (int) $discover['count'], 'dispatcher discovery must expose only operator-role non-human Staging operations' );
mad4b_assert( isset( $discover['operations']['managed_skills_reconciliation'] ), 'managed Skills reconciliation was not discoverable' );
mad4b_assert( ! isset( $discover['operations']['external_executor_work_claim'] ), 'external executor lease claim leaked into ChatGPT dispatcher' );
mad4b_assert( ! isset( $discover['operations']['human_decision_test'] ), 'human-decision operation leaked into ChatGPT dispatcher' );
mad4b_assert( ! isset( $discover['operations']['system_test'] ), 'system caller operation leaked into ChatGPT dispatcher' );

$claim_info = MAD4B_SCP_Enrollment_Dispatch::info( array( 'operation_id' => 'external_executor_work_claim' ) );
mad4b_assert( 'mad4b_enrollment_dispatch_operation_not_eligible' === mad4b_error_code( $claim_info ), 'external executor operation did not fail closed' );

mad4b_assert(
    true === MAD4B_SCP_Enrollment_Dispatch::can_execute( array( 'operation_id' => 'managed_skills_reconciliation' ) ),
    'valid operator enrollment dispatch did not pass step-up permission'
);
MAD4B_SCP_OAuth_Resource_Bridge::$scope = false;
$denied = MAD4B_SCP_Enrollment_Dispatch::can_execute( array( 'operation_id' => 'managed_skills_reconciliation' ) );
mad4b_assert( 'mad4b_enrollment_dispatch_step_up_scope_required' === mad4b_error_code( $denied ), 'missing step-up scope did not fail closed' );
MAD4B_SCP_OAuth_Resource_Bridge::$scope = true;
MAD4B_SCP_OAuth_Resource_Bridge::$client = false;
$denied = MAD4B_SCP_Enrollment_Dispatch::can_execute( array( 'operation_id' => 'managed_skills_reconciliation' ) );
mad4b_assert( 'mad4b_enrollment_dispatch_chatgpt_client_required' === mad4b_error_code( $denied ), 'wrong OAuth client did not fail closed' );
MAD4B_SCP_OAuth_Resource_Bridge::$client = true;

$info = MAD4B_SCP_Enrollment_Dispatch::info( array( 'operation_id' => 'managed_skills_reconciliation' ) );
mad4b_assert( is_array( $info ), 'eligible operation info failed' );
$execute_input = array(
    'operation_id' => 'managed_skills_reconciliation',
    'expected_registration_digest' => $info['registration_digest'],
    'expected_dispatch_policy_digest' => $info['dispatch_policy_digest'],
    'expected_input_schema_sha256' => $info['input_schema_sha256'],
    'input' => array(
        'expected_source_commit_sha' => str_repeat( 'a', 40 ),
        'confirmation' => 'RECONCILE MANAGED SKILLS',
    ),
);
$result = MAD4B_SCP_Enrollment_Dispatch::execute( $execute_input );
mad4b_assert( is_array( $result ), 'eligible enrollment operation did not execute' );
mad4b_assert( true === $result['operation_invoked'], 'dispatcher did not report operation invocation' );
mad4b_assert( false === $result['mutation_performed'], 'dispatcher overwrote explicit target no-op mutation evidence' );
mad4b_assert( 'target_result' === $result['mutation_evidence_source'], 'dispatcher did not identify target mutation evidence source' );
mad4b_assert( 1 === $skills->calls, 'eligible target did not execute exactly once' );

$bad = $execute_input;
$bad['expected_registration_digest'] = str_repeat( 'f', 64 );
$denied = MAD4B_SCP_Enrollment_Dispatch::execute( $bad );
mad4b_assert( 'mad4b_enrollment_dispatch_registration_drift' === mad4b_error_code( $denied ), 'registration drift did not fail closed' );
mad4b_assert( 1 === $skills->calls, 'registration drift executed the target unexpectedly' );

$bad = $execute_input;
$bad['expected_dispatch_policy_digest'] = str_repeat( 'f', 64 );
$denied = MAD4B_SCP_Enrollment_Dispatch::execute( $bad );
mad4b_assert( 'mad4b_enrollment_dispatch_policy_drift' === mad4b_error_code( $denied ), 'dispatch-policy drift did not fail closed' );
mad4b_assert( 1 === $skills->calls, 'policy drift executed the target unexpectedly' );

$bad = $execute_input;
$bad['expected_input_schema_sha256'] = str_repeat( 'f', 64 );
$denied = MAD4B_SCP_Enrollment_Dispatch::execute( $bad );
mad4b_assert( 'mad4b_enrollment_dispatch_schema_drift' === mad4b_error_code( $denied ), 'schema drift did not fail closed' );
mad4b_assert( 1 === $skills->calls, 'schema drift executed the target unexpectedly' );

MAD4B_SCP_Servers::$mounted = array();
$denied = MAD4B_SCP_Enrollment_Dispatch::info( array( 'operation_id' => 'managed_skills_reconciliation' ) );
mad4b_assert( 'mad4b_enrollment_dispatch_target_not_mounted' === mad4b_error_code( $denied ), 'unmounted target did not fail closed' );
MAD4B_SCP_Servers::$mounted = MAD4B_SCP_Remote_Operation_Parity::enrollment_abilities();

$GLOBALS['mad4b_test_abilities'][ MAD4B_SCP_Remote_Operation_Parity::SKILLS ] = new MAD4B_Test_Ability( mad4b_test_meta( array( 'surface' => 'admin' ) ), $schema, array() );
$denied = MAD4B_SCP_Enrollment_Dispatch::info( array( 'operation_id' => 'managed_skills_reconciliation' ) );
mad4b_assert( 'mad4b_enrollment_dispatch_target_surface_mismatch' === mad4b_error_code( $denied ), 'wrong target surface did not fail closed' );

$GLOBALS['mad4b_test_abilities'][ MAD4B_SCP_Remote_Operation_Parity::SKILLS ] = new MAD4B_Test_Ability( mad4b_test_meta( array( 'generic_remote_admin' => true ) ), $schema, array() );
$denied = MAD4B_SCP_Enrollment_Dispatch::info( array( 'operation_id' => 'managed_skills_reconciliation' ) );
mad4b_assert( 'mad4b_enrollment_dispatch_generic_admin_denied' === mad4b_error_code( $denied ), 'generic remote-admin target did not fail closed' );

$GLOBALS['mad4b_test_abilities'][ MAD4B_SCP_Remote_Operation_Parity::SKILLS ] = new MAD4B_Test_Ability( mad4b_test_meta( array( 'production_mutation_allowed' => true ) ), $schema, array() );
$denied = MAD4B_SCP_Enrollment_Dispatch::info( array( 'operation_id' => 'managed_skills_reconciliation' ) );
mad4b_assert( 'mad4b_enrollment_dispatch_target_production_denied' === mad4b_error_code( $denied ), 'Production-capable target did not fail closed' );

$GLOBALS['mad4b_test_abilities'][ MAD4B_SCP_Remote_Operation_Parity::SKILLS ] = new MAD4B_Test_Ability( mad4b_test_meta( array(), array( 'readonly' => true ) ), $schema, array() );
$denied = MAD4B_SCP_Enrollment_Dispatch::info( array( 'operation_id' => 'managed_skills_reconciliation' ) );
mad4b_assert( 'mad4b_enrollment_dispatch_read_target_denied' === mad4b_error_code( $denied ), 'readonly target entered mutation dispatcher' );

$GLOBALS['mad4b_test_abilities'][ MAD4B_SCP_Remote_Operation_Parity::SKILLS ] = new MAD4B_Test_Ability( mad4b_test_meta(), $schema, array( 'state' => 'ready' ) );
$info = MAD4B_SCP_Enrollment_Dispatch::info( array( 'operation_id' => 'managed_skills_reconciliation' ) );
$execute_input['expected_registration_digest'] = $info['registration_digest'];
$execute_input['expected_dispatch_policy_digest'] = $info['dispatch_policy_digest'];
$execute_input['expected_input_schema_sha256'] = $info['input_schema_sha256'];
$result = MAD4B_SCP_Enrollment_Dispatch::execute( $execute_input );
mad4b_assert( null === $result['mutation_performed'], 'dispatcher fabricated mutation evidence when target did not report it' );
mad4b_assert( 'not_reported' === $result['mutation_evidence_source'], 'dispatcher did not label absent target mutation evidence' );

echo "mad4b.enrollment-dispatch.runtime.v1: PASS\n";
