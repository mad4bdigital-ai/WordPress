<?php
/** No WordPress, no external I/O: prove that old checkpoint interpretation
 *  is read-only and cannot bypass an active owner/write gate.
 */
define( 'ABSPATH', __DIR__ );
$GLOBALS['mutate_allowed'] = false;
$GLOBALS['writes'] = 0;
class MAD4B_SCP_Policy {
    public static function can_mutate() { return $GLOBALS['mutate_allowed']; }
}
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function update_option() { $GLOBALS['writes']++; throw new RuntimeException( 'No checkpoint mutation permitted in read test.' ); }
function add_action( $hook, $callback, $priority = 10 ) { return true; }
require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-runtime-convergence.php';
$old = array(
    'version' => '0.4.0-rc.102', 'source_commit_sha' => str_repeat( 'a', 40 ),
    'build_fingerprint' => str_repeat( 'b', 64 ),
    'package_manifest_digest' => str_repeat( 'c', 64 ),
    'artifact_identity' => 'mad4b-site-control-plane-rc102',
);
$new = array(
    'version' => '0.4.0-rc.118', 'source_commit_sha' => str_repeat( 'd', 40 ),
    'build_fingerprint' => str_repeat( 'e', 64 ),
    'package_manifest_digest' => str_repeat( 'f', 64 ),
    'artifact_identity' => 'mad4b-site-control-plane-rc118',
);
$checkpoint = array(
    'source' => 'self_update', 'state' => 'pending_manual_resume',
    'resume_blocker' => 'mad4b_automation_automation_kill_switch',
    'target_identity' => $old,
);
$graph = array(
    'environment' => 'staging', 'state' => 'convergence_required', 'ready' => false,
    'phase_graph_valid' => true,
    'required_blockers' => array( 'authority_binding:owner_gate' ),
    'current_identity' => $new,
    'checkpoint' => $checkpoint,
    'checkpoint_operator_state' => array(
        'state' => 'historical_checkpoint_independent_blockers',
        'replay_old_checkpoint_allowed' => false,
    ),
    'phases' => array(
        'deployment' => array( 'required' => true, 'state' => 'ready' ),
        'schema' => array( 'required' => true, 'state' => 'ready' ),
        'local_oauth_store' => array( 'required' => true, 'state' => 'ready' ),
        'managed_skills' => array( 'required' => true, 'state' => 'ready' ),
        'authority_binding' => array( 'required' => true, 'state' => 'owner_gate' ),
    ),
);
// Read-only exception must never lift the general write/restart convergence gate.
$general = new ReflectionMethod( 'MAD4B_SCP_Runtime_Convergence', 'superseded_checkpoint_graph_safe' );
$general->setAccessible( true );
if ( $general->invoke( null, $checkpoint, $new, time() - 5, $graph ) !== false ) {
    fwrite( STDERR, 'FAIL owner write gate was incorrectly released' . PHP_EOL );
    exit( 1 );
}
$method = new ReflectionMethod( 'MAD4B_SCP_Runtime_Convergence', 'superseded_checkpoint_mcp_read_safe' );
$method->setAccessible( true ); // PHP 7.4 compatibility for private method regression checks.
$check = static function ( $expected, $checkpoint, $current, $graph, $description ) use ( $method ) {
    $result = $method->invoke( null, $checkpoint, $current, time() - 5, $graph );
    if ( $result !== $expected ) {
        fwrite( STDERR, 'FAIL ' . $description . ': ' . var_export( $result, true ) . PHP_EOL );
        exit( 1 );
    }
};
$check( true, $checkpoint, $new, $graph, 'obsolete exact version with only ungranted owner write' );
$GLOBALS['mutate_allowed'] = true;
$check( true, $checkpoint, $new, $graph, 'caller agent mutation permission must not decide historical transport readiness' );
$GLOBALS['mutate_allowed'] = false;
$g = $graph; $g['phases']['schema']['state'] = 'pending';
$check( false, $checkpoint, $new, $g, 'required schema pending' );
$g = $graph; $g['required_blockers'][] = 'managed_skills:pending';
$check( false, $checkpoint, $new, $g, 'unreviewed blocker' );
$g = $graph; $g['environment'] = 'production';
$check( false, $checkpoint, $new, $g, 'production never waived' );
$g = $graph; $g['phase_graph_valid'] = false;
$check( false, $checkpoint, $new, $g, 'invalid phase graph' );
$g = $graph; $g['checkpoint_operator_state']['replay_old_checkpoint_allowed'] = true;
$check( false, $checkpoint, $new, $g, 'old checkpoint replay permission never allowed' );
$g = $graph; $g['current_identity']['source_commit_sha'] = str_repeat( '9', 40 );
$check( false, $checkpoint, $new, $g, 'unverified current identity' );
$bad = $checkpoint; $bad['state'] = 'blocked';
$check( false, $bad, $new, $graph, 'blocked checkpoint no inference' );
$bad = $checkpoint; $bad['target_identity'] = $new;
$check( false, $bad, $new, $graph, 'same candidate is not historical' );
$bad = $new; $bad['version'] = '0.4.0-rc.102';
$check( false, $checkpoint, $bad, $graph, 'not newer version' );
$g = $graph; $g['required_blockers'] = array( 'deployment:pending', 'authority_binding:owner_gate' );
$g['phases']['deployment'] = array( 'required' => true, 'state' => 'pending',
    'details' => array( 'target_identity' => $old, 'cached_release_target_present' => true,
        'manifest_state' => 'ready' ) );
$check( true, $checkpoint, $new, $g, 'verified older cached release plus owner gate' );
$g['phases']['deployment']['details']['manifest_state'] = 'unverified';
$check( false, $checkpoint, $new, $g, 'unverified cache blocks release' );
if ( $GLOBALS['writes'] !== 0 ) { fwrite( STDERR, 'FAIL attempted mutation' ); exit( 1 ); }
echo "RUNTIME_SUPERSEDED_OWNER_GATE_READONLY: PASS\n";
