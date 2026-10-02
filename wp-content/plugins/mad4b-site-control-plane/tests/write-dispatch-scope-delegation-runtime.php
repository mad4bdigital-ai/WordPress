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

class WP_Error {
    private $code;
    public function __construct( $code = '', $message = '', $data = null ) { $this->code = (string) $code; }
    public function get_error_code() { return $this->code; }
}

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

final class MAD4B_SCP_Identity_Context {
    public static $ticket_id = '';
    public static function bind_approval_ticket_for_request( $ticket_id ) {
        $ticket_id = strtolower( trim( (string) $ticket_id ) );
        if ( '' !== self::$ticket_id && ! hash_equals( self::$ticket_id, $ticket_id ) ) return false;
        self::$ticket_id = $ticket_id;
        return true;
    }
}

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
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-abilities.php';

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

$dispatcher = new MAD4B_SCP_Abilities();
$capture = new ReflectionMethod( 'MAD4B_SCP_Abilities', 'capture_write_dispatch_governance_envelope' );
$capture->setAccessible( true );
$forward = new ReflectionMethod( 'MAD4B_SCP_Abilities', 'forward_write_dispatch_governance_envelope' );
$forward->setAccessible( true );

$ticket = '11111111-1111-4111-8111-111111111111';
$receipt = array( 'contract' => 'mad4b.context-receipt.v1', 'sha256' => str_repeat( 'e', 64 ) );
$outer = array(
    'ability_name' => $target,
    'expected_input_schema_sha256' => $schema_sha,
    'input' => array( 'ability' => 'mad4b/plugin-package-apply' ),
    '_mad4b_approval_ticket_id' => $ticket,
    '_mad4b_context_receipt' => $receipt,
);
mad4b_assert( true === $capture->invoke( $dispatcher, $outer ), 'Dispatcher permission preflight must capture exact governance metadata.' );
mad4b_assert( $ticket === MAD4B_SCP_Identity_Context::$ticket_id, 'Dispatcher must bind the exact approval ticket request-locally.' );

$stripped = array(
    'ability_name' => $target,
    'expected_input_schema_sha256' => $schema_sha,
    'input' => $outer['input'],
);
mad4b_assert( true === $capture->invoke( $dispatcher, $stripped ), 'Repeated sanitized permission preflight must preserve the already captured governance envelope.' );
mad4b_assert( true === $capture->invoke( $dispatcher, $outer ), 'Repeated identical governed permission preflight must be idempotent.' );

$rebind = $outer;
$rebind['_mad4b_approval_ticket_id'] = '22222222-2222-4222-8222-222222222222';
$rebind_conflict = $capture->invoke( $dispatcher, $rebind );
mad4b_assert( is_wp_error( $rebind_conflict ) && 'mad4b_write_dispatch_governance_envelope_rebind_conflict' === $rebind_conflict->get_error_code(), 'Repeated governed preflight must reject a different approval ticket without mutating the captured envelope.' );

$target_input = $forward->invoke( $dispatcher, $stripped, $stripped['input'] );
mad4b_assert( is_array( $target_input ), 'Forwarded target input must remain an object.' );
mad4b_assert( $ticket === $target_input['_mad4b_approval_ticket_id'], 'Approval ticket must survive provider-envelope stripping.' );
mad4b_assert( $receipt === $target_input['_mad4b_context_receipt'], 'Context Receipt must survive provider-envelope stripping.' );

$oversized = $outer;
$oversized['_mad4b_context_receipt'] = array( 'payload' => str_repeat( 'x', MAD4B_SCP_Abilities::MAX_WRITE_DISPATCH_CONTEXT_RECEIPT_BYTES + 1 ) );
$oversized_result = $capture->invoke( $dispatcher, $oversized );
mad4b_assert( is_wp_error( $oversized_result ) && 'mad4b_write_dispatch_context_receipt_oversized' === $oversized_result->get_error_code(), 'Oversized Context Receipt must fail before request-local governance binding.' );

$conflicting = $outer;
$conflicting['input']['_mad4b_approval_ticket_id'] = '22222222-2222-4222-8222-222222222222';
$conflict = $capture->invoke( $dispatcher, $conflicting );
mad4b_assert( is_wp_error( $conflict ) && 'mad4b_write_dispatch_governance_envelope_conflict' === $conflict->get_error_code(), 'Conflicting nested governance metadata must fail closed.' );

echo "mad4b.write-dispatch-scope-delegation.runtime.v3: PASS\n";
