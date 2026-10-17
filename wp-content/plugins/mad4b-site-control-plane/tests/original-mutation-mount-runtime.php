<?php
/** Original surfaces must honor provider Write denial; no execution authority is issued. */
define( 'ABSPATH', __DIR__ . '/' );
class WP_Error {
    private $code;
    public function __construct( $code, $message = '', $data = null ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function add_filter() {}
function apply_filters( $hook, $value ) { return $value; }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_-]/i', '', $value ) ); }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function get_current_blog_id() { return 1; }
// Reproduce MCP registration in WP-CLI before REST has initialized.
function did_action( $hook ) { return 'wp_abilities_api_init' === $hook ? 1 : 0; }
function doing_action( $hook ) { return false; }
function wp_has_ability( $name ) { return isset( $GLOBALS['mount_abilities'][ $name ] ); }
function wp_get_ability( $name ) { return $GLOBALS['mount_abilities'][ $name ] ?? null; }
class Mount_Ability {
    private $readonly;
    private $surface;
    public function __construct( $readonly, $surface = 'read' ) { $this->readonly = $readonly; $this->surface = $surface; }
    public function get_meta() { return array(
        'annotations' => array( 'readonly' => $this->readonly ),
        'mcp' => array( 'surface' => $this->surface ),
    ); }
    public function execute() { throw new RuntimeException( 'Descriptor inspection must not execute an ability.' ); }
    public function get_input_schema() { return array( 'type' => 'object' ); }
    public function get_output_schema() { return array( 'type' => 'object' ); }
}
class Mount_Adapter {
    public $eligible = false;
    public $status_calls = 0;
    public $eligibility_calls = 0;
    public function id() { return 'guarded-fixture'; }
    public function provider_key() { return 'core'; }
    public function status() { ++$this->status_calls; return array( 'mutation_requires_certification' => false ); }
    public function ability_names() { return array(
        'read' => array( 'example/read' ), 'content' => array( 'example/content' ),
        'admin' => array( 'example/admin', 'mad4b/wp-native-self-approve-install' ), 'write' => array( 'example/write-only' ),
    ); }
    public function mutation_ability_runtime_eligibility( $name ) {
        ++$this->eligibility_calls;
        return $this->eligible ? true : new WP_Error( 'fixture_prerequisite_missing' );
    }
}
class MAD4B_SCP_Adapter_Registry {
    public static $adapter;
    public static function instance() { return new self(); }
    public function register_defaults() {}
    public function all() { return array( self::$adapter ); }
    public function ability_names( $surface ) { return self::$adapter->ability_names()[ $surface ] ?? array(); }
}
function mount_check( $ok, $message ) {
    if ( ! $ok ) { fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL ); exit( 1 ); }
}
MAD4B_SCP_Adapter_Registry::$adapter = new Mount_Adapter();
$GLOBALS['mount_abilities'] = array(
    'example/admin' => new Mount_Ability( false, 'admin' ),
    'example/content' => new Mount_Ability( false, 'content' ),
    'example/write-only' => new Mount_Ability( false, 'write' ),
    'mad4b/wp-native-self-approve-install' => new Mount_Ability( false, 'admin' ),
    'example/read' => new Mount_Ability( true ),
);
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-servers.php';
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-ability-contract-inspector.php';
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-capability-descriptor-registry.php';
$descriptor_hashes = array();
$filter = null;
foreach ( array( false, true, false ) as $eligible ) {
    MAD4B_SCP_Adapter_Registry::$adapter->eligible = $eligible;
    MAD4B_SCP_Servers::reset_request_cache();
    $adapter = MAD4B_SCP_Adapter_Registry::$adapter;
    $adapter->status_calls = 0;
    $adapter->eligibility_calls = 0;
    foreach ( array( 'example/admin', 'example/content', 'mad4b/wp-native-self-approve-install' ) as $ability ) {
        $binding = MAD4B_SCP_Capability_Descriptor_Registry::binding( $ability, 'servers' );
        mount_check( ! is_wp_error( $binding ), 'Structural descriptor must exist independently of provider readiness.' );
        mount_check( false === $binding['authorizing'] && true === $binding['authority_revalidation_required'],
            'Structural descriptor must not create execution authority.' );
        if ( isset( $descriptor_hashes[ $ability ] ) ) {
            mount_check( $descriptor_hashes[ $ability ] === $binding['descriptor_sha256'],
                'Runtime eligibility changed canonical descriptor identity: ' . $ability );
        }
        $descriptor_hashes[ $ability ] = $binding['descriptor_sha256'];
    }
    mount_check( 0 === $adapter->status_calls && 0 === $adapter->eligibility_calls,
        'Structural descriptors enumerated live provider Write eligibility.' );
    mount_check( null === MAD4B_SCP_Servers::provider_for_capability_descriptor( 'mad4b-admin', 'example/content' )
        && null === MAD4B_SCP_Servers::provider_for_capability_descriptor( 'mad4b-admin', 'example/write-only' ),
        'Structural identity crossed its declared surface.' );
    foreach ( array(
        'example/admin' => 'mad4b-admin', 'example/content' => 'mad4b-content',
        'mad4b/wp-native-self-approve-install' => 'mad4b-admin',
    ) as $ability => $server ) {
        mount_check( MAD4B_SCP_Servers::ability_is_mounted( 'mad4b-write', $ability ) === $eligible,
            'Write must track effective provider eligibility: ' . $ability );
        mount_check( MAD4B_SCP_Servers::ability_is_mounted( $server, $ability ) === $eligible,
            'Original surface must not bypass Write eligibility: ' . $ability );
        if ( null === $filter ) {
            $filter = new ReflectionMethod( 'MAD4B_SCP_Servers', 'original_mutation_mount_tools' );
            $filter->setAccessible( true );
        }
        $materialized = $filter->invoke( null, $server, array( $ability, 'example/read' ) );
        mount_check( in_array( $ability, $materialized, true ) === $eligible,
            'Actual materialization must agree with mount readback: ' . $ability );
        mount_check( in_array( 'example/read', $materialized, true ), 'Readonly metadata must remain available.' );
    }
    mount_check( MAD4B_SCP_Servers::ability_is_mounted( 'mad4b-read', 'example/read' ), 'Readonly provider must remain mounted.' );
    mount_check( $adapter->status_calls > 0 && $adapter->eligibility_calls > 0,
        'Runtime mount resolution skipped current provider eligibility.' );
}
echo 'PASS: stable structural descriptors avoid live Write scans; original Content/Admin mount and materialization honor current provider Write denial' . PHP_EOL;
