<?php
/** Catalog availability must not run site census or browser capability callbacks. */
define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['catalog_browser_providers'] = array();
$GLOBALS['catalog_site_census_calls'] = 0;
$GLOBALS['catalog_browser_capability_calls'] = 0;
$GLOBALS['catalog_operation_scans'] = 0;
function add_action() {}
function add_filter() {}
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_-]/i', '', $value ) ); }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function wp_has_ability( $name ) { return true; }
function did_action( $hook ) { return 1; }
function doing_action( $hook ) { return false; }
function home_url( $path = '' ) { return 'https://catalog.example.test' . $path; }
function apply_filters( $hook, $value ) {
    if ( 'mad4b_browser_acceptance_providers' === $hook ) return $GLOBALS['catalog_browser_providers'];
    if ( 'mad4b_scp_remote_operation_catalog' === $hook ) ++$GLOBALS['catalog_operation_scans'];
    return $value;
}
class MAD4B_SCP_Site_Capability_Discovery {
    public static function observe( $origin, $providers ) {
        ++$GLOBALS['catalog_site_census_calls'];
        return array( 'read_only' => true, 'authorizing' => false );
    }
}
function catalog_boundary_check( $ok, $message ) {
    if ( ! $ok ) { fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL ); exit( 1 ); }
}
$plugin = dirname( __DIR__ );
require $plugin . '/includes/class-mad4b-scp-browser-acceptance-provider-registry.php';
require $plugin . '/includes/class-mad4b-scp-browser-acceptance-core.php';
require $plugin . '/includes/class-mad4b-scp-native-capability-browser-provider.php';
require $plugin . '/includes/class-mad4b-scp-remote-operation-parity.php';

$descriptor = MAD4B_SCP_Native_Capability_Browser_Provider::descriptor();
$provider = array(
    'provider_id' => $descriptor['provider_id'], 'contract' => $descriptor['contract'],
    'read_only' => true, 'authorizing' => false, 'transport_owned_by_provider' => false,
    'execution_mode' => 'external_browser_agent',
    'descriptor_callback' => static function () use ( $descriptor ) { return $descriptor; },
    'capabilities_callback' => static function () {
        ++$GLOBALS['catalog_browser_capability_calls'];
        return array( 'read_only' => true, 'authorizing' => false );
    },
    'plan_callback' => static function () { throw new RuntimeException( 'Unexpected browser plan.' ); },
    'result_callback' => static function () { throw new RuntimeException( 'Unexpected browser result.' ); },
);
$executor = new ReflectionMethod( 'MAD4B_SCP_Remote_Operation_Parity', 'executor_status' );
$executor->setAccessible( true );
$bad_descriptor = $descriptor;
$bad_descriptor['authorizing'] = true;
$cases = array(
    'valid' => array( array( 'one' => $provider ), true ),
    'empty' => array( array(), false ),
    'authorizing' => array( array( 'one' => array_replace( $provider, array( 'authorizing' => true ) ) ), false ),
    'descriptor-authorizing' => array( array( 'one' => array_replace( $provider, array(
        'descriptor_callback' => static function () use ( $bad_descriptor ) { return $bad_descriptor; },
    ) ) ), false ),
    'duplicate' => array( array( 'one' => $provider, 'two' => $provider ), false ),
    'overflow' => array( array_fill( 0, 33, $provider ), false ),
    'malformed' => array( 'invalid', false ),
    'callback-missing' => array( array( 'one' => array_replace( $provider, array( 'plan_callback' => null ) ) ), false ),
    'valid-again' => array( array( 'one' => $provider ), true ),
);
foreach ( $cases as $label => $case ) {
    $GLOBALS['catalog_browser_providers'] = $case[0];
    $status = $executor->invoke( null, 'external_browser_agent' );
    catalog_boundary_check( $status['available'] === $case[1], $label . ': executor availability drifted.' );
    catalog_boundary_check( $status['state'] === ( $case[1] ? 'browser_provider_available' : 'browser_provider_waiting' ), $label . ': state drifted.' );
    catalog_boundary_check( 0 === $GLOBALS['catalog_site_census_calls'], 'Executor availability performed a site census.' );
    catalog_boundary_check( 0 === $GLOBALS['catalog_browser_capability_calls'], 'Executor availability invoked browser capabilities.' );
}
// The whole operation catalog must retain current provider truth without census.
$rows = MAD4B_SCP_Remote_Operation_Parity::catalog();
catalog_boundary_check( ! empty( $rows ), 'Operation catalog was lost.' );
catalog_boundary_check( 0 === $GLOBALS['catalog_site_census_calls'], 'Operation catalog performed a site census.' );
catalog_boundary_check( 0 === $GLOBALS['catalog_browser_capability_calls'], 'Operation catalog invoked browser capabilities.' );

// Explicit operator capability inspection still owns its full discovery work.
$full = MAD4B_SCP_Browser_Acceptance_Core::capabilities();
catalog_boundary_check( 1 === $full['provider_count'], 'Full capability projection lost its valid provider.' );
catalog_boundary_check( 1 === $GLOBALS['catalog_site_census_calls'], 'Explicit capability inspection skipped its site census.' );
catalog_boundary_check( 1 === $GLOBALS['catalog_browser_capability_calls'], 'Explicit capability inspection skipped its provider.' );
$GLOBALS['catalog_browser_providers'] = array();
catalog_boundary_check( false === $executor->invoke( null, 'external_browser_agent' )['available'], 'Provider removal was hidden by a stale cache.' );

// Other server catalogs must not materialize the enrollment operation catalog.
require $plugin . '/includes/class-mad4b-scp-servers.php';
$GLOBALS['catalog_operation_scans'] = 0;
catalog_boundary_check( in_array( 'mad4b/content-get-post', MAD4B_SCP_Servers::core_tools( 'mad4b-content' ), true ), 'Content catalog drifted.' );
catalog_boundary_check( array( 'mad4b/database-raw-query' ) === MAD4B_SCP_Servers::core_tools( 'mad4b-breakglass' ), 'Breakglass declaration drifted.' );
catalog_boundary_check( array() === MAD4B_SCP_Servers::core_tools( 'unknown' ), 'Unknown server acquired tools.' );
catalog_boundary_check( 0 === $GLOBALS['catalog_operation_scans'], 'Unrelated server scanned the enrollment catalog.' );
$enrollment = MAD4B_SCP_Servers::core_tools( 'mad4b-enrollment' );
catalog_boundary_check( in_array( MAD4B_SCP_Remote_Operation_Parity::SKILLS_ABILITY, $enrollment, true ), 'Enrollment lost its built-in tools.' );
catalog_boundary_check( $GLOBALS['catalog_operation_scans'] > 0, 'Enrollment skipped its live operation validation.' );
echo "mad4b.catalog-executor-discovery-boundary.v1: PASS (9 provider states; live removal; explicit discovery; server isolation)\n";
