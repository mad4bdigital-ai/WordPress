<?php
/* Pure hermetic native-release ancestry admission tests. No network/site mutation. */
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );
class WP_Error {
    private $code;
    public function __construct( $code, $message = '' ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error( $x ) { return $x instanceof WP_Error; }
$GLOBALS['lineage_response'] = array();
$GLOBALS['lineage_requests'] = array();
$GLOBALS['lineage_cache'] = array();
function get_transient( $key ) { return $GLOBALS['lineage_cache'][$key] ?? false; }
function set_transient( $key, $value, $ttl ) {
    if ( $ttl !== 300 ) throw new RuntimeException( 'Lineage cache duration drift' );
    $GLOBALS['lineage_cache'][$key] = $value; return true;
}
function wp_safe_remote_get( $url, $options ) {
    $GLOBALS['lineage_requests'][] = array( $url, $options );
    if ( $options['sslverify'] !== true || $options['redirection'] !== 0
        || $options['limit_response_size'] !== 262145
        || strpos( $url, 'https://api.github.com/repos/mad4bdigital-ai/WordPress/compare/' ) !== 0 )
        throw new RuntimeException( 'Untrusted lineage request' );
    return $GLOBALS['lineage_response'];
}
function wp_remote_retrieve_response_code( $response ) { return $response['code']; }
function wp_remote_retrieve_body( $response ) { return $response['body']; }
function require_relation( $valid, $message ) {
    if ( ! $valid ) { fwrite( STDERR, "FAIL: $message\n" ); exit( 1 ); }
}
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-self-update.php';
$c = 'MAD4B_SCP_Self_Update';
$current = array( 'source_commit_sha' => str_repeat( 'a', 40 ), 'version' => '0.4.0-rc.102' );
$target = array( 'source_commit_sha' => str_repeat( 'b', 40 ), 'version' => '0.4.0-rc.102' );
require_relation( 'same' === $c::native_source_relationship( $current, $current ),
    'Exact same source must be current' );
require_relation( 'unknown' === $c::native_source_relationship( $current, $target, false ),
    'UI must not infer same-version upgrade without source ancestry' );
$older = $target; $older['version'] = '0.4.0-rc.101';
require_relation( 'target_behind' === $c::native_source_relationship( $current, $older ),
    'Older version cannot be offered without comparing branches' );
function simulate_lineage( $status, $ahead, $behind ) {
    $GLOBALS['lineage_response'] = array( 'code' => 200,
        'body' => json_encode( array( 'status' => $status,
            'ahead_by' => $ahead, 'behind_by' => $behind ) ) );
}
simulate_lineage( 'behind', 0, 273 );
require_relation( 'target_behind' === $c::native_source_relationship( $current, $target ),
    'Old SHA with same semantic version must be blocked as ancestor' );
require_relation( 'target_behind' === $c::native_source_relationship( $current, $target, false ),
    'UI must use verified source relation, not semver to offer rollback' );
$new = $target; $new['source_commit_sha'] = str_repeat( 'c', 40 );
simulate_lineage( 'ahead', 3, 0 );
require_relation( 'target_ahead' === $c::native_source_relationship( $current, $new ),
    'Certified successor branch should be eligible for subsequent governed checks' );
require_relation( 'target_ahead' === $c::native_source_relationship( $current, $new, false ),
    'Verified newer source should be cached for lightweight Plugins UI' );
$fork = $target; $fork['source_commit_sha'] = str_repeat( 'd', 40 );
simulate_lineage( 'diverged', 2, 7 );
require_relation( 'diverged' === $c::native_source_relationship( $current, $fork ),
    'Divergent branch must need an explicit review' );
$unknown = $target; $unknown['source_commit_sha'] = str_repeat( 'e', 40 );
$GLOBALS['lineage_response'] = new WP_Error( 'network_down' );
require_relation( 'unknown' === $c::native_source_relationship( $current, $unknown ),
    'Network outage must fail closed' );
$GLOBALS['lineage_response'] = array( 'code' => 200, 'body' => '{invalid' );
require_relation( 'unknown' === $c::native_source_relationship( $current, $unknown ),
    'Malformed GitHub response must fail closed' );
$GLOBALS['lineage_response'] = array( 'code' => 200,
    'body' => json_encode( array( 'status' => 'ahead', 'ahead_by' => 5, 'behind_by' => 0 ) ) );
require_relation( 'target_ahead' === $c::native_source_relationship( $current, $unknown ),
    'Successful retry should independently attest successor' );
require_relation( count( $GLOBALS['lineage_requests'] ) === 6,
    'Unexpected network accesses; UI and same/older source should remain local' );
$fixture = sys_get_temp_dir() . '/mad4b-lineage-' . bin2hex( random_bytes( 8 ) ) . '/';
mkdir( $fixture, 0700 );
define( 'MAD4B_SCP_DIR', $fixture );
define( 'MAD4B_SCP_FILE', $fixture . 'plugin.php' );
file_put_contents( MAD4B_SCP_FILE, "<?php\n/*\n * Version: 0.4.0-rc.102\n */\n" );
file_put_contents( $fixture . 'MAD4B-BUILD-PROVENANCE.json', json_encode( $current ) );
register_shutdown_function( static function () use ( $fixture ) {
    unlink( $fixture . 'plugin.php' ); unlink( $fixture . 'MAD4B-BUILD-PROVENANCE.json' ); rmdir( $fixture );
} );
$GLOBALS['lineage_cache'] = array();
$before = count( $GLOBALS['lineage_requests'] );
require_relation( 'needs_review' === $c::native_release_state( $new )['state'], 'Cold passive UI must not claim current' );
require_relation( count( $GLOBALS['lineage_requests'] ) === $before, 'Passive UI contacted GitHub' );
simulate_lineage( 'ahead', 3, 0 );
require_relation( 'available' === $c::native_release_state( $new, true )['state'], 'Explicit check failed to warm cold lineage' );
require_relation( 'available' === $c::native_release_state( $new )['state'], 'Passive UI did not reuse explicit verified check' );
$GLOBALS['lineage_cache'] = array(); // Simulate the 300-second cache expiring.
$GLOBALS['lineage_response'] = new WP_Error( 'network_down' );
require_relation( 'needs_review' === $c::native_release_state( $new, true )['state'], 'Expired/network unknown was mislabeled current' );
simulate_lineage( 'behind', 0, 273 );
require_relation( 'needs_review' === $c::native_release_state( $target, true )['state'], 'Ancestor was mislabeled current' );
require_relation( 'current' === $c::native_release_state( $current )['state'], 'Exact installed source must remain current' );
$admission = new ReflectionMethod( $c, 'native_replacement_admission' );
$admission->setAccessible( true );
foreach ( array( 'governed_native_release_pull', 'governed_native_release_pull_bootstrap',
    'governed_staging_recovery_cron', 'wordpress_admin_plugin_update', 'wordpress_admin_candidate_drift_bootstrap' ) as $channel ) {
    $GLOBALS['lineage_cache'] = array();
    simulate_lineage( 'ahead', 3, 0 );
    require_relation( true === $admission->invoke( null, $new, $channel ), 'Verified native successor rejected: ' . $channel );
    // Another completed installation overtakes the offered SHA while the
    // first worker downloads; the disk identity is now newer than the target.
    $overtaken = $new; $overtaken['source_commit_sha'] = str_repeat( 'f', 40 );
    file_put_contents( $fixture . 'MAD4B-BUILD-PROVENANCE.json', json_encode( $overtaken ) );
    simulate_lineage( 'behind', 0, 2 );
    require_relation( is_wp_error( $admission->invoke( null, $new, $channel ) ), 'Native replacement overtook a newer installed build: ' . $channel );
    file_put_contents( $fixture . 'MAD4B-BUILD-PROVENANCE.json', json_encode( $current ) );
}
require_relation( true === $admission->invoke( null, $target, 'governed_staging_candidate_upload' ),
    'Native ancestry gate must not replace explicit owner-governed candidate rollback policy' );
echo "NATIVE_SOURCE_LINEAGE: PASS (same, older, ancestor, successor, divergent, unknown and cache)\n";
