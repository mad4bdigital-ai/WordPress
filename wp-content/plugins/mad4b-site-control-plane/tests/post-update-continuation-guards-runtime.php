<?php
define( 'ABSPATH', __DIR__ . '/' );
class WP_Error { private $code; public function __construct( $code, $message = '', $data = null ) { $this->code = $code; } public function get_error_code() { return $this->code; } }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function wp_salt( $purpose ) { return 'fixture-signing-key'; }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( $value ) ); }
function absint( $value ) { return abs( (int) $value ); }
function untrailingslashit( $value ) { return rtrim( $value, '/' ); }
function wp_generate_uuid4() { return '11111111-2222-4333-8444-' . str_pad( (string) ++$GLOBALS['uuid'], 12, '0', STR_PAD_LEFT ); }
function maybe_serialize( $value ) { return serialize( $value ); }
function maybe_unserialize( $value ) { return unserialize( $value ); }
function wp_cache_delete( $key, $group ) {}
function add_option( $key, $value, $unused = '', $autoload = false ) { if ( isset( $GLOBALS['wpdb']->value ) ) return false; $GLOBALS['wpdb']->value = serialize( $value ); return true; }
function get_option( $key, $default = null ) { return isset( $GLOBALS['wpdb']->value ) ? unserialize( $GLOBALS['wpdb']->value ) : $default; }
function get_userdata( $id ) { return $id === 1 ? (object) array( 'ID' => 1 ) : false; }
function user_can( $user, $capability ) { return ! $GLOBALS['actor_revoked']; }
$GLOBALS['uuid'] = 0; $GLOBALS['actor_revoked'] = false;
class FixtureDB {
	public $options = 'wp_options'; public $value; public $reject_cas = false; public $in_transaction = false;
	public function prepare( $sql, ...$args ) { return array( $sql, $args ); }
	public function get_var( $prepared ) { return $this->value; }
	public function query( $prepared ) { if ( $this->reject_cas ) return 0; $args = $prepared[1]; if ( $this->value !== $args[2] ) return 0; $this->value = $args[0]; return 1; }
}
$GLOBALS['wpdb'] = new FixtureDB();
final class MAD4B_SCP_Connection_Identity_Resolver { public static $build_fingerprint = 'old'; public static function kernel() { return array( 'kernel_fingerprint' => 'stable-kernel', 'oauth' => array( 'issuer' => 'https://staging.example.com/oauth/mcp' ), 'resource' => array( 'url' => 'https://staging.example.com/wp-json/mcp/mad4b-chatgpt' ) ); } public static function fingerprint() { return self::$build_fingerprint; } }
final class MAD4B_SCP_Site_Profile {
	public static $environment = 'staging'; public static $revision = 2; public static $uuid = 'site';
	public static function current_environment() { return self::$environment; }
	public static function origin_enrolled() { return true; }
	public static function write_enabled() { return true; }
	public static function revision() { return self::$revision; }
	public static function profile_digest() { return str_repeat( 'a', 64 ); }
	public static function site_uuid() { return self::$uuid; }
	public static function current_origin() { return 'https://staging.example.com'; }
	public static function user_is_enrolled( $id ) { return $id === 1; }
}
final class MAD4B_SCP_Runtime_Maintenance_Lease { public static $lost = false; public static function refresh( $token, $owner ) { return self::$lost || $token !== 'lease' ? new WP_Error( 'lease_lost' ) : true; } }
final class MAD4B_SCP_Servers { public static function expected_server_ids() { return array( 'mad4b-chatgpt', 'mad4b-write' ); } }
final class MAD4B_SCP_MCP_Peer_Governance { public static $tools = 'stable'; public static function status() { return array( 'inventory_ready' => true, 'blockers' => array(), 'transport_inventory_fingerprint' => hash( 'sha256', self::$tools ), 'foreign_transport_inventory' => array() ); } }
final class MAD4B_SCP_Identity_Context { public static function current() { return array( 'authenticated' => true, 'auth_method' => 'oauth2_bearer', 'wp_user_id' => 1, 'subject_fingerprint' => str_repeat( 'a', 64 ), 'issuer_fingerprint' => str_repeat( 'b', 64 ), 'client_fingerprint' => str_repeat( 'c', 64 ), 'session_fingerprint' => str_repeat( 'd', 64 ) ); } }
final class MAD4B_SCP_Live_Acceptance_Observer { public static $identity; public static function build_provenance_identity_status() { return array_merge( self::$identity, array( 'identity_ready' => true ) ); } }
final class MAD4B_SCP_Skill_Runtime_Certification { public static $current = true; public static function persisted_status() { return array_merge( MAD4B_SCP_Live_Acceptance_Observer::$identity, array( 'ready' => true, 'build_identity_current' => self::$current ) ); } }
final class MAD4B_SCP_Audit { public static $fail_consumption = false; public static function record( $ability, $row, $status, $join = false ) { if ( strpos( $ability, '-consumed' ) !== false ) { if ( ! $join || ! $GLOBALS['wpdb']->in_transaction ) throw new RuntimeException( 'Consumption audit must join candidate transaction' ); if ( self::$fail_consumption ) return new WP_Error( 'audit_failed' ); } return true; } }
final class MAD4B_SCP_Staging_Write_Candidate_Binding { public static function audit_binding_snapshot( array $binding ) { $out = array(); foreach ( array( 'source_commit_sha', 'build_fingerprint', 'package_manifest_digest', 'artifact_identity' ) as $key ) $out[ $key ] = $binding['stored_' . $key]; return $out; } }
final class MAD4B_SCP_Staging_Write_Authority {
	public static $plan; public static $binding; public static $calls = 0; public static $injected_drift = '';
	public static function reconciliation_plan() { return self::$plan; }
	public static function candidate_binding_status() { return self::$binding; }
	public static function effective() { return self::$binding['match']; }
	public static function bind_candidate_identity( $sha, $build, $context ) {
		self::$calls++;
		if ( self::$injected_drift === 'profile' ) MAD4B_SCP_Site_Profile::$revision++;
		$valid = MAD4B_SCP_Post_Update_Continuation::validate_binding_context( $context, MAD4B_SCP_Live_Acceptance_Observer::$identity ); if ( is_wp_error( $valid ) ) return $valid;
		$old_binding = self::$binding; $old_permit = $GLOBALS['wpdb']->value; $GLOBALS['wpdb']->in_transaction = true;
		try {
			foreach ( $context['target_binding'] as $key => $value ) self::$binding['stored_' . $key] = $value;
			self::$binding['match'] = true;
			$consumed = MAD4B_SCP_Post_Update_Continuation::consume_binding_context( $context );
			if ( is_wp_error( $consumed ) ) { self::$binding = $old_binding; $GLOBALS['wpdb']->value = $old_permit; return $consumed; }
			return array( 'idempotent' => false, 'binding_mutation_performed' => true );
		} finally { $GLOBALS['wpdb']->in_transaction = false; }
	}
}
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-post-update-continuation.php';
function check( $condition, $message ) { if ( ! $condition ) throw new RuntimeException( $message ); }
function setup_fixture() {
	$GLOBALS['wpdb']->value = null; $GLOBALS['wpdb']->reject_cas = false; $GLOBALS['actor_revoked'] = false;
	MAD4B_SCP_Runtime_Maintenance_Lease::$lost = false; MAD4B_SCP_MCP_Peer_Governance::$tools = 'stable'; MAD4B_SCP_Site_Profile::$environment = 'staging'; MAD4B_SCP_Site_Profile::$revision = 2; MAD4B_SCP_Site_Profile::$uuid = 'site'; MAD4B_SCP_Audit::$fail_consumption = false; MAD4B_SCP_Skill_Runtime_Certification::$current = true; MAD4B_SCP_Staging_Write_Authority::$injected_drift = '';
	$plan = array( 'eligible' => true, 'current_ready' => true, 'agent_present' => true, 'read_only' => true, 'agent_public_id' => 'agent', 'write_tool_count' => 2, 'exact_grants_existing' => 2, 'write_inventory_fingerprint' => str_repeat( 'e', 64 ), 'grant_rows_fingerprint' => str_repeat( 'f', 64 ), 'persisted_grant_records_fingerprint' => str_repeat( 'b', 64 ) );
	foreach ( array( 'exact_grants_missing_count', 'stale_allow_grants_count', 'unreviewed_stale_allow_grants_count', 'broad_environment_grants_count', 'duplicate_exact_allow_grants_count', 'current_agent_wildcard_grants', 'global_registry_wildcard_grants' ) as $key ) $plan[ $key ] = 0;
	MAD4B_SCP_Staging_Write_Authority::$plan = $plan;
	$old = array( 'source_commit_sha' => str_repeat( '1', 40 ), 'build_fingerprint' => str_repeat( '2', 64 ), 'package_manifest_digest' => str_repeat( '3', 64 ), 'artifact_identity' => 'old-artifact' );
	MAD4B_SCP_Live_Acceptance_Observer::$identity = $old;
	$binding = array( 'match' => true ); foreach ( $old as $key => $value ) { $binding['stored_' . $key] = $value; $binding['current_' . $key] = $value; } MAD4B_SCP_Staging_Write_Authority::$binding = $binding;
	return array( 'version' => '0.4.0-rc.88', 'source_commit_sha' => str_repeat( '4', 40 ), 'build_fingerprint' => str_repeat( '5', 64 ), 'package_manifest_digest' => str_repeat( '6', 64 ), 'artifact_identity' => 'new-artifact', 'archive_sha256' => str_repeat( '7', 64 ), 'release_verdict_success' => true, 'release_root_trust_verified' => true, 'published_from_master' => true, 'release_verdict_run_id' => 1 );
}
function prepare_fixture( array $target ) { return MAD4B_SCP_Post_Update_Continuation::prepare( $target, 'governed_native_release_pull', str_repeat( '8', 64 ), 'lease' ); }
function restart_fixture( array $target ) {
	check( ! is_wp_error( MAD4B_SCP_Post_Update_Continuation::mark_readback_verified( $target ) ), 'readback checkpoint' );
	foreach ( array( 'source_commit_sha', 'build_fingerprint', 'package_manifest_digest', 'artifact_identity' ) as $key ) { MAD4B_SCP_Live_Acceptance_Observer::$identity[$key] = $target[$key]; MAD4B_SCP_Staging_Write_Authority::$binding['current_' . $key] = $target[$key]; }
	MAD4B_SCP_Staging_Write_Authority::$binding['match'] = false;
	MAD4B_SCP_Connection_Identity_Resolver::$build_fingerprint = 'new-build';
}
$target = setup_fixture(); $permit = prepare_fixture( $target ); check( is_array( $permit ), 'prepare before replacement' ); restart_fixture( $target );
$result = MAD4B_SCP_Post_Update_Continuation::evaluate_and_rebind( 'lease' ); check( ! is_wp_error( $result ) && $result['state'] === 'completed', 'build-only update must auto-rebind' );
check( unserialize( $GLOBALS['wpdb']->value )['state'] === 'consumed', 'atomic consumed marker' );
$calls = MAD4B_SCP_Staging_Write_Authority::$calls; check( is_wp_error( MAD4B_SCP_Post_Update_Continuation::evaluate_and_rebind( 'lease' ) ), 'permit replay rejected' ); check( $calls === MAD4B_SCP_Staging_Write_Authority::$calls, 'replay never reaches primitive' );
foreach ( array( 'grant', 'missing', 'wildcard', 'profile', 'transport', 'package', 'production', 'previous', 'site_uuid', 'actor', 'skills', 'cas', 'tamper', 'audit', 'toctou' ) as $case ) {
	$target = setup_fixture(); $permit = prepare_fixture( $target ); check( is_array( $permit ), 'prepare ' . $case ); restart_fixture( $target );
	if ( 'grant' === $case ) MAD4B_SCP_Staging_Write_Authority::$plan['persisted_grant_records_fingerprint'] = 'changed';
	if ( 'missing' === $case ) { MAD4B_SCP_Staging_Write_Authority::$plan['exact_grants_missing_count'] = 1; MAD4B_SCP_Staging_Write_Authority::$plan['grant_rows_fingerprint'] = 'changed-desired-catalog'; }
	if ( 'wildcard' === $case ) MAD4B_SCP_Staging_Write_Authority::$plan['global_registry_wildcard_grants'] = 1;
	if ( 'profile' === $case ) MAD4B_SCP_Site_Profile::$revision++;
	if ( 'transport' === $case ) MAD4B_SCP_MCP_Peer_Governance::$tools = 'renamed-tool-at-same-count';
	if ( 'package' === $case ) MAD4B_SCP_Live_Acceptance_Observer::$identity['source_commit_sha'] = str_repeat( '9', 40 );
	if ( 'production' === $case ) MAD4B_SCP_Site_Profile::$environment = 'production';
	if ( 'previous' === $case ) MAD4B_SCP_Staging_Write_Authority::$binding['stored_artifact_identity'] = 'foreign-binding';
	if ( 'site_uuid' === $case ) MAD4B_SCP_Site_Profile::$uuid = 'foreign-site';
	if ( 'actor' === $case ) $GLOBALS['actor_revoked'] = true;
	if ( 'skills' === $case ) MAD4B_SCP_Skill_Runtime_Certification::$current = false;
	if ( 'cas' === $case ) $GLOBALS['wpdb']->reject_cas = true;
	if ( 'tamper' === $case ) { $p = unserialize( $GLOBALS['wpdb']->value ); $p['expires_at']++; $GLOBALS['wpdb']->value = serialize( $p ); }
	if ( 'audit' === $case ) MAD4B_SCP_Audit::$fail_consumption = true;
	if ( 'toctou' === $case ) MAD4B_SCP_Staging_Write_Authority::$injected_drift = 'profile';
	$calls = MAD4B_SCP_Staging_Write_Authority::$calls; $result = MAD4B_SCP_Post_Update_Continuation::evaluate_and_rebind( 'lease' );
	check( ! MAD4B_SCP_Staging_Write_Authority::$binding['match'], $case . ' must leave Write quarantined' );
	if ( ! in_array( $case, array( 'audit', 'toctou' ), true ) ) check( $calls === MAD4B_SCP_Staging_Write_Authority::$calls, $case . ' must not reach primitive' );
	if ( in_array( $case, array( 'missing', 'profile', 'transport' ), true ) ) check( $result['state'] === 'owner_gate', $case . ' owner gate' );
}
$target = setup_fixture(); $permit = prepare_fixture( $target ); MAD4B_SCP_Post_Update_Continuation::cancel( 'rollback', $target ); check( is_wp_error( MAD4B_SCP_Post_Update_Continuation::mark_readback_verified( $target ) ), 'rollback invalidates permit' );
$target = setup_fixture(); MAD4B_SCP_Runtime_Maintenance_Lease::$lost = true; check( is_wp_error( prepare_fixture( $target ) ), 'concurrent update loses maintenance fence' );
check( is_wp_error( MAD4B_SCP_Post_Update_Continuation::validate_binding_context( array(), array() ) ), 'forged Cron context rejected' );
echo "post-update continuation guards runtime: PASS\n";
