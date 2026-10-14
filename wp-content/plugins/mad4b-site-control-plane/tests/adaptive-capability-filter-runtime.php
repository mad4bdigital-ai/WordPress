<?php
/* Real gateway metadata filter: read and write candidates remain separate. */
define( 'ABSPATH', __DIR__ );
class WP_Error {
    private $code;
    public function __construct( $code, $message = '', $data = array() ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function wp_json_encode( $x, $flags = 0 ) { return json_encode( $x, $flags ); }
function add_action( $hook, $callback, $priority = 10 ) {}
function sanitize_key( $value ) {
    return strtolower( preg_replace( '/[^a-z0-9_-]/i', '', (string) $value ) );
}
class MAD4B_SCP_Policy {
    public static function can_read() { return true; }
}
class MAD4B_SCP_ChatGPT_Tool_Projection {
    public static function all_site_ability_names() { return array_keys( $GLOBALS['abilities'] ); }
    public static function can_apply() { return new WP_Error( 'step_up_required' ); }
}
class FakeAbility {
    private $name;
    private $readonly;
    public function __construct( $name, $readonly ) {
        $this->name = $name;
        $this->readonly = $readonly;
    }
    public function get_meta() {
        $annotations = null === $this->readonly
            ? array() : array( 'readonly' => $this->readonly );
        return array( 'annotations' => $annotations, 'mcp' => array(
            'surface' => 'staging', 'search_aliases' => array( 'approval' ) ) );
    }
    public function get_label() { return 'Approval ' . $this->name; }
    public function get_description() { return 'Discover approval operation'; }
    public function get_category() { return 'mad4b-read'; }
}
function wp_has_ability( $name ) { return isset( $GLOBALS['abilities'][ $name ] ); }
function wp_get_ability( $name ) { return $GLOBALS['abilities'][ $name ]; }
function check_route( $ok, $why ) {
    if ( ! $ok ) { fwrite( STDERR, "FAIL: $why\n" ); exit( 1 ); }
}
$GLOBALS['abilities'] = array();
for ( $i = 0; $i < 30; $i++ )
    $GLOBALS['abilities']['example/approval-read-' . $i] =
        new FakeAbility( 'example/approval-read-' . $i, true );
$GLOBALS['abilities']['example/approval-plan'] =
    new FakeAbility( 'example/approval-plan', false );
$GLOBALS['abilities']['example/approval-unknown'] =
    new FakeAbility( 'example/approval-unknown', null );
$GLOBALS['abilities']['example/approval-untrusted-annotation'] =
    new FakeAbility( 'example/approval-untrusted-annotation', 'false' );
// The live gateway now requires a canonical authority scope and a bounded
// catalog index. Reproduce these readers, not a fabricated remote grant.
class MAD4B_SCP_Ability_Catalog_Transport {
    public static $scope_ready = true;
    public static function current_authority_scope() {
        return self::$scope_ready ? str_repeat( 'a', 64 ) : '';
    }
}
class MAD4B_SCP_Site_Profile {
    public static function status() { return array( 'profile_digest' => str_repeat( 'b', 64 ) ); }
}
function wp_salt( $scheme = 'auth' ) { return 'fixture-readonly-cursor-key'; }
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-capability-search-index.php';
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-unified-capability-gateway.php';
$gateway = 'MAD4B_SCP_Unified_Capability_Gateway';
// Default concurrency must be safe on slow/unmeasured WordPress hosts.
$unmeasured = $gateway::negotiate( array(), 'mcp' );
check_route( $unmeasured['transfer_policy']['recommended_parallel_schema_fetches'] === 1
    && $unmeasured['client_capabilities']['max_parallel_schema_fetches'] === 1,
    'Unmeasured WordPress requests recommend serial transport to avoid worker saturation' );
$slow = $gateway::negotiate( array( 'client_capabilities' => array(
    'observed_rtt_ms' => 6400,
    'recent_error_rate' => 0.2,
    'max_parallel_schema_fetches' => 8,
) ), 'mcp' );
check_route( $slow['transfer_policy']['recommended_parallel_schema_fetches'] === 1,
    'Slow or unreliable links remain serial despite client parallel capability' );
$fast = $gateway::negotiate( array( 'client_capabilities' => array(
    'observed_rtt_ms' => 80,
    'recent_error_rate' => 0,
    'max_parallel_schema_fetches' => 5,
) ), 'mcp' );
check_route( $fast['transfer_policy']['recommended_parallel_schema_fetches'] === 4,
    'Measured fast transport may opt into bounded concurrency' );
$bounded = $gateway::negotiate( array( 'client_capabilities' => array(
    'observed_rtt_ms' => 80,
    'recent_error_rate' => 0,
    'max_parallel_schema_fetches' => 1,
) ), 'mcp' );
check_route( $bounded['transfer_policy']['recommended_parallel_schema_fetches'] === 1,
    'Explicit client serial preference is respected' );
MAD4B_SCP_Ability_Catalog_Transport::$scope_ready = false;
$unscoped = $gateway::search( array( 'task' => 'approval' ) );
check_route( is_wp_error( $unscoped ) &&
    'mad4b_capability_search_scope_unavailable' === $unscoped->get_error_code(),
    'Missing authority scope denies capability discovery.' );
MAD4B_SCP_Ability_Catalog_Transport::$scope_ready = true;
$only_mutating = $gateway::search( array(
    'task' => 'approval', 'limit' => 5, 'declared_writeonly_only' => true ) );
check_route( $only_mutating['count'] === 1
    && $only_mutating['items'][0]['ability_name'] === 'example/approval-plan'
    && $only_mutating['declared_writeonly_filter_applied']
    && $only_mutating['search_mode'] === 'bounded_declared_mutation_metadata_relevance',
    'mutation-only search must find approval writer despite 30 high-scored readers' );
$only_reads = $gateway::search( array(
    'task' => 'approval', 'limit' => 5, 'declared_readonly_only' => true ) );
check_route( $only_reads['count'] === 5
    && !$only_reads['declared_writeonly_filter_applied']
    && !in_array( 'example/approval-plan',
        array_column( $only_reads['items'], 'ability_name' ), true ),
    'read-only search never leaks a declared mutation' );
$bad = $gateway::search( array(
    'task' => 'approval', 'declared_writeonly_only' => true,
    'declared_readonly_only' => true ) );
check_route( is_wp_error( $bad ), 'contradictory metadata filters fail closed' );
$malformed = $gateway::dispatch( array(
    'action' => 'search', 'task' => 'approval',
    'declared_writeonly_only' => 'yes' ) );
check_route( is_wp_error( $malformed )
    && $malformed->get_error_code() === 'mad4b_capability_gateway_write_filter_invalid',
    'nonboolean client write filter must be refused' );
check_route( $only_mutating['authority_effect'] === 'none'
    && $only_mutating['preparation_required_for_authority_and_schema'],
    'metadata discovery cannot grant write access or execution' );
$direct_invalid_write = $gateway::search( array(
    'task' => 'approval', 'declared_writeonly_only' => 'yes' ) );
check_route( is_wp_error( $direct_invalid_write )
    && $direct_invalid_write->get_error_code() === 'mad4b_capability_gateway_write_filter_invalid',
    'direct search rejects nonboolean mutation filter' );
$direct_invalid_read = $gateway::search( array(
    'task' => 'approval', 'declared_readonly_only' => 'yes' ) );
check_route( is_wp_error( $direct_invalid_read )
    && $direct_invalid_read->get_error_code() === 'mad4b_capability_gateway_read_filter_invalid',
    'direct search rejects nonboolean readonly filter' );
check_route( !in_array( 'example/approval-untrusted-annotation',
    array_column( $only_mutating['items'], 'ability_name' ), true )
    && !in_array( 'example/approval-untrusted-annotation',
        array_column( $only_reads['items'], 'ability_name' ), true ),
    'untrusted readonly annotations never promote to read or mutation' );
echo "mad4b.site-control-plane.adaptive-capability-filter.runtime.v1: PASS\n";
