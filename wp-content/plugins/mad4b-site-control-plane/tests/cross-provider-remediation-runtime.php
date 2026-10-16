<?php
define( 'ABSPATH', __DIR__ . '/' );
class WP_Error {
    private $code;
    public function __construct( $code, $message ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error( $x ) { return $x instanceof WP_Error; }
class MAD4B_SCP_Policy {
    public static $read = true;
    public static function can_read() { return self::$read; }
}
class Fixture_Adapter {
    public function ability_names() {
        return array( 'read' => array( 'elementor/get-page-structure' ),
            'admin' => array( 'elementor/update-page' ) );
    }
    public function reversible_contracts() {
        return array( 'elementor/update-page' => 'mad4b.rollback.elementor.v1' );
    }
}
class MAD4B_SCP_Adapter_Registry {
    private static $instance;
    public static function instance() {
        if ( ! self::$instance ) self::$instance = new self();
        return self::$instance;
    }
    public function all() {
        return array( 'elementor' => new Fixture_Adapter() );
    }
}
function check( $ok, $message ) {
    if ( ! $ok ) { fwrite( STDERR, 'FAIL: ' . $message . "\n" ); exit( 1 ); }
}
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-remediation-coverage.php';
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-root-cause-resolver.php';
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-adaptive-operation-router.php';

// One reported Elementor isolation must not cause arbitrary LiteSpeed mutation.
$case = MAD4B_SCP_Root_Cause_Resolver::assess( array(
    'error_code' => 'other', 'issue_family' => 'visual_builder',
    'baseline_fails' => true,
    'affected_provider' => 'elementor', 'isolated_provider' => 'elementor',
    'isolation_test_passes' => true, 'isolation_restored_fails' => true,
    'include_provider_fleet' => true, 'provider_version_changed' => true,
) );
check( ! is_wp_error( $case ), 'generic root cause intake' );
$coverage = $case['cross_provider_remediation'];
check( $coverage['issue_family'] === 'visual_builder', 'issue domain supported' );
check( $coverage['isolation_assessment'] === 'REPORTED_REPEATABLE_PROVIDER_ISOLATION',
    'reported isolated causal relation categorized' );
check( $coverage['cause_confirmed'] === false, 'never trust submitted browser evidence' );
check( $coverage['post_update_continuity'] ===
    'READ_CONTINUITY_REVALIDATE_CHANGED_PROVIDER_WRITE_REQUIRES_EXACT_RECERTIFICATION',
    'safe version-neutral discovery; changed write review' );
check( count( $coverage['provider_inventory'] ) === 1, 'runtime registered fleet enumerated' );
check( $coverage['provider_inventory'][0]['adapter_registered'] === true,
    'registered adapter observed' );
check( $coverage['provider_inventory'][0]['adapter_runtime_verified'] === false,
    'registered adapter cannot self-certify runtime' );
check( $coverage['provider_inventory'][0]['mutation_certified'] === false,
    'no automatic grant from existing writer' );
check( $coverage['cross_provider_mutation_prohibited'] === true,
    'cannot transfer provider owner attribution' );
check( $coverage['browser_acceptance_executed'] === false,
    'browser acceptance still external' );
check( $coverage['browser_acceptance_plan_ability'] === 'mad4b/browser-acceptance-plan',
    'use existing browser subsystem' );
check( $case['mutation_performed'] === false, 'no writes in root cause assessment' );

// New/unknown provider may be a legitimate hypothesis; absence from adapter
// registry is a capability gap, not a reason to create another plugin.
$case = MAD4B_SCP_Root_Cause_Resolver::assess( array(
    'error_code' => 'other', 'issue_family' => 'multilingual',
    'baseline_fails' => true, 'affected_provider' => 'wpml',
) );
check( ! is_wp_error( $case ), 'unregistered provider accepted as hypothesis' );
$coverage = $case['cross_provider_remediation'];
check( count( $coverage['provider_inventory'] ) === 1
    && $coverage['provider_inventory'][0]['provider_id'] === 'wpml'
    && $coverage['provider_inventory'][0]['adapter_registered'] === false,
    'no invented provider binding' );
check( $coverage['provider_actions'][0]['next_action'] ===
    'discover_native_provider_capabilities_then_plan_adapter_extension',
    'native first instead of new plugin' );

// Invalid input, forged browser receipt, and unsupported family all fail closed.
$seed = array( 'error_code' => 'other', 'baseline_fails' => true );
foreach ( array(
    array( 'issue_family' => 'anything-you-like' ),
    array( 'affected_provider' => '../escape' ),
    array( 'isolated_provider' => 'WPML' ),
    array( 'include_provider_fleet' => 'yes' ),
    array( 'browser_receipt_sha256' => str_repeat( 'A', 64 ) ),
    array( 'owner_token' => 'pretend_authorization' ),
) as $case ) {
    check( is_wp_error( MAD4B_SCP_Root_Cause_Resolver::assess( array_merge( $seed, $case ) ) ),
        'invalid input rejected' );
}

foreach ( array(
    'JetSmartFilters AJAX count mismatch' => 'ajax_filters',
    'Elementor editor broken' => 'visual_builder',
    'JetEngine dynamic content broken' => 'dynamic_content',
    'WPML hreflang translation error' => 'multilingual',
    'Rank Math canonical issue' => 'seo',
    'Fluent Forms form submission failed' => 'forms',
    'LiteSpeed page speed issue' => 'performance',
    'MCP Adapter plugin update failure' => 'integration',
) as $query => $expected ) {
    $route = MAD4B_SCP_Adaptive_Operation_Router::native_first_diagnostic_handoff(
        array( 'task' => $query ) );
    check( is_array( $route ) && $route['issue_family_hint'] === $expected
        && $route['automatic_mutation_allowed'] === false,
        'provider-neutral safe router handoff ' . $expected );
}
MAD4B_SCP_Policy::$read = false;
check( is_wp_error( MAD4B_SCP_Root_Cause_Resolver::inspect( $seed ) ),
    'read permission enforced' );
echo "CROSS_PROVIDER_REMEDIATION_RUNTIME_PASS\n";
