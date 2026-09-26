<?php
if ( PHP_VERSION_ID < 70400 ) {
    fwrite( STDERR, "FAIL: PHP 7.4+ required\n" );
    exit( 1 );
}

define( 'ABSPATH', __DIR__ . '/' );

function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( (string) $value ); }
function is_wp_error( $value ) { return $value instanceof WP_Error; }

class WP_Error {
    private $code;
    private $message;
    private $data;
    public function __construct( $code = '', $message = '', $data = null ) { $this->code = (string) $code; $this->message = (string) $message; $this->data = $data; }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
    public function get_error_data() { return $this->data; }
}

final class MAD4B_Test_Request {
    private $route;
    public function __construct( $route ) { $this->route = (string) $route; }
    public function get_route() { return $this->route; }
}

final class MAD4B_SCP_Staging_Write_Authority {
    public static $effective = true;
    public static $eligible = array();
    public static function effective() { return (bool) self::$effective; }
    public static function is_write_ability( $ability_name ) { return in_array( (string) $ability_name, self::$eligible, true ); }
}

final class MAD4B_SCP_Servers {
    public static $chatgpt = array();
    public static $write = array();
    public static $external = array();
    public static function expected_server_ids() { return array( 'mad4b-read', 'mad4b-chatgpt', 'mad4b-enrollment', 'mad4b-content', 'mad4b-write', 'mad4b-admin', 'mad4b-breakglass' ); }
    public static function is_external_write_candidate( $ability_name ) { return in_array( (string) $ability_name, self::$external, true ); }
    public static function ability_is_mounted( $server_id, $ability_name ) {
        if ( 'mad4b-chatgpt' === $server_id ) return in_array( (string) $ability_name, self::$chatgpt, true );
        if ( 'mad4b-write' === $server_id ) return in_array( (string) $ability_name, self::$write, true );
        return false;
    }
}

require dirname( __DIR__ ) . '/includes/class-mad4b-scp-transport-context.php';

function mad4b_assert( $condition, $message ) {
    if ( ! $condition ) {
        fwrite( STDERR, 'FAIL: ' . $message . "\n" );
        exit( 1 );
    }
}

$core = 'mad4b/content-update-post';
$provider = 'jetsmartfilters/update-filter-meta';
$read = 'mad4b/site-info';
$raw = 'mad4b/database-raw-query';
MAD4B_SCP_Servers::$external = array( $core, $provider );
MAD4B_SCP_Servers::$chatgpt = array( $core, $provider, $read );
MAD4B_SCP_Servers::$write = array( $core );
MAD4B_SCP_Staging_Write_Authority::$eligible = array( $core );

$request = new MAD4B_Test_Request( '/mcp/mad4b-chatgpt' );
$bound = MAD4B_SCP_Transport_Context::bind( 'mad4b-chatgpt', $request );
mad4b_assert( true === $bound, 'ChatGPT transport should bind for exact route.' );

// Unified discovery is intentionally available before mutation authority. A
// visible write must fail before grant lookup/approval/mutation while authority
// itself is not ready.
MAD4B_SCP_Staging_Write_Authority::$effective = false;
$result = MAD4B_SCP_Transport_Context::resolve_server_for_ability( 'mad4b-chatgpt', $core );
mad4b_assert( is_wp_error( $result ), 'Visible write must fail closed while Staging write authority is unavailable.' );
mad4b_assert( 'mad4b_write_authority_not_ready' === $result->get_error_code(), 'Unavailable write authority returned the wrong error.' );
MAD4B_SCP_Staging_Write_Authority::$effective = true;

// A registered provider write may be externally discoverable while gated, but
// it must fail before authority rebinding, approval claim, or mutation.
$result = MAD4B_SCP_Transport_Context::resolve_server_for_ability( 'mad4b-chatgpt', $provider );
mad4b_assert( is_wp_error( $result ), 'Gated provider write must fail closed.' );
mad4b_assert( 'mad4b_write_capability_not_eligible' === $result->get_error_code(), 'Gated provider write returned the wrong error.' );

// Promotion changes execution eligibility only; the same external ability now
// delegates to mad4b-write without changing the ChatGPT catalog.
MAD4B_SCP_Servers::$write[] = $provider;
MAD4B_SCP_Staging_Write_Authority::$eligible[] = $provider;
$result = MAD4B_SCP_Transport_Context::resolve_server_for_ability( 'mad4b-chatgpt', $provider );
mad4b_assert( 'mad4b-write' === $result, 'Certified provider write must delegate to mad4b-write.' );
mad4b_assert( in_array( $provider, MAD4B_SCP_Servers::$chatgpt, true ), 'Provider write unexpectedly left stable external catalog.' );

$result = MAD4B_SCP_Transport_Context::resolve_server_for_ability( 'mad4b-chatgpt', $core );
mad4b_assert( 'mad4b-write' === $result, 'Core governed write must delegate to mad4b-write.' );

$result = MAD4B_SCP_Transport_Context::resolve_server_for_ability( 'mad4b-chatgpt', $read );
mad4b_assert( 'mad4b-chatgpt' === $result, 'Read ability must remain on ChatGPT transport.' );

$result = MAD4B_SCP_Transport_Context::resolve_server_for_ability( 'mad4b-chatgpt', $raw );
mad4b_assert( is_wp_error( $result ), 'Raw SQL must remain unavailable on ChatGPT transport.' );
mad4b_assert( 'mad4b_transport_ability_not_mounted' === $result->get_error_code(), 'Raw SQL failed with unexpected error.' );

$status = MAD4B_SCP_Transport_Context::status();
mad4b_assert( 'mad4b.mcp-transport-context.v3' === $status['contract'], 'Transport v3 contract missing.' );
mad4b_assert( 'stable_unified_catalog_fail_closed_execution' === $status['chatgpt_write_discovery_model'], 'Unified fail-closed discovery model status missing.' );
mad4b_assert( empty( $status['credential_material_stored'] ), 'Transport status must never claim credential persistence.' );

// Bounded enrollment dispatcher: the compact ChatGPT surface may execute only
// the explicit parity allowlist and must preserve target Ability execution.
final class MAD4B_Test_Ability {
    private $name;
    private $meta;
    private $allow;
    public $execute_count = 0;
    public $last_input = null;
    public function __construct( $name, $meta, $allow = true ) { $this->name = $name; $this->meta = $meta; $this->allow = (bool) $allow; }
    public function get_name() { return $this->name; }
    public function get_label() { return 'Test ' . $this->name; }
    public function get_description() { return 'Bounded test ability.'; }
    public function get_category() { return 'mad4b-governance'; }
    public function get_input_schema() { return array( 'type' => 'object', 'additionalProperties' => true ); }
    public function get_output_schema() { return array( 'type' => 'object', 'additionalProperties' => true ); }
    public function get_meta() { return $this->meta; }
    public function execute( $input = null ) {
        $this->execute_count++;
        $this->last_input = $input;
        if ( ! $this->allow ) return new WP_Error( 'target_permission_denied', 'Underlying target permission denied.' );
        return array( 'ok' => true, 'input' => $input );
    }
}

$mad4b_test_abilities = array();
function wp_get_ability( $name ) {
    global $mad4b_test_abilities;
    return isset( $mad4b_test_abilities[ $name ] ) ? $mad4b_test_abilities[ $name ] : null;
}
function wp_has_ability( $name ) { return null !== wp_get_ability( $name ); }

require dirname( __DIR__ ) . '/includes/class-mad4b-scp-remote-operation-parity.php';

$enrollment_meta = array(
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

$skills_ability = new MAD4B_Test_Ability( MAD4B_SCP_Remote_Operation_Parity::SKILLS_ABILITY, $enrollment_meta, true );
$mad4b_test_abilities[ MAD4B_SCP_Remote_Operation_Parity::SKILLS_ABILITY ] = $skills_ability;

$allowed = MAD4B_SCP_Remote_Operation_Parity::chatgpt_enrollment_dispatch_abilities();
mad4b_assert( 4 === count( $allowed ), 'Compact enrollment dispatcher allowlist size drifted.' );
mad4b_assert( in_array( MAD4B_SCP_Remote_Operation_Parity::SKILLS_ABILITY, $allowed, true ), 'Managed Skills reconciliation missing from enrollment dispatcher.' );
mad4b_assert( ! in_array( MAD4B_SCP_Remote_Operation_Parity::WORK_CLAIM_ABILITY, $allowed, true ), 'External work lease claim leaked into compact enrollment dispatcher.' );
mad4b_assert( ! in_array( MAD4B_SCP_Remote_Operation_Parity::WORK_COMPLETE_ABILITY, $allowed, true ), 'External work completion leaked into compact enrollment dispatcher.' );

$info = MAD4B_SCP_Remote_Operation_Parity::enrollment_info( array( 'ability_name' => MAD4B_SCP_Remote_Operation_Parity::SKILLS_ABILITY ) );
mad4b_assert( ! is_wp_error( $info ), 'Allowlisted enrollment info unexpectedly failed.' );
mad4b_assert( ! empty( $info['bounded_dispatch'] ), 'Enrollment info lost bounded-dispatch marker.' );

$payload = array( 'confirmation' => 'RECONCILE MANAGED SKILLS', 'expected_source_commit_sha' => str_repeat( 'a', 40 ) );
$executed = MAD4B_SCP_Remote_Operation_Parity::enrollment_execute( array(
    'ability_name' => MAD4B_SCP_Remote_Operation_Parity::SKILLS_ABILITY,
    'input' => $payload,
) );
mad4b_assert( ! is_wp_error( $executed ), 'Allowlisted enrollment execution unexpectedly failed.' );
mad4b_assert( 1 === $skills_ability->execute_count, 'Dispatcher did not invoke WP_Ability::execute exactly once.' );
mad4b_assert( $payload === $skills_ability->last_input, 'Dispatcher mutated the target Ability input.' );
mad4b_assert( ! empty( $executed['bounded_dispatch'] ), 'Dispatcher success omitted bounded marker.' );
mad4b_assert( empty( $executed['production_mutation_allowed'] ), 'Dispatcher must never claim Production mutation authority.' );

$denied = MAD4B_SCP_Remote_Operation_Parity::enrollment_execute( array(
    'ability_name' => 'mad4b/database-raw-query',
    'input' => array(),
) );
mad4b_assert( is_wp_error( $denied ) && 'mad4b_enrollment_dispatch_target_denied' === $denied->get_error_code(), 'Raw SQL did not fail at the enrollment dispatcher allowlist.' );

$denied = MAD4B_SCP_Remote_Operation_Parity::enrollment_execute( array(
    'ability_name' => 'mad4b/staging-write-candidate-bind',
    'input' => array(),
) );
mad4b_assert( is_wp_error( $denied ) && 'mad4b_enrollment_dispatch_target_denied' === $denied->get_error_code(), 'Candidate binding leaked into compact enrollment dispatcher.' );

$bad_meta = $enrollment_meta;
$bad_meta['mcp']['surface'] = 'write';
$performance_ability = new MAD4B_Test_Ability( MAD4B_SCP_Remote_Operation_Parity::PERFORMANCE_INDEX_ABILITY, $bad_meta, true );
$mad4b_test_abilities[ MAD4B_SCP_Remote_Operation_Parity::PERFORMANCE_INDEX_ABILITY ] = $performance_ability;
$denied = MAD4B_SCP_Remote_Operation_Parity::enrollment_execute( array(
    'ability_name' => MAD4B_SCP_Remote_Operation_Parity::PERFORMANCE_INDEX_ABILITY,
    'input' => array(),
) );
mad4b_assert( is_wp_error( $denied ) && 'mad4b_enrollment_dispatch_target_contract_mismatch' === $denied->get_error_code(), 'Surface drift did not fail closed before target execution.' );
mad4b_assert( 0 === $performance_ability->execute_count, 'Contract-mismatched target was executed.' );

$permission_denied = new MAD4B_Test_Ability( MAD4B_SCP_Remote_Operation_Parity::PERFORMANCE_RECONCILE_ABILITY, $enrollment_meta, false );
$mad4b_test_abilities[ MAD4B_SCP_Remote_Operation_Parity::PERFORMANCE_RECONCILE_ABILITY ] = $permission_denied;
$denied = MAD4B_SCP_Remote_Operation_Parity::enrollment_execute( array(
    'ability_name' => MAD4B_SCP_Remote_Operation_Parity::PERFORMANCE_RECONCILE_ABILITY,
    'input' => array(),
) );
mad4b_assert( is_wp_error( $denied ) && 'target_permission_denied' === $denied->get_error_code(), 'Dispatcher failed to preserve underlying Ability permission failure.' );
mad4b_assert( 1 === $permission_denied->execute_count, 'Underlying Ability permission path was not invoked exactly once.' );

echo "mad4b.stable-external-write-catalog.runtime.v3: PASS\n";
