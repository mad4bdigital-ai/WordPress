<?php
/** Google OAuth is not equivalent to a certified Context write mount. */
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );
function add_action( $name, $callback, $priority = 10 ) {}
function sanitize_key( $x ) { return strtolower( preg_replace( '/[^a-z0-9_-]/', '', (string) $x ) ); }
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-provider-closure-matrix.php';
$method = new ReflectionMethod( 'MAD4B_SCP_Provider_Closure_Matrix', 'prerequisite_chain' );
$method->setAccessible( true );
$assert = static function ( $ok, $label ) {
    if ( ! $ok ) { fwrite( STDERR, 'FAIL: ' . $label . PHP_EOL ); exit( 1 ); }
};
$status = array(
    'artifact_authority_required' => true,
    'artifact_authority_bound' => false,
    'risk' => 'bounded_write',
    'reversible' => true,
    'behavioral_evidence' => array( 'behavioral_verified' => false, 'rollback_verified' => false ),
);
$read = static function ( $code, $selected ) use ( $method ) {
    return $method->invoke( null, $code, array( 'status' => $selected ) );
};
$stages = $read( 'mad4b_context_source_required_for_write', $status );
$assert( array_column( $stages, 'gate' ) === array(
    'artifact_identity', 'governed_context_source', 'behavioral_and_rollback_evidence',
), 'Missing artifact and source must be reported independently before behavioral probe' );
$assert( count( array_filter( $stages, static function ( $s ) { return $s['state'] === 'blocked'; } ) ) === 3,
    'No blocked gate is implicitly authorized by Google OAuth' );
$status['artifact_authority_required'] = false;
$status['artifact_authority_bound'] = true;
$stages = $read( 'mad4b_context_source_policy_blocks_write', $status );
$assert( array_column( $stages, 'gate' ) === array(
    'governed_context_source', 'behavioral_and_rollback_evidence',
), 'Artifact identity success does not authorize source policy or behavior' );
$status['behavioral_evidence'] = array( 'behavioral_verified' => true, 'rollback_verified' => true );
$assert( array_column( $read( '', $status ), 'gate' ) === array(), 'Certified prerequisites do not invent new blockers' );
$status['risk'] = 'high_risk_write';
$stages = $read( 'mad4b_google_drive_create_rollback_not_certified', $status );
$assert( array_column( $stages, 'gate' ) === array( 'drive_create_compensation' ),
    'Generic create remains blocked even after unrelated provider evidence' );
echo "PASS: Google Drive provider prerequisites remain independent, ordered, and fail closed\n";
