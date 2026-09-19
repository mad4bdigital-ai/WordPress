<?php

define( 'ABSPATH', __DIR__ . '/' );

final class WP_Error {
	private $code;
	private $message;
	public function __construct( $code, $message = '' ) { $this->code = (string) $code; $this->message = (string) $message; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) { return true; }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) ); }
function absint( $value ) { return abs( (int) $value ); }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function wp_slash( $value ) { return $value; }
function current_user_can( $capability, $object_id = null ) { return true; }

$GLOBALS['registered_abilities'] = array();
$GLOBALS['fake_posts'] = array();
$GLOBALS['fake_meta'] = array();
$GLOBALS['next_post_id'] = 700;

function wp_has_ability( $name ) { return isset( $GLOBALS['registered_abilities'][ $name ] ); }
function wp_register_ability( $name, $args ) { $GLOBALS['registered_abilities'][ $name ] = $args; return true; }
function get_post( $id ) { return isset( $GLOBALS['fake_posts'][ (int) $id ] ) ? $GLOBALS['fake_posts'][ (int) $id ] : null; }
function get_post_meta( $id, $key, $single = false ) {
	return isset( $GLOBALS['fake_meta'][ (int) $id ][ $key ] ) ? $GLOBALS['fake_meta'][ (int) $id ][ $key ] : '';
}
function get_posts( $args ) {
	$result = array();
	$statuses = isset( $args['post_status'] ) ? (array) $args['post_status'] : array();
	foreach ( $GLOBALS['fake_posts'] as $id => $post ) {
		if ( isset( $args['post_type'] ) && (string) $post->post_type !== (string) $args['post_type'] ) continue;
		if ( $statuses && ! in_array( (string) $post->post_status, $statuses, true ) ) continue;
		$key = isset( $args['meta_key'] ) ? (string) $args['meta_key'] : '';
		$value = isset( $args['meta_value'] ) ? (string) $args['meta_value'] : '';
		if ( '' !== $key && get_post_meta( $id, $key, true ) !== $value ) continue;
		$result[] = (int) $id;
	}
	sort( $result, SORT_NUMERIC );
	return array_slice( $result, 0, isset( $args['numberposts'] ) ? (int) $args['numberposts'] : count( $result ) );
}
function wp_insert_post( $data, $wp_error = false ) {
	$id = ++$GLOBALS['next_post_id'];
	$post = (object) array(
		'ID' => $id,
		'post_type' => isset( $data['post_type'] ) ? $data['post_type'] : 'post',
		'post_status' => isset( $data['post_status'] ) ? $data['post_status'] : 'draft',
		'post_title' => isset( $data['post_title'] ) ? $data['post_title'] : '',
		'post_content' => isset( $data['post_content'] ) ? $data['post_content'] : '',
		'post_excerpt' => isset( $data['post_excerpt'] ) ? $data['post_excerpt'] : '',
		'post_parent' => 0,
		'post_modified_gmt' => '2026-09-16 18:00:00',
	);
	$GLOBALS['fake_posts'][ $id ] = $post;
	$GLOBALS['fake_meta'][ $id ] = isset( $data['meta_input'] ) && is_array( $data['meta_input'] ) ? $data['meta_input'] : array();
	return $id;
}
function wp_delete_post( $id, $force_delete = false ) {
	$id = (int) $id;
	if ( ! isset( $GLOBALS['fake_posts'][ $id ] ) ) return false;
	$deleted = $GLOBALS['fake_posts'][ $id ];
	unset( $GLOBALS['fake_posts'][ $id ], $GLOBALS['fake_meta'][ $id ] );
	return $deleted;
}

class MAD4B_SCP_Policy {
	public static function can_read() { return true; }
	public static function can_admin() { return true; }
}
class MAD4B_SCP_Audit { public static function record( $event, $data = array(), $status = 'ok' ) { return true; } }

class MAD4B_SCP_Site_Profile {
	public static $environment = 'staging';
	public static function configured() { return true; }
	public static function current_environment() { return self::$environment; }
	public static function origin_enrolled() { return true; }
	public static function site_urls_match_enrollment() { return true; }
	public static function revision() { return 5; }
	public static function profile_digest() { return '6eaa1c917509d8c4c380b441dcee8c99a5acc27c701cb42ea8c5666ef502d5b5'; }
	public static function site_uuid() { return 'd745d81f-6fc4-5c6a-99dd-d953c92137bf'; }
	public static function current_origin() { return 'https://staging.egypttourgates.com'; }
}
class MAD4B_SCP_Live_Acceptance_Observer {
	public static $sha = '0ea3c791820a894ad97a4b2231852320f328b5c6';
	public static $fingerprint = '0a87ec727d5f92578cec09b12dbe108a78fd13ce6daadaf177c8702cf0e6354e';
	public static function build_provenance_status() {
		return array(
			'manifest_present' => true,
			'manifest_valid' => true,
			'runtime_manifest_match' => true,
			'stale' => false,
			'provenance_mismatch' => array(),
			'source_commit_sha' => self::$sha,
			'build_fingerprint' => self::$fingerprint,
		);
	}
}

abstract class MAD4B_SCP_Adapter_Base {
	abstract public function id();
	abstract public function label();
	abstract public function is_available();
	abstract public function ability_names();
	abstract public function register_abilities();
	public function provider_key() { return sanitize_key( (string) $this->certified_provider_key() ); }
	protected function certified_provider_key() { return $this->id(); }
	protected function mutation_requires_certification() { return true; }
	public function reversible_contracts() { return array(); }
	public function reversible_contract_for( $ability_name ) { $all = $this->reversible_contracts(); return isset( $all[ $ability_name ] ) ? $all[ $ability_name ] : ''; }
	public function capture_reversible_state( $ability_name, array $input ) { return new WP_Error( 'unsupported' ); }
	public function read_reversible_state( $ability_name, array $target ) { return new WP_Error( 'unsupported' ); }
	public function restore_reversible_state( $ability_name, array $target, array $state, array $record ) { return new WP_Error( 'unsupported' ); }
	protected function add_ability( $name, $label, $method, $permission, $input_schema = null, $surface = 'read', $readonly = true, $destructive = false, $idempotent = true ) {
		wp_register_ability( $name, array(
			'label' => $label,
			'execute_callback' => array( $this, $method ),
			'permission_callback' => $permission,
			'input_schema' => $input_schema,
			'meta' => array( 'mcp' => array( 'surface' => $surface ), 'annotations' => array( 'readonly' => $readonly, 'destructive' => $destructive, 'idempotent' => $idempotent ) ),
		) );
	}
	protected function schema( array $properties, array $required = array() ) { $schema = array( 'type' => 'object', 'properties' => $properties, 'additionalProperties' => false ); if ( $required ) $schema['required'] = $required; return $schema; }
}
class MAD4B_SCP_Adapter_Registry {
	public $registered = array();
	public function register( $adapter ) { if ( ! $adapter instanceof MAD4B_SCP_Adapter_Base ) return false; $this->registered[] = $adapter; return true; }
}

require dirname( __DIR__ ) . '/includes/adapters/class-mad4b-scp-acceptance-target-adapter.php';

function assert_true( $condition, $message ) { if ( ! $condition ) { fwrite( STDERR, "FAIL: {$message}\n" ); exit( 1 ); } }
function assert_error_code( $value, $code, $message ) { assert_true( is_wp_error( $value ) && $value->get_error_code() === $code, $message ); }

$registry = new MAD4B_SCP_Adapter_Registry();
MAD4B_SCP_Acceptance_Target_Adapter_Bootstrap::register( $registry );
assert_true( 1 === count( $registry->registered ), 'staging exact profile must register one acceptance adapter' );
$adapter = $registry->registered[0];
assert_true( 'core' === $adapter->provider_key(), 'acceptance provisioner must use core provider authority' );
$names = $adapter->ability_names();
assert_true( array( 'mad4b/acceptance-target-status' ) === $names['read'], 'status ability must be read-only surface' );
assert_true( array( 'mad4b/acceptance-target-provision' ) === $names['write'], 'provision ability must be write surface' );
assert_true( 'mad4b.rollback.acceptance-target.v1' === $adapter->reversible_contract_for( 'mad4b/acceptance-target-provision' ), 'reversible cleanup contract must be exact' );

$adapter->register_abilities();
assert_true( isset( $GLOBALS['registered_abilities']['mad4b/acceptance-target-status'] ), 'status ability must register' );
assert_true( true === $GLOBALS['registered_abilities']['mad4b/acceptance-target-status']['meta']['annotations']['readonly'], 'status ability must remain readonly' );
$provision_registration = $GLOBALS['registered_abilities']['mad4b/acceptance-target-provision'];
assert_true( false === $provision_registration['meta']['annotations']['readonly'], 'provision ability must be mutating' );
$props = array_keys( $provision_registration['input_schema']['properties'] );
sort( $props );
$expected_props = array( 'expected_build_fingerprint', 'expected_profile_digest', 'expected_revision', 'expected_source_commit_sha' );
sort( $expected_props );
assert_true( $expected_props === $props, 'caller schema must contain only exact immutable binding inputs' );
assert_true( false === $provision_registration['input_schema']['additionalProperties'], 'provision schema must reject extra target/authority inputs' );

$input = array(
	'expected_revision' => 5,
	'expected_profile_digest' => MAD4B_SCP_Site_Profile::profile_digest(),
	'expected_source_commit_sha' => MAD4B_SCP_Live_Acceptance_Observer::$sha,
	'expected_build_fingerprint' => MAD4B_SCP_Live_Acceptance_Observer::$fingerprint,
);
$status = $adapter->target_status();
assert_true( ! is_wp_error( $status ) && false === $status['exists'], 'fresh exact candidate must start without a fixture' );
$before = $adapter->capture_reversible_state( 'mad4b/acceptance-target-provision', $input );
assert_true( ! is_wp_error( $before ) && false === $before['state']['exists'], 'reversible envelope must capture absence before provision' );

$created = $adapter->provision( $input );
assert_true( ! is_wp_error( $created ) && true === $created['created'], 'exact-bound provision must create one fixture' );
$post_id = (int) $created['post_id'];
$post = get_post( $post_id );
assert_true( $post && 'post' === $post->post_type && 'draft' === $post->post_status, 'fixture must be a draft post' );
assert_true( '' === $post->post_content && '' === $post->post_excerpt, 'fixture content/excerpt must start empty' );
$status = $adapter->target_status();
assert_true( true === $status['exists'] && true === $status['safe_for_mutation_acceptance'], 'status must rediscover the exact isolated fixture' );

$again = $adapter->provision( $input );
assert_true( ! is_wp_error( $again ) && false === $again['created'] && true === $again['idempotent'] && $post_id === (int) $again['post_id'], 'repeat provision must not create a duplicate fixture' );
assert_true( 1 === count( $GLOBALS['fake_posts'] ), 'exact binding must own at most one fixture' );

// The acceptance mutation changes only excerpt; status remains safe. The subsequent
// content undo restores excerpt to empty before provision cleanup runs.
$GLOBALS['fake_posts'][ $post_id ]->post_excerpt = 'MAD4B acceptance probe ' . MAD4B_SCP_Live_Acceptance_Observer::$sha;
$status = $adapter->target_status();
assert_true( true === $status['safe_for_mutation_acceptance'], 'bounded excerpt probe must not destroy fixture identity' );
$GLOBALS['fake_posts'][ $post_id ]->post_excerpt = '';
$restored = $adapter->restore_reversible_state( 'mad4b/acceptance-target-provision', $before['target'], $before['state'], array() );
assert_true( ! is_wp_error( $restored ) && true === $restored['deleted'], 'provision undo must delete the exact isolated fixture' );
$status = $adapter->target_status();
assert_true( false === $status['exists'], 'cleanup readback must verify fixture absence' );

MAD4B_SCP_Live_Acceptance_Observer::$sha = '1111111111111111111111111111111111111111';
$stale = $adapter->read_reversible_state( 'mad4b/acceptance-target-provision', $before['target'] );
assert_error_code( $stale, 'mad4b_acceptance_target_recorded_binding_stale', 'recorded target must fail closed after candidate drift' );

MAD4B_SCP_Site_Profile::$environment = 'production';
$production_registry = new MAD4B_SCP_Adapter_Registry();
MAD4B_SCP_Acceptance_Target_Adapter_Bootstrap::register( $production_registry );
assert_true( 0 === count( $production_registry->registered ), 'Production must never register the acceptance target adapter' );

fwrite( STDOUT, "acceptance target runtime smoke: PASS\n" );
