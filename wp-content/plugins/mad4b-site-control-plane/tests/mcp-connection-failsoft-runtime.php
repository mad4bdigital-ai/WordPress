<?php
/** Offline MCP connector fail-soft and scoped preflight policy test. */
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );
class WP_Error {
    private $code;
    public function __construct( $code, $message = '', $data = null ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function add_action( $hook, $callback, $priority = 10 ) { return true; }
function wp_has_ability( $name ) { return isset( $GLOBALS['abilities'][ $name ] ); }
function wp_register_ability( $name, $config ) { $GLOBALS['abilities'][ $name ] = $config; }
function absint( $value ) { return abs( (int) $value ); }
function sanitize_text_field( $value ) { return strip_tags( (string) $value ); }
class MAD4B_SCP_Policy { public static function can_read() { return true; } }
class MAD4B_SCP_Connection_Status {
    public static function status() { throw new RuntimeException( 'fixture-secret-must-not-leak' ); }
}
class MAD4B_SCP_Connector_Resilience {
    const DEFAULT_REQUEST_BUDGET_MS = 12000;
    const CONTRACT = 'mad4b.connector-resilience.v2';
    public static function run_checks( $checks, $options ) { throw new RuntimeException( 'fixture-secret-must-not-leak' ); }
    public static function client_guidance() { return array( 'reads_retry_only' => true ); }
}
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-connection-ability.php';
function verify( $ok, $reason ) {
    if ( ! $ok ) { fwrite( STDERR, "FAIL: $reason\n" ); exit( 1 ); }
}
MAD4B_SCP_Connection_Ability::register();
$schema = $GLOBALS['abilities'][ MAD4B_SCP_Connection_Ability::PREFLIGHT_ABILITY ]['input_schema'];
verify( $schema['properties']['diagnostic_scope']['default'] === 'full'
    && $schema['properties']['diagnostic_scope']['enum'] === array( 'identity', 'full' ),
    'Scope is explicit and defaults to complete assessment' );
verify( is_wp_error( MAD4B_SCP_Connection_Ability::execute() ) &&
    MAD4B_SCP_Connection_Ability::execute()->get_error_code() === 'mad4b_connection_status_runtime_failure',
    'Connection status returns a structured safe WP_Error after internal exception' );
$identity = MAD4B_SCP_Connection_Ability::preflight( array(
    'diagnostic_scope' => 'identity', 'include_staging_certification' => true,
    'include_operation_discovery' => true,
) );
verify( $identity['state'] === 'partial' && $identity['diagnostic_scope'] === 'identity' &&
    $identity['complete_runtime_authority_evaluated'] === false &&
    $identity['deferred_scope_checks'] !== array() &&
    $identity['included_staging_certification'] === false &&
    $identity['included_operation_discovery'] === false,
    'Identity-only probe defers authority and optional expensive checks' );
$full = MAD4B_SCP_Connection_Ability::preflight( array( 'diagnostic_scope' => 'full' ) );
verify( $full['state'] === 'partial' && $full['complete_runtime_authority_evaluated'] === false &&
    $full['error_code'] === 'mad4b_preflight_internal_exception' &&
    $full['mutation_performed'] === false,
    'Transport diagnostic exception is partial, not a false PASS' );
verify( strpos( json_encode( $full ), 'fixture-secret-must-not-leak' ) === false,
    'Never send raw exception text to MCP clients' );
echo "PASS: MCP connection fail-soft preflight with explicit identity/full scopes\n";
