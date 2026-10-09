<?php
/** CSO01 isolated WordPress Ability contract smoke + adversarial denial tests. */
define( 'ABSPATH', '/fixture/' );
class WP_Error {
    private $code;
    public function __construct( $code, $message = '' ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function wp_json_encode( $data ) { return json_encode( $data ); }
$GLOBALS['can_read'] = true;
$GLOBALS['enrolled'] = true;
$GLOBALS['site_origin'] = 'https://staging.example.org';
$GLOBALS['abilities'] = array();
$GLOBALS['hooks'] = array();
function current_user_can( $cap ) { return 'manage_options' === $cap && $GLOBALS['can_read']; }
function get_current_blog_id() { return 3; }
function get_current_user_id() { return 7; }
function add_action( $name, $cb, $priority = 10 ) { $GLOBALS['hooks'][] = $name; }
function wp_has_ability( $name ) { return isset( $GLOBALS['abilities'][ $name ] ); }
function wp_register_ability( $name, $args ) { $GLOBALS['abilities'][ $name ] = $args; }
function wp_get_ability( $name ) { return new Fake_Ability( $GLOBALS['abilities'][ $name ] ); }
class MAD4B_SCP_Policy { public static function can_read() { return true; }
    public static function can_connect_user( $id ) { return 7 === $id && ( ! isset( $GLOBALS['actor_enrolled'] ) || $GLOBALS['actor_enrolled'] ); } }
class MAD4B_SCP_Site_Profile {
    public static function configured() { return $GLOBALS['enrolled']; }
    public static function origin_enrolled() { return $GLOBALS['enrolled']; }
    public static function site_uuid() { return '12345678-1234-1234-1234-123456789abc'; }
    public static function current_origin() { return $GLOBALS['site_origin']; }
    public static function current_environment() { return 'staging'; }
    public static function revision() { return 'v9'; }
}
class MAD4B_SCP_Unified_Capability_Gateway {
    public static function runtime_blog_matches() { return ! empty( $GLOBALS['blog_ok'] ) || ! isset( $GLOBALS['blog_ok'] ); }
}
class MAD4B_SCP_Servers {
    public static function core_tools( $name ) { return 'mad4b-read' === $name ? array( 'mad4b/site-info' ) : array(); }
}
class MAD4B_SCP_Capability_Descriptor_Registry {
    public static function binding( $name, $consumer ) {
        return array( 'execution_lane' => isset( $GLOBALS['lane'] ) ? $GLOBALS['lane'] : 'read',
            'descriptor_sha256' => str_repeat( 'a', 64 ), 'consumer' => $consumer,
            'ability_name' => $name, 'generation_roots' => array( 'site_root' => 'mock' ) );
    }
}
class MAD4B_SCP_Site_Capability_Discovery {
    public static function observe( $origin ) {
        return array( 'discovery_complete' => ! isset( $GLOBALS['inventory_ready'] ) || $GLOBALS['inventory_ready'], 'origin' => $origin, 'plugins' => array(),
            'post_types' => array( 'post' ), 'taxonomies' => array( 'category' ),
            'provider_matches' => array(), 'certification_issued' => false, 'authorizing' => false );
    }
}
class Fake_Ability {
    private $args;
    public function __construct( $args ) { $this->args = $args; }
    public function get_meta() { return $this->args['meta']; }
    public function get_input_schema() { return $this->args['input_schema']; }
}
function mad4b_test( $condition, $name ) {
    if ( ! $condition ) { fwrite( STDERR, "FAIL $name\n" ); exit( 1 ); }
}
require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-cso01-read-foundation.php';
$target = 'mad4b/site-info';
$input_schema = array( 'type' => 'object', 'additionalProperties' => false,
    'properties' => array(
        'query' => array( 'type' => 'string', 'maxLength' => 20 ),
        'limit' => array( 'type' => 'integer', 'enum' => array( 1, 2, 3 ) ),
        'confirmed' => array( 'type' => 'boolean' ),
    ), 'required' => array( 'query' ) );
$GLOBALS['abilities'][ $target ] = array( 'input_schema' => $input_schema,
    'meta' => array( 'mcp' => array( 'surface' => 'read' ),
        'annotations' => array( 'readonly' => true ) ) );
MAD4B_SCP_CSO01_Read_Foundation::boot();
mad4b_test( in_array( 'wp_abilities_api_init', $GLOBALS['hooks'], true ), 'register hook missing' );
MAD4B_SCP_CSO01_Read_Foundation::register_abilities();
mad4b_test( 4 === count( MAD4B_SCP_CSO01_Read_Foundation::read_ability_names() ),
    'owned read ability registration not recognized' );
foreach ( array( 'cso/discover', 'cso/form-schema', 'cso/form-validate', 'cso/form-explain' ) as $name ) {
    mad4b_test( wp_has_ability( $name ), 'registered ability missing: ' . $name );
    $args = $GLOBALS['abilities'][ $name ];
    mad4b_test( true === $args['meta']['annotations']['readonly']
        && false === $args['meta']['annotations']['destructive']
        && 'read' === $args['meta']['mcp']['surface'], 'write surface introduced' );
}
$discovered = MAD4B_SCP_CSO01_Read_Foundation::discover();
mad4b_test( ! is_wp_error( $discovered ) && false === $discovered['writes_enabled']
    && false === $discovered['provider_certification_issued'], 'discovery incorrectly authorized' );
$GLOBALS['inventory_ready'] = false;
$incomplete = MAD4B_SCP_CSO01_Read_Foundation::discover();
mad4b_test( ! is_wp_error( $incomplete ) && false === $incomplete['discovery_complete']
    && false === $incomplete['writes_enabled'] && false === $incomplete['authorizing']
    && 'reconcile_inventory_evidence_before_any_certification' === $incomplete['next_safe_action'],
    'partial inventory falsely authorized or hidden' );
unset( $GLOBALS['inventory_ready'] );
$form = MAD4B_SCP_CSO01_Read_Foundation::form_schema( array( 'ability_name' => $target ) );
mad4b_test( ! is_wp_error( $form ) && false === $form['editable']
    && 64 === strlen( $form['descriptor_sha256'] ) && 3 === count( $form['fields'] ),
    'bounded form failed' );
$arg = array( 'ability_name' => $target,
    'expected_descriptor_sha256' => $form['descriptor_sha256'],
    'values' => array( 'query' => 'hello', 'limit' => 2, 'confirmed' => false ) );
$ok = MAD4B_SCP_CSO01_Read_Foundation::form_validate( $arg );
$arg['values']['query'] = 'رحلة';
$arabic = MAD4B_SCP_CSO01_Read_Foundation::form_validate( $arg );
mad4b_test( ! is_wp_error( $arabic ) && true === $arabic['valid'], 'Arabic Unicode text rejected' );
$arg['values']['query'] = str_repeat( 'ر', 21 );
$long = MAD4B_SCP_CSO01_Read_Foundation::form_validate( $arg );
mad4b_test( ! is_wp_error( $long ) && false === $long['valid'], 'Unicode overflow accepted' );
$arg['values']['query'] = 'hello';
mad4b_test( ! is_wp_error( $ok ) && true === $ok['valid'] && false === $ok['saved'], 'stateless validation failed' );
$arg['values']['unexpected'] = 'DO_NOT_ECHO';
$invalid = MAD4B_SCP_CSO01_Read_Foundation::form_validate( $arg );
mad4b_test( ! is_wp_error( $invalid ) && false === $invalid['valid']
    && false === strpos( wp_json_encode( $invalid ), 'DO_NOT_ECHO' ), 'hidden input leaked or accepted' );
$arg['values'] = array( 'limit' => 3 );
$missing = MAD4B_SCP_CSO01_Read_Foundation::form_validate( $arg );
mad4b_test( false === $missing['valid'] && in_array( 'required_field_absent', $missing['issues'], true ),
    'missing required field accepted' );
$arg['values'] = array( 'query' => 'ok', 'limit' => 50 );
$enum = MAD4B_SCP_CSO01_Read_Foundation::form_validate( $arg );
mad4b_test( false === $enum['valid'], 'enum bypassed' );
$arg['expected_descriptor_sha256'] = str_repeat( 'b', 64 );
$stale = MAD4B_SCP_CSO01_Read_Foundation::form_validate( $arg );
mad4b_test( is_wp_error( $stale ) && 'mad4b_cso01_stale_descriptor' === $stale->get_error_code(), 'stale descriptor accepted' );
$explain = MAD4B_SCP_CSO01_Read_Foundation::form_explain( array( 'ability_name' => $target, 'field' => 'query' ) );
mad4b_test( ! is_wp_error( $explain ) && false === $explain['write_supported'], 'explain claimed write' );
mad4b_test( is_wp_error( MAD4B_SCP_CSO01_Read_Foundation::form_explain( array( 'ability_name' => $target, 'field' => 'private_field' ) ) ), 'unknown help disclosed' );
foreach ( array( 'api_key', 'Password', 'bearer_token', 'secretField' ) as $secret_key ) {
    $schema = $input_schema;
    $schema['properties'][ $secret_key ] = array( 'type' => 'string' );
    mad4b_test( is_wp_error( MAD4B_SCP_CSO01_Read_Foundation::compile_schema( $schema ) ), 'secret key exposed' );
}
$schema = $input_schema; $schema['additionalProperties'] = true;
mad4b_test( is_wp_error( MAD4B_SCP_CSO01_Read_Foundation::compile_schema( $schema ) ), 'unbounded object allowed' );
$schema = $input_schema; $schema['properties']['nested'] = array( 'type' => 'object' );
mad4b_test( is_wp_error( MAD4B_SCP_CSO01_Read_Foundation::compile_schema( $schema ) ), 'nested arbitrary write form allowed' );
$schema = $input_schema; $schema['properties']['query']['default'] = 'DO_NOT_ECHO';
mad4b_test( is_wp_error( MAD4B_SCP_CSO01_Read_Foundation::compile_schema( $schema ) ), 'potentially sensitive default exposed' );
$schema = $input_schema;
$schema['properties']['query']['pattern'] = '^[A-Z]+
mad4b_test( is_wp_error( MAD4B_SCP_CSO01_Read_Foundation::form_schema( array( 'ability_name' => $target ) ) ), 'write lane accepted' );
unset( $GLOBALS['lane'] );
$GLOBALS['can_read'] = false;
mad4b_test( is_wp_error( MAD4B_SCP_CSO01_Read_Foundation::discover() ), 'unauthorized discovery accepted' );
$GLOBALS['can_read'] = true;
$GLOBALS['actor_enrolled'] = false;
mad4b_test( is_wp_error( MAD4B_SCP_CSO01_Read_Foundation::discover() ), 'unenrolled actor accepted' );
$GLOBALS['actor_enrolled'] = true;
$GLOBALS['enrolled'] = false;
mad4b_test( is_wp_error( MAD4B_SCP_CSO01_Read_Foundation::discover() ), 'unenrolled site accepted' );
$GLOBALS['enrolled'] = true;
$GLOBALS['blog_ok'] = false;
mad4b_test( is_wp_error( MAD4B_SCP_CSO01_Read_Foundation::discover() ), 'blog switch accepted' );
$GLOBALS['blog_ok'] = true;
$GLOBALS['abilities']['evil/arbitrary-write'] = $GLOBALS['abilities'][ $target ];
mad4b_test( is_wp_error( MAD4B_SCP_CSO01_Read_Foundation::form_schema( array( 'ability_name' => 'evil/arbitrary-write' ) ) ),
    'untrusted registered plugin ability auto-certified' );
echo "mad4b.cso01.read-foundation.runtime.v1: PASS\n";
;
mad4b_test( is_wp_error( MAD4B_SCP_CSO01_Read_Foundation::compile_schema( $schema ) ), 'ignored pattern accepted' );
$schema = $input_schema;
$schema['properties']['limit']['minimum'] = 2;
mad4b_test( is_wp_error( MAD4B_SCP_CSO01_Read_Foundation::compile_schema( $schema ) ), 'ignored minimum accepted' );
$GLOBALS['lane'] = 'write';
mad4b_test( is_wp_error( MAD4B_SCP_CSO01_Read_Foundation::form_schema( array( 'ability_name' => $target ) ) ), 'write lane accepted' );
unset( $GLOBALS['lane'] );
$GLOBALS['can_read'] = false;
mad4b_test( is_wp_error( MAD4B_SCP_CSO01_Read_Foundation::discover() ), 'unauthorized discovery accepted' );
$GLOBALS['can_read'] = true;
$GLOBALS['actor_enrolled'] = false;
mad4b_test( is_wp_error( MAD4B_SCP_CSO01_Read_Foundation::discover() ), 'unenrolled actor accepted' );
$GLOBALS['actor_enrolled'] = true;
$GLOBALS['enrolled'] = false;
mad4b_test( is_wp_error( MAD4B_SCP_CSO01_Read_Foundation::discover() ), 'unenrolled site accepted' );
$GLOBALS['enrolled'] = true;
$GLOBALS['blog_ok'] = false;
mad4b_test( is_wp_error( MAD4B_SCP_CSO01_Read_Foundation::discover() ), 'blog switch accepted' );
$GLOBALS['blog_ok'] = true;
$GLOBALS['abilities']['evil/arbitrary-write'] = $GLOBALS['abilities'][ $target ];
mad4b_test( is_wp_error( MAD4B_SCP_CSO01_Read_Foundation::form_schema( array( 'ability_name' => 'evil/arbitrary-write' ) ) ),
    'untrusted registered plugin ability auto-certified' );
echo "mad4b.cso01.read-foundation.runtime.v1: PASS\n";
