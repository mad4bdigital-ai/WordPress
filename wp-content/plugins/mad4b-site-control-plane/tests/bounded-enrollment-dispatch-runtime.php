<?php
if ( PHP_VERSION_ID < 70400 ) {
    fwrite( STDERR, "FAIL: PHP 7.4+ required\n" );
    exit( 1 );
}

define( 'ABSPATH', __DIR__ . '/' );

function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function is_wp_error( $value ) { return $value instanceof WP_Error; }

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
        if ( is_callable( $this->result ) ) return call_user_func( $this->result, $input );
        return $this->result;
    }
}

$GLOBALS['mad4b_test_abilities'] = array();
function wp_has_ability( $name ) { return isset( $GLOBALS['mad4b_test_abilities'][ $name ] ); }
function wp_get_ability( $name ) { return isset( $GLOBALS['mad4b_test_abilities'][ $name ] ) ? $GLOBALS['mad4b_test_abilities'][ $name ] : null; }

final class MAD4B_SCP_Remote_Operation_Parity {
    public static $allowed = array(
        'mad4b/reconcile-managed-skills',
        'mad4b/frontend-performance-sample-run',
        'mad4b/admin-query-performance-apply',
        'mad4b/admin-query-performance-reconcile',
        'mad4b/remote-operation-work-claim',
        'mad4b/remote-operation-work-complete',
    );
    public static $grant = true;
    public static function enrollment_abilities() { return self::$allowed; }
    public static function can_execute( $input = null ) { return self::$grant; }
}

final class MAD4B_SCP_Servers {
    public static $mounted = array();
    public static function ability_is_mounted( $server_id, $ability_name ) {
        return 'mad4b-enrollment' === (string) $server_id && in_array( (string) $ability_name, self::$mounted, true );
    }
}

require dirname( __DIR__ ) . '/includes/class-mad4b-scp-abilities.php';

function mad4b_assert( $condition, $message ) {
    if ( ! $condition ) {
        fwrite( STDERR, 'FAIL: ' . $message . "\n" );
        exit( 1 );
    }
}

function mad4b_error_code( $value ) {
    return is_wp_error( $value ) ? $value->get_error_code() : '';
}

function mad4b_enrollment_meta( array $overrides = array() ) {
    $meta = array(
        'mcp' => array(
            'surface' => 'enrollment',
            'generic_remote_admin' => false,
            'production_mutation_allowed' => false,
        ),
        'annotations' => array(
            'readonly' => false,
            'destructive' => false,
            'idempotent' => true,
        ),
    );
    foreach ( $overrides as $key => $value ) {
        if ( 'mcp' === $key || 'annotations' === $key ) {
            $meta[ $key ] = array_merge( $meta[ $key ], $value );
        } else {
            $meta[ $key ] = $value;
        }
    }
    return $meta;
}

$dispatcher = new MAD4B_SCP_Abilities();
$target = 'mad4b/reconcile-managed-skills';
$schema = array(
    'type' => 'object',
    'properties' => array(
        'expected_source_commit_sha' => array( 'type' => 'string' ),
        'confirmation' => array( 'type' => 'string' ),
    ),
    'required' => array( 'expected_source_commit_sha', 'confirmation' ),
    'additionalProperties' => false,
);
$payload = array(
    'expected_source_commit_sha' => str_repeat( 'a', 40 ),
    'confirmation' => 'RECONCILE MANAGED SKILLS',
);
$ability = new MAD4B_Test_Ability(
    mad4b_enrollment_meta(),
    $schema,
    array( 'state' => 'ready', 'mutation_performed' => true, 'production_mutation' => false )
);
$GLOBALS['mad4b_test_abilities'][ $target ] = $ability;
MAD4B_SCP_Servers::$mounted = array( $target );
$schema_hash = hash( 'sha256', wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
$request = array(
    'ability_name' => $target,
    'expected_input_schema_sha256' => $schema_hash,
    'input' => $payload,
);

mad4b_assert( true === $dispatcher->can_enrollment_dispatch( $request ), 'bounded enrollment dispatcher did not authorize the canonical Remote Operation Parity target' );
$result = $dispatcher->enrollment_execute( $request );
mad4b_assert( is_array( $result ), 'bounded enrollment execution did not return an array result' );
mad4b_assert( 'mad4b.chatgpt-enrollment-execute.v1' === $result['contract'], 'bounded enrollment execution contract drifted' );
mad4b_assert( $target === $result['ability_name'], 'bounded enrollment execution changed target identity' );
mad4b_assert( 'mad4b-enrollment' === $result['authority_surface'], 'bounded enrollment execution lost authority-surface identity' );
mad4b_assert( true === $result['dispatch_performed'], 'bounded enrollment execution did not record dispatch' );
mad4b_assert( true === $result['mutation_performed'], 'bounded enrollment execution did not preserve target mutation evidence' );
mad4b_assert( false === $result['production_mutation'], 'bounded enrollment execution must never claim Production mutation' );
mad4b_assert( 1 === $ability->calls, 'bounded enrollment target did not execute exactly once' );

$bad_schema = $request;
$bad_schema['expected_input_schema_sha256'] = str_repeat( '0', 64 );
$drift = $dispatcher->enrollment_execute( $bad_schema );
mad4b_assert( 'mad4b_enrollment_dispatch_schema_drift' === mad4b_error_code( $drift ), 'schema drift did not fail closed' );
mad4b_assert( 1 === $ability->calls, 'schema drift executed the target unexpectedly' );

$rogue = 'mad4b/plugin-activate';
$GLOBALS['mad4b_test_abilities'][ $rogue ] = new MAD4B_Test_Ability( mad4b_enrollment_meta(), $schema, array() );
MAD4B_SCP_Servers::$mounted[] = $rogue;
$denied = $dispatcher->can_enrollment_dispatch( array( 'ability_name' => $rogue, 'input' => array() ) );
mad4b_assert( 'mad4b_enrollment_dispatch_target_not_cataloged' === mad4b_error_code( $denied ), 'non-parity admin target entered bounded enrollment dispatcher' );

$recursive = $dispatcher->can_enrollment_dispatch( array( 'ability_name' => 'mad4b/enrollment-execute', 'input' => array() ) );
mad4b_assert( 'mad4b_enrollment_dispatch_recursion_denied' === mad4b_error_code( $recursive ), 'dispatcher recursion did not fail closed' );

$unmounted = 'mad4b/frontend-performance-sample-run';
$GLOBALS['mad4b_test_abilities'][ $unmounted ] = new MAD4B_Test_Ability( mad4b_enrollment_meta(), $schema, array() );
$denied = $dispatcher->can_enrollment_dispatch( array( 'ability_name' => $unmounted, 'input' => array() ) );
mad4b_assert( 'mad4b_enrollment_dispatch_target_not_mounted' === mad4b_error_code( $denied ), 'unmounted enrollment target did not fail closed' );

MAD4B_SCP_Servers::$mounted[] = $unmounted;
$GLOBALS['mad4b_test_abilities'][ $unmounted ] = new MAD4B_Test_Ability( mad4b_enrollment_meta( array( 'mcp' => array( 'surface' => 'admin' ) ) ), $schema, array() );
$denied = $dispatcher->can_enrollment_dispatch( array( 'ability_name' => $unmounted, 'input' => array() ) );
mad4b_assert( 'mad4b_enrollment_dispatch_surface_mismatch' === mad4b_error_code( $denied ), 'wrong-surface enrollment target did not fail closed' );

$GLOBALS['mad4b_test_abilities'][ $unmounted ] = new MAD4B_Test_Ability( mad4b_enrollment_meta( array( 'mcp' => array( 'generic_remote_admin' => true ) ) ), $schema, array() );
$denied = $dispatcher->can_enrollment_dispatch( array( 'ability_name' => $unmounted, 'input' => array() ) );
mad4b_assert( 'mad4b_enrollment_dispatch_generic_admin_denied' === mad4b_error_code( $denied ), 'generic remote-admin target did not fail closed' );

$GLOBALS['mad4b_test_abilities'][ $unmounted ] = new MAD4B_Test_Ability( mad4b_enrollment_meta( array( 'mcp' => array( 'production_mutation_allowed' => true ) ) ), $schema, array() );
$denied = $dispatcher->can_enrollment_dispatch( array( 'ability_name' => $unmounted, 'input' => array() ) );
mad4b_assert( 'mad4b_enrollment_dispatch_production_denied' === mad4b_error_code( $denied ), 'Production-capable target did not fail closed' );

$GLOBALS['mad4b_test_abilities'][ $unmounted ] = new MAD4B_Test_Ability( mad4b_enrollment_meta( array( 'annotations' => array( 'readonly' => true ) ) ), $schema, array() );
$denied = $dispatcher->can_enrollment_dispatch( array( 'ability_name' => $unmounted, 'input' => array() ) );
mad4b_assert( 'mad4b_enrollment_dispatch_read_target_denied' === mad4b_error_code( $denied ), 'readonly target entered mutation dispatcher' );

$GLOBALS['mad4b_test_abilities'][ $unmounted ] = new MAD4B_Test_Ability( mad4b_enrollment_meta(), $schema, array() );
MAD4B_SCP_Remote_Operation_Parity::$grant = new WP_Error( 'mad4b_test_enrollment_authority_denied', 'Denied by bounded enrollment authority.' );
$denied = $dispatcher->can_enrollment_dispatch( array( 'ability_name' => $unmounted, 'input' => array() ) );
mad4b_assert( 'mad4b_test_enrollment_authority_denied' === mad4b_error_code( $denied ), 'bounded enrollment authority denial was not preserved' );

echo "mad4b.bounded-enrollment-dispatch.runtime.v1: PASS\n";
