<?php
define( 'ABSPATH', '/tmp/' );
define( 'MAD4B_MCP_MUTATION_ENABLED', true );

class WP_Error {
	private $code;
	private $message;
	private $data;
	public function __construct( $code, $message = '', $data = null ) { $this->code = (string) $code; $this->message = (string) $message; $this->data = $data; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function add_action() { return true; }
function add_filter() { return true; }
function remove_filter() { return true; }
function has_filter() { return false; }
function current_user_can( $cap ) { return 'manage_options' === $cap; }
function get_current_user_id() { return 7; }
function sanitize_key( $v ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $v ) ); }
function absint( $v ) { return abs( (int) $v ); }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function wp_json_encode( $v, $flags = 0 ) { return json_encode( $v, $flags ); }
function wp_generate_uuid4() { static $i = 0; ++$i; return sprintf( '00000000-0000-4000-8000-%012d', $i ); }
function sanitize_text_field( $v ) { return is_scalar( $v ) ? preg_replace( '/[\x00-\x1F\x7F]/', '', (string) $v ) : ''; }

$GLOBALS['mad4b_bind_options'] = array();
$GLOBALS['mad4b_bind_abilities'] = array();
function get_option( $key, $default = false ) { return array_key_exists( $key, $GLOBALS['mad4b_bind_options'] ) ? $GLOBALS['mad4b_bind_options'][ $key ] : $default; }
function update_option( $key, $value, $autoload = null ) { $before = array_key_exists( $key, $GLOBALS['mad4b_bind_options'] ) ? $GLOBALS['mad4b_bind_options'][ $key ] : null; $GLOBALS['mad4b_bind_options'][ $key ] = $value; return $before !== $value; }
function wp_has_ability( $name ) { return isset( $GLOBALS['mad4b_bind_abilities'][ $name ] ); }
function wp_register_ability( $name, $args ) { $GLOBALS['mad4b_bind_abilities'][ $name ] = $args; return true; }

final class MAD4B_SCP_Policy {
	public static function can_breakglass() { return false; }
}
final class MAD4B_SCP_Identity_Context {
	public static function current() {
		return array(
			'authenticated' => true,
			'auth_method' => 'oauth2_bearer',
			'subject_type' => 'oauth',
			'subject_fingerprint' => str_repeat( '1', 64 ),
			'issuer_fingerprint' => str_repeat( '2', 64 ),
			'client_fingerprint' => str_repeat( '3', 64 ),
			'token_instance_fingerprint' => str_repeat( '4', 64 ),
			'wp_user_id' => 7,
			'request_id' => 'runtime-request-0001',
		);
	}
}
final class MAD4B_SCP_Agent_Registry {
	public static function resolve_agent( $identity ) {
		return array(
			'id' => 5,
			'public_id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
			'slug' => 'chatgpt-governed-write',
			'status' => 'enabled',
			'environment' => 'staging',
			'wp_user_id' => 7,
		);
	}
}

final class MAD4B_SCP_OAuth_Resource_Bridge {
	public static function verified_bearer_active() { return true; }
}

final class MAD4B_SCP_Transport_Context {
	public static function current_server_id() { return 'mad4b-chatgpt'; }
}

final class MAD4B_SCP_Site_Profile {
	public static function configured() { return true; }
	public static function current_environment() { return 'staging'; }
	public static function origin_enrolled() { return true; }
	public static function site_urls_match_enrollment() { return true; }
	public static function write_enabled() { return true; }
	public static function agent_slug() { return 'chatgpt-governed-write'; }
	public static function user_is_enrolled( $id ) { return 7 === (int) $id; }
	public static function current_origin() { return 'https://staging.example.test'; }
	public static function revision() { return 9; }
	public static function profile_digest() { return str_repeat( 'a', 64 ); }
	public static function site_uuid() { return '11111111-2222-3333-4444-555555555555'; }
}

final class MAD4B_SCP_Audit {
	public static $events = array();
	public static $fail_completion = false;
	public static function storage_status() { return array( 'ready' => true ); }
	public static function record( $event, $data = array(), $status = 'ok' ) {
		self::$events[] = array( 'event' => $event, 'data' => $data, 'status' => $status );
		if ( self::$fail_completion && 'mad4b/staging-write-candidate-binding-complete' === $event ) return new WP_Error( 'audit_failed' );
		return array( 'recorded' => true );
	}
}

final class MAD4B_SCP_Staging_Write_Authority {
	const OPTION = 'mad4b_scp_staging_write_authority_v1';
	const CANDIDATE_BINDING_CONTRACT = 'mad4b.governed-write-authority-candidate-binding.v2';
	public static $reconcile_calls = 0;
	public static $current_sha = '3b1dd1c339e3dd3edc315cb65f6bcdb6f17ef6c9';
	public static $current_build = '11acee18169e65a4d9e5437e77be08e31a901a04951d8128127ff95f60539bbd';
	public static $current_manifest = '2fed7ef3164cc6909a4f40ed28e80994c4c9e4129d040e9928e9dda9fe793f3f';
	public static $current_artifact = 'mad4b-site-control-plane-general-distribution-kit-3b1dd1c339e3dd3edc315cb65f6bcdb6f17ef6c9';
	public static $inventory_fp = '';
	public static $rows = array(
		array( 'ability' => 'mad4b/content-update-post', 'provider' => 'core', 'mounted' => true, 'exact_grant_present' => true, 'grant_state' => 'exact_current_environment' ),
		array( 'ability' => 'mad4b/plugin-activate', 'provider' => 'core', 'mounted' => true, 'exact_grant_present' => true, 'grant_state' => 'exact_current_environment' ),
	);

	public static function augment_write_ability( $args, $name ) { return $args; }
	public static function bootstrap() { return get_option( self::OPTION, array() ); }
	public static function reconcile() { ++self::$reconcile_calls; return new WP_Error( 'must_not_be_called' ); }
	public static function candidate_binding_status() {
		$status = get_option( self::OPTION, array() );
		$stored_sha = isset( $status['source_commit_sha'] ) ? (string) $status['source_commit_sha'] : '';
		$stored_build = isset( $status['build_fingerprint'] ) ? (string) $status['build_fingerprint'] : '';
		$stored_manifest = isset( $status['package_manifest_digest'] ) ? (string) $status['package_manifest_digest'] : '';
		$stored_artifact = isset( $status['artifact_identity'] ) ? (string) $status['artifact_identity'] : '';
		$complete = '' !== $stored_sha && '' !== $stored_build && '' !== $stored_manifest && '' !== $stored_artifact;
		return array(
			'contract' => self::CANDIDATE_BINDING_CONTRACT,
			'required' => true,
			'stored_bound' => '' !== $stored_sha && '' !== $stored_build,
			'identity_completeness' => $complete ? 'complete' : ( '' !== $stored_sha || '' !== $stored_build ? 'legacy_partial' : 'unbound' ),
			'match' => $complete && hash_equals( self::$current_sha, $stored_sha ) && hash_equals( self::$current_build, $stored_build ) && hash_equals( self::$current_manifest, $stored_manifest ) && hash_equals( self::$current_artifact, $stored_artifact ),
			'stored_source_commit_sha' => $stored_sha,
			'stored_build_fingerprint' => $stored_build,
			'stored_package_manifest_digest' => $stored_manifest,
			'stored_artifact_identity' => $stored_artifact,
			'current_source_commit_sha' => self::$current_sha,
			'current_build_fingerprint' => self::$current_build,
			'current_package_manifest_digest' => self::$current_manifest,
			'current_artifact_identity' => self::$current_artifact,
		);
	}
	public static function effective() { $b = self::candidate_binding_status(); return ! empty( $b['match'] ); }
	public static function reconciliation_plan() {
		$status = get_option( self::OPTION, array() );
		$rows_fp = hash( 'sha256', wp_json_encode( self::$rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		return array(
			'contract' => 'mad4b.governed-write-authority-reconciliation-plan.v2',
			'read_only' => true,
			'mutation_performed' => false,
			'eligible' => true,
			'current_ready' => ! empty( $status['ready'] ),
			'persisted_ready' => ! empty( $status['ready'] ),
			'effective_ready' => self::effective(),
			'environment' => 'staging',
			'agent_present' => true,
			'agent_public_id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
			'write_tool_count' => count( self::$rows ),
			'write_inventory_fingerprint' => self::$inventory_fp,
			'grant_rows_fingerprint' => $rows_fp,
			'exact_grants_existing' => count( self::$rows ),
			'exact_grants_missing_count' => 0,
			'stale_allow_grants_count' => 0,
			'broad_environment_grants_count' => 0,
			'duplicate_exact_allow_grants_count' => 0,
			'current_agent_wildcard_grants' => 0,
			'global_registry_wildcard_grants' => 0,
			'breakglass_included' => false,
			'candidate_binding' => self::candidate_binding_status(),
			'rows' => self::$rows,
		);
	}
	public static function bind_candidate_identity( $sha, $build, $context = array() ) {
		if ( ! is_array( $context ) || 'mad4b.staging-write-candidate-binding.v2' !== ( isset( $context['contract'] ) ? (string) $context['contract'] : '' ) ) return new WP_Error( 'mad4b_candidate_binding_audit_context_required' );
		if ( ! hash_equals( self::$current_sha, strtolower( (string) $sha ) ) || ! hash_equals( self::$current_build, strtolower( (string) $build ) ) ) return new WP_Error( 'candidate_mismatch' );
		$status = get_option( self::OPTION, array() );
		$before = $status;
		$current_binding = self::candidate_binding_status();
		if ( ! empty( $current_binding['match'] ) ) {
			MAD4B_SCP_Audit::record( 'mad4b/staging-write-candidate-binding-noop', array(
				'contract' => $context['contract'],
				'operation_id' => $context['operation_id'],
				'state' => 'already_bound',
				'idempotent' => true,
				'mutation_performed' => false,
				'binding_mutation_performed' => false,
				'previous_binding' => $current_binding,
				'new_binding' => $current_binding,
			) );
			return array( 'contract' => $context['contract'], 'operation_id' => $context['operation_id'], 'state' => 'already_bound', 'idempotent' => true, 'mutation_performed' => false, 'binding_mutation_performed' => false );
		}
		$intent = MAD4B_SCP_Audit::record( 'mad4b/staging-write-candidate-binding-authorized', array_merge( $context, array(
			'state' => 'authorized',
			'mutation_performed' => false,
			'binding_mutation_performed' => false,
		) ) );
		if ( is_wp_error( $intent ) ) return $intent;
		$status['source_commit_sha'] = self::$current_sha;
		$status['build_fingerprint'] = self::$current_build;
		$status['package_manifest_digest'] = self::$current_manifest;
		$status['artifact_identity'] = self::$current_artifact;
		update_option( self::OPTION, $status, false );
		$after = self::candidate_binding_status();
		$completion = MAD4B_SCP_Audit::record( 'mad4b/staging-write-candidate-binding-complete', array_merge( $context, array(
			'state' => 'bound',
			'idempotent' => false,
			'mutation_performed' => true,
			'binding_mutation_performed' => true,
			'previous_binding' => $before,
			'new_binding' => get_option( self::OPTION, array() ),
			'candidate_binding_match' => ! empty( $after['match'] ),
			'grant_mutation_performed' => false,
			'subject_mutation_performed' => false,
			'agent_mutation_performed' => false,
			'reconcile_called' => false,
			'production_mutation' => false,
		) ) );
		if ( is_wp_error( $completion ) ) {
			$GLOBALS['mad4b_bind_options'][ self::OPTION ] = $before;
			MAD4B_SCP_Audit::record( 'mad4b/staging-write-candidate-binding-rollback', array_merge( $context, array( 'state' => 'rollback', 'mutation_performed' => true, 'binding_mutation_performed' => true ) ), 'error' );
			return new WP_Error( 'mad4b_candidate_bind_completion_audit_failed' );
		}
		return array(
			'contract' => $context['contract'],
			'operation_id' => $context['operation_id'],
			'state' => 'bound',
			'idempotent' => false,
			'mutation_performed' => true,
			'binding_mutation_performed' => true,
			'grant_mutation_performed' => false,
			'subject_mutation_performed' => false,
			'agent_mutation_performed' => false,
			'reconcile_called' => false,
			'production_mutation' => false,
		);
	}
}

$inventory_rows = array();
foreach ( MAD4B_SCP_Staging_Write_Authority::$rows as $row ) {
	$inventory_rows[] = array( 'ability' => $row['ability'], 'provider' => sanitize_key( $row['provider'] ) );
}
usort( $inventory_rows, static function ( $a, $b ) { return strcmp( $a['ability'] . "\0" . $a['provider'], $b['ability'] . "\0" . $b['provider'] ); } );
MAD4B_SCP_Staging_Write_Authority::$inventory_fp = hash( 'sha256', wp_json_encode( $inventory_rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );

$old_status = array(
	'contract' => 'mad4b.governed-write-authority.v2',
	'ready' => true,
	'state' => 'ready',
	'blocker' => '',
	'agent_public_id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
	'write_tool_count' => 2,
	'write_inventory_fingerprint' => MAD4B_SCP_Staging_Write_Authority::$inventory_fp,
	'source_commit_sha' => 'ad05dbb3fa4c5a2c83fb951b701a10fd638cdb1a',
	'build_fingerprint' => 'c00a46f8890df0fdec0c38b853944fd308e51a804693c63747e099a6986f3e9c',
);
$GLOBALS['mad4b_bind_options'][ MAD4B_SCP_Staging_Write_Authority::OPTION ] = $old_status;

require dirname( __DIR__ ) . '/includes/class-mad4b-scp-staging-write-candidate-binding.php';

function mad4b_bind_assert( $condition, $message, $data = null ) {
	if ( $condition ) return;
	fwrite( STDERR, 'FAIL: ' . $message . ( null !== $data ? ' ' . json_encode( $data ) : '' ) . PHP_EOL );
	exit( 1 );
}

MAD4B_SCP_Staging_Write_Candidate_Binding::register_ability();
mad4b_bind_assert( isset( $GLOBALS['mad4b_bind_abilities']['mad4b/staging-write-candidate-bind'] ), 'binding ability did not register' );
MAD4B_SCP_Staging_Write_Candidate_Binding::register_audit_ability();
mad4b_bind_assert( isset( $GLOBALS['mad4b_bind_abilities']['mad4b/staging-write-candidate-binding-audit'] ), 'binding audit ability did not register' );
$audit_registration = $GLOBALS['mad4b_bind_abilities']['mad4b/staging-write-candidate-binding-audit'];
mad4b_bind_assert( true === $audit_registration['meta']['annotations']['readonly'], 'binding audit ability must be read-only' );
mad4b_bind_assert( 'enrollment' === $audit_registration['meta']['mcp']['surface'], 'binding audit ability must use enrollment surface' );
$registration = $GLOBALS['mad4b_bind_abilities']['mad4b/staging-write-candidate-bind'];
mad4b_bind_assert( 'enrollment' === $registration['meta']['mcp']['surface'], 'binding ability must use enrollment surface', $registration );
mad4b_bind_assert( false === $registration['meta']['annotations']['readonly'], 'binding ability must declare mutation truthfully' );
mad4b_bind_assert( true === $registration['meta']['annotations']['idempotent'], 'binding ability must be idempotent' );

$plan = MAD4B_SCP_Staging_Write_Authority::reconciliation_plan();
$input = array(
	'expected_revision' => MAD4B_SCP_Site_Profile::revision(),
	'expected_profile_digest' => MAD4B_SCP_Site_Profile::profile_digest(),
	'expected_source_commit_sha' => MAD4B_SCP_Staging_Write_Authority::$current_sha,
	'expected_build_fingerprint' => MAD4B_SCP_Staging_Write_Authority::$current_build,
	'expected_package_manifest_digest' => MAD4B_SCP_Staging_Write_Authority::$current_manifest,
	'expected_artifact_identity' => MAD4B_SCP_Staging_Write_Authority::$current_artifact,
	'expected_agent_public_id' => $plan['agent_public_id'],
	'expected_write_tool_count' => $plan['write_tool_count'],
	'expected_write_inventory_fingerprint' => $plan['write_inventory_fingerprint'],
	'expected_grant_rows_fingerprint' => $plan['grant_rows_fingerprint'],
	'confirmation' => MAD4B_SCP_Staging_Write_Candidate_Binding::CONFIRMATION,
);

$drifted = $input;
$drifted['expected_grant_rows_fingerprint'] = str_repeat( 'f', 64 );
$bad = MAD4B_SCP_Staging_Write_Candidate_Binding::bind( $drifted );
mad4b_bind_assert( is_wp_error( $bad ) && 'mad4b_candidate_bind_grant_rows_fingerprint_mismatch' === $bad->get_error_code(), 'reviewed grant snapshot drift was not rejected', $bad );
mad4b_bind_assert( ! MAD4B_SCP_Staging_Write_Authority::effective(), 'failed precondition unexpectedly changed authority binding' );

$result = MAD4B_SCP_Staging_Write_Candidate_Binding::bind( $input );
mad4b_bind_assert( ! is_wp_error( $result ), 'exact binding-only operation failed', $result );
mad4b_bind_assert( 'bound' === $result['state'], 'binding operation did not report bound state', $result );
mad4b_bind_assert( ! empty( $result['mutation_performed'] ) && ! empty( $result['binding_mutation_performed'] ), 'true binding did not report its mutation explicitly', $result );
$authorized = array_values( array_filter( MAD4B_SCP_Audit::$events, static function ( $e ) { return 'mad4b/staging-write-candidate-binding-authorized' === $e['event']; } ) );
$completed = array_values( array_filter( MAD4B_SCP_Audit::$events, static function ( $e ) { return 'mad4b/staging-write-candidate-binding-complete' === $e['event']; } ) );
mad4b_bind_assert( 1 === count( $authorized ) && 1 === count( $completed ), 'binding audit pair missing', MAD4B_SCP_Audit::$events );
mad4b_bind_assert( $authorized[0]['data']['operation_id'] === $completed[0]['data']['operation_id'], 'binding audit operation_id drifted' );
mad4b_bind_assert( str_repeat( '3', 64 ) === $authorized[0]['data']['actor']['client_fingerprint'], 'safe OAuth client attribution was not preserved' );
mad4b_bind_assert( str_repeat( '4', 64 ) === $authorized[0]['data']['actor']['token_instance_fingerprint'], 'safe OAuth token-instance attribution was not preserved' );
mad4b_bind_assert( ! isset( $authorized[0]['data']['actor']['client_id'] ) && ! isset( $authorized[0]['data']['actor']['jti'] ), 'raw OAuth client/session material leaked into binding audit' );
mad4b_bind_assert( ! empty( $completed[0]['data']['mutation_performed'] ) && ! empty( $completed[0]['data']['binding_mutation_performed'] ), 'completion audit did not distinguish true mutation' );
mad4b_bind_assert( ! empty( $result['effective'] ), 'authority did not become effective after exact binding', $result );
mad4b_bind_assert( empty( $result['grant_mutation_performed'] ) && empty( $result['subject_mutation_performed'] ) && empty( $result['agent_mutation_performed'] ), 'binding-only operation claimed broader mutations', $result );
mad4b_bind_assert( empty( $result['reconcile_called'] ) && 0 === MAD4B_SCP_Staging_Write_Authority::$reconcile_calls, 'binding-only operation called full reconcile', $result );
mad4b_bind_assert( 2 === (int) $result['exact_grants_existing'], 'exact grant count changed during binding', $result );

$again = MAD4B_SCP_Staging_Write_Candidate_Binding::bind( $input );
mad4b_bind_assert( ! is_wp_error( $again ) && 'already_bound' === $again['state'] && ! empty( $again['idempotent'] ) && empty( $again['mutation_performed'] ), 'repeat exact binding was not idempotent', $again );
$noop = array_values( array_filter( MAD4B_SCP_Audit::$events, static function ( $e ) { return 'mad4b/staging-write-candidate-binding-noop' === $e['event']; } ) );
mad4b_bind_assert( 1 === count( $noop ) && 'already_bound' === $noop[0]['data']['state'] && empty( $noop[0]['data']['mutation_performed'] ), 'idempotent replay did not emit explicit no-op evidence', $noop );
mad4b_bind_assert( 0 === MAD4B_SCP_Staging_Write_Authority::$reconcile_calls, 'idempotent binding called reconcile' );

$GLOBALS['mad4b_bind_options'][ MAD4B_SCP_Staging_Write_Authority::OPTION ] = $old_status;
$direct = MAD4B_SCP_Staging_Write_Authority::bind_candidate_identity( MAD4B_SCP_Staging_Write_Authority::$current_sha, MAD4B_SCP_Staging_Write_Authority::$current_build );
mad4b_bind_assert( is_wp_error( $direct ) && 'mad4b_candidate_binding_audit_context_required' === $direct->get_error_code(), 'direct primitive binding bypassed mandatory audit context', $direct );
MAD4B_SCP_Audit::$fail_completion = true;
$failed_audit = MAD4B_SCP_Staging_Write_Candidate_Binding::bind( $input );
mad4b_bind_assert( is_wp_error( $failed_audit ) && 'mad4b_candidate_bind_completion_audit_failed' === $failed_audit->get_error_code(), 'completion audit failure did not fail closed', $failed_audit );
mad4b_bind_assert( ! MAD4B_SCP_Staging_Write_Authority::effective(), 'completion audit failure did not restore previous binding' );
$restored = get_option( MAD4B_SCP_Staging_Write_Authority::OPTION, array() );
mad4b_bind_assert( $old_status === $restored, 'binding rollback did not restore exact previous persisted authority', $restored );
mad4b_bind_assert( 0 === MAD4B_SCP_Staging_Write_Authority::$reconcile_calls, 'audit rollback path called reconcile' );

echo "mad4b.staging-write-candidate-binding.runtime.v2: PASS\n";
