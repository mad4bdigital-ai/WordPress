<?php
if ( PHP_VERSION_ID < 70400 ) {
    fwrite( STDERR, "FAIL: PHP 7.4+ required\n" );
    exit( 1 );
}
define( 'ABSPATH', __DIR__ . '/' );

function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( (string) $value ); }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function is_wp_error( $value ) { return $value instanceof WP_Error; }

class WP_Error {
    private $code;
    public function __construct( $code = '', $message = '', $data = null ) { $this->code = (string) $code; }
    public function get_error_code() { return $this->code; }
}

final class MAD4B_Test_Request {
    private $route;
    public function __construct( $route ) { $this->route = (string) $route; }
    public function get_route() { return $this->route; }
}

final class MAD4B_Test_Ability {
    private $schema;
    public function __construct( array $schema ) { $this->schema = $schema; }
    public function get_input_schema() { return $this->schema; }
}

$GLOBALS['mad4b_test_abilities'] = array();
function wp_has_ability( $name ) { return isset( $GLOBALS['mad4b_test_abilities'][ (string) $name ] ); }
function wp_get_ability( $name ) { return $GLOBALS['mad4b_test_abilities'][ (string) $name ] ?? null; }

final class MAD4B_SCP_Servers {
    public static $external = array();
    public static $write = array();
    public static function expected_server_ids() { return array( 'mad4b-chatgpt', 'mad4b-write', 'mad4b-admin' ); }
    public static function is_external_write_candidate( $ability_name ) { return in_array( (string) $ability_name, self::$external, true ); }
    public static function ability_is_mounted( $server_id, $ability_name ) {
        if ( 'mad4b-write' === (string) $server_id ) return in_array( (string) $ability_name, self::$write, true );
        if ( 'mad4b-chatgpt' === (string) $server_id ) return 'mad4b/write-execute' === (string) $ability_name;
        return false;
    }
}

final class MAD4B_SCP_Staging_Write_Authority {
    public static function effective() { return true; }
    public static function candidate_bootstrap_allowed( $ability_name, $input = null ) { return false; }
    public static function candidate_bootstrap_status( $ability_name, $input = null ) { return array(); }
    public static function is_write_ability( $ability_name ) { return in_array( (string) $ability_name, MAD4B_SCP_Servers::$write, true ); }
}

require dirname( __DIR__ ) . '/includes/class-mad4b-scp-transport-context.php';

function mad4b_assert( $condition, $message ) {
    if ( ! $condition ) {
        fwrite( STDERR, 'FAIL: ' . $message . "\n" );
        exit( 1 );
    }
}
function mad4b_assert_error( $value, $code, $message ) {
    mad4b_assert( is_wp_error( $value ), $message . ' (expected WP_Error)' );
    mad4b_assert( $code === $value->get_error_code(), $message . ' (unexpected code ' . $value->get_error_code() . ')' );
}

$target = 'mad4b/approval-plan';
$other = 'mad4b/content-update-post';
$schema = array(
    'type' => 'object',
    'properties' => array( 'ability' => array( 'type' => 'string' ) ),
    'additionalProperties' => false,
);
$GLOBALS['mad4b_test_abilities'][ $target ] = new MAD4B_Test_Ability( $schema );
$GLOBALS['mad4b_test_abilities'][ $other ] = new MAD4B_Test_Ability( $schema );
MAD4B_SCP_Servers::$external = array( $target, $other );
MAD4B_SCP_Servers::$write = array( $target, $other );
$schema_sha = hash( 'sha256', wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );

$bound = MAD4B_SCP_Transport_Context::bind( 'mad4b-chatgpt', new MAD4B_Test_Request( '/mcp/mad4b-chatgpt' ) );
mad4b_assert( true === $bound, 'ChatGPT transport should bind.' );

$direct = MAD4B_SCP_Transport_Context::resolve_server_for_ability( 'mad4b-admin', $target, array() );
mad4b_assert_error( $direct, 'mad4b_transport_ability_not_mounted', 'Hidden target must remain denied outside write-execute.' );

$result = MAD4B_SCP_Transport_Context::with_write_dispatch_target(
    $target,
    $schema_sha,
    static function () use ( $target, $other ) {
        $inside = MAD4B_SCP_Transport_Context::resolve_server_for_ability( 'mad4b-admin', $target, array() );
        mad4b_assert( 'mad4b-write' === $inside, 'Exact nested target must resolve to mad4b-write.' );
        $other_result = MAD4B_SCP_Transport_Context::resolve_server_for_ability( 'mad4b-content', $other, array() );
        mad4b_assert_error( $other_result, 'mad4b_transport_ability_not_mounted', 'Different hidden target must remain denied during exact dispatch.' );
        $status = MAD4B_SCP_Transport_Context::status();
        mad4b_assert( ! empty( $status['write_dispatch_target_bound'] ), 'Nested dispatch binding should be observable as a boolean.' );
        return 'ok';
    }
);
mad4b_assert( 'ok' === $result, 'Exact nested dispatcher callback should return normally.' );
mad4b_assert( empty( MAD4B_SCP_Transport_Context::status()['write_dispatch_target_bound'] ), 'Nested dispatch binding must clear after success.' );

$after = MAD4B_SCP_Transport_Context::resolve_server_for_ability( 'mad4b-admin', $target, array() );
mad4b_assert_error( $after, 'mad4b_transport_ability_not_mounted', 'Hidden target must fail closed after dispatcher callback.' );

$drift = MAD4B_SCP_Transport_Context::with_write_dispatch_target( $target, str_repeat( 'd', 64 ), static function () { return true; } );
mad4b_assert_error( $drift, 'mad4b_write_dispatch_schema_drift', 'Schema drift must fail before binding.' );

$recursive = MAD4B_SCP_Transport_Context::with_write_dispatch_target( 'mad4b/write-execute', $schema_sha, static function () { return true; } );
mad4b_assert_error( $recursive, 'mad4b_write_dispatch_target_denied', 'Recursive dispatcher target must fail closed.' );

try {
    MAD4B_SCP_Transport_Context::with_write_dispatch_target(
        $target,
        $schema_sha,
        static function () { throw new RuntimeException( 'expected-test-exception' ); }
    );
    mad4b_assert( false, 'Exception fixture should throw.' );
} catch ( RuntimeException $e ) {
    mad4b_assert( 'expected-test-exception' === $e->getMessage(), 'Unexpected exception escaped fixture.' );
}
mad4b_assert( empty( MAD4B_SCP_Transport_Context::status()['write_dispatch_target_bound'] ), 'Nested dispatch binding must clear in finally after exception.' );

MAD4B_SCP_Transport_Context::clear();
mad4b_assert( '' === MAD4B_SCP_Transport_Context::current_server_id(), 'clear() must reset transport.' );
mad4b_assert( empty( MAD4B_SCP_Transport_Context::status()['write_dispatch_target_bound'] ), 'clear() must reset nested dispatch binding.' );

echo "mad4b.write-dispatch-nested-transport.runtime.v1: PASS\n";
