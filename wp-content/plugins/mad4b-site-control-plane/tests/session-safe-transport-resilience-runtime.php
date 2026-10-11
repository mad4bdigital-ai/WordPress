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
    $schema['enum'] === array( 'full', 'identity' ),
    'Explicit low-cost identity diagnostics; full remains default' );
require_contract( $ability['meta']['annotations']['readonly'] === true &&
    $ability['meta']['annotations']['destructive'] === false,
    'Diagnostics remain read-only without authority escalation' );

$method = new ReflectionMethod( 'MAD4B_SCP_Read_Consistency', 'session_safe_operator_summary' );
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
echo "PASS: session-safe scoped schema / historical-vs-live Skills / write fail-closed\n";
