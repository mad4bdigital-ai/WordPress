<?php
/**
 * Fast, offline transport diagnostics acceptance, no WordPress writes.
 * PHP 7.4 / 8.3 compatible. Tests truth separation and optional identity
 * probe without simulating a successful actual MCP transport session.
 */
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\\-]/', '', strtolower( (string) $value ) ); }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function add_action( $name, $handler, $priority = 10 ) { return true; }
class WP_Error {
    private $code;
    public function __construct( $code, $message = '', $data = null ) {
        $this->code = (string) $code;
    }
    public function get_error_code() { return $this->code; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function absint( $value ) { return abs( (int) $value ); }
class MAD4B_SCP_Connector_Resilience {}
class MAD4B_SCP_Live_Acceptance_Observer {
    public static function build_provenance_identity_status() {
        throw new RuntimeException( 'private-server-error-do-not-leak' );
    }
}
function wp_register_ability( $name, $definition ) { $GLOBALS['abilities'][ $name ] = $definition; }
function wp_has_ability( $name ) { return isset( $GLOBALS['abilities'][ $name ] ); }
class MAD4B_SCP_Policy { public static function can_read() { return true; } }
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-read-consistency.php';

function require_contract( $yes, $reason ) {
    if ( ! $yes ) { fwrite( STDERR, "FAIL: " . $reason . "\n" ); exit( 1 ); }
}
MAD4B_SCP_Read_Consistency::register_session_safe_report();
$ability = $GLOBALS['abilities'][ MAD4B_SCP_Read_Consistency::SESSION_SAFE_REPORT_ABILITY ];
$schema = $ability['input_schema']['properties']['diagnostic_scope'] ?? array();
require_contract( $schema['default'] === 'full' &&
    $schema['enum'] === array( 'full', 'identity', 'triage' ),
    'Explicit low-cost identity diagnostics; full remains default' );
require_contract( $ability['meta']['annotations']['readonly'] === true &&
    $ability['meta']['annotations']['destructive'] === false,
    'Diagnostics remain read-only without authority escalation' );

$method = new ReflectionMethod( 'MAD4B_SCP_Read_Consistency', 'session_safe_operator_summary' );
$method->setAccessible( true ); // PHP 7.4 reflection of private fixture method.
$sections = array( 'runtime' => array( 'checks' => array(
    'write_authority' => array( 'summary' => array(
        'effective_authority_ready' => true,
        'candidate_binding_match' => true,
        'current_grant_snapshot_ready' => true
    ) ),
    'adapter_lifecycle' => array( 'summary' => array( 'ready' => true ) ),
    'database_topology' => array( 'summary' => array( 'ready' => true,
        'read_your_writes' => true ) ),
    'mcp_protocol_profile' => array( 'summary' => array( 'ready' => true ) ),
    'skills_runtime' => array( 'summary' => array(
        // False historical checkpoint is not evidence of live failure.
        'effective_skill_ready' => false, 'live_skill_ready' => null,
        'live_skill_evaluation_deferred' => true ) ),
) ) );
$info = $method->invoke( null, $sections, false, array(), array( 'environment' => 'staging' ) );
require_contract( $info['signals']['skills_runtime_ready'] === null &&
    $info['signals']['skills_candidate_checkpoint_ready'] === false &&
    $info['signals']['skills_live_validation_deferred'] === true,
    'Historical checkpoint must not masquerade as live Skills truth' );
require_contract( $info['state'] === 'DEGRADED' &&
    in_array( 'skills_live_validation_deferred', $info['reasons'], true ) &&
    ! in_array( 'skills_runtime_not_ready', $info['reasons'], true ),
    'Unavailable live Skills check must be deferred, not a false failure' );
$sections['runtime']['checks']['write_authority']['summary']['effective_authority_ready'] = false;
$denied = $method->invoke( null, $sections, false,
    array( 'write_authority_not_effective' ), array( 'environment' => 'staging' ) );
require_contract( $denied['state'] === 'BLOCKED' &&
    $denied['signals']['write_authority_ready'] === false,
    'Real stale Write Authority remains blocking despite deferred Skills' );
// A WordPress-level Throwable in a generation snapshot must never terminate
// an MCP client with a raw fatal, and cannot be counted as a ready identity.
$snapshot = new ReflectionMethod( 'MAD4B_SCP_Read_Consistency', 'safe_read_snapshot' );
$snapshot->setAccessible( true );
$failed = $snapshot->invoke( null, 'rtx_fixture_12345678', 'preflight' );
require_contract( is_wp_error( $failed ) &&
    $failed->get_error_code() === 'mad4b_snapshot_preflight_unavailable',
    'Preflight identity exceptions become typed, fail-closed WP_Error' );
$metadata = MAD4B_SCP_Read_Consistency::metadata_envelope( array(
    'target_type' => 'ability', 'target' => 'mad4b/site-info' ) );
require_contract( is_wp_error( $metadata ) &&
    $metadata->get_error_code() === 'mad4b_snapshot_preflight_unavailable',
    'Metadata reads reuse shared snapshot isolation guard' );
$bundle = MAD4B_SCP_Read_Consistency::diagnostic_bundle( array( 'bundle' => 'identity' ) );
require_contract( is_wp_error( $bundle ) &&
    $bundle->get_error_code() === 'mad4b_snapshot_preflight_unavailable',
    'Bundle diagnostics isolate failed snapshots instead of PHP fatal' );
// Bounded O(1) module/bootstrap measurement must be exposed without database
// writes or declaring the transport proxy latency to be PHP execution time.
if ( ! defined( 'MAD4B_SCP_BOOT_START_AT' ) ) define( 'MAD4B_SCP_BOOT_START_AT', microtime( true ) - 0.250 );
if ( ! defined( 'MAD4B_SCP_BOOT_MODULES_READY_AT' ) ) define( 'MAD4B_SCP_BOOT_MODULES_READY_AT', MAD4B_SCP_BOOT_START_AT + 0.100 );
if ( ! defined( 'MAD4B_SCP_BOOT_HOOKS_READY_AT' ) ) define( 'MAD4B_SCP_BOOT_HOOKS_READY_AT', MAD4B_SCP_BOOT_START_AT + 0.220 );
$metric_reader = new ReflectionMethod( 'MAD4B_SCP_Read_Consistency', 'request_metrics' );
$metric_reader->setAccessible( true );
$metric_sample = $metric_reader->invoke( null );
$boot = $metric_sample['plugin_bootstrap_phases'] ?? array();
require_contract( ! empty( $boot['valid'] ) &&
    $boot['plugin_module_load_phase_ms'] >= 99 &&
    $boot['plugin_module_load_phase_ms'] <= 101 &&
    $boot['plugin_hook_registration_phase_ms'] >= 119 &&
    $boot['plugin_hook_registration_phase_ms'] <= 121 &&
    $boot['plugin_bootstrap_total_ms'] >= 219 &&
    $boot['plugin_bootstrap_total_ms'] <= 221 &&
    $boot['gateway_latency_included'] === false &&
    $boot['pre_plugin_request_can_include_core_and_other_plugins'] === true &&
    $boot['other_plugin_component_attribution_available'] === false &&
    $boot['mutation_performed'] === false,
    'Plugin bootstrap metrics isolate source module loading and hooks, not external gateway time' );
echo "PASS: scoped diagnostics / Skills truth / snapshot isolation / bootstrap timing\n";
