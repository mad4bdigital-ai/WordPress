<?php

if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );

class WP_Error {
	private $code;
	private $message;
	public function __construct( $code, $message = '' ) { $this->code = $code; $this->message = $message; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) ); }
function absint( $value ) { return abs( (int) $value ); }
function wp_parse_url( $url ) { return parse_url( $url ); }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }

require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-site-profile.php';

function mad4b_assert( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

$canonical = MAD4B_SCP_Site_Profile::canonicalize_origin( 'https://Example.COM/path/', 'staging' );
mad4b_assert( 'https://example.com/path' === $canonical, 'canonical origin lowercases host and preserves subdirectory path' );

$bad = MAD4B_SCP_Site_Profile::canonicalize_origin( 'http://example.com', 'staging' );
mad4b_assert( is_wp_error( $bad ) && 'mad4b_site_profile_https_required' === $bad->get_error_code(), 'staging requires HTTPS' );

$local = MAD4B_SCP_Site_Profile::canonicalize_origin( 'http://localhost:8080/wp/', 'local' );
mad4b_assert( 'http://localhost:8080/wp' === $local, 'local development may use HTTP and custom port' );

$ambiguous = MAD4B_SCP_Site_Profile::canonicalize_origin( 'https://example.com/?tenant=1', 'staging' );
mad4b_assert( is_wp_error( $ambiguous ) && 'mad4b_site_profile_origin_ambiguous' === $ambiguous->get_error_code(), 'query-bearing origin is denied' );

$existing = array( 'revision' => 3 );
$input = array(
	'expected_revision' => 3,
	'canonical_origin' => 'https://staging.client.test',
	'environment' => 'staging',
	'write_enabled' => true,
);
$result = MAD4B_SCP_Site_Profile::transition_for_test( $existing, $input, 'https://staging.client.test/', 'staging', true );
mad4b_assert( ! empty( $result['ok'] ), 'generic staging enrollment accepts exact live origin/environment' );

$stale = $input;
$stale['expected_revision'] = 2;
$result = MAD4B_SCP_Site_Profile::transition_for_test( $existing, $stale, 'https://staging.client.test', 'staging', true );
mad4b_assert( empty( $result['ok'] ) && 'mad4b_site_profile_revision_conflict' === $result['error'], 'optimistic revision blocks stale profile update' );

$drift = $input;
$result = MAD4B_SCP_Site_Profile::transition_for_test( $existing, $drift, 'https://cloned.client.test', 'staging', true );
mad4b_assert( empty( $result['ok'] ) && 'mad4b_site_profile_origin_mismatch' === $result['error'], 'cloned or moved origin cannot inherit enrollment' );

$prod = array(
	'expected_revision' => 3,
	'canonical_origin' => 'https://client.test',
	'environment' => 'production',
	'write_enabled' => true,
);
$result = MAD4B_SCP_Site_Profile::transition_for_test( $existing, $prod, 'https://client.test', 'production', true );
mad4b_assert( empty( $result['ok'] ) && 'mad4b_site_profile_production_confirmation_required' === $result['error'], 'production write requires explicit phrase' );

$prod['production_write_confirmation'] = MAD4B_SCP_Site_Profile::PRODUCTION_WRITE_CONFIRMATION;
$result = MAD4B_SCP_Site_Profile::transition_for_test( $existing, $prod, 'https://client.test', 'production', true );
mad4b_assert( ! empty( $result['ok'] ), 'production write accepts exact explicit confirmation' );

$result = MAD4B_SCP_Site_Profile::transition_for_test( $existing, $input, 'https://staging.client.test', 'staging', false );
mad4b_assert( empty( $result['ok'] ) && 'mad4b_site_profile_admin_required' === $result['error'], 'non-admin cannot enroll site profile' );

$profile_a = array(
	'site_uuid' => '11111111-1111-4111-8111-111111111111',
	'revision' => 1,
	'canonical_origin' => 'https://staging.client.test',
	'environment' => 'staging',
	'subject_user_id' => 7,
	'openai_app_id' => 'plugin_asdk_app_example',
	'write_enabled' => true,
);
$profile_b = $profile_a;
$profile_b['revision'] = 2;
mad4b_assert( MAD4B_SCP_Site_Profile::profile_digest( $profile_a ) !== MAD4B_SCP_Site_Profile::profile_digest( $profile_b ), 'profile revision participates in authorization identity' );

$profile_c = $profile_a;
$profile_c['canonical_origin'] = 'https://staging.other.test';
mad4b_assert( MAD4B_SCP_Site_Profile::profile_digest( $profile_a ) !== MAD4B_SCP_Site_Profile::profile_digest( $profile_c ), 'origin participates in authorization identity' );

fwrite( STDOUT, "MAD4B tenant-neutral site profile runtime: PASS\n" );
