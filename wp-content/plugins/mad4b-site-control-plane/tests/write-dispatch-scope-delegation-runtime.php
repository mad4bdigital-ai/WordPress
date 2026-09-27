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
function get_option( $name, $default = false ) { return $default; }

class WP_Error {}

final class MAD4B_Test_Write_Ability {
    private $schema;
    private $readonly;
    public function __construct( array $schema, $readonly = false ) {
        $this->schema = $schema;
        $this->readonly = (bool) $readonly;
    }
    public function get_input_schema() { return $this->schema; }
    public function get_meta() { return array( 'annotations' => array( 'readonly' => $this->readonly ) ); }
}

$GLOBALS['mad4b_test_abilities'] = array();
function wp_has_ability( $name ) { return isset( $GLOBALS['mad4b_test_abilities'][ (string) $name ] ); }
function wp_get_ability( $name ) { return $GLOBALS['mad4b_test_abilities'][ (string) $name ] ?? null; }

final class MAD4B_SCP_Transport_Context {
    public static $server_id = 'mad4b-chatgpt';
    public static function current_server_id() { return self::$server_id; }
}

final class MAD4B_SCP_Servers {
    public static $write_tools = array();
    public static function write_tools() { return self::$write_tools; }
    public static function provider_for_ability( $server_id, $ability_name ) {
        if ( 'mad4b-write' !== (string) $server_id ) return null;
        return in_array( (string) $ability_name, self::$write_tools, true ) ? 'core' : null;
    }
}

require dirname( __DIR__ ) . '/includes/class-mad4b-scp-staging-write-authority.php';

function mad4b_assert( $condition, $message ) {
    if ( ! $condition ) {
        fwrite( STDERR, 'FAIL: ' . $message . "\n" );
        exit( 1 );
    }
}

$sha = str_repeat( 'a', 40 );
$build = str_repeat( 'b', 64 );
$manifest = str_repeat( 'c', 64 );
$artifact = 'mad4b-site-control-plane-0.4.0-rc.66-' . $sha;

$status_property = new ReflectionProperty( 'MAD4B_SCP_Staging_Write_Authority', 'status' );
$status_property->setAccessible( true );
$status_property->setValue( null, array(
    'contract' => MAD4B_SCP_Staging_Write_Authority::CONTRACT,
    'ready' => true,
    'state' => 'ready',
    'blocker' => '',
    'source_commit_sha' => $sha,
    'build_fingerprint' => $build,
    'package_manifest_digest' => $manifest,
    'artifact_identity' => $artifact,
) );

$candidate_property = new ReflectionProperty( 'MAD4B_SCP_Staging_Write_Authority', 'candidate_identity' );
$candidate_property->setAccessible( true );
$candidate_property->setValue( null, array(
    'available' => true,
    'source_commit_sha' => $sha,
    'build_fingerprint' => $build,
    'package_manifest_digest' => $manifest,
    'artifact_identity' => $artifact,
) );

$target = 'mad4b/approval-plan';
$schema = array(
    'type' => 'object',
    'properties' => array(
        'agent_public_id' => array( 'type' => 'string' ),
        'ability' => array( 'type' => 'string' ),
    ),
    'additionalProperties' => false,
);
$GLOBALS['mad4b_test_abilities'][ $target ] = new MAD4B_Test_Write_Ability( $schema, false );
MAD4B_SCP_Servers::$write_tools = array( $target );

$helper = new ReflectionMethod( 'MAD4B_SCP_Staging_Write_Authority', 'write_dispatch_scope_delegation_allowed' );
$helper->setAccessible( true );

$schema_sha = hash( 'sha256', wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
$valid = array(
    'ability_name' => $target,
    'expected_input_schema_sha256' => $schema_sha,
    'input' => array(),
);

mad4b_assert( true === $helper->invoke( null, 'mad4b-chatgpt', $valid ), 'Exact ChatGPT dispatcher target/schema should receive bounded scope delegation.' );

$schema_drift = $valid;
$schema_drift['expected_input_schema_sha256'] = str_repeat( 'd', 64 );
mad4b_assert( false === $helper->invoke( null, 'mad4b-chatgpt', $schema_drift ), 'Schema drift must fail closed.' );

$recursive = $valid;
$recursive['ability_name'] = 'mad4b/write-execute';
mad4b_assert( false === $helper->invoke( null, 'mad4b-chatgpt', $recursive ), 'Recursive write dispatch must fail closed.' );

$raw = $valid;
$raw['ability_name'] = 'mad4b/database-raw-query';
mad4b_assert( false === $helper->invoke( null, 'mad4b-chatgpt', $raw ), 'Raw SQL must fail closed.' );

MAD4B_SCP_Transport_Context::$server_id = 'mad4b-write';
mad4b_assert( false === $helper->invoke( null, 'mad4b-chatgpt', $valid ), 'Transport-context drift must fail closed.' );
MAD4B_SCP_Transport_Context::$server_id = 'mad4b-chatgpt';

$readonly = 'mad4b/test-readonly-target';
$GLOBALS['mad4b_test_abilities'][ $readonly ] = new MAD4B_Test_Write_Ability( $schema, true );
MAD4B_SCP_Servers::$write_tools[] = $readonly;
$readonly_input = $valid;
$readonly_input['ability_name'] = $readonly;
mad4b_assert( false === $helper->invoke( null, 'mad4b-chatgpt', $readonly_input ), 'readonly=true target must fail closed.' );

echo "mad4b.write-dispatch-scope-delegation.runtime.v1: PASS\n";
