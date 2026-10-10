<?php
/** Production CSO + canonical registry/dispatcher/fence; explicit in-memory WordPress/framework doubles.
 * This is executable PHP regression evidence, not WordPress/DB/host/certification acceptance.
 * Define CSO_REGISTRY_FIXTURE_ONLY=true before requiring to reuse dependencies without assertions.
 */
define( 'ABSPATH', __DIR__ . '/' );
define( 'MAD4B_SCP_DIR', dirname( __DIR__ ) . '/' );
define( 'WP_PLUGIN_DIR', dirname( __DIR__ ) );
define( 'MAD4B_SCP_VERSION', 'fixture-1.0.0' );

class WP_Error {
	private $code; private $message; private $data;
	public function __construct( $code = '', $message = '', $data = null ) { $this->code = $code; $this->message = $message; $this->data = $data; }
	public function get_error_code() { return $this->code; } public function get_error_message() { return $this->message; } public function get_error_data() { return $this->data; }
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function wp_json_encode( $v, $flags = 0, $depth = 512 ) { return json_encode( $v, $flags, $depth ); }
function sanitize_key( $v ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $v ) ); }
function wp_check_invalid_utf8( $v, $strip = false ) { return 1 === preg_match( '//u', $v ) ? $v : ''; }
function absint( $v ) { return abs( (int) $v ); }
// No WordPress lifecycle is run; hook registration is recorded only by the source under test.
function add_filter( $hook, $callback, $priority = 10, $args = 1 ) { return true; }
function add_action( $hook, $callback, $priority = 10, $args = 1 ) { return true; }
function get_current_blog_id() { return $GLOBALS['cso_fixture']['blog']; }
function get_current_user_id() { return $GLOBALS['cso_fixture']['user_id']; }
function wp_get_current_user() { return (object) array( 'ID' => get_current_user_id(), 'roles' => $GLOBALS['cso_fixture']['roles'], 'allcaps' => $GLOBALS['cso_fixture']['caps'] ); }
function get_userdata( $id ) { return $id === get_current_user_id() ? wp_get_current_user() : false; }
function user_can( $user, $cap ) { return true === ( $user->allcaps[ $cap ] ?? false ); }
function current_user_can( $cap, ...$args ) { return user_can( wp_get_current_user(), $cap ); }
function determine_locale() { return $GLOBALS['cso_fixture']['locale']; }
function is_user_logged_in() { return $GLOBALS['cso_fixture']['logged_in']; }
function wp_get_session_token() { return $GLOBALS['cso_fixture']['cookie_session']; }
function apply_filters( $hook, $v, ...$args ) {
	if ( 'mad4b_scp_authenticated_subject_context' === $hook ) return $GLOBALS['cso_fixture']['identity'];
	if ( 'mad4b_scp_connection_capability' === $hook ) return 'read';
	if ( 'mad4b_scp_read_capability' === $hook ) return $GLOBALS['cso_fixture']['read_enabled'] ? 'read' : 'forbidden';
	return $v;
}
function get_option( $key, $default = false ) { if ( array_key_exists( $key, $GLOBALS['cso_fixture']['options'] ) ) return $GLOBALS['cso_fixture']['options'][ $key ]; return 'active_plugins' === $key ? array( 'fixture/fixture.php' ) : $default; }
function get_site_option( $key, $default = false ) { return $default; }
function get_file_data( $file, $headers, $context = '' ) { return array( 'Version' => $GLOBALS['cso_fixture']['plugin_version'] ); }
function get_post_types( $args = array(), $output = 'names' ) { $v = $GLOBALS['cso_fixture']['post_types']; return 'objects' === $output ? $v : array_combine( array_keys( $v ), array_keys( $v ) ); }
function get_taxonomies( $args = array(), $output = 'names' ) { $v = $GLOBALS['cso_fixture']['taxonomies']; return 'objects' === $output ? $v : array_combine( array_keys( $v ), array_keys( $v ) ); }
function wp_get_theme() { return new CSO_Fixture_Theme(); }
function wp_has_ability( $name ) { return isset( $GLOBALS['cso_fixture']['abilities'][ $name ] ); }
function wp_get_ability( $name ) { return $GLOBALS['cso_fixture']['abilities'][ $name ] ?? null; }
function wp_get_abilities() { return $GLOBALS['cso_fixture']['abilities']; }
class CSO_Fixture_Theme { public function get_stylesheet() { return 'fixture'; } public function get( $name ) { return '1.0.0'; } public function parent() { return false; } }
function cso_fixture_hash( $value ) { return hash( 'sha256', (string) $value ); }

class MAD4B_SCP_Site_Profile {
	public static function configured() { return $GLOBALS['cso_fixture']['configured']; }
	public static function governed_write_ready() { return true === ( $GLOBALS['cso_fixture']['governed_write_ready'] ?? false ); }
	public static function origin_enrolled() { return $GLOBALS['cso_fixture']['enrolled']; }
	public static function site_urls_match_enrollment() { return $GLOBALS['cso_fixture']['url_match']; }
	public static function user_is_enrolled( $id ) { return $GLOBALS['cso_fixture']['user_enrolled']; }
	public static function site_uuid() { return $GLOBALS['cso_fixture']['site_uuid']; }
	public static function current_origin() { return $GLOBALS['cso_fixture']['origin']; }
	public static function current_environment() { return $GLOBALS['cso_fixture']['environment']; }
	public static function revision() { return $GLOBALS['cso_fixture']['profile_revision']; }
	public static function profile_digest() { return cso_fixture_hash( self::revision() ); }
	public static function deployment_binding_digest() { return cso_fixture_hash( $GLOBALS['cso_fixture']['deployment'] ); }
	public static function status() { return array( 'authority_ready' => $GLOBALS['cso_fixture']['authority_ready'], 'origin_match' => true, 'environment_match' => true, 'deployment_binding_match' => true, 'profile_authority_quarantined' => false ); }
	public static function deployment_binding_proof( $purpose, $digest ) { return hash_hmac( 'sha256', $purpose . ':' . $digest, $GLOBALS['cso_fixture']['deployment'] ); }
	public static function verify_deployment_binding_proof( $purpose, $digest, $proof ) { return hash_equals( self::deployment_binding_proof( $purpose, $digest ), $proof ); }
}
class MAD4B_SCP_Adaptive_Operations_Context {
	public static function current() {
		if ( ! $GLOBALS['cso_fixture']['runtime_ready'] ) return new WP_Error( 'fixture_unverified', 'unverified fixture runtime' );
		return array( 'site_uuid' => MAD4B_SCP_Site_Profile::site_uuid(), 'environment' => MAD4B_SCP_Site_Profile::current_environment(), 'profile_digest' => MAD4B_SCP_Site_Profile::profile_digest(), 'origin_sha256' => cso_fixture_hash( MAD4B_SCP_Site_Profile::current_origin() ), 'runtime_generation' => $GLOBALS['cso_fixture']['generation'], 'artifact_sha256' => $GLOBALS['cso_fixture']['package'], 'restore_epoch' => $GLOBALS['cso_fixture']['restore'], 'external_record_sha256' => $GLOBALS['cso_fixture']['external'] );
	}
}
class MAD4B_SCP_Live_Acceptance_Observer { public static function build_provenance_identity_status() { return array( 'identity_ready' => $GLOBALS['cso_fixture']['manifest_ready'], 'manifest_valid' => $GLOBALS['cso_fixture']['manifest_ready'], 'source_commit_sha' => $GLOBALS['cso_fixture']['source'], 'package_manifest_digest' => $GLOBALS['cso_fixture']['package'] ); } }
class MAD4B_SCP_Environment { public static function effective() { return $GLOBALS['cso_fixture']['environment']; } }
class MAD4B_SCP_Identity_Context {
	public static function current() { return $GLOBALS['cso_fixture']['identity']; }
	public static function with_approval_ticket_for_request( $ticket, $callback ) {
		if ( ! is_string( $ticket ) || ! preg_match( '/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D', $ticket ) || ! is_callable( $callback ) ) return new WP_Error( 'fixture_ticket_invalid', 'Invalid fixture ticket' );
		$previous = $GLOBALS['cso_fixture']['identity']['approval_ticket_id'] ?? null; $GLOBALS['cso_fixture']['identity']['approval_ticket_id'] = $ticket;
		try { return $callback(); } finally { if ( null === $previous ) unset( $GLOBALS['cso_fixture']['identity']['approval_ticket_id'] ); else $GLOBALS['cso_fixture']['identity']['approval_ticket_id'] = $previous; }
	}
}
class MAD4B_SCP_Agent_Registry {
	public static function subject_binding( $type, $fp ) { return $GLOBALS['cso_fixture']['binding']; }
	public static function resolve_agent( $identity ) { return $GLOBALS['cso_fixture']['agent']; }
	public static function grants_for_agent( $id ) { return $GLOBALS['cso_fixture']['grants']; }
}
class MAD4B_SCP_Authorization {
	public static function execution_boundary_verified( $ability ) { return $ability instanceof CSO_Fixture_Ability && true === $ability->boundary; }
	public static function begin_execution_callback_observation( $name ) { $GLOBALS['cso_fixture']['callback_observations'][ $name ] = false; }
	public static function mark_execution_callback_started( $name ) { $GLOBALS['cso_fixture']['callback_observations'][ $name ] = true; }
	public static function execution_callback_started( $name ) { return true === ( $GLOBALS['cso_fixture']['callback_observations'][ $name ] ?? false ); }
	public static function clear_execution_callback_observation( $name ) { unset( $GLOBALS['cso_fixture']['callback_observations'][ $name ] ); }
}
class MAD4B_SCP_Servers {
	public static function provider_for_capability_descriptor( $server, $name ) { return $GLOBALS['cso_fixture']['providers'][ $name ] ?? null; }
	public static function provider_for_ability( $server, $name ) { return true === ( $GLOBALS['cso_fixture']['mounted'][ $name ] ?? false ) ? self::provider_for_capability_descriptor( $server, $name ) : null; }
	public static function ability_is_mounted( $server, $name ) { return null !== self::provider_for_ability( $server, $name ); }
	public static function core_tools( $server ) { return array(); }
	public static function is_external_write_candidate( $name ) { return in_array( $name, self::write_tools(), true ); }
	public static function write_tools() { return $GLOBALS['cso_fixture']['write_candidates'] ?? array(); }
	public static function chatgpt_full_catalog_candidates() { return array_keys( $GLOBALS['cso_fixture']['abilities'] ); }
}
class CSO_Fixture_Adapter { public function id() { return 'fixture'; } public function provider_key() { return 'fixture'; } public function runtime_version() { return $GLOBALS['cso_fixture']['provider_version']; } }
class MAD4B_SCP_Adapter_Registry { public static function instance() { return new self(); } public function all() { return array( new CSO_Fixture_Adapter() ); } }
class MAD4B_SCP_Provider_Compatibility_Certification {
	public static function certification_generation_sha256( $provider ) { return cso_fixture_hash( $provider . $GLOBALS['cso_fixture']['provider_version'] ); }
	public static function ability_status( $provider, $name ) {
		if ( isset( $GLOBALS['cso_fixture']['provider_status'][ $name ] ) ) return $GLOBALS['cso_fixture']['provider_status'][ $name ];
		$ability = wp_get_ability( $name ); $read = $ability instanceof CSO_Fixture_Ability && true === $ability->meta['annotations']['readonly'];
		return array( 'read_eligible' => $read && true === ( $GLOBALS['cso_fixture']['mounted'][ $name ] ?? false ), 'structural_compatible' => $read, 'cso_conformance_certified' => false );
	}
}
class MAD4B_SCP_Entropy { public static function hex( $purpose, $bytes ) { return substr( cso_fixture_hash( $purpose . ++$GLOBALS['cso_fixture']['nonce'] ), 0, 2 * $bytes ); } }
class MAD4B_SCP_Time_Policy {
	public static function now_epoch() { return $GLOBALS['cso_fixture']['now']; }
	public static function bounded_ttl( $purpose, $ttl ) { return $ttl; }
	public static function assert_timestamp( $purpose, $time, $ttl ) { return $time <= self::now_epoch() && self::now_epoch() - $time < $ttl ? true : new WP_Error( 'fixture_time', 'expired' ); }
}
class MAD4B_SCP_Abuse_Budget { public static function admit( $purpose, $row ) { if ( 'prepare' === $purpose ) ++$GLOBALS['cso_fixture']['preparations']; return true; } }
class MAD4B_SCP_Crypto_Profile {
	public static function sign_digest_for_purpose( $purpose, $digest ) { return array( 'profile_id' => 'fixture_hmac', 'kid' => 'fixture', 'mac' => MAD4B_SCP_Site_Profile::deployment_binding_proof( $purpose, $digest ) ); }
	public static function verify_digest_for_purpose( $signature, $digest, $purpose ) { return is_array( $signature ) && hash_equals( MAD4B_SCP_Site_Profile::deployment_binding_proof( $purpose, $digest ), $signature['mac'] ?? '' ) ? true : new WP_Error( 'fixture_mac', 'invalid' ); }
}
class MAD4B_SCP_Connector_Resilience {
	const CONTRACT = 'fixture.resilience';
	public static function execute_read( $name, $callback ) { $result = $callback(); return is_wp_error( $result ) ? $result : array( 'result' => $result, 'attempts' => 1, 'elapsed_ms' => 0 ); }
	public static function execute_mutation( $surface, $name, $callback ) { ++$GLOBALS['cso_fixture']['mutation_dispatch_attempts']; $result = $callback(); return is_wp_error( $result ) ? $result : array( 'result' => $result, 'attempts' => 1, 'elapsed_ms' => 0 ); }
}

class CSO_Fixture_Ability {
	public $name; public $input; public $output; public $meta; public $boundary; public $permission; public $result; public $after;
	public function __construct( $name, $read = true, $input = null, $output = null ) { $this->name = $name; $this->input = $input; $this->output = $output; $this->meta = array( 'annotations' => array( 'readonly' => $read ), 'mcp' => array( 'surface' => $read ? 'read' : 'write' ) ); $this->boundary = ! $read; $this->permission = true; $this->result = array( 'id' => 7, 'title' => 'رحلة مصر', 'revision' => 3 ); }
	public function get_name() { return $this->name; } public function get_label() { return $this->name; } public function get_description() { return 'Fixture native contract'; } public function get_category() { return 'fixture'; }
	public function get_input_schema() { return $this->input; } public function get_output_schema() { return $this->output; } public function get_meta() { return $this->meta; }
	public function check_permissions( $input = null ) { ++$GLOBALS['cso_fixture']['permission_calls']; if ( false === $this->meta['annotations']['readonly'] ) { ++$GLOBALS['cso_fixture']['write_permission_calls']; throw new RuntimeException( 'Mutation permission must never be probed for discovery' ); } return is_callable( $this->permission ) ? call_user_func( $this->permission, $input ) : $this->permission; }
	public function execute( $input = null ) {
		if ( MAD4B_SCP_Execution_Fence::has_active_frame() && ! MAD4B_SCP_Execution_Fence::governed_child_permit_matches( $this->name, $input ) ) { ++$GLOBALS['cso_fixture']['missing_children']; return new WP_Error( 'fixture_child_required', 'missing child permit' ); }
		$frame = MAD4B_SCP_Execution_Fence::enter_execution_frame( $this->name, $input, false ); if ( is_wp_error( $frame ) ) return $frame;
		try {
			if ( 'mad4b/read-execute' === $this->name ) { ++$GLOBALS['cso_fixture']['dispatches']; $GLOBALS['cso_fixture']['last_dispatch'] = $input; ++$GLOBALS['cso_fixture']['inside_dispatch']; try { return ( new MAD4B_SCP_Abilities() )->read_execute( $input ); } finally { --$GLOBALS['cso_fixture']['inside_dispatch']; } }
			if ( ! $GLOBALS['cso_fixture']['inside_dispatch'] ) { ++$GLOBALS['cso_fixture']['direct_targets']; return new WP_Error( 'fixture_direct_denied', 'Native target bypass' ); }
			if ( true !== $this->check_permissions( $input ) ) return new WP_Error( 'fixture_permission_denied', 'sk-providerSecretDoNotEcho0000', array( 'password' => 'raw-secret' ) );
			++$GLOBALS['cso_fixture']['target_effects']; $result = is_callable( $this->result ) ? call_user_func( $this->result, $input ) : $this->result; if ( is_callable( $this->after ) ) call_user_func( $this->after ); return $result;
		} finally { MAD4B_SCP_Execution_Fence::leave_execution_frame( $frame ); }
	}
}
function cso_fixture_reset() {
	unset( $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] );
	$GLOBALS['cso_fixture'] = array( 'blog' => 1, 'user_id' => 9, 'roles' => array( 'administrator' ), 'caps' => array( 'read' => true, 'manage_options' => true, 'edit_posts' => true, 'assign_terms' => true, 'activate_plugins' => true ), 'locale' => 'ar', 'logged_in' => true, 'cookie_session' => 'fixture-cookie-session-0123456789', 'identity' => array( 'authenticated' => false ), 'binding' => array( 'agent_id' => 1, 'status' => 'enabled', 'revision' => 1 ), 'agent' => array( 'id' => 1, 'status' => 'enabled' ), 'grants' => array( array( 'id' => 1, 'capability' => 'read', 'status' => 'active' ) ), 'read_enabled' => true, 'configured' => true, 'enrolled' => true, 'url_match' => true, 'user_enrolled' => true, 'authority_ready' => true, 'runtime_ready' => true, 'manifest_ready' => true, 'site_uuid' => '11111111-2222-3333-4444-555555555555', 'origin' => 'https://fixture.test', 'environment' => 'staging', 'profile_revision' => 1, 'deployment' => str_repeat( 'fixture-deployment-secret-', 3 ), 'source' => str_repeat( 'a', 40 ), 'package' => cso_fixture_hash( 'package' ), 'generation' => cso_fixture_hash( 'generation' ), 'restore' => 1, 'external' => cso_fixture_hash( 'external' ), 'plugin_version' => '1.0.0', 'provider_version' => '1.0.0', 'nonce' => 0, 'now' => 100000, 'preparations' => 0, 'permission_calls' => 0, 'write_permission_calls' => 0, 'dispatches' => 0, 'direct_targets' => 0, 'inside_dispatch' => 0, 'target_effects' => 0, 'missing_children' => 0, 'last_dispatch' => array(), 'abilities' => array(), 'providers' => array(), 'mounted' => array(), 'post_types' => array(), 'taxonomies' => array() );
	$int = array( 'type' => 'integer' ); $text = array( 'type' => 'string', 'maxLength' => 128 );
	$GLOBALS['cso_fixture']['governed_write_ready'] = false; $GLOBALS['cso_fixture']['write_candidates'] = array(); $GLOBALS['cso_fixture']['callback_observations'] = array(); $GLOBALS['cso_fixture']['mutation_dispatch_attempts'] = 0;
	$GLOBALS['cso_fixture']['options'] = array(); $GLOBALS['cso_fixture']['provider_status'] = array();
	$ri = array( 'type' => 'object', 'properties' => array( 'id' => $int ), 'additionalProperties' => false );
	$ro = array( 'type' => 'object', 'properties' => array( 'id' => $int, 'title' => $text, 'revision' => $int ), 'additionalProperties' => false );
	$wi = array( 'type' => 'object', 'properties' => array( 'id' => $int, 'title' => $text, 'expected_revision' => $int ), 'required' => array( 'id', 'title', 'expected_revision' ), 'additionalProperties' => false );
	foreach ( array( new CSO_Fixture_Ability( 'fixture/read', true, $ri, $ro ), new CSO_Fixture_Ability( 'fixture/private', true, $ri, $ro ), new CSO_Fixture_Ability( 'fixture/write', false, $wi, $ro ), new CSO_Fixture_Ability( 'mad4b/read-execute', true ) ) as $ability ) { $GLOBALS['cso_fixture']['abilities'][ $ability->name ] = $ability; $GLOBALS['cso_fixture']['providers'][ $ability->name ] = 'fixture'; $GLOBALS['cso_fixture']['mounted'][ $ability->name ] = true; }
	wp_get_ability( 'fixture/private' )->permission = false;
	wp_get_ability( 'fixture/write' )->meta['mad4b_cso'] = array( 'storage_kind' => 'native_ability', 'certified' => true, 'fields' => array( 'title' => array( 'sensitivity' => 'PUBLIC', 'visibility' => 'visible', 'help' => 'Native title', 'exclusive_with' => array(), 'certified' => true, 'write_eligible' => true ) ), 'readback' => array( 'ability_name' => 'fixture/read', 'input_map' => array( 'id' => 'id' ), 'revision_path' => 'revision', 'field_map' => array( 'title' => 'title' ), 'target_map' => array( 'id' => 'id' ), 'revision_input' => 'expected_revision' ) );
	$GLOBALS['cso_fixture']['post_types'] = array( 'post' => (object) array( 'cap' => (object) array( 'edit_posts' => 'edit_posts' ), 'label' => 'Posts', 'public' => true, 'show_in_rest' => true ), 'private_doc' => (object) array( 'cap' => (object) array( 'edit_posts' => 'edit_private' ), 'label' => 'Private', 'public' => false ) );
	$GLOBALS['cso_fixture']['taxonomies'] = array( 'category' => (object) array( 'cap' => (object) array( 'assign_terms' => 'assign_terms' ), 'label' => 'Categories', 'object_type' => array( 'post', 'private_doc' ), 'public' => true ), 'private_tax' => (object) array( 'cap' => (object) array( 'assign_terms' => 'edit_private' ), 'label' => 'Private taxonomy', 'object_type' => array( 'private_doc' ) ) );
	if ( class_exists( 'MAD4B_SCP_Execution_Fence' ) ) MAD4B_SCP_Execution_Fence::reset_request_cache();
}
function cso_fixture_oauth() { $GLOBALS['cso_fixture']['identity'] = array( 'authenticated' => true, 'wp_user_id' => 9, 'subject_type' => 'oauth_subject', 'subject_fingerprint' => cso_fixture_hash( 'subject' ), 'issuer_fingerprint' => cso_fixture_hash( 'issuer' ), 'client_fingerprint' => cso_fixture_hash( 'client' ), 'session_fingerprint' => cso_fixture_hash( 'session' ), 'token_scopes' => array( 'read' ), 'auth_method' => 'oauth_bearer', 'origin' => 'https://fixture.test' ); }
cso_fixture_reset();
foreach ( array( 'policy', 'ability-contract-inspector', 'capability-descriptor-registry', 'ability-catalog-transport', 'preparation-receipt', 'abilities', 'site-capability-discovery', 'execution-fence', 'cso-scope', 'cso-registry', 'cso-discovery' ) as $file ) require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-' . $file . '.php';
MAD4B_SCP_CSO_Scope::boot();
if ( defined( 'CSO_REGISTRY_FIXTURE_ONLY' ) && true === CSO_REGISTRY_FIXTURE_ONLY ) return;

$passed = 0; $failed = 0;
function cso_check( $truth, $name ) { global $passed, $failed; if ( true === $truth ) { ++$passed; echo "PASS $name\n"; } else { ++$failed; echo "FAIL $name\n"; } }
function cso_denied( $value ) { return is_wp_error( $value ); }
function cso_scope_drift( $key, $replacement ) { $scope = MAD4B_SCP_CSO_Scope::current(); $old = $GLOBALS['cso_fixture'][ $key ]; $GLOBALS['cso_fixture'][ $key ] = $replacement; $denied = cso_denied( MAD4B_SCP_CSO_Scope::assert_current( $scope ) ); $GLOBALS['cso_fixture'][ $key ] = $old; return $denied; }

cso_check( false === MAD4B_SCP_CSO_Scope::enabled( 'discovery' ) && cso_denied( MAD4B_SCP_CSO_Registry::catalog() ), 'inventory default OFF' );
define( 'MAD4B_CSO_READ_INVENTORY_ENABLED', true ); define( 'MAD4B_CSO_FORMS_ENABLED', 'true' );
cso_check( true === MAD4B_SCP_CSO_Scope::enabled( 'read_inventory' ) && false === MAD4B_SCP_CSO_Scope::enabled( 'forms' ) && false === MAD4B_SCP_CSO_Scope::enabled( 'single_write' ), 'flags require exact boolean and preserve OFF' );
$scope = MAD4B_SCP_CSO_Scope::current();
cso_check( is_array( $scope ) && 64 === strlen( $scope['binding_sha256'] ) && $scope['scope_sha256'] === $scope['binding_sha256'] && ! isset( $scope['wp_user_id'], $scope['session'] ), 'exact cookie scope hides actor material' );
cso_check( true === MAD4B_SCP_CSO_Scope::assert_current( $scope ), 'fresh exact scope admitted' );
foreach ( array( 'source' => str_repeat( 'b', 40 ), 'package' => cso_fixture_hash( 'new_package' ), 'generation' => cso_fixture_hash( 'new_generation' ), 'restore' => 2, 'external' => cso_fixture_hash( 'new_external' ), 'profile_revision' => 2, 'deployment' => str_repeat( 'other-deployment-', 4 ), 'site_uuid' => '99999999-2222-3333-4444-555555555555', 'origin' => 'https://other.test', 'environment' => 'production', 'locale' => 'en_US', 'blog' => 2, 'user_id' => 10, 'roles' => array( 'editor' ), 'caps' => array( 'read' => true ), 'cookie_session' => 'other-cookie-session-1234567890' ) as $key => $value ) cso_check( cso_scope_drift( $key, $value ), 'scope rejects changed ' . $key );
foreach ( array( 'runtime_ready', 'manifest_ready', 'authority_ready', 'configured', 'enrolled', 'url_match', 'user_enrolled', 'read_enabled', 'logged_in' ) as $key ) cso_check( cso_scope_drift( $key, false ), 'scope rejects unavailable ' . $key );
$extra = $scope; $extra['caller_grant'] = true; cso_check( cso_denied( MAD4B_SCP_CSO_Scope::assert_current( $extra ) ), 'extra scope keys rejected' );
foreach ( array( 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION' ) as $header ) { $_SERVER[ $header ] = 'Bearer fixture-unverified-token'; cso_check( cso_denied( MAD4B_SCP_CSO_Scope::current() ), 'unverified bearer cannot downgrade ' . $header ); unset( $_SERVER[ $header ] ); }
cso_fixture_oauth(); $oauth = MAD4B_SCP_CSO_Scope::current(); cso_check( is_array( $oauth ) && $oauth['actor_sha256'] !== $scope['actor_sha256'], 'verified OAuth realm separate from cookie' );
foreach ( array( 'subject_fingerprint', 'issuer_fingerprint', 'client_fingerprint', 'session_fingerprint' ) as $key ) { $old = $GLOBALS['cso_fixture']['identity'][ $key ]; $GLOBALS['cso_fixture']['identity'][ $key ] = cso_fixture_hash( 'changed_' . $key ); cso_check( cso_denied( MAD4B_SCP_CSO_Scope::assert_current( $oauth ) ), 'OAuth scope rejects ' . $key ); $GLOBALS['cso_fixture']['identity'][ $key ] = $old; }
$GLOBALS['cso_fixture']['identity']['token_scopes'][] = 'write'; cso_check( cso_denied( MAD4B_SCP_CSO_Scope::assert_current( $oauth ) ), 'OAuth token scope changes suspend' ); array_pop( $GLOBALS['cso_fixture']['identity']['token_scopes'] );
foreach ( array( 'binding', 'agent', 'grants' ) as $key ) { $old = $GLOBALS['cso_fixture'][ $key ]; $GLOBALS['cso_fixture'][ $key ] = 'grants' === $key ? array() : array( 'status' => 'disabled' ); cso_check( cso_denied( MAD4B_SCP_CSO_Scope::assert_current( $oauth ) ), 'live NHI changed ' . $key ); $GLOBALS['cso_fixture'][ $key ] = $old; }
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer verified-fixture'; cso_check( is_array( MAD4B_SCP_CSO_Scope::current() ), 'verified OAuth remains admitted with auth header' ); unset( $_SERVER['HTTP_AUTHORIZATION'] ); cso_fixture_reset();
cso_check( MAD4B_SCP_CSO_Scope::digest( array( 'b' => 2, 'a' => 1 ) ) === MAD4B_SCP_CSO_Scope::digest( array( 'a' => 1, 'b' => 2 ) ), 'canonical object key order stable' );
cso_check( MAD4B_SCP_CSO_Scope::digest( 2 ) !== MAD4B_SCP_CSO_Scope::digest( 2.0 ) && MAD4B_SCP_CSO_Scope::digest( array( 1, 2 ) ) !== MAD4B_SCP_CSO_Scope::digest( array( 2, 1 ) ), 'integer float and list order commitments distinct' );
foreach ( array( INF, NAN, new stdClass(), str_repeat( 'a', MAD4B_SCP_CSO_Scope::MAX_BYTES + 1 ), "\xFF", array_fill( 0, 8200, 1 ) ) as $value ) cso_check( false === MAD4B_SCP_CSO_Scope::bounded( $value ) && cso_denied( MAD4B_SCP_CSO_Scope::digest( $value ) ), 'invalid data bounded and digest denied' );
$deep = 1; for ( $i = 0; $i < 26; ++$i ) $deep = array( $deep ); cso_check( false === MAD4B_SCP_CSO_Scope::bounded( $deep ), 'depth overflow denied' );
foreach ( array( array( 'password' => 'plain' ), 'Bearer abcdefghijklmnop', 'sk-abcdefghijklmnop', 'https://user:password@fixture.test/path', array( 'values' => array( 'api_key' => 'plain' ) ) ) as $value ) cso_check( false === MAD4B_SCP_CSO_Scope::safe_data( $value ) && false === MAD4B_SCP_CSO_Scope::safe_data( $value, false ), 'credential data boolean rejection' );
cso_check( true === MAD4B_SCP_CSO_Scope::safe_data( array( 'credential_reference' => 'opaque-id', 'password' => array( 'type' => 'string', 'writeOnly' => true ) ), false ), 'typed reference metadata admitted' );
$material = array( 'n' => 2, 'expires_at' => 1 ); $sealed = MAD4B_SCP_CSO_Scope::seal( $material, 'mad4b.cso01.fixture.v1' );
cso_check( is_array( $sealed ) && $sealed['material'] === $material && MAD4B_SCP_CSO_Scope::unseal( $sealed, 'mad4b.cso01.fixture.v1' ) === $material, 'MAC exact material without ambient flags scope expiry' );
$tamper = $sealed; $tamper['material']['n'] = 2.0; cso_check( cso_denied( MAD4B_SCP_CSO_Scope::unseal( $tamper, 'mad4b.cso01.fixture.v1' ) ), 'same-value float tamper rejected' );
cso_check( cso_denied( MAD4B_SCP_CSO_Scope::unseal( $sealed, 'mad4b.cso01.other.v1' ) ) && cso_denied( MAD4B_SCP_CSO_Scope::seal( array( 'password' => 'plain' ), 'mad4b.cso01.fixture.v1' ) ), 'cross-purpose and secret material rejected' );
$tamper = $sealed; $tamper['extra'] = true; cso_check( cso_denied( MAD4B_SCP_CSO_Scope::unseal( $tamper, 'mad4b.cso01.fixture.v1' ) ), 'extra seal keys rejected' );
cso_check( 'mad4b_cso_input_invalid' === MAD4B_SCP_CSO_Scope::error( 'INPUT_INVALID' )->get_error_code(), 'controlled uppercase error normalized' );

$row = MAD4B_SCP_CSO_Registry::describe( 'fixture/read' );
cso_check( is_array( $row ) && $row['input_schema'] === wp_get_ability( 'fixture/read' )->input && $row['output_schema'] === wp_get_ability( 'fixture/read' )->output && false === $row['authorizing'], 'native schemas preserved and nonauthorizing' );
cso_check( is_array( $row ) && $row['schema_sha256'] === MAD4B_SCP_CSO_Scope::digest( array( 'input_schema' => $row['input_schema'], 'output_schema' => $row['output_schema'] ) ) && '1.0.0' === $row['provider']['runtime_version'], 'schema pair and runtime provider provenance pinned' );
$write = MAD4B_SCP_CSO_Registry::describe( 'fixture/write' );
cso_check( is_array( $write ) && true === $write['writable_by_adapter'] && 'WRITE_CANDIDATE' === $write['storage_status'] && false === $write['cso_conformance_certified'] && 0 === $GLOBALS['cso_fixture']['write_permission_calls'], 'write planning uses existing mount without consuming permission' );
cso_check( is_array( $write ) && ! isset( $write['field_metadata']['title']['certified'], $write['field_metadata']['title']['write_eligible'] ) && isset( $write['readback']['capability_binding'] ), 'native witness pinned and certification flags stripped' );
$GLOBALS['cso_fixture']['abilities']['fixture/write']->meta['mad4b_cso']['storage_kind'] = 'custom_table'; $unknown = MAD4B_SCP_CSO_Registry::describe( 'fixture/write' );
cso_check( is_array( $unknown ) && 'UNSUPPORTED' === $unknown['storage_status'] && false === $unknown['writable_by_adapter'], 'unknown table remains unsupported' ); cso_fixture_reset();
$GLOBALS['cso_fixture']['abilities']['fixture/write']->meta['mad4b_cso']['readback']['target_map'] = array( 'id' => 'title' ); $bad = MAD4B_SCP_CSO_Registry::describe( 'fixture/write' ); cso_check( is_array( $bad ) && false === $bad['writable_by_adapter'], 'unrelated typed witness denied' ); cso_fixture_reset();
unset( wp_get_ability( 'fixture/write' )->meta['mad4b_cso']['readback']['revision_input'] ); $bad = MAD4B_SCP_CSO_Registry::describe( 'fixture/write' ); cso_check( is_array( $bad ) && false === $bad['writable_by_adapter'], 'missing CAS revision input denies executable candidate' ); cso_fixture_reset();
wp_get_ability( 'fixture/write' )->boundary = false; cso_check( cso_denied( MAD4B_SCP_CSO_Registry::describe( 'fixture/write' ) ), 'caller certified flag cannot forge real boundary' ); cso_fixture_reset();
cso_check( cso_denied( MAD4B_SCP_CSO_Registry::describe( 'fixture/private' ) ) && cso_denied( MAD4B_SCP_CSO_Registry::describe( 'mad4b/read-execute' ) ), 'private targets and generic dispatchers not forms' );
$catalog = MAD4B_SCP_CSO_Registry::catalog( '', 1 ); $second = MAD4B_SCP_CSO_Registry::catalog( '', 1, 1 );
cso_check( is_array( $catalog ) && 1 === $catalog['count'] && true === $catalog['has_more'] && 'fixture/read' === $catalog['items'][0]['ability_name'] && 'fixture/write' === $second['items'][0]['ability_name'] && false === strpos( wp_json_encode( $catalog ), 'fixture/private' ), 'authorized pagination hides private existence' );
foreach ( array( array( '', 51, 0 ), array( '', 1, 4097 ), array( str_repeat( 'ا', 161 ), 1, 0 ), array( "\xFF", 1, 0 ), array( 'Bearer abcdefghijklmnop', 1, 0 ) ) as $args ) cso_check( cso_denied( MAD4B_SCP_CSO_Registry::catalog( ...$args ) ), 'catalog input budgets and privacy' );
wp_get_ability( 'fixture/read' )->input['properties']['api_key'] = array( 'type' => 'string', 'default' => 'rawSecret' ); cso_check( cso_denied( MAD4B_SCP_CSO_Registry::describe( 'fixture/read' ) ), 'secret schema defaults denied before disclosure' ); cso_fixture_reset();
$row = MAD4B_SCP_CSO_Registry::describe( 'fixture/read' ); $read = MAD4B_SCP_CSO_Registry::read( 'fixture/read', array( 'id' => 7 ), $row['preparation'] );
cso_check( is_array( $read ) && 'mad4b.cso01.native-read.v1' === $read['contract'] && 'رحلة مصر' === $read['result']['title'] && 1 === $GLOBALS['cso_fixture']['dispatches'] && 0 === $GLOBALS['cso_fixture']['direct_targets'] && 0 === $GLOBALS['cso_fixture']['missing_children'], 'actual production fixed read dispatcher and native result' );
$frame = MAD4B_SCP_Execution_Fence::enter_execution_frame( 'cso/fixture-plan', array(), false ); $nested = MAD4B_SCP_CSO_Registry::read( 'fixture/read', array( 'id' => 7 ), $row['preparation'] ); MAD4B_SCP_Execution_Fence::leave_execution_frame( $frame );
cso_check( is_array( $nested ) && 0 === $GLOBALS['cso_fixture']['missing_children'], 'genuine parent child permits consumed at both nested boundaries' );
$issues = $GLOBALS['cso_fixture']['preparations']; $effects = $GLOBALS['cso_fixture']['target_effects'];
cso_check( cso_denied( MAD4B_SCP_CSO_Registry::read( 'fixture/read', array(), array() ) ) && $issues === $GLOBALS['cso_fixture']['preparations'] && $effects === $GLOBALS['cso_fixture']['target_effects'], 'missing preparation denies without reissuing or effect' );
cso_check( cso_denied( MAD4B_SCP_CSO_Registry::read( 'fixture/write', array( 'id' => 7 ), MAD4B_SCP_CSO_Registry::describe( 'fixture/write' )['preparation'] ) ) && cso_denied( MAD4B_SCP_CSO_Registry::read( 'fixture/read', array( 'api_key' => 'raw' ), $row['preparation'] ) ), 'mutative read targets and secret inputs denied' );
$GLOBALS['cso_fixture']['provider_version'] = '2.0.0'; cso_check( cso_denied( MAD4B_SCP_CSO_Registry::read( 'fixture/read', array( 'id' => 7 ), $row['preparation'] ) ) && $effects === $GLOBALS['cso_fixture']['target_effects'], 'provider upgrade suspends before target effect' ); cso_fixture_reset();
$row = MAD4B_SCP_CSO_Registry::describe( 'fixture/read' ); wp_get_ability( 'fixture/read' )->input['properties']['id']['maximum'] = 10; cso_check( cso_denied( MAD4B_SCP_CSO_Registry::read( 'fixture/read', array( 'id' => 7 ), $row['preparation'] ) ) && 0 === $GLOBALS['cso_fixture']['target_effects'], 'native schema drift suspended before read effect' ); cso_fixture_reset();
$row = MAD4B_SCP_CSO_Registry::describe( 'fixture/read' ); wp_get_ability( 'fixture/read' )->after = static function () { wp_get_ability( 'fixture/read' )->output['properties']['title']['maxLength'] = 127; }; cso_check( cso_denied( MAD4B_SCP_CSO_Registry::read( 'fixture/read', array( 'id' => 7 ), $row['preparation'] ) ), 'post-read schema drift suppresses returned result' ); cso_fixture_reset();
wp_get_ability( 'fixture/read' )->permission = static function ( $input ) { return is_array( $input ) && 7 === ( $input['id'] ?? null ); }; $object_row = MAD4B_SCP_CSO_Registry::describe( 'fixture/read', array( 'id' => 7 ) ); cso_check( is_array( $object_row ) && cso_denied( MAD4B_SCP_CSO_Registry::describe( 'fixture/read' ) ) && cso_denied( MAD4B_SCP_CSO_Registry::read( 'fixture/read', array( 'id' => 8 ), $object_row['preparation'] ) ), 'read object permissions rechecked with exact params' ); cso_fixture_reset();
$row = MAD4B_SCP_CSO_Registry::describe( 'fixture/read' ); wp_get_ability( 'fixture/read' )->result = array( 'api_key' => 'must-not-echo' ); $secret_error = MAD4B_SCP_CSO_Registry::read( 'fixture/read', array( 'id' => 7 ), $row['preparation'] ); cso_check( cso_denied( $secret_error ) && false === strpos( $secret_error->get_error_message(), 'must-not-echo' ), 'secret output never returned in error' ); cso_fixture_reset();
$row = MAD4B_SCP_CSO_Registry::describe( 'fixture/read' ); $GLOBALS['cso_fixture']['now'] += 301; cso_check( cso_denied( MAD4B_SCP_CSO_Registry::read( 'fixture/read', array( 'id' => 7 ), $row['preparation'] ) ), 'actual preparation receipt expiry honored by dispatcher' ); cso_fixture_reset();
$snapshot = MAD4B_SCP_CSO_Discovery::discover();
cso_check( is_array( $snapshot ) && 1 === count( $snapshot['inventory']['post_types'] ) && 1 === count( $snapshot['inventory']['taxonomies'] ) && array( 'post' ) === $snapshot['inventory']['taxonomies'][0]['object_types'] && false === strpos( wp_json_encode( $snapshot ), 'private_doc' ), 'private CPT taxonomy existence excluded from snapshot' );
cso_check( true === MAD4B_SCP_CSO_Discovery::assert_snapshot( $snapshot ) && false === $snapshot['storage_policy']['arbitrary_sql'], 'fresh inventory stays nonauthorizing and SQL-free' );
$GLOBALS['cso_fixture']['plugin_version'] = '2.0.0'; cso_check( cso_denied( MAD4B_SCP_CSO_Discovery::assert_snapshot( $snapshot ) ), 'plugin version drift suspends inventory' ); cso_fixture_reset();
$snapshot = MAD4B_SCP_CSO_Discovery::discover(); wp_get_ability( 'fixture/read' )->output['properties']['title']['maxLength'] = 120; cso_check( cso_denied( MAD4B_SCP_CSO_Discovery::assert_snapshot( $snapshot ) ), 'selected catalog schema drift suspends inventory' ); cso_fixture_reset();
$GLOBALS['cso_fixture']['caps']['manage_options'] = false; $GLOBALS['cso_fixture']['caps']['activate_plugins'] = false; $hidden_inventory = MAD4B_SCP_CSO_Discovery::discover(); cso_check( is_array( $hidden_inventory ) && false === $hidden_inventory['inventory']['plugin_inventory_visible'] && array() === $hidden_inventory['inventory']['plugins'], 'plugin inventory requires its own permission' ); cso_fixture_reset();
cso_check( cso_denied( MAD4B_SCP_CSO_Discovery::discover( array( 'table' => 'wp_options' ) ) ), 'arbitrary storage query denied' );
$copy = clone wp_get_ability( 'fixture/write' );
for ( $i = 0; $i < 650; ++$i ) { $ability = clone $copy; $ability->name = 'a/noop-' . str_pad( (string) $i, 4, '0', STR_PAD_LEFT ); $GLOBALS['cso_fixture']['abilities'][ $ability->name ] = $ability; $GLOBALS['cso_fixture']['providers'][ $ability->name ] = 'fixture'; $GLOBALS['cso_fixture']['mounted'][ $ability->name ] = true; }
$large = MAD4B_SCP_CSO_Registry::catalog( 'fixture/read', 5 ); cso_check( is_array( $large ) && 1 === $large['count'] && 'fixture/read' === $large['items'][0]['ability_name'] && 0 === $GLOBALS['cso_fixture']['write_permission_calls'], '650 preceding write schemas never starve relevant read or consume approvals' ); cso_fixture_reset();
wp_get_ability( 'fixture/read' )->permission = static function ( $input ) { $GLOBALS['cso_fixture']['cookie_session'] = 'changed-session-during-catalog'; return true; };
cso_check( cso_denied( MAD4B_SCP_CSO_Registry::catalog() ), 'whole-scan scope drift suppresses catalog disclosure' ); cso_fixture_reset();
$alias = MAD4B_SCP_CSO_Registry::describe( 'fixture/write' ); cso_check( is_array( $alias ) && $alias['readonly'] === $alias['read_only'] && $alias['provider']['id'] === $alias['provider']['provider_id'] && $alias['provider']['certification_sha256'] === $alias['provider']['certification_generation_sha256'] && $alias['storage']['kind'] === $alias['storage_kind'] && $alias['storage']['native_route_available'] === $alias['native_route_available'] && false === $alias['cso_conformance_certified'], 'compatibility aliases share exact evidence and never claim conformance' );
$GLOBALS['cso_fixture']['caps']['manage_options'] = false; $GLOBALS['cso_fixture']['caps']['edit_posts'] = false; cso_check( cso_denied( MAD4B_SCP_CSO_Registry::describe( 'fixture/write' ) ) && 0 === $GLOBALS['cso_fixture']['write_permission_calls'], 'unauthorized planner never consumes mutation permission' );
echo "SUMMARY $passed PASS $failed FAIL; framework doubles, no live WordPress or native database acceptance\n";
exit( $failed ? 1 : 0 );
