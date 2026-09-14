<?php

if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );
if ( ! defined( 'MAD4B_SCP_DIR' ) ) define( 'MAD4B_SCP_DIR', rtrim( dirname( __DIR__ ), '/\\' ) . '/' );

$GLOBALS['mad4b_test_options'] = array();
$GLOBALS['mad4b_test_environment'] = 'staging';
$GLOBALS['mad4b_test_home'] = 'https://staging.client.test/subdir/';
$GLOBALS['mad4b_test_user_id'] = 7;
$GLOBALS['mad4b_test_is_admin'] = true;
$GLOBALS['mad4b_test_audit_ready'] = true;
$GLOBALS['mad4b_test_audit_fail'] = false;
$GLOBALS['mad4b_test_audit_events'] = array();

class WP_Error {
	private $code;
	private $message;
	private $data;
	public function __construct( $code, $message = '', $data = null ) { $this->code = $code; $this->message = $message; $this->data = $data; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function absint( $value ) { return abs( (int) $value ); }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function trailingslashit( $value ) { return rtrim( (string) $value, '/\\' ) . '/'; }
function home_url( $path = '' ) { return rtrim( $GLOBALS['mad4b_test_home'], '/' ) . ( '' === $path ? '' : '/' . ltrim( $path, '/' ) ); }
function wp_get_environment_type() { return $GLOBALS['mad4b_test_environment']; }
function current_user_can( $capability ) { return 'manage_options' === $capability ? ! empty( $GLOBALS['mad4b_test_is_admin'] ) : false; }
function get_current_user_id() { return (int) $GLOBALS['mad4b_test_user_id']; }
function get_option( $key, $default = false ) { return array_key_exists( $key, $GLOBALS['mad4b_test_options'] ) ? $GLOBALS['mad4b_test_options'][ $key ] : $default; }
function update_option( $key, $value, $autoload = null ) { $GLOBALS['mad4b_test_options'][ $key ] = $value; return true; }
function delete_option( $key ) { unset( $GLOBALS['mad4b_test_options'][ $key ] ); return true; }
function wp_generate_uuid4() { static $n = 0; ++$n; return sprintf( '11111111-1111-4111-8111-%012d', $n ); }
function get_userdata( $user_id ) { return absint( $user_id ) > 0 ? (object) array( 'ID' => absint( $user_id ) ) : false; }
function user_can( $user, $capability ) { return is_object( $user ) && 'manage_options' === $capability; }
function get_bloginfo( $key ) { return 'Client Test'; }
function wp_register_ability() {}

final class MAD4B_SCP_Audit {
	public static function storage_status() { return array( 'ready' => ! empty( $GLOBALS['mad4b_test_audit_ready'] ) ); }
	public static function record( $ability, $summary, $status ) {
		if ( ! empty( $GLOBALS['mad4b_test_audit_fail'] ) ) return new WP_Error( 'audit_failed', 'forced audit failure' );
		$GLOBALS['mad4b_test_audit_events'][] = array( 'ability' => $ability, 'summary' => $summary, 'status' => $status );
		return true;
	}
}

require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-site-profile.php';

function mad4b_assert( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}
function mad4b_reset_profile_state() {
	$GLOBALS['mad4b_test_options'] = array();
	$GLOBALS['mad4b_test_audit_events'] = array();
	$GLOBALS['mad4b_test_audit_ready'] = true;
	$GLOBALS['mad4b_test_audit_fail'] = false;
	$GLOBALS['mad4b_test_is_admin'] = true;
	MAD4B_SCP_Site_Profile::reset_cache();
}

// Installation on an unknown site must create no authority even though the same
// binary contains reviewed legacy migration presets for known deployments.
mad4b_reset_profile_state();
$status = MAD4B_SCP_Site_Profile::status();
mad4b_assert( empty( $status['configured'] ), 'unknown site is not auto-enrolled' );
mad4b_assert( ! MAD4B_SCP_Site_Profile::origin_enrolled(), 'unknown site has no enrolled origin' );
mad4b_assert( ! MAD4B_SCP_Site_Profile::write_enabled(), 'unknown site has zero write authority' );

// The reviewed ETG legacy preset is a migration bridge, not product-wide
// authority. It must match only the exact Staging origin/environment and must
// persist the same Site Profile contract consumed by generic runtime code.
mad4b_reset_profile_state();
$GLOBALS['mad4b_test_environment'] = 'staging';
$GLOBALS['mad4b_test_home'] = 'https://staging.egypttourgates.com/';
$legacy = MAD4B_SCP_Site_Profile::status();
mad4b_assert( ! empty( $legacy['configured'] ), 'exact ETG Staging legacy preset is discoverable' );
mad4b_assert( 'legacy_preset_migrated' === $legacy['source'], 'ETG legacy preset is persisted through the migration bridge' );
mad4b_assert( ! empty( $legacy['origin_match'] ) && ! empty( $legacy['environment_match'] ), 'ETG legacy preset is exact-origin/environment bound' );
mad4b_assert( '5ab73d30-b26f-4ea7-a9fa-3dc839187ab6' === $legacy['site_uuid'], 'ETG legacy preset carries the reviewed site UUID' );
mad4b_assert( ! empty( $legacy['skills_enabled'] ) && ! empty( $legacy['write_enabled'] ) && ! empty( $legacy['oauth_enabled'] ), 'ETG legacy preset restores only its reviewed Staging feature set' );
mad4b_assert( 'plugin_asdk_app_6aa05fa2f97481919c24b99855fadba2' === MAD4B_SCP_Site_Profile::chatgpt_app_id(), 'ETG legacy preset restores the reviewed ChatGPT App mapping' );
$persisted_legacy = get_option( MAD4B_SCP_Site_Profile::OPTION, array() );
mad4b_assert( is_array( $persisted_legacy ) && 'https://staging.egypttourgates.com' === $persisted_legacy['canonical_origin'], 'ETG legacy preset persists canonical origin exactly' );

// A near-match must not inherit ETG authority.
mad4b_reset_profile_state();
$GLOBALS['mad4b_test_home'] = 'https://staging.egypttourgates.com/subdir/';
$legacy_near_match = MAD4B_SCP_Site_Profile::status();
mad4b_assert( empty( $legacy_near_match['configured'] ), 'ETG legacy preset does not match a different WordPress path' );
mad4b_assert( ! MAD4B_SCP_Site_Profile::write_enabled(), 'ETG legacy preset near-match remains zero-authority' );

// Generic staging enrollment is exact-origin, path-aware, audited, and revisioned.
mad4b_reset_profile_state();
$GLOBALS['mad4b_test_environment'] = 'staging';
$GLOBALS['mad4b_test_home'] = 'https://staging.client.test/subdir/';
$input = array(
	'expected_revision' => 0,
	'display_name' => 'Client Test',
	'chatgpt_app_id' => 'plugin_asdk_app_example123',
	'oauth_user_ids' => array( 7, 8 ),
	'oauth_enabled' => true,
	'skills_enabled' => true,
	'write_enabled' => true,
	'provider_isolation_enabled' => true,
	'managed_runtime_enabled' => true,
	'acceptance_enabled' => true,
);
$result = MAD4B_SCP_Site_Profile::save_current_site( $input );
mad4b_assert( ! is_wp_error( $result ) && ! empty( $result['configured'] ), 'generic staging site can be explicitly enrolled' );
mad4b_assert( 'https://staging.client.test/subdir' === $result['canonical_origin'], 'canonical origin preserves WordPress subdirectory identity' );
mad4b_assert( 1 === (int) $result['revision'], 'first enrollment starts revision one' );
mad4b_assert( ! empty( $result['write_enabled'] ), 'explicit staging write enrollment becomes eligible' );
mad4b_assert( 1 === count( $GLOBALS['mad4b_test_audit_events'] ), 'site profile enrollment emits append-only audit evidence' );
$digest_v1 = $result['profile_digest'];
$uuid_v1 = $result['site_uuid'];

// Same binary on a cloned/moved origin must fail closed without rewriting profile.
$GLOBALS['mad4b_test_home'] = 'https://clone.client.test/subdir/';
MAD4B_SCP_Site_Profile::reset_cache();
$drift = MAD4B_SCP_Site_Profile::status();
mad4b_assert( empty( $drift['origin_match'] ), 'cloned origin does not match enrolled origin' );
mad4b_assert( in_array( 'site_profile_origin_drift', $drift['blockers'], true ), 'origin drift is reported explicitly' );
mad4b_assert( ! MAD4B_SCP_Site_Profile::write_enabled(), 'origin drift removes governed write eligibility' );

// Return to the enrolled origin and reject a stale optimistic-concurrency update.
$GLOBALS['mad4b_test_home'] = 'https://staging.client.test/subdir/';
MAD4B_SCP_Site_Profile::reset_cache();
$stale = $input;
$stale['expected_revision'] = 0;
$stale_result = MAD4B_SCP_Site_Profile::save_current_site( $stale );
mad4b_assert( is_wp_error( $stale_result ) && 'mad4b_site_profile_stale' === $stale_result->get_error_code(), 'stale profile revision is rejected' );

// A valid second revision retains stable site UUID but changes authorization digest.
$update = $input;
$update['expected_revision'] = 1;
$update['display_name'] = 'Client Test Updated';
$updated = MAD4B_SCP_Site_Profile::save_current_site( $update );
mad4b_assert( ! is_wp_error( $updated ) && 2 === (int) $updated['revision'], 'fresh revision update succeeds' );
mad4b_assert( $uuid_v1 === $updated['site_uuid'], 'site UUID remains stable across policy revisions' );
mad4b_assert( $digest_v1 !== $updated['profile_digest'], 'profile revision/policy change changes authorization digest' );

// Non-admins cannot enroll or mutate the site profile.
$GLOBALS['mad4b_test_is_admin'] = false;
$denied = $update;
$denied['expected_revision'] = 2;
$denied_result = MAD4B_SCP_Site_Profile::save_current_site( $denied );
mad4b_assert( is_wp_error( $denied_result ) && 'mad4b_site_profile_admin_required' === $denied_result->get_error_code(), 'non-admin cannot mutate site profile' );
$GLOBALS['mad4b_test_is_admin'] = true;

// Audit failure rolls the profile mutation back rather than leaving undocumented authority.
$GLOBALS['mad4b_test_audit_fail'] = true;
$before = get_option( MAD4B_SCP_Site_Profile::OPTION, array() );
$failed = $update;
$failed['expected_revision'] = 2;
$failed['display_name'] = 'Must Roll Back';
$failed_result = MAD4B_SCP_Site_Profile::save_current_site( $failed );
mad4b_assert( is_wp_error( $failed_result ) && 'mad4b_site_profile_audit_failed' === $failed_result->get_error_code(), 'failed audit aborts profile mutation' );
mad4b_assert( $before === get_option( MAD4B_SCP_Site_Profile::OPTION, array() ), 'failed audit restores exact prior profile bytes/value' );
$GLOBALS['mad4b_test_audit_fail'] = false;

// Production governed write is a distinct explicit, typed-confirmation transition.
mad4b_reset_profile_state();
$GLOBALS['mad4b_test_environment'] = 'production';
$GLOBALS['mad4b_test_home'] = 'https://client.test/';
$prod = array(
	'expected_revision' => 0,
	'display_name' => 'Client Production',
	'oauth_user_ids' => array( 7 ),
	'oauth_enabled' => true,
	'write_enabled' => true,
	'production_write_confirmed' => true,
);
$prod_denied = MAD4B_SCP_Site_Profile::save_current_site( $prod );
mad4b_assert( is_wp_error( $prod_denied ) && 'mad4b_site_profile_production_write_confirmation_required' === $prod_denied->get_error_code(), 'production write requires exact typed confirmation' );
$prod['production_write_confirmation'] = MAD4B_SCP_Site_Profile::PRODUCTION_WRITE_CONFIRMATION;
$prod_ok = MAD4B_SCP_Site_Profile::save_current_site( $prod );
mad4b_assert( ! is_wp_error( $prod_ok ) && ! empty( $prod_ok['write_enabled'] ), 'production write accepts the exact explicit confirmation' );

// Production/local environment identity itself is binding: environment drift fails closed.
$GLOBALS['mad4b_test_environment'] = 'staging';
MAD4B_SCP_Site_Profile::reset_cache();
$env_drift = MAD4B_SCP_Site_Profile::status();
mad4b_assert( empty( $env_drift['environment_match'] ), 'environment drift is detected' );
mad4b_assert( ! MAD4B_SCP_Site_Profile::write_enabled(), 'environment drift removes write authority' );

fwrite( STDOUT, "MAD4B tenant-neutral site profile runtime: PASS\n" );
