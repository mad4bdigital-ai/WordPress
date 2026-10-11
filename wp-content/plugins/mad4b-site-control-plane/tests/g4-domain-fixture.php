<?php
/** Hermetic domain fixture. No provider installation, HTTP, SQL or live WordPress connection. */
define( 'ABSPATH', __DIR__ . '/' );
define( 'MAD4B_SCP_DIR', dirname( __DIR__ ) . '/' );
class WP_Error {
	private $code;
	public function __construct( $code, $message = '', $data = null ) { $this->code = $code; }
	public function get_error_code() { return $this->code; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_-]/i', '', (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function add_action( $hook, $callback, $priority = 10 ) { $GLOBALS['g4_hooks'][ $hook ][] = $callback; }
function do_action( $hook, $argument = null ) {}
function current_user_can( $capability, $id = 0 ) { return $GLOBALS['g4_admin']; }
function get_current_user_id() { return $GLOBALS['g4_actor']; }
function wp_has_ability( $name ) { return isset( $GLOBALS['g4_abilities'][ $name ] ); }
function wp_register_ability( $name, $definition ) { $GLOBALS['g4_abilities'][ $name ] = $definition; }
function wp_get_ability( $name ) { return $GLOBALS['g4_native_abilities'][ $name ] ?? null; }
function get_plugins() { return $GLOBALS['g4_plugins']; }
function is_plugin_active( $file ) { return isset( $GLOBALS['g4_plugins'][ $file ] ) && ! empty( $GLOBALS['g4_plugins'][ $file ]['active'] ); }
function is_plugin_active_for_network( $file ) { return false; }
function wp_check_post_lock( $id ) { return false; }
function rest_validate_value_from_schema( $value, $schema, $path = '' ) {
	if ( ! is_array( $schema ) ) return new WP_Error( 'fixture_schema' );
	$type = $schema['type'] ?? '';
	$valid = ( 'object' === $type && is_array( $value ) ) || ( 'array' === $type && is_array( $value ) ) || ( 'string' === $type && is_string( $value ) ) || ( 'integer' === $type && is_int( $value ) ) || ( 'boolean' === $type && is_bool( $value ) );
	if ( ! $valid || ( isset( $schema['enum'] ) && ! in_array( $value, $schema['enum'], true ) ) ) return new WP_Error( 'fixture_schema' );
	if ( is_string( $value ) && ( ( isset( $schema['maxLength'] ) && strlen( $value ) > $schema['maxLength'] ) || ( isset( $schema['pattern'] ) && preg_match( '#' . $schema['pattern'] . '#D', $value ) !== 1 ) ) ) return new WP_Error( 'fixture_schema' );
	if ( is_int( $value ) && ( ( isset( $schema['minimum'] ) && $value < $schema['minimum'] ) || ( isset( $schema['maximum'] ) && $value > $schema['maximum'] ) ) ) return new WP_Error( 'fixture_schema' );
	if ( 'object' === $type ) {
		if ( array_diff( $schema['required'] ?? array(), array_keys( $value ) ) || ( false === ( $schema['additionalProperties'] ?? null ) && array_diff( array_keys( $value ), array_keys( $schema['properties'] ?? array() ) ) ) ) return new WP_Error( 'fixture_schema' );
		foreach ( $value as $key => $child ) if ( isset( $schema['properties'][ $key ] ) && is_wp_error( rest_validate_value_from_schema( $child, $schema['properties'][ $key ], $key ) ) ) return new WP_Error( 'fixture_schema' );
	}
	return true;
}
$GLOBALS['g4_admin'] = true; $GLOBALS['g4_actor'] = 12; $GLOBALS['g4_tests'] = 0;
$GLOBALS['g4_abilities'] = array(); $GLOBALS['g4_native_abilities'] = array(); $GLOBALS['g4_plugins'] = array();
class MAD4B_SCP_Policy { public static function can_admin() { return $GLOBALS['g4_admin']; } }
class MAD4B_SCP_Time_Policy { public static $now = 1700000000; public static function now_epoch() { return self::$now; } }
class MAD4B_SCP_Runtime_Generation_Fence {
	public static $epoch = 0;
	public static function capture() { return array( 'contract'=>'fixture.generation', 'generation_sha256'=>hash( 'sha256', (string) self::$epoch ) ); }
	public static function assert_current( array $expected ) { return $expected === self::capture() ? true : new WP_Error( 'fixture_generation_drift' ); }
}
class MAD4B_SCP_Capability_Descriptor_Registry {
	public static $epoch = 0;
	public static function binding( $name, $consumer ) { return array( 'name'=>$name, 'consumer'=>$consumer, 'epoch'=>self::$epoch ); }
	public static function assert_binding( $name, array $expected, $consumer ) { return $expected === self::binding( $name, $consumer ) ? true : new WP_Error( 'fixture_descriptor_drift' ); }
}
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-structural-redaction.php';
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-wordpress-domain-coverage.php';
function g4_assert( $condition, $message ) { if ( ! $condition ) throw new RuntimeException( $message ); ++$GLOBALS['g4_tests']; }
function g4_valid( $profile, array $desired, array $facts ) {
	$out = MAD4B_SCP_WordPress_Domain_Coverage::guard( $profile, $desired, $facts );
	g4_assert( ! is_wp_error( $out ), $profile . ' positive case: ' . ( is_wp_error( $out ) ? $out->get_error_code() : '' ) );
	g4_assert( false === $out['authorizing'] && false === $out['mutation_performed'] && false === $out['auto_promotion_allowed'] && false === $out['reversible_claimed'], 'Preflight claimed authority or rollback.' );
	return $out;
}
function g4_deny( $profile, array $desired, array $facts, $reason = '' ) {
	$out = MAD4B_SCP_WordPress_Domain_Coverage::guard( $profile, $desired, $facts );
	g4_assert( is_wp_error( $out ), $profile . ' denial accepted: ' . $reason );
	if ( '' !== $reason ) g4_assert( $out->get_error_code() === 'mad4b_domain_' . $reason, 'Unexpected denial: ' . $out->get_error_code() );
}
function g4_field( $effect = 'local_content', $type = 'string' ) { return array( 'schema'=>array( 'type'=>$type, 'maxLength'=>200 ), 'authorized'=>true, 'privacy'=>'public', 'effect'=>$effect ); }
function g4_done( $group ) { echo 'mad4b.g4-' . $group . ': PASS assertions=' . $GLOBALS['g4_tests'] . "\n"; }
