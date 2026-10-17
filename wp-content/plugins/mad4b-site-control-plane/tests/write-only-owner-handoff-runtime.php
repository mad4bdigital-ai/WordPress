<?php
// Hermetic, PHP 7.4+ regression for read-only owner handoff inside an existing
// direct MCP handshake. Never dispatch a governed write in this fixture.
define( 'ABSPATH', __DIR__ . '/' );
class WP_Error {
    private $code;
    public function __construct( $code ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function sanitize_key( $value ) {
    return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) );
}
class MAD4B_SCP_Staging_Write_Authority_Convergence {
    public static $result;
    public static $calls = 0;
    public static function handshake() {
        ++self::$calls;
        return self::$result;
    }
}
class MAD4B_SCP_Staging_Write_Owner_Intent {
    public static $state = 'OWNER_INTENT_CURRENT';
    public static $calls = 0;
    public static function page_intent_status( $review = null ) {
        ++self::$calls;
        if ( empty( $review['review_digest'] ) ) throw new RuntimeException( 'missing review' );
        return array( 'state' => self::$state, 'expires_at' => 1792300000 );
    }
}
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-full-staging-authority.php';

function check_handoff( $condition, $message ) {
    if ( ! $condition ) {
        fwrite( STDERR, 'FAIL ' . $message . PHP_EOL );
        exit( 1 );
    }
}
$generation = str_repeat( '1', 64 );
$source = str_repeat( '2', 40 );
$plan_hash = str_repeat( '3', 64 );
$review_hash = str_repeat( '4', 64 );
$exact = array(
    'expected_plan_sha256' => $plan_hash,
    'expected_source_commit_sha' => $source,
    'expected_build_fingerprint' => str_repeat( '5', 64 ),
    'expected_package_manifest_digest' => str_repeat( '6', 64 ),
    'expected_artifact_identity' => 'mad4b-site-control-plane-0.4.0-rc.102-' . $source,
    'expected_site_uuid' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
    'expected_profile_revision' => 7,
    'expected_profile_digest' => str_repeat( '7', 64 ),
    'owner_review_digest' => $review_hash,
    'confirmation' => 'ENABLE GOVERNED STAGING WRITE AUTHORITY',
);
MAD4B_SCP_Staging_Write_Authority_Convergence::$result = array(
    'runtime_generation' => $generation,
    'ready_to_apply' => true,
    'already_current' => false,
    'hard_blockers' => array(),
    'exact_apply' => $exact,
);
$method = new ReflectionMethod( 'MAD4B_SCP_Full_Staging_Authority', 'write_only_handoff' );
$method->setAccessible( true );
$invoke = static function ( $expected ) use ( $method ) {
    return $method->invoke( null, $expected );
};
$ok = $invoke( $generation );
check_handoff( $ok['state'] === 'awaiting_exact_write_only_apply', 'write-only handoff readiness' );
check_handoff( $ok['owner_review_current'] === true, 'exact current owner review is visible' );
check_handoff( $ok['exact_apply']['expected_plan_sha256'] === $plan_hash, 'independent plan preserved' );
check_handoff( $ok['exact_apply']['owner_review_digest'] === $review_hash, 'review digest preserved' );
check_handoff( $ok['exact_apply']['confirmation'] === 'ENABLE GOVERNED STAGING WRITE AUTHORITY', 'Write-only confirmation preserved' );
check_handoff( $ok['authority_scope'] === 'governed_staging_write_only'
    && $ok['developer_authority_requested'] === false
    && $ok['developer_breakglass_requested'] === false
    && $ok['production_mutation_allowed'] === false
    && $ok['mutation_performed'] === false,
    'no Full Authority, Breakglass, Production, or mutation scope inherited' );
$wrong = $invoke( str_repeat( 'f', 64 ) );
check_handoff( $wrong['state'] === 'stale_generation' && ! isset( $wrong['exact_apply'] ), 'stale generation cannot expose reusable plan' );
MAD4B_SCP_Staging_Write_Authority_Convergence::$result = new WP_Error( 'mad4b_review_blocked' );
$failed = $invoke( $generation );
check_handoff( $failed['state'] === 'read_failed' && $failed['blocker'] === 'mad4b_review_blocked'
    && ! isset( $failed['exact_apply'] ), 'safe named error instead of opaque internal failure' );
MAD4B_SCP_Staging_Write_Authority_Convergence::$result = array(
    'runtime_generation' => $generation,
    'ready_to_apply' => false,
    'already_current' => false,
    'exact_apply' => array( 'expected_plan_sha256' => $plan_hash ),
);
$incomplete = $invoke( $generation );
check_handoff( $incomplete['state'] === 'read_failed' && ! isset( $incomplete['exact_apply'] ),
    'incomplete plan fails closed' );
MAD4B_SCP_Staging_Write_Authority_Convergence::$result = array(
    'runtime_generation' => $generation,
    'ready_to_apply' => false,
    'already_current' => true,
    'exact_apply' => $exact,
);
MAD4B_SCP_Staging_Write_Owner_Intent::$state = 'OWNER_INTENT_CLAIMED';
$current = $invoke( $generation );
check_handoff( $current['state'] === 'already_current' && ! $current['owner_review_current'],
    'already-converged is not owner intent reuse' );
check_handoff( MAD4B_SCP_Staging_Write_Authority_Convergence::$calls === 5, 'one bounded fresh read per handoff' );
echo "WRITE_ONLY_OWNER_HANDOFF_RUNTIME_PASS\n";
