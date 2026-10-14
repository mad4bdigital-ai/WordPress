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
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_-]/i', '', $value ) ); }
function wp_has_ability( $name ) { return isset( $GLOBALS['mount_abilities'][ $name ] ); }
function wp_get_ability( $name ) { return $GLOBALS['mount_abilities'][ $name ] ?? null; }
class Mount_Ability {
    private $readonly;
    public function __construct( $readonly ) { $this->readonly = $readonly; }
    public function get_meta() { return array( 'annotations' => array( 'readonly' => $this->readonly ) ); }
}
class Mount_Adapter {
    public $eligible = false;
    public function id() { return 'guarded-fixture'; }
    public function provider_key() { return 'core'; }
    public function status() { return array( 'mutation_requires_certification' => false ); }
    public function ability_names() { return array(
        'read' => array( 'example/read' ), 'content' => array( 'example/content' ),
        'admin' => array( 'example/admin', 'mad4b/wp-native-self-approve-install' ), 'write' => array(),
    ); }
    public function mutation_ability_runtime_eligibility( $name ) {
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
    'example/admin' => new Mount_Ability( false ),
    'example/content' => new Mount_Ability( false ),
    'mad4b/wp-native-self-approve-install' => new Mount_Ability( false ),
    'example/read' => new Mount_Ability( true ),
);
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-servers.php';
$filter = null;
foreach ( array( false, true, false ) as $eligible ) {
    MAD4B_SCP_Adapter_Registry::$adapter->eligible = $eligible;
    MAD4B_SCP_Servers::reset_request_cache();
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
}
echo 'PASS: original Content/Admin mount and materialization honor current provider Write denial' . PHP_EOL;
